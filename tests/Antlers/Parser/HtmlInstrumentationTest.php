<?php

namespace Tests\Antlers\Parser;

use Statamic\Contracts\View\Antlers\Parser as ParserContract;
use Statamic\Facades\Antlers;
use Statamic\View\Antlers\Language\Parser\DocumentParser;
use Statamic\View\Antlers\Language\Runtime\RuntimeConfiguration;
use Statamic\View\Instrumentation\HtmlInstrumentation;
use Tests\Antlers\ParserTestCase;

class HtmlInstrumentationTest extends ParserTestCase
{
    public function test_comments_instrument_single_nodes_in_normal_element_content()
    {
        $metadata = [
            'type' => 'single',
            'engine' => 'antlers',
            'expression' => 'title',
            'line' => 1,
            'start' => 3,
            'end' => 14,
            'context' => 'element-content',
            'element' => 'p',
            'view' => 'pages.home',
            'id' => 1,
        ];
        $encoded = base64_encode(json_encode($metadata));

        $instrumented = HtmlInstrumentation::make()
            ->comments('debug')
            ->withMetadata(['view' => 'pages.home'])
            ->instrument('<p>{{ title }}</p>');

        $this->assertSame(
            '<p><!-- debug:start '.$encoded.' -->{{ title }}<!-- debug:end 1 --></p>',
            $instrumented
        );
        $this->assertSame($metadata, HtmlInstrumentation::decode($instrumented));
    }

    public function test_comments_wrap_the_output_region_of_antlers_pairs()
    {
        $instrumented = $this->plainCommentInstrumenter()->instrument('<p>{{ if show }}{{ title }}{{ /if }}</p>');

        $this->assertSame(
            '<p><!-- start:pair:if show -->{{ if show }}<!-- start:single:title -->{{ title }}<!-- end:single:title -->{{ /if }}<!-- end:pair:if show --></p>',
            $instrumented
        );

        $this->assertStringContainsString('Title', $this->renderString($instrumented, ['show' => true, 'title' => 'Title']));
    }

    public function test_pair_markers_remain_balanced_across_if_else_branches()
    {
        $instrumented = $this->plainCommentInstrumenter()->instrument(
            '<div>{{ if primary }}One{{ elseif secondary }}Two{{ else }}Three{{ /if }}</div>'
        );

        foreach ([[true, false], [false, true], [false, false]] as [$primary, $secondary]) {
            $rendered = $this->renderString($instrumented, compact('primary', 'secondary'));

            $this->assertSame(1, substr_count($rendered, '<!-- start:pair:if primary -->'));
            $this->assertSame(1, substr_count($rendered, '<!-- end:pair:if primary -->'));
        }
    }

    public function test_pairs_crossing_element_containers_are_not_given_dom_boundaries()
    {
        $template = '<section>{{ if show }}<div>{{ /if }}</section>';

        $this->assertSame($template, $this->plainCommentInstrumenter()->instrument($template));

        $sameNamedSiblings = '<div>{{ if show }}</div><div>{{ /if }}</div>';

        $this->assertSame($sameNamedSiblings, $this->plainCommentInstrumenter()->instrument($sameNamedSiblings));
    }

    public function test_dynamic_element_ancestry_blocks_comments_and_attribute_layers()
    {
        $instrumented = $this->plainCommentInstrumenter()
            ->attributes()
            ->instrument('<x-{{ kind }} class="{{ classes }}"><span data-id="{{ id }}">{{ content }}</span></x-{{ kind }}><p>{{ after }}</p>');

        $this->assertSame(1, substr_count($instrumented, '<!-- start:single:'));
        $this->assertStringContainsString(
            '<p data-antlers="',
            $instrumented
        );
        $this->assertSame(1, substr_count($instrumented, 'data-antlers='));
        $this->assertStringNotContainsString('data-antlers=', substr($instrumented, 0, strpos($instrumented, '<p')));
    }

