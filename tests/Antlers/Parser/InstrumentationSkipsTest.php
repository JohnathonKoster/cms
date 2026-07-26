<?php

namespace Tests\Antlers\Parser;

use Statamic\View\Instrumentation\HtmlContext;
use Statamic\View\Instrumentation\HtmlInstrumentation;
use Statamic\View\Instrumentation\TemplateRegion;
use Tests\Antlers\ParserTestCase;

/**
 * Coverage observability: every node that produces no output at all is
 * reported through onSkip() with an accurate reason, and nodes that produce
 * any output are never reported.
 */
class InstrumentationSkipsTest extends ParserTestCase
{
    public function test_nodes_inside_html_comments_are_reported()
    {
        $skips = $this->skipsFor('<!-- {{ x }} --><p>{{ safe }}</p>');

        $this->assertSame(['x' => HtmlInstrumentation::SKIP_INSIDE_HTML_COMMENT], $skips);
    }

    public function test_nodes_inside_doctypes_are_reported()
    {
        $skips = $this->skipsFor('<!doctype {{ x }}><p>{{ safe }}</p>');

        $this->assertSame(['x' => HtmlInstrumentation::SKIP_INSIDE_DOCTYPE], $skips);
    }

    public function test_dynamic_markup_is_reported_for_names_and_descendants()
    {
        $skips = $this->skipsFor('<{{ tag }}><b>{{ inner }}</b></{{ tag }}><p>{{ safe }}</p>');

        $this->assertSame(HtmlInstrumentation::SKIP_DYNAMIC_MARKUP, $skips['tag']);
        $this->assertSame(HtmlInstrumentation::SKIP_DYNAMIC_MARKUP, $skips['inner']);
        $this->assertArrayNotHasKey('safe', $skips);
    }

    public function test_cross_container_pairs_are_reported_when_comments_are_the_only_strategy()
    {
        $skips = $this->skipsFor('<section>{{ if show }}<div>{{ /if }}</section>');

        $this->assertSame(['if show' => HtmlInstrumentation::SKIP_CROSS_CONTAINER_PAIR], $skips);
    }

    public function test_cross_container_pairs_are_not_skips_when_an_attribute_layer_can_hold_them()
    {
        $template = '<section>{{ if show }}<div>{{ /if }}</section>';
        $skips = [];

        $instrumented = HtmlInstrumentation::make()
            ->attributes('data-antlers')
            ->onSkip(function ($metadata, $region, $reason) use (&$skips) {
                $skips[$metadata['expression']] = $reason;
            })
            ->instrument($template);

        // The pair's metadata landed on the containing <section> instead.
        $this->assertSame([], $skips);
        $this->assertStringStartsWith('<section data-antlers="', $instrumented);
    }

    public function test_unsafe_positions_without_attribute_layers_are_reported()
    {
        // Comments-only configuration: raw text and attribute values have no
        // fallback, so they are honest skips.
        $skips = $this->skipsFor('<script>{{ x }}</script><div class="{{ y }}">z</div>');

        $this->assertSame([
            'x' => HtmlInstrumentation::SKIP_NO_STRATEGY,
            'y' => HtmlInstrumentation::SKIP_NO_STRATEGY,
        ], $skips);
    }

    public function test_filtered_nodes_are_reported_as_filtered()
    {
        $skips = [];

        HtmlInstrumentation::make()
            ->matching(['partial:*'])
            ->onSkip(function ($metadata, $region, $reason) use (&$skips) {
                $skips[$metadata['expression']] = $reason;
            })
            ->instrument('<p>{{ title }}{{ partial:cards }}</p>');

        $this->assertSame(['title' => HtmlInstrumentation::SKIP_FILTERED], $skips);
    }

    public function test_the_callback_receives_the_region_alongside_the_reason()
    {
        $received = null;

        HtmlInstrumentation::make()
            ->onSkip(function ($metadata, $region, $reason) use (&$received) {
                $received = [$metadata, $region, $reason];
            })
            ->instrument('<script>{{ x }}</script>');

        [$metadata, $region, $reason] = $received;

        $this->assertSame('x', $metadata['expression']);
        $this->assertInstanceOf(TemplateRegion::class, $region);
        $this->assertSame('x', trim($region->raw()->content));
        $this->assertSame(HtmlContext::KIND_RAW_TEXT, $region->context()->kind);
        $this->assertSame(HtmlInstrumentation::SKIP_NO_STRATEGY, $reason);
    }

    public function test_nodes_that_produce_output_are_never_reported()
    {
        $skips = $this->skipsFor('<p>{{ title }}{{ if a }}b{{ /if }}</p>');

        $this->assertSame([], $skips);
    }

    public function test_nodes_are_reported_when_every_attribute_layer_is_unavailable()
    {
        $skips = [];

        $instrumented = HtmlInstrumentation::make()
            ->withoutComments()
            ->attributes('data-antlers')
            ->onSkip(function ($metadata, $region, $reason) use (&$skips) {
                $skips[$metadata['expression']] = $reason;
            })
            ->instrument('<div data-antlers="mine" class="{{ classes }}">x</div>');

        $this->assertSame('<div data-antlers="mine" class="{{ classes }}">x</div>', $instrumented);
        $this->assertSame(['classes' => HtmlInstrumentation::SKIP_NO_STRATEGY], $skips);
    }

    public function test_nodes_are_reported_when_every_attribute_factory_omits_its_layer()
    {
        $skips = [];

        HtmlInstrumentation::make()
            ->withoutComments()
            ->attribute('data-antlers', fn () => null)
            ->onSkip(function ($metadata, $region, $reason) use (&$skips) {
                $skips[$metadata['expression']] = $reason;
            })
            ->instrument('<div class="{{ classes }}">x</div>');

        $this->assertSame(['classes' => HtmlInstrumentation::SKIP_NO_STRATEGY], $skips);
    }

    public function test_nodes_are_reported_when_a_custom_marker_factory_emits_nothing()
    {
        $skips = [];

        HtmlInstrumentation::make()
            ->markersUsing(fn () => ['', ''])
            ->onSkip(function ($metadata, $region, $reason) use (&$skips) {
                $skips[$metadata['expression']] = $reason;
            })
            ->instrument('<p>{{ title }}</p>');

        $this->assertSame(['title' => HtmlInstrumentation::SKIP_NO_STRATEGY], $skips);
    }

    public function test_skips_are_not_re_reported_for_memoized_results()
    {
        $count = 0;

        $instrumenter = HtmlInstrumentation::make()
            ->onSkip(function () use (&$count) {
                $count++;
            });

        $instrumenter->instrument('<script>{{ x }}</script>');
        $instrumenter->instrument('<script>{{ x }}</script>');

        $this->assertSame(1, $count);
    }

    public function test_instrumentation_without_strategies_is_a_no_op_and_reports_nothing()
    {
        $count = 0;
        $template = '<p>{{ title }}</p>';

        $result = HtmlInstrumentation::make()
            ->withoutComments()
            ->onSkip(function () use (&$count) {
                $count++;
            })
            ->instrument($template);

        $this->assertSame($template, $result);
        $this->assertSame(0, $count);
    }

    /**
     * Runs a comments-only instrumenter and returns expression => reason.
     *
     * @param  string  $template
     * @return array<string, string>
     */
    protected function skipsFor($template)
    {
        $skips = [];

        HtmlInstrumentation::make()
            ->onSkip(function ($metadata, $region, $reason) use (&$skips) {
                $skips[$metadata['expression']] = $reason;
            })
            ->instrument($template);

        return $skips;
    }
}
