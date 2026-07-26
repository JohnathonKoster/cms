<?php

namespace Tests\View\Blade;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Statamic\Tags\Tags;
use Statamic\View\Blade\BladeTagHost;
use Statamic\View\Instrumentation\InstrumentationManager;
use Statamic\View\Instrumentation\Span;
use Tests\TestCase;
use Tests\View\Instrumentation\Support\RecordingSpanTracer;

class BladeTagHostTest extends TestCase
{
    public function tearDown(): void
    {
        BladeTagHost::traceUsing(null);

        parent::tearDown();
    }

    #[Test]
    public function it_hands_tracers_registered_before_the_manager_existed_over_to_it()
    {
        $tracer = new RecordingSpanTracer;

        // Stands in for a tracer registered before the service provider bound
        // the manager.
        $fallback = new \ReflectionProperty(BladeTagHost::class, 'fallbackTracers');
        $fallback->setAccessible(true);
        $fallback->setValue(null, [$tracer]);

        (new BladeTagHost([]))->setTag(new BladeTagHostTestTag, 'index')->render();

        $this->assertContains($tracer, $this->app->make(InstrumentationManager::class)->bladeTracers());
        $this->assertSame([], $fallback->getValue());
        $this->assertCount(2, $tracer->events);
    }

    #[Test]
    public function it_gives_each_tracer_its_own_handle()
    {
        $first = new RecordingSpanTracer('first');
        $second = new RecordingSpanTracer('second');

        BladeTagHost::traceUsing($first);
        BladeTagHost::addTracer($second);

        (new BladeTagHost([]))->setTag(new BladeTagHostTestTag, 'index')->render();

        $this->assertSame($first->events[0][1], $second->events[0][1], 'Both tracers see one span.');
        $this->assertSame('handle-1', $first->events[1][2]);
        $this->assertSame('handle-1', $second->events[1][2]);
    }

    #[Test]
    public function it_traces_tag_execution_with_spans()
    {
        $tracer = new RecordingSpanTracer;
        $tag = new BladeTagHostTestTag;

        BladeTagHost::traceUsing($tracer);

        $result = (new BladeTagHost([]))
            ->setTag($tag, 'index')
            ->render();

        $this->assertSame('rendered', $result);
        $this->assertCount(2, $tracer->events);

        [$enterEvent, $exitEvent] = $tracer->events;

        $this->assertSame('enter', $enterEvent[0]);
        $span = $enterEvent[1];
        $this->assertInstanceOf(Span::class, $span);
        $this->assertSame(Span::ENGINE_BLADE, $span->engine);
        $this->assertSame(Span::KIND_TAG, $span->kind);
        $this->assertSame('index', $span->meta('method'));
        $this->assertSame($tag, $span->raw());

        // Exit receives the same span, the handle from enter, and the output.
        $this->assertSame(['exit', $span, 'handle-1', 'rendered'], $exitEvent);
    }

    #[Test]
    public function it_exits_the_trace_with_null_output_when_tag_execution_throws()
    {
        $tracer = new RecordingSpanTracer;
        $tag = new BladeTagHostTestTag;

        BladeTagHost::traceUsing($tracer);

        try {
            (new BladeTagHost([]))
                ->setTag($tag, 'fail')
                ->render();

            $this->fail('Expected tag execution to throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('failed', $exception->getMessage());
        }

        $this->assertSame('enter', $tracer->events[0][0]);
        $this->assertSame('exit', $tracer->events[1][0]);
        $this->assertNull($tracer->events[1][3]);
    }

    #[Test]
    public function a_failed_reused_host_does_not_report_the_previous_output()
    {
        $tracer = new RecordingSpanTracer;
        $tag = new BladeTagHostTestTag;
        $host = new BladeTagHost([]);

        BladeTagHost::traceUsing($tracer);

        $host->setTag($tag, 'index')->render();

        try {
            $host->setTag($tag, 'fail')->render();
            $this->fail('Expected tag execution to throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('failed', $exception->getMessage());
        }

        $this->assertNull($tracer->events[3][3]);
    }

    #[Test]
    public function multiple_tracers_are_notified_and_exit_in_reverse_order()
    {
        $first = new RecordingSpanTracer('first');
        $second = new RecordingSpanTracer('second');
        $order = [];
        $first->log = function ($event) use (&$order) {
            $order[] = 'first:'.$event;
        };
        $second->log = function ($event) use (&$order) {
            $order[] = 'second:'.$event;
        };

        BladeTagHost::traceUsing($first);
        BladeTagHost::addTracer($second);

        (new BladeTagHost([]))
            ->setTag(new BladeTagHostTestTag, 'index')
            ->render();

        $this->assertSame([
            'first:enter', 'second:enter',
            'second:exit', 'first:exit',
        ], $order);
    }

    #[Test]
    public function trace_using_replaces_previously_registered_tracers()
    {
        $first = new RecordingSpanTracer;
        $second = new RecordingSpanTracer;

        BladeTagHost::traceUsing($first);
        BladeTagHost::traceUsing($second);

        (new BladeTagHost([]))
            ->setTag(new BladeTagHostTestTag, 'index')
            ->render();

        $this->assertSame([], $first->events);
        $this->assertCount(2, $second->events);
    }

    #[Test]
    public function tracers_do_not_leak_when_the_application_manager_is_rebuilt()
    {
        $tracer = new RecordingSpanTracer;

        BladeTagHost::traceUsing($tracer);
        $this->app->forgetInstance(InstrumentationManager::class);

        (new BladeTagHost([]))
            ->setTag(new BladeTagHostTestTag, 'index')
            ->render();

        $this->assertSame([], $tracer->events);
    }
}

class BladeTagHostTestTag extends Tags
{
    public function index()
    {
        return 'rendered';
    }

    public function fail()
    {
        throw new RuntimeException('failed');
    }
}