    public function test_escaped_antlers_inside_an_element_name_is_not_rewritten_using_a_static_prefix()
    {
        $instrumented = $this->plainCommentInstrumenter()
            ->attributes()
            ->instrument('<di@{{}}v class="{{ classes }}">{{ content }}</di@{{}}v><p>{{ after }}</p>');

        $paragraphOffset = strpos($instrumented, '<p');

        $this->assertNotFalse($paragraphOffset);
        $this->assertStringStartsWith('<di@{{}}v class="{{ classes }}">{{ content }}</di@{{}}v>', $instrumented);
        $this->assertStringNotContainsString('data-antlers=', substr($instrumented, 0, $paragraphOffset));
        $this->assertSame(1, substr_count($instrumented, '<!-- start:single:'));
        $this->assertSame(1, substr_count($instrumented, 'data-antlers='));
        $this->assertStringContainsString('<p data-antlers="', $instrumented);
    }

    public function test_multibyte_static_element_names_are_rewritten_after_the_complete_name()
    {
        $instrumented = HtmlInstrumentation::make()
            ->withoutComments()
            ->attributes()
            ->instrument('<café class="{{ classes }}">{{ content }}</café>');

        $this->assertStringStartsWith('<café data-antlers="', $instrumented);
        $this->assertStringNotContainsString('<caf data-antlers=', $instrumented);
    }

    public function test_noparse_html_controls_marker_safety_but_is_not_itself_instrumented()
    {
        $instrumented = $this->plainCommentInstrumenter()->instrument(
            '@{{ escaped }}{{ noparse }}<title>literal{{ /noparse }}{{ real }}</title><p>{{ after }}</p>'
        );

        $this->assertSame(1, substr_count($instrumented, '<!-- start:single:'));
        $this->assertStringContainsString('{{ real }}</title>', $instrumented);
        $this->assertStringContainsString(
            '<p><!-- start:single:after -->{{ after }}<!-- end:single:after --></p>',
            $instrumented
        );
        $this->assertStringNotContainsString('single:noparse', $instrumented);
        $this->assertStringStartsWith('@{{ escaped }}{{ noparse }}', $instrumented);
    }

    public function test_attribute_mode_uses_static_elements_when_comments_are_unsafe()
    {
        $instrumented = HtmlInstrumentation::make()
            ->withoutComments()
            ->jsonAttribute('data-debug')
            ->instrument('<title>{{ title }}</title><pre><code>{{ code }}</code></pre>');

        $this->assertStringStartsWith('<title data-debug="', $instrumented);
        $this->assertStringContainsString('<code data-debug="', $instrumented);
        $this->assertSame(2, substr_count($instrumented, 'data-debug='));
        $this->assertStringNotContainsString('<!-- antlers:', $instrumented);
    }

    public function test_adjacent_single_nodes_close_before_the_next_marker_opens()
    {
        $instrumented = $this->plainCommentInstrumenter()->instrument('<p>{{ first }}{{ second }}</p>');

        $this->assertSame(
            '<p><!-- start:single:first -->{{ first }}<!-- end:single:first --><!-- start:single:second -->{{ second }}<!-- end:single:second --></p>',
            $instrumented
        );
    }

    public function test_attribute_layers_collect_eligible_nodes_on_the_owning_opening_element()
    {
        $instrumented = HtmlInstrumentation::make()
            ->withoutComments()
            ->attribute('data-antlers', function ($markers) {
                return implode('|', array_column($markers, 'expression'));
            })
            ->attribute('data-antlers-contexts', function ($markers) {
                return implode(',', array_column($markers, 'context'));
            })
            ->instrument('Hello <a href="{{ url }}" {{ attributes }}>{{ label }}</a>');

        $this->assertSame(
            'Hello <a data-antlers="url|attributes|label" data-antlers-contexts="attribute-value,tag-open,element-content" href="{{ url }}" {{ attributes }}>{{ label }}</a>',
            $instrumented
        );
    }

    public function test_attribute_layers_never_stamp_closing_tags_or_dynamic_element_names()
    {
        $instrumented = HtmlInstrumentation::make()
            ->withoutComments()
            ->attributes()
            ->instrument('<{{ element }}>{{ value }}</{{ element }}><div {{ attributes }}></div {{ closing }}>');

        $this->assertSame(1, substr_count($instrumented, 'data-antlers='));
        $this->assertStringContainsString('<div data-antlers=', $instrumented);
        $this->assertStringNotContainsString('<{{ element }} data-antlers=', $instrumented);
        $this->assertStringNotContainsString('</div data-antlers=', $instrumented);
    }

