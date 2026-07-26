<?php

namespace Tests\View\Instrumentation;

use Statamic\View\Antlers\Language\Nodes\AntlersNode;
use Statamic\View\Antlers\Language\Parser\DocumentParser;
use Statamic\View\Antlers\Language\Runtime\GlobalRuntimeState;
use Statamic\View\Instrumentation\Blade\TemplateAnalyzer;
use Statamic\View\Instrumentation\HtmlContext;
use Statamic\View\Instrumentation\HtmlInstrumentation;
use Statamic\View\Instrumentation\InstrumentationManager;
use Tests\Antlers\ParserTestCase;

/**
 * Blade instrumentation through the optional Forte parser. These tests are
 * skipped when Forte is not installed; CI installs it explicitly.
 */
class BladeInstrumentationTest extends ParserTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! TemplateAnalyzer::isAvailable()) {
            $this->markTestSkipped('fortephp/forte is not installed.');
        }

        HtmlInstrumentation::flushSharedResultCache();
    }

    public function test_echoes_in_element_content_receive_comment_pairs_with_blade_engine_metadata()
    {
        $instrumented = HtmlInstrumentation::make()->instrumentBlade('<p>{{ $title }}</p>');

        $this->assertSame(1, substr_count($instrumented, '<!-- antlers:start'));
        $this->assertStringContainsString('{{ $title }}<!-- antlers:end 1 --></p>', $instrumented);

        $metadata = HtmlInstrumentation::decode($instrumented);

        $this->assertSame('blade', $metadata['engine']);
        $this->assertSame('single', $metadata['type']);
        $this->assertSame('$title', $metadata['expression']);
        $this->assertSame('element-content', $metadata['context']);
        $this->assertSame('p', $metadata['element']);
    }

    public function test_blade_templates_without_supported_regions_are_returned_untouched()
    {
        $template = '<main><p>Static Blade</p>@if($visible)<hr>@endif</main>';

        $this->assertSame($template, HtmlInstrumentation::make()->instrumentBlade($template));
    }

    public function test_raw_echoes_are_instrumented_like_escaped_ones()
    {
        $instrumented = HtmlInstrumentation::make()->instrumentBlade('<div>{!! $html !!}</div>');

        $this->assertStringContainsString('{!! $html !!}<!-- antlers:end 1 -->', $instrumented);
        $this->assertSame('$html', HtmlInstrumentation::decode($instrumented)['expression']);
    }

    public function test_echoes_in_attribute_values_land_on_the_owning_element_with_the_attribute_name()
    {
        $instrumented = HtmlInstrumentation::make()
            ->attributes('data-antlers')
            ->instrumentBlade('<a class="btn {{ $classes }}" href="/x">Go</a>');

        $this->assertStringNotContainsString('<!--', $instrumented);
        $this->assertStringStartsWith('<a data-antlers="', $instrumented);

        preg_match('/data-antlers="([^"]+)"/', $instrumented, $matches);
        $payload = HtmlInstrumentation::decode($matches[1]);

        $this->assertSame('attribute-value', $payload[0]['context']);
        $this->assertSame('class', $payload[0]['attribute']);
        $this->assertSame('a', $payload[0]['element']);
    }

    public function test_echoes_in_script_content_fall_back_to_the_containing_element()
    {
        $instrumented = HtmlInstrumentation::make()
            ->attributes('data-antlers')
            ->instrumentBlade('<script>var t = {{ $title }};</script>');

        $this->assertStringNotContainsString('<!--', $instrumented);
        $this->assertStringStartsWith('<script data-antlers="', $instrumented);
        $this->assertStringContainsString('var t = {{ $title }};</script>', $instrumented);

        preg_match('/data-antlers="([^"]+)"/', $instrumented, $matches);

        $this->assertSame('raw-text', HtmlInstrumentation::decode($matches[1])[0]['context']);
    }

    public function test_blade_components_are_dynamic_markup()
    {
        $skips = [];

        $instrumented = HtmlInstrumentation::make()
            ->attributes('data-antlers')
            ->onSkip(function ($metadata, $region, $reason) use (&$skips) {
                $skips[$metadata['expression']] = $reason;
            })
            ->instrumentBlade('<x-card :title="$t">{{ $slotContent }}</x-card><p>{{ $after }}</p>');

        // A component renders arbitrary markup — nothing inside one can be
        // classified, and its attributes are props, not HTML.
        $this->assertSame(['$slotContent' => HtmlInstrumentation::SKIP_DYNAMIC_MARKUP], $skips);
        $this->assertStringNotContainsString('<x-card data-antlers', $instrumented);
        $this->assertStringContainsString('{{ $after }}<!-- antlers:end', $instrumented);
    }

    public function test_statamic_blade_components_are_dynamic_markup_for_every_supported_prefix()
    {
        $skips = [];
        $template = '<s:collection :from="$one">{{ $insideOne }}</s:collection>'
            .'<s-collection :from="$two">{{ $insideTwo }}</s-collection>'
            .'<statamic:collection :from="$three">{{ $insideThree }}</statamic:collection>'
            .'<statamic-collection :from="$four">{{ $insideFour }}</statamic-collection>'
            .'<p>{{ $after }}</p>';

        $instrumented = HtmlInstrumentation::make()
            ->attributes('data-template')
            ->onSkip(function ($metadata, $region, $reason) use (&$skips) {
                $skips[$metadata['expression']] = $reason;
            })
            ->instrumentBlade($template);

        $this->assertSame([
            '$insideOne' => HtmlInstrumentation::SKIP_DYNAMIC_MARKUP,
            '$insideTwo' => HtmlInstrumentation::SKIP_DYNAMIC_MARKUP,
            '$insideThree' => HtmlInstrumentation::SKIP_DYNAMIC_MARKUP,
            '$insideFour' => HtmlInstrumentation::SKIP_DYNAMIC_MARKUP,
        ], $skips);
        $this->assertSame(1, substr_count($instrumented, 'data-template='));
        $this->assertStringContainsString('<p data-template="', $instrumented);
    }

    public function test_existing_attributes_are_never_overwritten()
    {
        $instrumented = HtmlInstrumentation::make()
            ->attributes('data-antlers')
            ->instrumentBlade('<div data-antlers="mine" class="{{ $classes }}">x</div>');

        $this->assertSame(1, substr_count($instrumented, 'data-antlers='));
        $this->assertStringContainsString('data-antlers="mine"', $instrumented);
    }

    public function test_antlers_blocks_are_instrumented_by_the_antlers_pipeline()
    {
        $template = "<div>\n@antlers\n<span>{{ antlers_var }}</span>\n@endantlers\n</div>";

        $instrumented = HtmlInstrumentation::make()->instrumentBlade($template);

        // The block's interior carries Antlers-engine markers; the block
        // markers themselves stay untouched.
        $this->assertStringContainsString('@antlers', $instrumented);
        $this->assertStringContainsString('@endantlers', $instrumented);
        $this->assertStringContainsString('{{ antlers_var }}<!-- antlers:end 1 --></span>', $instrumented);

        preg_match('/antlers:start ([A-Za-z0-9+\/=]+) -->/', $instrumented, $matches);

        $this->assertSame('antlers', HtmlInstrumentation::decode($matches[1])['engine']);
    }

    public function test_antlers_blocks_share_the_surrounding_blade_id_sequence()
    {
        $instrumented = HtmlInstrumentation::make()->instrumentBlade(
            '<p>{{ $before }}</p>@antlers<span>{{ inside }}</span>@endantlers<p>{{ $after }}</p>'
        );

        preg_match_all('/antlers:start ([A-Za-z0-9+\/=]+) -->/', $instrumented, $matches);
        $metadata = array_map([HtmlInstrumentation::class, 'decode'], $matches[1]);

        $this->assertSame([1, 2, 3], array_column($metadata, 'id'));
        $this->assertSame(['blade', 'antlers', 'blade'], array_column($metadata, 'engine'));
        $this->assertSame(1, substr_count($instrumented, '<!-- antlers:end 1 -->'));
        $this->assertSame(1, substr_count($instrumented, '<!-- antlers:end 2 -->'));
        $this->assertSame(1, substr_count($instrumented, '<!-- antlers:end 3 -->'));
    }

    public function test_direct_sources_allow_root_comments_but_registered_views_do_not()
    {
        $instrumenter = HtmlInstrumentation::make();

        $this->assertStringContainsString(
            '<!-- antlers:start ',
            $instrumenter->instrumentBlade('{{ $title }}')
        );
        $manager = new InstrumentationManager;
        $manager->register($instrumenter, [InstrumentationManager::ENGINE_BLADE]);

        $this->assertSame('{{ $title }}', $manager->preprocessBlade('{{ $title }}'));

        $nestedStaticRoot = $manager->preprocessBlade(
            '@antlers<p>{{ title }}</p>@endantlers'
        );

        $this->assertStringContainsString('<!-- antlers:start ', $nestedStaticRoot);
        $this->assertSame('antlers', HtmlInstrumentation::decode($nestedStaticRoot)['engine']);
    }

    public function test_filters_apply_to_expressions_inside_antlers_blocks()
    {
        $skips = [];
        $template = '@antlers<span>{{ title }}{{ partial:card }}</span>@endantlers';

        $instrumented = HtmlInstrumentation::make()
            ->matching(['partial:*'])
            ->onSkip(function ($metadata, $region, $reason) use (&$skips) {
                $skips[$metadata['expression']] = $reason;
            })
            ->instrumentBlade($template);

        $this->assertSame(['title' => HtmlInstrumentation::SKIP_FILTERED], $skips);
        $this->assertSame(1, substr_count($instrumented, '<!-- antlers:start'));
        $this->assertStringContainsString('{{ partial:card }}<!-- antlers:end 1 -->', $instrumented);
        $this->assertSame('partial:card', HtmlInstrumentation::decode($instrumented)['expression']);
    }

    public function test_antlers_block_line_metadata_is_relative_to_the_blade_template()
    {
        $template = "<div>\n<p>Blade</p>\n@antlers\n{{ title }}\n@endantlers\n</div>";
        $previousStack = GlobalRuntimeState::$globalTagEnterStack;
        GlobalRuntimeState::$globalTagEnterStack = [];
        $outerNodes = (new DocumentParser)->parse(str_repeat("\n", 8).'{{ outer }}');
        $outer = collect($outerNodes)->first(fn ($node) => $node instanceof AntlersNode);
        GlobalRuntimeState::$globalTagEnterStack = [$outer];

        try {
            $instrumented = HtmlInstrumentation::make()->instrumentBlade($template);
        } finally {
            GlobalRuntimeState::$globalTagEnterStack = $previousStack;
        }

        $this->assertSame(4, HtmlInstrumentation::decode($instrumented)['line']);
    }

    public function test_antlers_blocks_in_unsafe_positions_are_left_alone_and_reported()
    {
        $skips = [];
        $template = '<script>@antlers{{ x }}@endantlers</script>';

        $instrumented = HtmlInstrumentation::make()
            ->onSkip(function ($metadata, $region, $reason) use (&$skips) {
                $skips[$metadata['expression']] = $reason;
            })
            ->instrumentBlade($template);

        $this->assertSame($template, $instrumented);
        $this->assertSame(HtmlInstrumentation::SKIP_NO_STRATEGY, $skips['@antlers']);
    }

    public function test_blade_comments_and_html_comment_content_produce_no_markers()
    {
        $template = '{{-- {{ $bladeComment }} --}}<p>{{ $real }}</p>';

        $instrumented = HtmlInstrumentation::make()->instrumentBlade($template);

        $this->assertSame(1, substr_count($instrumented, '<!-- antlers:start'));
        $this->assertStringContainsString('{{-- {{ $bladeComment }} --}}', $instrumented);
    }

    public function test_echoes_inside_html_comments_are_not_treated_as_blade_regions()
    {
        $skips = [];
        $template = '<!-- {{ $hidden }} --><p>{{ $real }}</p>';

        $instrumented = HtmlInstrumentation::make()
            ->onSkip(function ($metadata, $region, $reason) use (&$skips) {
                $skips[$metadata['expression']] = $reason;
            })
            ->instrumentBlade($template);

        $this->assertSame([], $skips);
        $this->assertStringContainsString('<!-- {{ $hidden }} -->', $instrumented);
        $this->assertSame(1, substr_count($instrumented, '<!-- antlers:start'));
    }

    public function test_multibyte_templates_report_character_offsets()
    {
        $instrumented = HtmlInstrumentation::make()->instrumentBlade('<p>é{{ $x }}</p>');

        $metadata = HtmlInstrumentation::decode($instrumented);

        // The echo starts at character 4 (byte 5); metadata offsets are
        // character-based on every engine.
        $this->assertSame(4, $metadata['start']);
        $this->assertTrue(mb_check_encoding($instrumented, 'UTF-8'));
    }

    public function test_blade_results_are_memoized_separately_from_antlers()
    {
        $template = '<p>{{ $title }}</p>';
        $instrumenter = HtmlInstrumentation::make();

        $blade = $instrumenter->instrumentBlade($template);
        $antlers = $instrumenter->instrument($template);

        $this->assertNotSame($blade, $antlers);
        $this->assertSame('blade', HtmlInstrumentation::decode($blade)['engine']);
        $this->assertSame('antlers', HtmlInstrumentation::decode($antlers)['engine']);
        $this->assertSame($blade, $instrumenter->instrumentBlade($template));
    }

    public function test_the_skip_callback_receives_the_region_wrapping_the_forte_node()
    {
        $received = null;

        HtmlInstrumentation::make()
            ->onSkip(function ($metadata, $region, $reason) use (&$received) {
                $received = [$region, $reason];
            })
            ->instrumentBlade('<script>{{ $x }}</script>');

        [$region, $reason] = $received;

        $this->assertInstanceOf(\Forte\Ast\EchoNode::class, $region->raw());
        $this->assertInstanceOf(HtmlContext::class, $region->context());
        $this->assertSame(HtmlContext::KIND_RAW_TEXT, $region->context()->kind);
        $this->assertSame(HtmlInstrumentation::SKIP_NO_STRATEGY, $reason);
    }
}

