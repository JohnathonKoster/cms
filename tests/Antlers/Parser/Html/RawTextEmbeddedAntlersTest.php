<?php

namespace Tests\Antlers\Parser\Html;

use Statamic\View\Antlers\Language\Analyzers\Html\Text;
use Statamic\View\Antlers\Language\Nodes\AntlersNode;
use Statamic\View\Antlers\Language\Parser\DocumentParser;
use Statamic\View\Instrumentation\HtmlContext;
use Statamic\View\Instrumentation\HtmlInstrumentation;
use Tests\Antlers\ParserTestCase;

/**
 * Embedded-Antlers coverage for raw text (script, style, textarea, title)
 * and whitespace-sensitive (pre) elements: the places where injected markup
 * or misread boundaries would corrupt real pages.
 */
class RawTextEmbeddedAntlersTest extends ParserTestCase
{
    public function test_regions_inside_script_and_style_resolve_raw_text_contexts()
    {
        $template = '<script>var t = {{ title }};</script><style>.a { color: {{ color }}; }</style><pre>  {{ code }}</pre>';
        [, $nodes] = $this->parseHtml($template);

        [$title, $color, $code] = $nodes;

        $this->assertSame(HtmlContext::KIND_RAW_TEXT, $title->htmlContext()->kind);
        $this->assertSame('script', $title->htmlContext()->elementName);

        $this->assertSame(HtmlContext::KIND_RAW_TEXT, $color->htmlContext()->kind);
        $this->assertSame('style', $color->htmlContext()->elementName);

        // pre is whitespace-sensitive, not raw text: content is real markup.
        $this->assertSame(HtmlContext::KIND_ELEMENT_CONTENT, $code->htmlContext()->kind);
        $this->assertSame('pre', $code->htmlContext()->elementName);

        foreach ([$title, $color, $code] as $node) {
            $this->assertFalse($node->htmlContext()->isSafeForHtmlComments());
            $this->assertTrue($node->htmlContext()->canInstrumentContainingElement());
        }
    }

    public function test_a_region_containing_a_script_closer_does_not_end_the_element()
    {
        // The parser's region boundaries make expression content opaque: a
        // literal </script> inside the expression never reaches the HTML
        // state machine.
        $template = '<script>var a = {{ x ?? \'</script>\' }};</script><p>{{ after }}</p>';
        [$parser, $nodes] = $this->parseHtml($template);

        $this->assertSame(HtmlContext::KIND_RAW_TEXT, $nodes[0]->htmlContext()->kind);
        $this->assertSame('script', $nodes[0]->htmlContext()->elementName);
        $this->assertSame(HtmlContext::KIND_ELEMENT_CONTENT, $nodes[1]->htmlContext()->kind);
        $this->assertSame('p', $nodes[1]->htmlContext()->elementName);

        $script = $parser->html()->first('script');
        $this->assertSame('</script>', $script->closingTag());
        $this->assertSame($template, $parser->html()->markDirty()->toHtml());
    }

    public function test_a_literal_style_closer_inside_a_css_string_ends_the_element_like_a_browser()
    {
        // Raw text ends at the first closing sequence regardless of CSS
        // string syntax — browsers behave the same way.
        $template = '<style>.a::before { content: "</style>"; }</style>{{ x }}';
        [$parser, $nodes] = $this->parseHtml($template);

        $context = $nodes[0]->htmlContext();
        $this->assertSame(HtmlContext::KIND_ELEMENT_CONTENT, $context->kind);
        $this->assertSame([], $context->elementStack);
        $this->assertSame($template, $parser->html()->markDirty()->toHtml());
    }

    public function test_script_content_becomes_text_and_antlers_nodes_in_the_graph()
    {
        [$parser, $nodes] = $this->parseHtml('<script>var t = {{ title }};</script>');
        $script = $parser->html()->first('script');

        $kinds = $script->content()->nodes()
            ->map(fn ($node) => (new \ReflectionClass($node))->getShortName())
            ->all();

        $this->assertSame(['Text', 'AntlersNode', 'Text'], $kinds);

        // Regions inside raw text remain mutable graph nodes.
        $nodes[0]->htmlNode()->source('{{ title | json }}');

        $this->assertSame(
            '<script>var t = {{ title | json }};</script>',
            $parser->html()->toHtml()
        );
    }

    public function test_double_escaped_script_regions_keep_their_context_and_round_trip()
    {
        $template = '<script><!-- <script>{{ inner }}</script> -->{{ outer }}</script><p>{{ after }}</p>';
        [$parser, $nodes] = $this->parseHtml($template);

        // Both regions live inside the (still open) script element; the
        // double-escaped </script> did not close it.
        $this->assertSame('script', $nodes[0]->htmlContext()->elementName);
        $this->assertSame('script', $nodes[1]->htmlContext()->elementName);
        $this->assertSame('p', $nodes[2]->htmlContext()->elementName);
        $this->assertSame($template, $parser->html()->markDirty()->toHtml());
    }

