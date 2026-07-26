<?php

namespace Tests\Antlers\Runtime;

use Statamic\Facades\Instrument;
use Statamic\View\Antlers\Language\Nodes\AbstractNode;
use Statamic\View\Antlers\Language\Runtime\RuntimeConfiguration;
use Statamic\View\Antlers\Language\Runtime\Tracing\RuntimeTracerContract;
use Statamic\View\Antlers\Language\Runtime\Tracing\TraceManager;
use Statamic\View\Instrumentation\Span;
use Statamic\View\Instrumentation\TracerContract;
use Tests\Antlers\ParserTestCase;
use Tests\FakesViews;

class SpanTracingTest extends ParserTestCase
{
    use FakesViews;

    public function test_engine_neutral_tracers_receive_spans_from_the_antlers_runtime()
    {
        $tracer = new RecordingAntlersSpanTracer;

        $result = (string) $this->tracingParser($tracer)->parse('<p>{{ title }}</p>', ['title' => 'Hello']);

        $this->assertStringContainsString('Hello', $result);

        $enters = array_values(array_filter($tracer->events, fn ($event) => $event[0] === 'enter'));
        $exits = array_values(array_filter($tracer->events, fn ($event) => $event[0] === 'exit'));

        $this->assertNotEmpty($enters);
        $this->assertCount(count($enters), $exits);

        // The runtime traces every node, literals included; find the
        // expression node's span.
        $titleEnter = collect($enters)->first(fn ($event) => $event[1]->expression === 'title');
        $this->assertNotNull($titleEnter);

        /** @var Span $span */
        $span = $titleEnter[1];
        $this->assertSame(Span::ENGINE_ANTLERS, $span->engine);
        $this->assertSame(Span::KIND_NODE, $span->kind);
        $this->assertInstanceOf(AbstractNode::class, $span->raw());

        // Handles round-trip: each exit carries the handle its enter returned.
        foreach ($exits as $exit) {
            $this->assertStringStartsWith('handle-', $exit[2]);
        }

        // The span can expose the active scope.
        $this->assertArrayHasKey('title', $titleEnter[3]);
    }

    public function test_legacy_and_span_tracers_run_side_by_side()
    {
        $legacy = new RecordingLegacyTracer;
        $span = new RecordingAntlersSpanTracer;

        $manager = new TraceManager;
        $manager->registerTracer($legacy);
        $manager->registerTracer($span);

        $configuration = new RuntimeConfiguration;
        $configuration->isTracingEnabled = true;
        $configuration->traceManager = $manager;

        $parser = $this->parser();
        $parser->setRuntimeConfiguration($configuration);
        $parser->parse('{{ title }}', ['title' => 'Hello']);

        $this->assertNotEmpty($legacy->entered);
        $this->assertNotEmpty($span->events);
    }

    public function test_open_spans_are_closed_when_rendering_throws()
    {
        $tracer = new RecordingAntlersSpanTracer;
        $thrown = null;

        try {
            $this->tracingParser($tracer)->parse('{{ title | modifier_that_does_not_exist }}', ['title' => 'Hello']);
        } catch (\Throwable $throwable) {
            $thrown = $throwable;
        }

        $this->assertNotNull($thrown);

        $enters = array_values(array_filter($tracer->events, fn ($event) => $event[0] === 'enter'));
        $exits = array_values(array_filter($tracer->events, fn ($event) => $event[0] === 'exit'));

        $this->assertNotEmpty($enters);
        $this->assertCount(count($enters), $exits);
        $this->assertSame('complete', $tracer->events[array_key_last($tracer->events)][0]);
    }

    public function test_nested_view_completion_does_not_abandon_the_calling_tag_span()
    {
        $this->withFakeViews();
        $this->viewShouldReturnRaw('outer', '<main>{{ partial:inner }}</main>');
        $this->viewShouldReturnRaw('inner', '<p>{{ title }}</p>');

        $tracer = new RecordingAntlersSpanTracer;

        Instrument::register($tracer, ['antlers']);

        $this->assertSame('<main><p>Hello</p></main>', view('outer', ['title' => 'Hello'])->render());

        $partialEnter = collect($tracer->events)
            ->first(fn ($event) => $event[0] === 'enter' && $event[1]->expression === 'partial:inner');
        $partialExit = collect($tracer->events)
            ->first(fn ($event) => $event[0] === 'exit' && $event[1] === ($partialEnter[1] ?? null));

        $this->assertNotNull($partialEnter);
        $this->assertNotNull($partialExit);
        $this->assertSame('<p>Hello</p>', $partialExit[3]);
        $this->assertSame($partialEnter[2], $partialExit[2]);
    }

