<?php

namespace Tests\View\Instrumentation;

use Statamic\View\Antlers\Language\Parser\ComponentCompiler;
use Statamic\View\Antlers\Language\Runtime\RuntimeConfiguration;
use Statamic\View\Instrumentation\HtmlInstrumentation;
use Statamic\View\Instrumentation\InstrumentationManager;
use Tests\Antlers\ParserTestCase;
use Tests\FakesViews;

class AntlersComponentInstrumentationTest extends ParserTestCase
{
    use FakesViews;

    public function test_authored_component_callers_are_opaque_when_instrumented_directly()
    {
        $template = '<x-card title="{{ title }}"><span>{{ inside }}</span></x-card><p>{{ after }}</p>';
        $skips = [];

        $instrumented = HtmlInstrumentation::make()
            ->attributes('data-antlers')
            ->onSkip(function ($metadata, $region, $reason) use (&$skips) {
                $skips[$metadata['expression']] = $reason;
            })
            ->instrument($template);

        $this->assertSame([
            'title' => HtmlInstrumentation::SKIP_DYNAMIC_MARKUP,
            'inside' => HtmlInstrumentation::SKIP_DYNAMIC_MARKUP,
        ], $skips);
        $this->assertStringNotContainsString('<x-card data-antlers=', $instrumented);
        $this->assertStringNotContainsString('<span data-antlers=', $instrumented);

        // Skipped regions do not consume an id, so the only emitted region
        // is the first one even though two were analyzed before it.
        $this->assertStringContainsString('{{ after }}<!-- antlers:end 1 --></p>', $instrumented);
    }

    public function test_component_callers_are_opaque_like_blade_components()
    {
        $compiled = (new ComponentCompiler)->compile(
            '<main><x-card><span>{{ inside }}</span></x-card><p>{{ after }}</p></main>'
        );
        $skips = [];

        $instrumented = HtmlInstrumentation::make()
            ->attributes('data-antlers')
            ->onSkip(function ($metadata, $region, $reason) use (&$skips) {
                $skips[$metadata['expression']] = $reason;
            })
            ->instrument($compiled);

        $this->assertSame(['inside' => HtmlInstrumentation::SKIP_DYNAMIC_MARKUP], $skips);
        $this->assertStringContainsString('<span>{{ inside }}</span>{{ /%component_proxy:index }}', $instrumented);
        $this->assertStringNotContainsString('<span data-antlers=', $instrumented);
        $this->assertStringContainsString('{{ after }}<!-- antlers:end 1 --></p>', $instrumented);
        $this->assertSame('after', HtmlInstrumentation::decode($instrumented)['expression']);
    }

    public function test_component_view_is_instrumented_without_reinstrumenting_its_slot()
    {
        $rendered = $this->renderInstrumentedComponent(
            '<article>{{ slot }}</article>',
            '<x-review><b>{{ value }}</b></x-review>',
            ['value' => 'Hello']
        );

        $this->assertSame(1, substr_count($rendered, '<!-- antlers:start '));
        $this->assertSame(1, substr_count($rendered, 'data-antlers='));
        $this->assertStringContainsString('<b>Hello</b>', $rendered);
        $this->assertStringNotContainsString('<b data-antlers=', $rendered);
        $this->assertSame('slot', HtmlInstrumentation::decode($rendered)['expression']);
    }

    public function test_component_slots_do_not_put_markers_inside_attribute_values()
    {
        $rendered = $this->renderInstrumentedComponent(
            '<input value="{{ slot }}">',
            '<x-review>{{ value }}</x-review>',
            ['value' => 'Hello']
        );

        $this->assertStringNotContainsString('<!--', $rendered);
        $this->assertStringContainsString(' value="Hello">', $rendered);
        preg_match('/data-antlers="([^"]+)"/', $rendered, $matches);

        $this->assertSame('slot', HtmlInstrumentation::decode($matches[1])[0]['expression']);
    }

    public function test_root_component_expressions_do_not_put_markers_in_the_callers_raw_text_context()
    {
        $rendered = $this->renderInstrumentedComponent(
            '{{ title }}',
            '<textarea><x-review title="Hello" /></textarea>'
        );

        $this->assertSame('<textarea>Hello</textarea>', $rendered);
    }

    protected function renderInstrumentedComponent($component, $caller, $data = [])
    {
        $configuration = app(RuntimeConfiguration::class);

        app(InstrumentationManager::class)->register(
            HtmlInstrumentation::make()->attributes('data-antlers'),
            [InstrumentationManager::ENGINE_ANTLERS]
        );

        $this->withFakeViews();
        $this->viewShouldReturnRaw('components.review', $component);

        $parser = $this->parser($data, true);
        $parser->setRuntimeConfiguration($configuration);

        return (string) $parser->parse($caller, $data);
    }
}