/**
 * The inverse guard: without Forte, Blade instrumentation must fail loudly
 * rather than silently returning uninstrumented output. Skipped whenever
 * Forte is installed.
 */
class BladeInstrumentationWithoutForteTest extends ParserTestCase
{
    public function test_static_blade_sources_are_passthrough_without_forte()
    {
        if (TemplateAnalyzer::isAvailable()) {
            $this->markTestSkipped('fortephp/forte is installed; the missing-dependency path cannot be exercised.');
        }

        $template = '<p>Nothing to instrument.</p>';

        $this->assertSame($template, HtmlInstrumentation::make()->instrumentBlade($template));
    }

    public function test_paired_directives_receive_marker_pairs()
    {
        $instrumented = HtmlInstrumentation::make()->comments('probe')->instrumentBlade(
            '<div>@if($x)<p>text</p>@endif</div>'
        );

        $this->assertStringContainsString('<div><!-- probe:start', $instrumented);
        $this->assertStringContainsString('@endif<!-- probe:end 1 --></div>', $instrumented);

        $metadata = HtmlInstrumentation::decode($instrumented);

        $this->assertSame('blade', $metadata['engine']);
        $this->assertSame('pair', $metadata['type']);
        $this->assertSame('@if ($x)', $metadata['expression']);
        $this->assertSame('div', $metadata['element']);
    }

