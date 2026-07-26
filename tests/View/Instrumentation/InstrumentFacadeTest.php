<?php

namespace Tests\View\Instrumentation;

use Illuminate\Support\Facades\Blade;
use Illuminate\View\Component;
use InvalidArgumentException;
use RuntimeException;
use Statamic\Contracts\View\Antlers\Parser;
use Statamic\Facades\Instrument;
use Statamic\View\Antlers\Language\Nodes\AbstractNode;
use Statamic\View\Antlers\Language\Runtime\RuntimeConfiguration;
use Statamic\View\Antlers\Language\Runtime\Tracing\RuntimeTracerContract;
use Statamic\View\Blade\BladeTagHost;
use Statamic\View\Instrumentation\Blade\TemplateAnalyzer;
use Statamic\View\Instrumentation\HtmlInstrumentation;
use Statamic\View\Instrumentation\InstrumentationManager;
use Tests\TestCase;
use Tests\View\Instrumentation\Support\RecordingSpanTracer;

class InstrumentFacadeTest extends TestCase
{
    public function tearDown(): void
    {
        BladeTagHost::traceUsing(null);

        parent::tearDown();
    }

    protected function bladeTracers(): array
    {
        return $this->app->make(InstrumentationManager::class)->bladeTracers();
    }

    public function test_the_facade_builds_fresh_html_instrumentation()
    {
        $first = Instrument::html();
        $second = Instrument::html();

        $this->assertInstanceOf(HtmlInstrumentation::class, $first);
        $this->assertNotSame($first, $second);
    }

    public function test_the_facade_decodes_marker_payloads()
    {
        $instrumented = Instrument::html()->comments('probe')->instrument('<p>{{ title }}</p>');

        $metadata = Instrument::decode($instrumented);

        $this->assertSame('title', $metadata['expression']);
        $this->assertSame('antlers', $metadata['engine']);
    }

    public function test_html_instrumentation_registers_as_an_antlers_preparser()
    {
        $instrumentation = HtmlInstrumentation::make()->comments('probe');

        Instrument::register($instrumentation, ['antlers']);

        $this->assertContains($instrumentation, $this->app->make(RuntimeConfiguration::class)->getPreparsers());
    }

    public function test_registering_the_same_html_instrumentation_twice_does_not_duplicate_it()
    {
        $instrumentation = HtmlInstrumentation::make()->comments('probe');

        Instrument::register($instrumentation);
        Instrument::register($instrumentation);

        $this->assertSame(
            [$instrumentation],
            $this->app->make(RuntimeConfiguration::class)->getPreparsers()
        );
        $this->assertSame(
            1,
            substr_count(
                $this->app->make(InstrumentationManager::class)->preprocessBlade('<p>{{ $title }}</p>'),
                '<!-- probe:start'
            )
        );
    }

    public function test_registrations_after_the_runtime_configuration_resolves_apply_immediately()
    {
        $config = $this->app->make(RuntimeConfiguration::class);

        $this->assertFalse($config->isTracingEnabled);

        $instrumentation = HtmlInstrumentation::make()->comments('probe');
        $tracer = new RecordingSpanTracer;

        Instrument::register($instrumentation, ['antlers']);
        Instrument::register($tracer, ['antlers']);

        $this->assertContains($instrumentation, $config->getPreparsers());
        $this->assertTrue($config->isTracingEnabled);
        $this->assertNotNull($config->traceManager);
    }

    public function test_html_instrumentation_registered_after_the_parser_resolves_is_used_immediately()
    {
        $parser = $this->app->make(Parser::class);
        $instrumentation = HtmlInstrumentation::make()->comments('probe');

        Instrument::register($instrumentation, ['antlers']);

        $rendered = (string) $parser->parse('<p>{{ title }}</p>', ['title' => 'Hello']);

        $this->assertStringContainsString('<!-- probe:start', $rendered);
        $this->assertStringContainsString('Hello<!-- probe:end 1 -->', $rendered);
    }

    public function test_unified_tracers_register_with_both_engines_by_default()
    {
        $tracer = new RecordingSpanTracer;

        Instrument::register($tracer);

        $config = $this->app->make(RuntimeConfiguration::class);

        $this->assertTrue($config->isTracingEnabled);
        $this->assertNotNull($config->traceManager);
        $this->assertContains($tracer, $this->bladeTracers());
    }

    public function test_registering_the_same_blade_tracer_twice_does_not_duplicate_callbacks()
    {
        $tracer = new RecordingSpanTracer;

        Instrument::register($tracer, ['blade']);
        Instrument::register($tracer, ['blade']);

        $this->assertSame([$tracer], $this->bladeTracers());
    }