    public function test_unsafe_comment_contexts_are_left_unmodified()
    {
        $instrumented = HtmlInstrumentation::make()->instrument(
            '<title>{{ title }}</title><pre>{{ code }}</pre><script>{{ script }}</script><p>{{ body }}</p>'
        );

        $this->assertSame(1, substr_count($instrumented, '<!-- antlers:start '));
        $this->assertStringContainsString('<title>{{ title }}</title>', $instrumented);
        $this->assertStringContainsString('<pre>{{ code }}</pre>', $instrumented);
        $this->assertStringContainsString('<script>{{ script }}</script>', $instrumented);
    }

    public function test_table_foster_parenting_uses_attributes_instead_of_comment_boundaries()
    {
        $instrumented = HtmlInstrumentation::make()
            ->attributes('data-antlers')
            ->instrument('<div><table>{{ direct }}<tr><td>{{ cell }}</td></tr></table></div>');
        $tableStart = strpos($instrumented, '<table');
        $rowStart = strpos($instrumented, '<tr');

        $this->assertSame(1, substr_count($instrumented, '<!-- antlers:start '));
        $this->assertStringNotContainsString(
            '<!-- antlers:start ',
            substr($instrumented, $tableStart, $rowStart - $tableStart)
        );
        $this->assertStringContainsString('<table data-antlers="', $instrumented);
    }

    public function test_recovered_table_boundaries_do_not_receive_comment_markers()
    {
        $instrumented = HtmlInstrumentation::make()
            ->attributes('data-antlers')
            ->matching(['after'])
            ->instrument(
                '<div><table><tbody><tr><td>{{ cell }}<caption>{{ caption }}</caption>{{ after }}</td></table></div>'
            );

        $this->assertStringContainsString('<table data-antlers="', $instrumented);
        $this->assertStringNotContainsString('<!-- antlers:start ', $instrumented);
    }

    public function test_pending_formatting_reconstruction_uses_an_element_attribute()
    {
        $instrumented = HtmlInstrumentation::make()
            ->attributes('data-antlers')
            ->matching(['inside'])
            ->instrument('<p><b>before<div>{{ inside }}</b>after</div>');

        $this->assertStringContainsString('<div data-antlers="', $instrumented);
        $this->assertStringNotContainsString('<!-- antlers:start ', $instrumented);
    }

    public function test_existing_attribute_layer_is_not_duplicated()
    {
        $instrumented = HtmlInstrumentation::make()
            ->withoutComments()
            ->attributes('data-antlers')
            ->instrument('<div data-antlers="app-owned" class="{{ classes }}">Content</div>');

        $this->assertSame(1, substr_count($instrumented, 'data-antlers='));
    }

    public function test_existing_attribute_after_a_stray_slash_is_not_duplicated()
    {
        $instrumented = HtmlInstrumentation::make()
            ->withoutComments()
            ->attributes('data-antlers')
            ->instrument('<div/data-antlers="app-owned" class="{{ classes }}">Content</div>');

        $this->assertSame(1, substr_count($instrumented, 'data-antlers='));
    }

    public function test_attribute_name_inside_an_unquoted_value_does_not_block_instrumentation()
    {
        $instrumented = HtmlInstrumentation::make()
            ->withoutComments()
            ->attributes('data-antlers')
            ->instrument('<div title=x/data-antlers=app class="{{ classes }}">Content</div>');

        $this->assertStringStartsWith('<div data-antlers="', $instrumented);
        $this->assertSame(2, substr_count($instrumented, 'data-antlers='));
    }

    public function test_existing_attribute_is_detected_after_a_dynamic_tag_expression()
    {
        $instrumented = HtmlInstrumentation::make()
            ->withoutComments()
            ->attributes('data-antlers')
            ->instrument('<div {{ if count > 1 }}class="many"{{ /if }} data-antlers="app-owned">Content</div>');

        $this->assertSame(1, substr_count($instrumented, 'data-antlers='));
    }