    public function test_unterminated_script_with_a_region_consumes_to_the_end_of_the_document()
    {
        $template = '<script>var a = "{{ x }}"; // never closed';
        [$parser, $nodes] = $this->parseHtml($template);

        $this->assertSame(HtmlContext::KIND_RAW_TEXT, $nodes[0]->htmlContext()->kind);
        $this->assertSame('script', $nodes[0]->htmlContext()->elementName);
        $this->assertSame($template, $parser->html()->markDirty()->toHtml());
    }

    public function test_pre_whitespace_survives_attribute_mutation()
    {
        $template = "<pre>  {{ code }}\n    indented\n</pre>";
        [$parser] = $this->parseHtml($template);

        $parser->html()->first('pre')->setAttribute('data-lang', 'antlers');

        $this->assertSame(
            "<pre data-lang=\"antlers\">  {{ code }}\n    indented\n</pre>",
            $parser->html()->toHtml()
        );
    }

    public function test_pairs_inside_raw_text_are_recorded_on_the_element_instead_of_commented()
    {
        $instrumented = HtmlInstrumentation::make()
            ->attributes('data-antlers')
            ->instrument('<script>{{ if debug }}console.log(1);{{ /if }}</script>');

        $this->assertStringNotContainsString('<!--', $instrumented);
        $this->assertStringStartsWith('<script data-antlers="', $instrumented);
        $this->assertStringContainsString('{{ if debug }}console.log(1);{{ /if }}</script>', $instrumented);

        preg_match('/data-antlers="([^"]+)"/', $instrumented, $matches);
        $payload = HtmlInstrumentation::decode($matches[1]);

        $this->assertSame('pair', $payload[0]['type']);
        $this->assertSame('raw-text', $payload[0]['context']);
        $this->assertSame('script', $payload[0]['element']);

        // Attribute layers cannot mark boundaries inside raw text, so the
        // payload carries template source extents instead — spanning the
        // whole pair through its closer.
        $template = '<script>{{ if debug }}console.log(1);{{ /if }}</script>';
        $this->assertSame(strlen('<script>'), $payload[0]['start']);
        $this->assertSame(strlen($template) - strlen('</script>'), $payload[0]['end']);
    }

    public function test_instrumenting_a_mixed_template_only_touches_safe_positions()
    {
        $template = implode('', [
            '<p>{{ safe }}</p>',
            '<pre>{{ code }}</pre>',
            '<style>.a { color: {{ color }}; }</style>',
            '<script>var t = {{ title }};</script>',
            '<textarea>{{ draft }}</textarea>',
            '<title>{{ page_title }}</title>',
        ]);

        $instrumented = HtmlInstrumentation::make()
            ->attributes('data-antlers')
            ->instrument($template);

        // Exactly one comment pair — the <p> — and one attribute per
        // raw/whitespace-sensitive element.
        $this->assertSame(1, substr_count($instrumented, '<!-- antlers:start'));
        $this->assertSame(1, substr_count($instrumented, '<!-- antlers:end'));

        foreach (['pre', 'style', 'script', 'textarea', 'title'] as $element) {
            $this->assertSame(
                1,
                preg_match('/<'.$element.' data-antlers="[^"]+">/', $instrumented),
                "Expected an attribute layer on <{$element}>."
            );
        }

        // The raw content itself is byte-identical.
        foreach (['{{ code }}</pre>', 'color: {{ color }}; }</style>', 'var t = {{ title }};</script>', '{{ draft }}</textarea>', '{{ page_title }}</title>'] as $fragment) {
            $this->assertStringContainsString($fragment, $instrumented);
        }

        // Instrumented output reparses and round-trips cleanly.
        $parser = new DocumentParser();
        $parser->parse($instrumented);
        $this->assertSame($instrumented, $parser->html()->markDirty()->toHtml());
    }

    public function test_textarea_and_title_regions_resolve_their_owning_elements()
    {
        $template = '<textarea>{{ draft }}</textarea><title>{{ page_title }}</title>';
        [, $nodes] = $this->parseHtml($template);

        $this->assertSame(HtmlContext::KIND_RAW_TEXT, $nodes[0]->htmlContext()->kind);
        $this->assertSame('textarea', $nodes[0]->htmlContext()->elementName);
        $this->assertSame(HtmlContext::KIND_RAW_TEXT, $nodes[1]->htmlContext()->kind);
        $this->assertSame('title', $nodes[1]->htmlContext()->elementName);
    }

    /**
     * @return array{0: DocumentParser, 1: AntlersNode[]}
     */
    protected function parseHtml($template)
    {
        $parser = new DocumentParser();
        $parser->parse($template);
        $nodes = array_values(array_filter($parser->getNodes(), function ($node) {
            return $node instanceof AntlersNode && ! $node->isClosingTag;
        }));

        return [$parser, $nodes];
    }
}