    public function test_nested_directive_pairs_close_from_the_inside_out()
    {
        $instrumented = HtmlInstrumentation::make()->comments('probe')->instrumentBlade(
            '<div>@foreach($a as $b)@if($b)<p>{{ $b }}</p>@endif@endforeach</div>'
        );

        $this->assertSame(3, substr_count($instrumented, '<!-- probe:start'));
        $this->assertStringContainsString('@endif<!-- probe:end 2 -->@endforeach<!-- probe:end 1 -->', $instrumented);
    }

    public function test_directive_pairs_crossing_element_containers_are_not_marked()
    {
        $template = '<div><p>@if($x)</p><span>@endif</span></div>';

        $this->assertSame($template, HtmlInstrumentation::make()->comments('probe')->instrumentBlade($template));
    }

    public function test_directives_in_tag_markup_are_never_marked()
    {
        $instrumented = HtmlInstrumentation::make()->comments('probe')->instrumentBlade(
            '<div @if($x) class="a" @endif>{{ $y }}</div>'
        );

        // A marker between `<div` and `>` would corrupt the opening tag.
        $this->assertStringContainsString('<div @if($x) class="a" @endif>', $instrumented);
        $this->assertSame(1, substr_count($instrumented, '<!-- probe:start'));
    }

    public function test_directive_pairs_in_unsafe_html_contexts_are_skipped()
    {
        $unsafe = [
            'raw text' => '<script>@if($x)var a = 1;@endif</script>',
            'rcdata' => '<title>@if($x)a@endif</title>',
            'attribute value' => '<div class="@if($x)a@endif">b</div>',
            'table foster parenting' => '<table>@foreach($a as $b)<tr><td>c</td></tr>@endforeach</table>',
            'component interior' => '<x-card>@if($x)<p>y</p>@endif</x-card>',
        ];

        foreach ($unsafe as $label => $template) {
            $this->assertStringNotContainsString(
                'probe:start',
                HtmlInstrumentation::make()->comments('probe')->instrumentBlade($template),
                'Unsafe position received a marker: '.$label
            );
        }
    }