    public function test_existing_attribute_is_detected_after_a_quoted_dynamic_expression()
    {
        $instrumented = HtmlInstrumentation::make()
            ->withoutComments()
            ->attributes('data-antlers')
            ->instrument('<div title="{{ value ? "many > one" : "one" }}" data-antlers="app-owned" class="{{ classes }}">Content</div>');

        $this->assertSame(1, substr_count($instrumented, 'data-antlers='));
    }

    public function test_regions_ending_inside_quoted_strings_shrink_the_recovered_tag_boundary()
    {
        // The region ends at the `}}` inside the string, so the recovered
        // opening tag stops at the `>` that follows it and the app-owned
        // attribute lands outside the tag. Instrumentation adds its own
        // attribute to the recovered tag; the result must still reparse and
        // round trip.
        $template = '<div title="{{ value == "}}" ? "many > one" : "one" }}" data-antlers="app-owned" class="{{ classes }}">Content</div>';

        $instrumented = HtmlInstrumentation::make()
            ->withoutComments()
            ->attributes('data-antlers')
            ->instrument($template);

        $this->assertSame(2, substr_count($instrumented, 'data-antlers='));

        $parser = new DocumentParser();
        $parser->parse($instrumented);

        $this->assertSame($instrumented, $parser->html()->markDirty()->toHtml());
    }

    public function test_attribute_name_text_inside_another_value_does_not_block_instrumentation()
    {
        $instrumented = HtmlInstrumentation::make()
            ->withoutComments()
            ->attributes('data-antlers')
            ->instrument('<div title="mentions data-antlers= here" class="{{ classes }}">Content</div>');

        $this->assertStringStartsWith('<div data-antlers="', $instrumented);
    }

    public function test_instrumenter_can_be_registered_as_an_antlers_preparser()
    {
        $configuration = new RuntimeConfiguration;
        $configuration->preparse(HtmlInstrumentation::make()->comments('debug'));

        $parser = $this->parser();
        $parser->setRuntimeConfiguration($configuration);
        $rendered = (string) $parser->parse('<p>{{ title }}</p>', ['title' => 'Title']);

        $this->assertStringContainsString('<!-- debug:start ', $rendered);
        $this->assertStringContainsString('Title', $rendered);
        $this->assertStringContainsString('<!-- debug:end 1 -->', $rendered);
    }

    public function test_direct_sources_allow_root_comments_but_registered_views_do_not()
    {
        $instrumenter = HtmlInstrumentation::make()->comments('debug');

        $this->assertStringContainsString(
            '<!-- debug:start ',
            $instrumenter->instrumentAntlers('{{ title }}')
        );

        $configuration = new RuntimeConfiguration;
        $configuration->preparse($instrumenter);

        $parser = $this->parser();
        $parser->setRuntimeConfiguration($configuration);

        $this->assertSame('Title', (string) $parser->parse('{{ title }}', ['title' => 'Title']));
        $this->assertStringContainsString(
            '<!-- debug:start ',
            (string) $parser->parse('<p>{{ title }}</p>', ['title' => 'Title'])
        );
    }

    public function test_config_factory_controls_the_default_comment_and_attribute_layers()
    {
        $metadata = [
            [
                'type' => 'single',
                'engine' => 'antlers',
                'expression' => 'url',
                'line' => 1,
                'start' => 9,
                'end' => 18,
                'context' => 'attribute-value',
                'element' => 'a',
                'attribute' => 'href',
                'template' => 'pages.home',
                'id' => 1,
            ],
            [
                'type' => 'single',
                'engine' => 'antlers',
                'expression' => 'label',
                'line' => 1,
                'start' => 20,
                'end' => 31,
                'context' => 'element-content',
                'element' => 'a',
                'template' => 'pages.home',
                'id' => 2,
            ],
        ];
        $encoded = base64_encode(json_encode($metadata));

        $instrumented = HtmlInstrumentation::fromConfig([
            'comments' => false,
            'attributes' => ['data-antlers'],
            'metadata' => ['template' => 'pages.home'],
        ])->instrument('<a href="{{ url }}">{{ label }}</a>');

        $this->assertSame(
            '<a data-antlers="'.$encoded.'" href="{{ url }}">{{ label }}</a>',
            $instrumented
        );
    }