    public function test_engine_narrowing_limits_where_a_tracer_registers()
    {
        Instrument::register(new RecordingSpanTracer, ['blade']);

        $this->assertCount(1, $this->bladeTracers());
        $this->assertFalse($this->app->make(RuntimeConfiguration::class)->isTracingEnabled);

        BladeTagHost::traceUsing(null);

        Instrument::register(new RecordingSpanTracer, ['antlers']);

        $this->assertSame([], $this->bladeTracers());
        $this->assertTrue($this->app->make(RuntimeConfiguration::class)->isTracingEnabled);
    }

    public function test_antlers_specific_tracers_never_register_with_blade()
    {
        Instrument::register(new FacadeAntlersOnlyTracer);

        $this->assertSame([], $this->bladeTracers());
        $this->assertTrue($this->app->make(RuntimeConfiguration::class)->isTracingEnabled);
    }

    public function test_antlers_specific_tracers_cannot_target_blade()
    {
        $this->expectException(InvalidArgumentException::class);

        Instrument::register(new FacadeAntlersOnlyTracer, ['blade']);
    }

    public function test_class_strings_resolve_through_the_container()
    {
        Instrument::register(RecordingSpanTracer::class, ['blade']);

        $this->assertInstanceOf(RecordingSpanTracer::class, $this->bladeTracers()[0]);
    }

    public function test_unknown_engines_are_rejected()
    {
        $this->expectException(InvalidArgumentException::class);

        Instrument::register(new RecordingSpanTracer, ['vue']);
    }

    public function test_empty_engine_lists_are_rejected()
    {
        $this->expectException(InvalidArgumentException::class);

        Instrument::register(new RecordingSpanTracer, []);
    }

    public function test_arbitrary_objects_are_rejected()
    {
        $this->expectException(InvalidArgumentException::class);

        Instrument::register(new \stdClass);
    }

    public function test_html_instrumentation_reaches_blade_through_the_precompiler_seam()
    {
        if (! TemplateAnalyzer::isAvailable()) {
            $this->markTestSkipped('fortephp/forte is not installed.');
        }

        Instrument::register(HtmlInstrumentation::make()->comments('probe'));

        $compiled = $this->app['blade.compiler']->compileString('<p>{{ $title }}</p>');

        $this->assertStringContainsString('<!-- probe:start', $compiled);
        $this->assertStringContainsString('<!-- probe:end 1 -->', $compiled);
    }

    public function test_blade_instrumentation_sees_native_components_before_laravel_compiles_them()
    {
        Blade::component(NativeInstrumentationComponent::class, 'instrument-card');
        Instrument::register(HtmlInstrumentation::make()->comments('probe'), ['blade']);

        $compiled = $this->app['blade.compiler']->compileString(
            '<x-instrument-card><p>{{ $inside }}</p></x-instrument-card><p>{{ $after }}</p>'
        );

        $this->assertSame(1, substr_count($compiled, '<!-- probe:start'));
        $this->assertStringNotContainsString('probe:start', strstr($compiled, '$inside', true));
        $this->assertStringContainsString('$after', $compiled);
    }

    public function test_blade_preparation_leaves_verbatim_echoes_uninstrumented()
    {
        Instrument::register(HtmlInstrumentation::make()->comments('probe'), ['blade']);

        $compiled = $this->app['blade.compiler']->compileString(
            '@verbatim<p>{{ $literal }}</p>@endverbatim<p>{{ $real }}</p>'
        );

        $this->assertSame(1, substr_count($compiled, '<!-- probe:start'));
        $this->assertStringContainsString('<p>{{ $literal }}</p>', $compiled);
    }

    public function test_blade_preparation_leaves_php_block_contents_uninstrumented()
    {
        Instrument::register(HtmlInstrumentation::make()->comments('probe'), ['blade']);

        $compiled = $this->app['blade.compiler']->compileString(
            <<<'BLADE'
@php
    $literal = '{{ $not_a_blade_echo }}';
@endphp
<p>{{ $real }}</p>
BLADE
        );

        $this->assertSame(1, substr_count($compiled, '<!-- probe:start'));
        $this->assertStringContainsString("'{{ \$not_a_blade_echo }}'", $compiled);
    }

    public function test_blade_precompilation_is_untouched_when_nothing_is_registered()
    {
        $this->assertStringNotContainsString(
            'antlers:start',
            $this->app['blade.compiler']->compileString('<p>{{ $title }}</p>')
        );
    }