    public function test_directives_whose_contents_are_not_html_are_left_alone()
    {
        $untouched = [
            'verbatim' => '<div>@verbatim{{ $literal }}@endverbatim</div>',
            'php' => '<div>@php $x = 1; @endphp</div>',
        ];

        foreach ($untouched as $label => $template) {
            $this->assertSame(
                $template,
                HtmlInstrumentation::make()->comments('probe')->instrumentBlade($template),
                'Directive was instrumented: '.$label
            );
        }
    }

    public function test_an_unclosed_directive_block_is_not_marked()
    {
        // Without a closing directive there is no second boundary, and the
        // block's own extent runs to wherever the parse recovered.
        $instrumented = HtmlInstrumentation::make()->comments('probe')->instrumentBlade(
            '<div>@if($x)<p>{{ $a }}</p></div>'
        );

        $this->assertSame(1, substr_count($instrumented, '<!-- probe:start'));
        $this->assertStringContainsString('{{ $a }}<!-- probe:end 1 --></p></div>', $instrumented);
    }

    public function test_instrumenting_blade_without_forte_throws_with_install_guidance()
    {
        if (TemplateAnalyzer::isAvailable()) {
            $this->markTestSkipped('fortephp/forte is installed; the missing-dependency path cannot be exercised.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('composer require fortephp/forte');

        HtmlInstrumentation::make()->instrumentBlade('<p>{{ $title }}</p>');
    }
}