    public function test_json_attribute_layers_can_be_used_without_comment_markers()
    {
        $metadata = [[
            'type' => 'single',
            'engine' => 'antlers',
            'expression' => 'url',
            'line' => 1,
            'start' => 9,
            'end' => 18,
            'context' => 'attribute-value',
            'element' => 'a',
            'attribute' => 'href',
            'id' => 1,
        ]];
        $json = htmlspecialchars(
            json_encode($metadata, JSON_HEX_APOS | JSON_HEX_QUOT),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        $instrumented = HtmlInstrumentation::make()
            ->withoutComments()
            ->jsonAttribute('data-antlers')
            ->instrument('<a href="{{ url }}">Docs</a>');

        $this->assertSame('<a data-antlers="'.$json.'" href="{{ url }}">Docs</a>', $instrumented);
    }

    public function test_antlers_facade_exposes_the_instrumentation_builder()
    {
        $instrumented = Antlers::instrument(
            '<p>{{ title }}</p>',
            HtmlInstrumentation::make()->comments('debug')
        );

        $this->assertStringContainsString('<!-- debug:start ', $instrumented);
    }

    public function test_antlers_has_an_explicit_direct_instrumentation_method_matching_blade()
    {
        $instrumenter = HtmlInstrumentation::make()->withoutComments()->attributes('data-template');
        $template = '<p>{{ title }}</p>';

        $this->assertSame(
            $instrumenter->instrument($template),
            $instrumenter->instrumentAntlers($template)
        );
        $this->assertStringStartsWith('<p data-template="', $instrumenter->instrumentAntlers($template));
    }

    public function test_prefix_and_matching_patterns_control_which_nodes_are_instrumented()
    {
        $instrumented = HtmlInstrumentation::make()
            ->prefix('trace')
            ->matching(['title', 'partial:*'])
            ->instrument('<p>{{ title }} {{ body }} {{ partial:nav }}</p>');

        $this->assertSame(2, substr_count($instrumented, '<!-- trace:start '));
        $this->assertStringContainsString('<!-- trace:start ', $instrumented);
        $this->assertStringContainsString('{{ body }}', $instrumented);

        $filtered = HtmlInstrumentation::make()
            ->attributes()
            ->filter(fn ($metadata, $region) => $region->context()->kind === 'attribute-value')
            ->instrument('<a href="{{ url }}">{{ label }}</a>');

        $this->assertStringNotContainsString('<!-- antlers:start ', $filtered);
        $this->assertStringStartsWith('<a data-antlers="', $filtered);
    }

    public function test_comments_sets_the_prefix_and_reenables_comment_markers()
    {
        $instrumenter = HtmlInstrumentation::make()->withoutComments();
        $template = '<p>{{ title }}</p>';

        $this->assertSame($template, $instrumenter->instrument($template));

        $instrumented = $instrumenter->comments('template')->instrument($template);

        $this->assertStringContainsString('<!-- template:start ', $instrumented);
        $this->assertStringContainsString('<!-- template:end 1 -->', $instrumented);
    }

    public function test_invalid_comment_prefixes_are_rejected_before_they_can_break_html()
    {
        $this->expectException(\InvalidArgumentException::class);

        HtmlInstrumentation::make()->comments('debug -->');
    }

    public function test_comment_prefixes_cannot_contain_invalid_double_hyphens()
    {
        $this->expectException(\InvalidArgumentException::class);

        HtmlInstrumentation::make()->comments('debug--trace');
    }

    public function test_invalid_attribute_names_are_rejected_before_they_can_break_html()
    {
        $this->expectException(\InvalidArgumentException::class);

        HtmlInstrumentation::make()->attributes('data-bad name');
    }

    public function test_crlf_templates_preserve_marker_positions_and_source_offsets()
    {
        $template = "<p>{{ first }}</p>\r\n<p>{{ second }}</p>";
        $instrumented = HtmlInstrumentation::make()->instrument($template);

        $this->assertStringContainsString("</p>\r\n<p><!-- antlers:start ", $instrumented);
        $this->assertStringContainsString('{{ second }}<!-- antlers:end 2 --></p>', $instrumented);

        preg_match_all('/antlers:start ([A-Za-z0-9+\/=]+) -->/', $instrumented, $matches);
        $second = HtmlInstrumentation::decode($matches[1][1]);

        $this->assertSame(23, $second['start']);
        $this->assertSame(35, $second['end']);
    }

    public function test_multiline_templates_instrument_dollar_variables_in_element_content()
    {
        $template = <<<'ANTLERS'
<a
    href="{{ $url }}"
    class="transition-all duration-75 ease-in-out h-full block relative top-0 hover:-top-2 shadow-lg hover:shadow-xl bg-white rounded-xl overflow-hidden"
>
    <img
        class="{{ $oversized ? 'hidden md:block' : 'hidden' }} squiggle"
        height="285"
        src="{{ glide:hero_image height="570" width="1472" fit="crop_focal" }}" alt="{{ $hero_image:alt }}">
    <img class="squiggle {{ $oversized ?= 'md:hidden' }}" height="285" src="{{ glide:hero_image height="570" width="736" fit="crop_focal" }}" alt="{{ $hero_image:alt }}">
    <div class="py-6 px-8">
        <h2 class="font-bold text-2xl leading-tight">
            {{ $title }}
        </h2>
        <p class="text-xs text-gray-600 mt-4 flex items-center">
            {{ $date }}
        </p>
    </div>
</a>
ANTLERS;

        $instrumented = $this->plainCommentInstrumenter()->instrument($template);

        $this->assertStringContainsString('<!-- start:single:$title -->{{ $title }}<!-- end:single:$title -->', $instrumented);
        $this->assertStringContainsString('<!-- start:single:$date -->{{ $date }}<!-- end:single:$date -->', $instrumented);
        $this->assertSame(2, substr_count($instrumented, '<!-- start:single:'));
    }

    public function test_enabled_config_instruments_dollar_variables_during_rendering()
    {
        config(['statamic.antlers.instrumentation' => [
            'enabled' => true,
            'comments' => true,
            'prefix' => 'debug',
            'attributes' => [],
            'metadata' => [],
        ]]);

        $metadata = [
            'type' => 'single',
            'engine' => 'antlers',
            'expression' => '$date',
            'line' => 1,
            'start' => 3,
            'end' => 14,
            'context' => 'element-content',
            'element' => 'p',
            'id' => 1,
        ];
        $marker = '<!-- debug:start '.base64_encode(json_encode($metadata)).' -->';

        $rendered = (string) app(ParserContract::class)->parse('<p>{{ $date }}</p>', ['date' => 'July 10']);

        $this->assertSame('<p>'.$marker.'July 10<!-- debug:end 1 --></p>', $rendered);
    }

    public function test_config_built_instrumenters_share_a_process_wide_cache()
    {
        HtmlInstrumentation::flushSharedResultCache();

        $config = ['comments' => true, 'prefix' => 'antlers', 'attributes' => [], 'metadata' => []];
        $template = '<p>{{ title }}</p>';

        $first = HtmlInstrumentation::fromConfig($config);
        $result = $first->instrument($template);

        $shared = new \ReflectionProperty(HtmlInstrumentation::class, 'sharedResultCache');
        $shared->setAccessible(true);
        $buckets = $shared->getValue();

        $this->assertCount(1, $buckets);
        $this->assertCount(1, current($buckets));

        // A fresh instance with the same config (a new request on the same
        // worker) reuses the shared result rather than adding an entry.
        $second = HtmlInstrumentation::fromConfig($config);

        $this->assertSame($result, $second->instrument($template));
        $this->assertCount(1, current($shared->getValue()));

        // Mutating a config-built instance detaches it from the shared
        // bucket, since the bucket no longer describes its configuration.
        $second->prefix('debug');

        $this->assertSame([], $shared->getValue());
        $this->assertStringContainsString('<!-- debug:start ', $second->instrument($template));

        HtmlInstrumentation::flushSharedResultCache();
    }

    public function test_repeat_instrumentation_is_memoized_per_view_and_template()
    {
        $instrumenter = HtmlInstrumentation::make()->attributes('data-antlers');
        $template = '<p>{{ title }}</p>';

        $first = $instrumenter->instrument($template);
        $second = $instrumenter->instrument($template);

        $this->assertSame($first, $second);

        // The view participates in metadata, so it must partition the cache.
        $previous = \Statamic\View\Antlers\Language\Runtime\GlobalRuntimeState::$currentExecutionFile;
        \Statamic\View\Antlers\Language\Runtime\GlobalRuntimeState::$currentExecutionFile = 'partials/card.antlers.html';

        try {
            $viewScoped = $instrumenter->instrument($template);
        } finally {
            \Statamic\View\Antlers\Language\Runtime\GlobalRuntimeState::$currentExecutionFile = $previous;
        }

        $this->assertNotSame($first, $viewScoped);
        $this->assertSame('partials/card.antlers.html', HtmlInstrumentation::decode($viewScoped)['view']);

        // Reconfiguring flushes memoized output.
        $instrumenter->prefix('debug');
        $reconfigured = $instrumenter->instrument($template);

        $this->assertStringContainsString('<!-- debug:start ', $reconfigured);
        $this->assertNotSame($first, $reconfigured);
    }

    public function test_templates_without_antlers_are_returned_untouched()
    {
        $template = '<section class="a"><h2>Static</h2><p>No regions here, including escaped @braces.</p></section>';

        $instrumented = HtmlInstrumentation::make()
            ->attributes('data-antlers')
            ->instrument($template);

        $this->assertSame($template, $instrumented);
    }

    public function test_marker_ids_correlate_pairs_and_attribute_payloads()
    {
        $instrumented = HtmlInstrumentation::make()
            ->attributes('data-antlers')
            ->instrument('<p>{{ if a }}x{{ /if }}{{ title }}</p><div class="{{ classes }}">y</div>');

        // Two comment pairs, each end marker carrying its node's id.
        $this->assertSame(1, substr_count($instrumented, '<!-- antlers:end 1 -->'));
        $this->assertSame(1, substr_count($instrumented, '<!-- antlers:end 2 -->'));

        // Attribute layers share the same id sequence: the containing <p>
        // collects the two content nodes, the <div> its attribute node.
        preg_match_all('/data-antlers="([^"]+)"/', $instrumented, $matches);
        $paragraphPayload = HtmlInstrumentation::decode($matches[1][0]);
        $divPayload = HtmlInstrumentation::decode($matches[1][1]);

        $this->assertSame([1, 2], array_column($paragraphPayload, 'id'));
        $this->assertSame(3, $divPayload[0]['id']);
        $this->assertSame('classes', $divPayload[0]['expression']);
    }

    public function test_the_active_view_is_recorded_in_marker_metadata()
    {
        $previous = \Statamic\View\Antlers\Language\Runtime\GlobalRuntimeState::$currentExecutionFile;
        \Statamic\View\Antlers\Language\Runtime\GlobalRuntimeState::$currentExecutionFile = 'partials/card.antlers.html';

        try {
            $instrumented = HtmlInstrumentation::make()->instrument('<p>{{ title }}</p>');
        } finally {
            \Statamic\View\Antlers\Language\Runtime\GlobalRuntimeState::$currentExecutionFile = $previous;
        }

        $this->assertSame('partials/card.antlers.html', HtmlInstrumentation::decode($instrumented)['view']);

        // withMetadata() can override the recorded value.
        \Statamic\View\Antlers\Language\Runtime\GlobalRuntimeState::$currentExecutionFile = 'partials/card.antlers.html';

        try {
            $instrumented = HtmlInstrumentation::make()
                ->withMetadata(['view' => 'cards.hero'])
                ->instrument('<p>{{ title }}</p>');
        } finally {
            \Statamic\View\Antlers\Language\Runtime\GlobalRuntimeState::$currentExecutionFile = $previous;
        }

        $this->assertSame('cards.hero', HtmlInstrumentation::decode($instrumented)['view']);
    }

    public function test_marker_metadata_never_exposes_the_absolute_application_path()
    {
        $previous = \Statamic\View\Antlers\Language\Runtime\GlobalRuntimeState::$currentExecutionFile;
        \Statamic\View\Antlers\Language\Runtime\GlobalRuntimeState::$currentExecutionFile = base_path('resources/views/card.antlers.html');

        try {
            $instrumented = HtmlInstrumentation::make()->instrument('<p>{{ title }}</p>');
        } finally {
            \Statamic\View\Antlers\Language\Runtime\GlobalRuntimeState::$currentExecutionFile = $previous;
        }

        $metadata = HtmlInstrumentation::decode($instrumented);

        $this->assertSame('resources/views/card.antlers.html', $metadata['view']);
        $this->assertStringNotContainsString(str_replace('\\', '/', base_path()), $metadata['view']);
    }

    public function test_custom_element_markers_are_refused_everywhere_comments_are_unsafe()
    {
        $wrapper = HtmlInstrumentation::make()->markersUsing(function ($metadata) {
            return ['<span data-probe="'.$metadata['expression'].'">', '</span>'];
        });

        $unsafe = [
            'attribute value' => '<div class="{{ x }}">y</div>',
            'tag open' => '<div {{ attrs }}>y</div>',
            'raw text' => '<script>var a = "{{ x }}";</script>',
            'rcdata' => '<title>{{ x }}</title>',
            'whitespace sensitive' => '<pre>{{ x }}</pre>',
            'table foster parenting' => '<table>{{ x }}<tr><td>y</td></tr></table>',
            'html comment' => '<!-- {{ x }} -->',
            'dynamic ancestor' => '<{{ tag }}><b>{{ x }}</b></{{ tag }}>',
            'pair crossing containers' => '<section>{{ if a }}<div>{{ /if }}</section>',
            'pair inside foster context' => '<table>{{ if a }}<tr><td>y</td></tr>{{ /if }}</table>',
        ];

        foreach ($unsafe as $label => $template) {
            $this->assertSame(
                $template,
                $wrapper->instrument($template),
                'Unsafe position received a wrapper: '.$label
            );
        }

        // The same wrapper is applied in safe element content.
        $this->assertSame(
            '<p><span data-probe="if a">{{ if a }}x{{ /if }}</span><span data-probe="title">{{ title }}</span></p>',
            $wrapper->instrument('<p>{{ if a }}x{{ /if }}{{ title }}</p>')
        );
    }

    public function test_marker_ids_are_contiguous_when_regions_are_skipped()
    {
        // The attribute value and the script body are both unsafe for
        // comments, so neither should consume an id.
        $instrumented = HtmlInstrumentation::make()->comments('probe')->instrument(
            '<div class="{{ a }}"><script>{{ b }}</script>{{ c }}<p>{{ d }}</p></div>'
        );

        $this->assertStringContainsString('{{ c }}<!-- probe:end 1 -->', $instrumented);
        $this->assertStringContainsString('{{ d }}<!-- probe:end 2 -->', $instrumented);
    }

    public function test_memoized_results_are_evicted_rather_than_dropped_wholesale()
    {
        $instrumentation = HtmlInstrumentation::make()->comments('probe');
        $limit = 256;

        for ($index = 0; $index <= $limit; $index++) {
            $instrumentation->instrument("<p>{{ value_$index }}</p>");
        }

        $cache = new \ReflectionProperty(HtmlInstrumentation::class, 'resultCache');
        $cache->setAccessible(true);

        // Half the bucket is discarded at the limit, so most of the recent
        // working set survives instead of the cache resetting to empty.
        $this->assertGreaterThan($limit / 2, count($cache->getValue($instrumentation)));
    }

    public function test_component_prefixes_are_configurable()
    {
        $template = '<flux-card><p>{{ title }}</p></flux-card>';

        // Third party prefixes are not built in, so this is ordinary markup.
        $this->assertStringContainsString(
            '<!-- probe:start',
            HtmlInstrumentation::make()->comments('probe')->instrument($template)
        );

        $configured = HtmlInstrumentation::make()->comments('probe')->componentPrefixes(['flux-']);

        $this->assertSame($template, $configured->instrument($template));

        // Configured prefixes are added to the built-in ones, not swapped in.
        $this->assertStringNotContainsString(
            'probe:start',
            $configured->instrument('<x-card><p>{{ title }}</p></x-card>')
        );
    }

    private function plainCommentInstrumenter()
    {
        return HtmlInstrumentation::make()->markersUsing(function ($metadata) {
            $label = $metadata['type'].':'.$metadata['expression'];

            return ['<!-- start:'.$label.' -->', '<!-- end:'.$label.' -->'];
        });
    }
}