    public function test_explicit_blade_targeting_without_forte_throws()
    {
        $manager = new class extends InstrumentationManager
        {
            protected function bladeAvailable()
            {
                return false;
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('composer require fortephp/forte');

        $manager->register(HtmlInstrumentation::make()->comments('probe'), ['blade']);
    }

    public function test_configuration_changed_after_the_parser_resolves_still_applies()
    {
        $this->app->make(Parser::class);

        config(['statamic.antlers.instrumentation' => [
            'enabled' => true,
            'comments' => true,
            'prefix' => 'late',
        ]]);

        $rendered = (string) $this->app->make(Parser::class)->parse('<p>{{ title }}</p>', ['title' => 'Hello']);

        $this->assertStringContainsString('<!-- late:start', $rendered);
    }

    public function test_repeatedly_applying_configuration_does_not_stack_up_registrations()
    {
        config(['statamic.antlers.instrumentation' => [
            'enabled' => true,
            'comments' => true,
            'prefix' => 'once',
        ]]);
        config(['statamic.antlers.tracing' => true]);
        config(['statamic.antlers.tracers' => [RecordingSpanTracer::class]]);

        $this->app->make(Parser::class);
        $this->app->make(Parser::class);
        $rendered = (string) $this->app->make(Parser::class)->parse('<p>{{ title }}</p>', ['title' => 'Hello']);

        $config = $this->app->make(RuntimeConfiguration::class);

        $this->assertCount(1, $config->getPreparsers());
        $this->assertCount(1, $config->traceManager->spanTracers());
        $this->assertSame(1, substr_count($rendered, '<!-- once:start'));
    }

    public function test_forget_removes_a_registration_from_both_engines()
    {
        $instrumentation = HtmlInstrumentation::make()->comments('probe');
        $tracer = new RecordingSpanTracer;

        Instrument::register($instrumentation);
        Instrument::register($tracer);

        Instrument::forget($instrumentation);
        Instrument::forget($tracer);

        $config = $this->app->make(RuntimeConfiguration::class);

        $this->assertSame([], $config->getPreparsers());
        $this->assertSame([], $config->traceManager->spanTracers());
        $this->assertSame([], $this->bladeTracers());
        $this->assertSame([], $this->app->make(InstrumentationManager::class)->bladeInstrumenters());
    }

    public function test_forget_can_be_narrowed_to_one_engine()
    {
        $tracer = new RecordingSpanTracer;

        Instrument::register($tracer);
        Instrument::forget($tracer, ['blade']);

        $this->assertSame([], $this->bladeTracers());
        $this->assertSame(
            [$tracer],
            $this->app->make(RuntimeConfiguration::class)->traceManager->spanTracers()
        );
    }

    public function test_flush_drops_every_registration()
    {
        Instrument::register(HtmlInstrumentation::make()->comments('probe'));
        Instrument::register(new RecordingSpanTracer);

        Instrument::flush();

        $config = $this->app->make(RuntimeConfiguration::class);

        $this->assertSame([], $config->getPreparsers());
        $this->assertSame([], $config->traceManager?->spanTracers() ?? []);
        $this->assertSame([], $this->bladeTracers());
        $this->assertStringNotContainsString(
            'probe:start',
            $this->app->make(InstrumentationManager::class)->preprocessBlade('<p>{{ $title }}</p>')
        );
    }

    public function test_flush_leaves_tracers_registered_outside_the_manager_alone()
    {
        Instrument::register(new RecordingSpanTracer);

        $config = $this->app->make(RuntimeConfiguration::class);

        // Stands in for the debugger and profiler, which attach to the trace
        // manager directly rather than registering through the facade.
        $foreign = new FacadeAntlersOnlyTracer;
        $config->traceManager->registerTracer($foreign);

        Instrument::flush();

        $this->assertSame([], $config->traceManager->spanTracers());
        $this->assertSame([$foreign], $config->traceManager->tracers());
    }

    public function test_configurations_that_cannot_be_encoded_do_not_share_a_result_cache()
    {
        $manager = $this->app->make(InstrumentationManager::class);

        $first = $manager->configuredHtmlInstrumentation([
            'enabled' => true, 'prefix' => 'one', 'metadata' => ['bin' => "\xB1\x31"],
        ]);
        $second = $manager->configuredHtmlInstrumentation([
            'enabled' => true, 'prefix' => 'two', 'metadata' => ['bin' => "\xB1\x99"],
        ]);

        $this->assertNotSame($first, $second);
        $this->assertStringContainsString('<!-- one:start', $first->instrumentAntlers('<p>{{ a }}</p>'));
        $this->assertStringContainsString('<!-- two:start', $second->instrumentAntlers('<p>{{ a }}</p>'));
    }

    public function test_default_engines_silently_skip_blade_without_forte()
    {
        $manager = new class extends InstrumentationManager
        {
            protected function bladeAvailable()
            {
                return false;
            }
        };

        $manager->register(HtmlInstrumentation::make()->comments('probe'));

        $this->assertSame('<p>{{ $title }}</p>', $manager->preprocessBlade('<p>{{ $title }}</p>'));
    }
}

class FacadeAntlersOnlyTracer implements RuntimeTracerContract
{
    public function onEnter(AbstractNode $node)
    {
    }

    public function onExit(AbstractNode $node, $runtimeContent)
    {
    }

    public function onRenderComplete()
    {
    }
}

class NativeInstrumentationComponent extends Component
{
    public function render()
    {
        return '<div>{{ $slot }}</div>';
    }
}