    public function test_antlers_specific_tracers_are_completed_when_a_render_throws()
    {
        $legacy = new RecordingLegacyTracer;
        $parser = $this->tracingParser($legacy);
        $thrown = null;

        try {
            $parser->parse('{{ title | modifier_that_does_not_exist }}', ['title' => 'Hello']);
        } catch (\Throwable $throwable) {
            $thrown = $throwable;
        }

        $this->assertNotNull($thrown);
        $this->assertNotEmpty($legacy->entered);
        $this->assertSame(1, $legacy->completions);
    }

    public function test_render_completion_is_reported_once_for_the_outermost_render()
    {
        $this->withFakeViews();
        $this->viewShouldReturnRaw('outer', '<main>{{ partial:inner }}{{ partial:inner }}</main>');
        $this->viewShouldReturnRaw('inner', '<p>{{ title }}</p>');

        $tracer = new RecordingAntlersSpanTracer;

        Instrument::register($tracer, ['antlers']);

        view('outer', ['title' => 'Hello'])->render();

        $completions = array_filter($tracer->events, fn ($event) => $event[0] === 'complete');

        // Two partials render inside the view; neither is the end of the
        // render that asked for them.
        $this->assertCount(1, $completions);
        $this->assertSame('complete', $tracer->events[array_key_last($tracer->events)][0]);
    }

    public function test_a_spans_scope_is_captured_rather_than_re_read()
    {
        $tracer = new RecordingAntlersSpanTracer;

        $this->tracingParser($tracer)->parse('{{ items }}{{ value }}{{ /items }}', [
            'items' => [['value' => 'first'], ['value' => 'second']],
        ]);

        $entered = collect($tracer->events)
            ->filter(fn ($event) => $event[0] === 'enter' && $event[1]->expression === 'value')
            ->values();

        $this->assertCount(2, $entered);

        // The scope recorded at enter time still reports that iteration after
        // the runtime has moved on to the next one.
        $this->assertSame('first', $entered[0][3]['value']);
        $this->assertSame('first', $entered[0][1]->scope()['value']);
        $this->assertSame('second', $entered[1][1]->scope()['value']);
    }

    public function test_each_tracer_receives_its_own_handle()
    {
        $first = new HandleStampingTracer('first');
        $second = new HandleStampingTracer('second');

        $manager = new TraceManager;
        $manager->registerTracer($first);
        $manager->registerTracer($second);

        $configuration = new RuntimeConfiguration;
        $configuration->isTracingEnabled = true;
        $configuration->traceManager = $manager;

        $parser = $this->parser();
        $parser->setRuntimeConfiguration($configuration);
        $parser->parse('<p>{{ title }}</p>', ['title' => 'Hello']);

        $this->assertNotEmpty($first->received);
        $this->assertNotEmpty($second->received);
        $this->assertSame(['first'], array_unique($first->received));
        $this->assertSame(['second'], array_unique($second->received));
    }

    /**
     * @param  TracerContract|RuntimeTracerContract  $tracer
     */
    protected function tracingParser($tracer)
    {
        $manager = new TraceManager;
        $manager->registerTracer($tracer);

        $configuration = new RuntimeConfiguration;
        $configuration->isTracingEnabled = true;
        $configuration->traceManager = $manager;

        $parser = $this->parser();
        $parser->setRuntimeConfiguration($configuration);

        return $parser;
    }
}

class HandleStampingTracer implements TracerContract
{
    public array $received = [];

    public function __construct(protected string $name)
    {
    }

    public function onEnter(Span $span): mixed
    {
        return $this->name;
    }

    public function onExit(Span $span, mixed $handle, mixed $output): void
    {
        $this->received[] = $handle;
    }

    public function onRenderComplete(): void
    {
    }
}

class RecordingAntlersSpanTracer implements TracerContract
{
    public array $events = [];

    protected int $handles = 0;

    public function onEnter(Span $span): mixed
    {
        $handle = 'handle-'.(++$this->handles);
        $this->events[] = ['enter', $span, $handle, $span->scope()];

        return $handle;
    }

    public function onExit(Span $span, mixed $handle, mixed $output): void
    {
        $this->events[] = ['exit', $span, $handle, $output];
    }

    public function onRenderComplete(): void
    {
        $this->events[] = ['complete'];
    }
}

class RecordingLegacyTracer implements RuntimeTracerContract
{
    public array $entered = [];

    public int $completions = 0;

    public function onEnter(AbstractNode $node)
    {
        $this->entered[] = $node;
    }

    public function onExit(AbstractNode $node, $runtimeContent)
    {
    }

    public function onRenderComplete()
    {
        $this->completions++;
    }
}
