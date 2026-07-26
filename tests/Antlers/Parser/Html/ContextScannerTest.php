<?php

namespace Tests\Antlers\Parser\Html;

use Statamic\View\Antlers\Language\Nodes\AntlersNode;
use Statamic\View\Antlers\Language\Parser\DocumentParser;
use Statamic\View\Instrumentation\Antlers\ContextScanner;
use Statamic\View\Instrumentation\HtmlContext as Context;
use Tests\Antlers\ParserTestCase;

class ContextScannerTest extends ParserTestCase
{
    private function annotatedAntlersNodes(string $template): array
    {
        $nodes = $this->parseNodes($template);

        (new ContextScanner)->annotate($nodes);

        $antlersNodes = [];

        foreach ($nodes as $node) {
            if ($node instanceof AntlersNode && ! $node->isComment) {
                $antlersNodes[] = $node;
            }
        }

        return $antlersNodes;
    }

    public function test_nodes_in_element_content_are_safe_for_comments()
    {
        [$title] = $this->annotatedAntlersNodes('<div><p>{{ title }}</p></div>');

        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $title->htmlContext->kind);
        $this->assertSame('p', $title->htmlContext->elementName);
        $this->assertSame(['div', 'p'], $title->htmlContext->elementStack);
        $this->assertTrue($title->htmlContext->isSafeForHtmlComments());
    }

    public function test_nodes_inside_attribute_values_are_detected()
    {
        [$classes] = $this->annotatedAntlersNodes('<div class="hero {{ classes }}">Content</div>');

        $this->assertSame(Context::KIND_ATTRIBUTE_VALUE, $classes->htmlContext->kind);
        $this->assertSame('div', $classes->htmlContext->elementName);
        $this->assertSame('class', $classes->htmlContext->attributeName);
        $this->assertSame(0, $classes->htmlContext->elementStartOffset);
        $this->assertFalse($classes->htmlContext->isSafeForHtmlComments());
    }

    public function test_node_context_can_be_resolved_lazily_without_enabling_global_annotation()
    {
        $nodes = $this->parseNodes('<div class="{{ classes }}">{{ content }}</div>');
        $antlers = array_values(array_filter($nodes, fn ($node) => $node instanceof AntlersNode));
        [$classes, $content] = $antlers;

        $this->assertNull($classes->htmlContext);
        $this->assertTrue($classes->htmlContext()->isAttributeContext());
        $this->assertTrue($classes->htmlContext()->isTagContext());
        $this->assertFalse($classes->htmlContext()->isElementContent());
        $this->assertSame('class', $classes->htmlContext()->attributeName);

        $this->assertFalse($content->htmlContext()->isAttributeContext());
        $this->assertFalse($content->htmlContext()->isTagContext());
        $this->assertTrue($content->htmlContext()->isElementContent());
        $this->assertSame('div', $content->htmlContext()->elementName);
    }

    public function test_nodes_inside_single_quoted_attribute_values_are_detected()
    {
        [$url] = $this->annotatedAntlersNodes("<a href='{{ url }}'>Link</a>");

        $this->assertSame(Context::KIND_ATTRIBUTE_VALUE, $url->htmlContext->kind);
        $this->assertSame('a', $url->htmlContext->elementName);
        $this->assertSame('href', $url->htmlContext->attributeName);
    }

    public function test_nodes_in_tag_open_position_are_detected()
    {
        [$attributes] = $this->annotatedAntlersNodes('<button {{ attributes }}>Go</button>');

        $this->assertSame(Context::KIND_TAG_OPEN, $attributes->htmlContext->kind);
        $this->assertSame('button', $attributes->htmlContext->elementName);
        $this->assertSame(0, $attributes->htmlContext->elementStartOffset);
    }

    public function test_nodes_in_element_and_attribute_names_are_not_treated_as_generic_tag_content()
    {
        [$elementName, $attributeName, $attributes] = $this->annotatedAntlersNodes(
            '<di{{ element_name }}v cla{{ attribute_name }}ss {{ attributes }}>Content</div>'
        );

        $this->assertSame(Context::KIND_ELEMENT_NAME, $elementName->htmlContext->kind);
        $this->assertSame('di', $elementName->htmlContext->elementName);
        $this->assertFalse($elementName->htmlContext->isSafeForHtmlComments());
        $this->assertFalse($elementName->htmlContext->canInstrumentOwningElement());

        $this->assertSame(Context::KIND_ATTRIBUTE_NAME, $attributeName->htmlContext->kind);
        $this->assertSame('cla', $attributeName->htmlContext->attributeName);
        $this->assertFalse($attributeName->htmlContext->canInstrumentOwningElement());

        $this->assertSame(Context::KIND_TAG_OPEN, $attributes->htmlContext->kind);
        $this->assertNull($attributes->htmlContext->elementName);
        $this->assertFalse($attributes->htmlContext->canInstrumentOwningElement());
    }

    public function test_malformed_static_names_follow_html_tokenizer_boundaries()
    {
        $replacement = "\xEF\xBF\xBD";
        [$equalsTag, $quotedTag, $nullTag, $slash, $equals, $quoted, $vertical] = $this->annotatedAntlersNodes(
            '<x=y>{{ equals_tag }}</x=y><x"y>{{ quoted_tag }}</x"y>'
                ."<x\0y>{{ null_tag }}</x\0y>"
                .'<div a/b="{{ slash }}" =x="{{ equals }}" "q"="{{ quoted }}"'
                ." a\vb=\"{{ vertical }}\"></div>"
        );

        $this->assertSame(['x=y'], $equalsTag->htmlContext->elementStack);
        $this->assertSame(['x"y'], $quotedTag->htmlContext->elementStack);
        $this->assertSame(['x'.$replacement.'y'], $nullTag->htmlContext->elementStack);
        $this->assertSame('b', $slash->htmlContext->attributeName);
        $this->assertSame('=x', $equals->htmlContext->attributeName);
        $this->assertSame('"q"', $quoted->htmlContext->attributeName);
        $this->assertSame("a\vb", $vertical->htmlContext->attributeName);
    }

    public function test_dynamic_element_names_make_their_element_and_descendants_conservatively_unsafe()
    {
        [$openingKind, $classes, $id, $content, $closingKind, $after] = $this->annotatedAntlersNodes(
            '<x-{{ kind }} class="{{ classes }}"><span data-id="{{ id }}">{{ content }}</span></x-{{ kind }}><p>{{ after }}</p>'
        );

        $this->assertSame(Context::KIND_ELEMENT_NAME, $openingKind->htmlContext->kind);
        $this->assertSame(Context::KIND_ATTRIBUTE_VALUE, $classes->htmlContext->kind);
        $this->assertNull($classes->htmlContext->elementName);
        $this->assertFalse($classes->htmlContext->canInstrumentOwningElement());

        $this->assertSame([Context::DYNAMIC_ELEMENT], $id->htmlContext->elementStack);
        $this->assertFalse($id->htmlContext->canInstrumentOwningElement());
        $this->assertSame([Context::DYNAMIC_ELEMENT, 'span'], $content->htmlContext->elementStack);
        $this->assertFalse($content->htmlContext->isSafeForHtmlComments());

        $this->assertSame(Context::KIND_ELEMENT_NAME, $closingKind->htmlContext->kind);
        $this->assertSame(['p'], $after->htmlContext->elementStack);
        $this->assertTrue($after->htmlContext->isSafeForHtmlComments());
    }

    public function test_antlers_comments_do_not_make_an_otherwise_static_element_name_dynamic()
    {
        [$classes, $content] = $this->annotatedAntlersNodes(
            '<di{{# ignored #}}v class="{{ classes }}">{{ content }}</div>'
        );

        $this->assertSame('div', $classes->htmlContext->elementName);
        $this->assertTrue($classes->htmlContext->canInstrumentOwningElement());
        $this->assertSame(['div'], $content->htmlContext->elementStack);
        $this->assertTrue($content->htmlContext->isSafeForHtmlComments());
    }

    public function test_noparse_html_advances_context_as_literal_rendered_output()
    {
        $template = '{{ noparse }}<title>literal{{ /noparse }}{{ real }}</title><p>{{ after }}</p>';
        $nodes = $this->annotatedAntlersNodes($template);
        $real = null;
        $after = null;

        foreach ($nodes as $node) {
            if (trim((string) $node->content) === 'real') {
                $real = $node;
            } elseif (trim((string) $node->content) === 'after') {
                $after = $node;
            }
        }

        $this->assertNotNull($real);
        $this->assertNotNull($after);
        $this->assertSame(Context::KIND_RAW_TEXT, $real->htmlContext->kind);
        $this->assertSame('title', $real->htmlContext->elementName);
        $this->assertFalse($real->htmlContext->isSafeForHtmlComments());
        $this->assertSame(['p'], $after->htmlContext->elementStack);
        $this->assertTrue($after->htmlContext->isSafeForHtmlComments());
    }

    public function test_rcdata_and_whitespace_sensitive_content_are_not_safe_for_comment_markers()
    {
        [$title, $textarea, $pre, $paragraph] = $this->annotatedAntlersNodes(
            '<title>{{ title }}</title><textarea>{{ input }}</textarea><pre>{{ code }}</pre><p>{{ body }}</p>'
        );

        $this->assertSame(Context::KIND_RAW_TEXT, $title->htmlContext->kind);
        $this->assertSame('title', $title->htmlContext->elementName);
        $this->assertFalse($title->htmlContext->isSafeForHtmlComments());
        $this->assertSame(0, $title->htmlContext->elementStartOffset);
        $this->assertTrue($title->htmlContext->canInstrumentContainingElement());
        $this->assertSame(Context::KIND_RAW_TEXT, $textarea->htmlContext->kind);
        $this->assertFalse($textarea->htmlContext->isSafeForHtmlComments());
        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $pre->htmlContext->kind);
        $this->assertFalse($pre->htmlContext->isSafeForHtmlComments());
        $this->assertTrue($pre->htmlContext->canInstrumentContainingElement());
        $this->assertTrue($paragraph->htmlContext->isSafeForHtmlComments());
        $this->assertTrue($paragraph->htmlContext->canInstrumentContainingElement());
    }

    public function test_descendants_of_whitespace_sensitive_elements_are_not_safe_for_comment_markers()
    {
        [$code, $listing, $paragraph] = $this->annotatedAntlersNodes(
            '<pre><code>{{ code }}</code></pre><listing><span>{{ listing }}</span></listing><p>{{ body }}</p>'
        );

        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $code->htmlContext->kind);
        $this->assertSame(['pre', 'code'], $code->htmlContext->elementStack);
        $this->assertFalse($code->htmlContext->isSafeForHtmlComments());
        $this->assertSame(['listing', 'span'], $listing->htmlContext->elementStack);
        $this->assertFalse($listing->htmlContext->isSafeForHtmlComments());
        $this->assertTrue($paragraph->htmlContext->isSafeForHtmlComments());
    }

    public function test_noscript_is_conservatively_treated_as_raw_text()
    {
        [$fallback, $body] = $this->annotatedAntlersNodes('<noscript>{{ fallback }}</noscript><p>{{ body }}</p>');

        $this->assertSame(Context::KIND_RAW_TEXT, $fallback->htmlContext->kind);
        $this->assertSame('noscript', $fallback->htmlContext->elementName);
        $this->assertFalse($fallback->htmlContext->isSafeForHtmlComments());
        $this->assertTrue($body->htmlContext->isSafeForHtmlComments());
    }

    public function test_doctype_ends_at_gt_even_inside_a_quoted_identifier()
    {
        [$identifier, $body] = $this->annotatedAntlersNodes('<!DOCTYPE html SYSTEM "urn:test>{{ identifier }}">{{ body }}');

        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $identifier->htmlContext->kind);
        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $body->htmlContext->kind);
    }

    public function test_bang_closed_comments_return_to_element_content()
    {
        [$comment, $body] = $this->annotatedAntlersNodes('<!-- {{ comment }} --!>{{ body }}');

        $this->assertSame(Context::KIND_COMMENT, $comment->htmlContext->kind);
        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $body->htmlContext->kind);
    }

    public function test_abruptly_closed_empty_comments_return_to_element_content()
    {
        [$first, $second] = $this->annotatedAntlersNodes('<!-->{{ first }}<!--->{{ second }}');

        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $first->htmlContext->kind);
        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $second->htmlContext->kind);
    }

    public function test_comment_terminators_cannot_overlap_the_opening_dashes()
    {
        [$first] = $this->annotatedAntlersNodes('<main><div><!--!>{{ first }}</div></main>');
        [$second] = $this->annotatedAntlersNodes('<main><section><!---!></section>{{ second }}</main>');

        $this->assertSame(Context::KIND_COMMENT, $first->htmlContext->kind);
        $this->assertSame(['main', 'div'], $first->htmlContext->elementStack);
        $this->assertSame(Context::KIND_COMMENT, $second->htmlContext->kind);
        $this->assertSame(['main', 'section'], $second->htmlContext->elementStack);
    }

    public function test_html_doctypes_do_not_honor_xml_internal_subsets()
    {
        [$entity, $body] = $this->annotatedAntlersNodes(
            '<!DOCTYPE svg [ <!ENTITY example "value"> {{ entity }} ]>{{ body }}'
        );

        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $entity->htmlContext->kind);
        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $body->htmlContext->kind);
    }

    public function test_plaintext_consumes_markup_until_the_end_of_the_document()
    {
        [$first, $second] = $this->annotatedAntlersNodes('<plaintext>{{ first }}</plaintext><p>{{ second }}</p>');

        $this->assertSame(Context::KIND_RAW_TEXT, $first->htmlContext->kind);
        $this->assertSame('plaintext', $first->htmlContext->elementName);
        $this->assertSame(Context::KIND_RAW_TEXT, $second->htmlContext->kind);
        $this->assertSame('plaintext', $second->htmlContext->elementName);
    }

    public function test_only_opening_elements_can_be_stamped_with_attribute_instrumentation()
    {
        [$value, $closingTag] = $this->annotatedAntlersNodes('<div data-id="{{ value }}">x</div {{ closing_tag }}>');

        $this->assertTrue($value->htmlContext->canInstrumentOwningElement());
        $this->assertFalse($closingTag->htmlContext->canInstrumentOwningElement());
        $this->assertTrue($closingTag->htmlContext->isClosingTag);
    }

    public function test_nodes_inside_script_and_style_are_raw_text()
    {
        [$data, $color] = $this->annotatedAntlersNodes(
            '<script>const data = {{ data }};</script><style>body { color: {{ color }}; }</style>'
        );

        $this->assertSame(Context::KIND_RAW_TEXT, $data->htmlContext->kind);
        $this->assertSame('script', $data->htmlContext->elementName);
        $this->assertSame(Context::KIND_RAW_TEXT, $color->htmlContext->kind);
        $this->assertSame('style', $color->htmlContext->elementName);
    }

    public function test_nodes_inside_html_comments_are_detected()
    {
        [$note] = $this->annotatedAntlersNodes('<div><!-- {{ note }} --></div>');

        $this->assertSame(Context::KIND_COMMENT, $note->htmlContext->kind);
    }

    public function test_element_stack_tracks_void_and_self_closing_elements()
    {
        [$title] = $this->annotatedAntlersNodes('<section><img src="a.png"><br/><span>{{ title }}</span></section>');

        $this->assertSame(['section', 'span'], $title->htmlContext->elementStack);
    }

    public function test_self_closing_syntax_is_ignored_for_non_void_html_elements()
    {
        [$content, $script] = $this->annotatedAntlersNodes('<div/>{{ content }}<script/>{{ script }}');

        $this->assertSame(['div'], $content->htmlContext->elementStack);
        $this->assertSame(Context::KIND_RAW_TEXT, $script->htmlContext->kind);
        $this->assertSame(['div', 'script'], $script->htmlContext->elementStack);
    }

    public function test_foreign_namespaces_do_not_inherit_html_raw_text_or_void_rules()
    {
        [$title, $afterPath, $html] = $this->annotatedAntlersNodes(
            '<svg><title>{{ title }}</title><path/>{{ after_path }}'
            .'<foreignObject><div>{{ html }}</div></foreignObject></svg>'
        );

        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $title->htmlContext->kind);
        $this->assertSame(['svg', 'title'], $title->htmlContext->elementStack);
        $this->assertSame(['svg'], $afterPath->htmlContext->elementStack);
        $this->assertSame(['svg', 'foreignObject', 'div'], $html->htmlContext->elementStack);
    }

    public function test_formatting_end_tags_cross_non_scoping_foreign_descendants()
    {
        [$marker] = $this->annotatedAntlersNodes('<b><svg><g></b>{{ marker }}');

        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $marker->htmlContext->kind);
        $this->assertSame([], $marker->htmlContext->elementStack);
    }

    public function test_foreign_table_like_names_cannot_loop_table_reprocessing()
    {
        [$marker, $tail] = $this->annotatedAntlersNodes(
            '<table><svg><td><div></svg>{{ marker }}<form><td><table>{{ tail }}'
        );

        $this->assertSame(['table', 'div'], $marker->htmlContext->elementStack);
        $this->assertSame(['table', 'td', 'table'], $tail->htmlContext->elementStack);
    }

    public function test_html_breakout_tags_exit_foreign_content()
    {
        [$vector, $html, $paragraph, $font, $foreignFont] = $this->annotatedAntlersNodes(
            '<svg><g>{{ vector }}<div>{{ html }}</div></svg>'
                .'<math><mrow><p>{{ paragraph }}</p></math>'
                .'<svg><g><font color="red">{{ font }}</font></g></svg>'
                .'<svg><g><font data-color="red">{{ foreign_font }}</font></g></svg>'
        );

        $this->assertSame(['svg', 'g'], $vector->htmlContext->elementStack);
        $this->assertSame(['div'], $html->htmlContext->elementStack);
        $this->assertSame(['p'], $paragraph->htmlContext->elementStack);
        $this->assertSame(['font'], $font->htmlContext->elementStack);
        $this->assertSame(['svg', 'g', 'font'], $foreignFont->htmlContext->elementStack);
    }

    public function test_mathml_text_integration_points_apply_html_raw_text_rules()
    {
        [$title, $glyph] = $this->annotatedAntlersNodes(
            '<math><mtext><title>{{ title }}</title><mglyph><title>{{ glyph }}</title></mglyph></mtext></math>'
        );

        $this->assertSame(Context::KIND_RAW_TEXT, $title->htmlContext->kind);
        $this->assertSame('title', $title->htmlContext->elementName);
        $this->assertSame(['math', 'mtext', 'title'], $title->htmlContext->elementStack);
        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $glyph->htmlContext->kind);
        $this->assertSame(['math', 'mtext', 'mglyph', 'title'], $glyph->htmlContext->elementStack);
    }

    public function test_foreign_end_tags_respect_html_integration_point_boundaries()
    {
        [$titleBefore, $titleAfter] = $this->annotatedAntlersNodes(
            '<svg><title><span>{{ before }}</title>{{ after }}</svg>'
        );
        [$math] = $this->annotatedAntlersNodes('<math><mrow></p>{{ value }}</math>');
        [$objectBefore, $objectAfter] = $this->annotatedAntlersNodes(
            '<svg><foreignObject><p>{{ before }}</foreignObject>{{ after }}</svg>'
        );
        [$afterHtmlAncestor] = $this->annotatedAntlersNodes('<div><svg><g></div>{{ after }}');

        $this->assertSame(['svg', 'title', 'span'], $titleBefore->htmlContext->elementStack);
        $this->assertSame(['svg', 'title', 'span'], $titleAfter->htmlContext->elementStack);
        $this->assertSame(['math', 'mrow'], $math->htmlContext->elementStack);
        $this->assertSame(['svg', 'foreignObject', 'p'], $objectBefore->htmlContext->elementStack);
        $this->assertSame(['svg', 'foreignObject', 'p'], $objectAfter->htmlContext->elementStack);
        $this->assertSame([], $afterHtmlAncestor->htmlContext->elementStack);
    }

    public function test_static_annotation_xml_html_encodings_enable_html_raw_text_rules()
    {
        [$html, $xhtml, $format, $dynamic, $duplicateFormat, $duplicate] = $this->annotatedAntlersNodes(
            '<math><annotation-xml encoding="text/html"><script>{{ html }}</script></annotation-xml>'
            .'<annotation-xml encoding = application/xhtml+xml><title>{{ xhtml }}</title></annotation-xml>'
            .'<annotation-xml encoding="{{ format }}"><script>{{ dynamic }}</script></annotation-xml>'
            .'<annotation-xml encoding="{{ duplicate_format }}" encoding="text/html">'
            .'<script>{{ duplicate }}</script></annotation-xml></math>'
        );

        $this->assertSame(Context::KIND_RAW_TEXT, $html->htmlContext->kind);
        $this->assertSame('script', $html->htmlContext->elementName);
        $this->assertSame(Context::KIND_RAW_TEXT, $xhtml->htmlContext->kind);
        $this->assertSame('title', $xhtml->htmlContext->elementName);
        $this->assertSame(Context::KIND_ATTRIBUTE_VALUE, $format->htmlContext->kind);
        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $dynamic->htmlContext->kind);
        $this->assertSame(
            ['math', 'annotation-xml', 'script'],
            $dynamic->htmlContext->elementStack
        );
        $this->assertSame(Context::KIND_ATTRIBUTE_VALUE, $duplicateFormat->htmlContext->kind);
        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $duplicate->htmlContext->kind);
    }

    public function test_optional_end_tags_follow_scoped_html_tree_rules()
    {
        [$paragraph, $section, $heading, $nextHeading, $firstCell, $secondCell] = $this->annotatedAntlersNodes(
            '<p>{{ paragraph }}<section>{{ section }}</section>'
            .'<h1>{{ heading }}<h2>{{ next_heading }}</h2>'
            .'<table><tbody><tr><td>{{ first_cell }}<td>{{ second_cell }}</table>'
        );

        $this->assertSame(['p'], $paragraph->htmlContext->elementStack);
        $this->assertSame(['section'], $section->htmlContext->elementStack);
        $this->assertSame(['h1'], $heading->htmlContext->elementStack);
        $this->assertSame(['h2'], $nextHeading->htmlContext->elementStack);
        $this->assertSame(['table', 'tbody', 'tr', 'td'], $firstCell->htmlContext->elementStack);
        $this->assertSame(['table', 'tbody', 'tr', 'td'], $secondCell->htmlContext->elementStack);
    }

    public function test_heading_starts_do_not_close_through_intervening_elements()
    {
        [$outer, $inner] = $this->annotatedAntlersNodes(
            '<h1><section><span>{{ outer }}<h2>{{ inner }}'
        );
        [$paragraph, $next] = $this->annotatedAntlersNodes(
            '<h1><p>{{ paragraph }}<h2>{{ next }}'
        );

        $this->assertSame(['h1', 'section', 'span'], $outer->htmlContext->elementStack);
        $this->assertSame(['h1', 'section', 'span', 'h2'], $inner->htmlContext->elementStack);
        $this->assertSame(['h1', 'p'], $paragraph->htmlContext->elementStack);
        $this->assertSame(['h2'], $next->htmlContext->elementStack);
    }

    public function test_nested_button_recovery_reconstructs_formatting_before_the_new_button()
    {
        [$before, $after] = $this->annotatedAntlersNodes(
            '<button><em><b><span>{{ before }}<button>{{ after }}'
        );

        $this->assertSame(['button', 'em', 'b', 'span'], $before->htmlContext->elementStack);
        $this->assertSame(['em', 'b', 'button'], $after->htmlContext->elementStack);
    }

    public function test_nested_anchor_start_uses_formatting_adoption_before_inserting_the_new_anchor()
    {
        [$blockBefore, $blockAfter] = $this->annotatedAntlersNodes(
            '<a><section>{{ block_before }}<a>{{ block_after }}'
        );
        [$inlineBefore, $inlineAfter] = $this->annotatedAntlersNodes(
            '<a><span>{{ inline_before }}<a>{{ inline_after }}'
        );
        [$layeredBefore, $layeredAfter] = $this->annotatedAntlersNodes(
            '<a><div><i>{{ layered_before }}<a>{{ layered_after }}'
        );

        $this->assertSame(['a', 'section'], $blockBefore->htmlContext->elementStack);
        $this->assertSame(['section', 'a'], $blockAfter->htmlContext->elementStack);
        $this->assertSame(['a', 'span'], $inlineBefore->htmlContext->elementStack);
        $this->assertSame(['a'], $inlineAfter->htmlContext->elementStack);
        $this->assertSame(['a', 'div', 'i'], $layeredBefore->htmlContext->elementStack);
        $this->assertSame(['div', 'i', 'a'], $layeredAfter->htmlContext->elementStack);
    }

    public function test_html_end_tags_respect_special_and_scope_boundaries()
    {
        [$specialBefore, $specialAfter] = $this->annotatedAntlersNodes(
            '<x-box><div>{{ before }}</x-box>{{ after }}'
        );
        [$inlineBefore, $inlineAfter] = $this->annotatedAntlersNodes(
            '<x-box><span>{{ before }}</x-box>{{ after }}'
        );
        [$tableBefore, $tableAfter] = $this->annotatedAntlersNodes(
            '<div><table>{{ before }}</div>{{ after }}'
        );
        [$headingBefore, $headingAfter] = $this->annotatedAntlersNodes(
            '<h1><span>{{ before }}</h2>{{ after }}'
        );
        [$templateBefore, $templateAfter] = $this->annotatedAntlersNodes(
            '<template><table><tr><td>{{ before }}</template>{{ after }}'
        );
        [$paragraph] = $this->annotatedAntlersNodes('<div></p>{{ after }}');

        $this->assertSame(['x-box', 'div'], $specialBefore->htmlContext->elementStack);
        $this->assertSame(['x-box', 'div'], $specialAfter->htmlContext->elementStack);
        $this->assertSame(['x-box', 'span'], $inlineBefore->htmlContext->elementStack);
        $this->assertSame([], $inlineAfter->htmlContext->elementStack);
        $this->assertSame(['div', 'table'], $tableBefore->htmlContext->elementStack);
        $this->assertSame(['div', 'table'], $tableAfter->htmlContext->elementStack);
        $this->assertSame(['h1', 'span'], $headingBefore->htmlContext->elementStack);
        $this->assertSame([], $headingAfter->htmlContext->elementStack);
        $this->assertSame(['template', 'table', 'tr', 'td'], $templateBefore->htmlContext->elementStack);
        $this->assertSame([], $templateAfter->htmlContext->elementStack);
        $this->assertSame(['div'], $paragraph->htmlContext->elementStack);
    }

    public function test_list_item_and_description_item_starts_close_open_paragraphs()
    {
        [$paragraph, $item, $nextParagraph, $term] = $this->annotatedAntlersNodes(
            '<p>{{ paragraph }}<li>{{ item }}<p>{{ next_paragraph }}<dt>{{ term }}'
        );

        $this->assertSame(['p'], $paragraph->htmlContext->elementStack);
        $this->assertSame(['li'], $item->htmlContext->elementStack);
        $this->assertSame(['li', 'p'], $nextParagraph->htmlContext->elementStack);
        $this->assertSame(['li', 'dt'], $term->htmlContext->elementStack);
    }

    public function test_option_start_only_closes_an_option_when_it_is_current()
    {
        [$first, $second, $paragraph, $nested] = $this->annotatedAntlersNodes(
            '<option>{{ first }}<option>{{ second }}<p>{{ paragraph }}<option>{{ nested }}'
        );

        $this->assertSame(['option'], $first->htmlContext->elementStack);
        $this->assertSame(['option'], $second->htmlContext->elementStack);
        $this->assertSame(['option', 'p'], $paragraph->htmlContext->elementStack);
        $this->assertSame(['option', 'p', 'option'], $nested->htmlContext->elementStack);

        [$outer, $option, $nestedGroup, $selectedOuter, $selectedNext] = $this->annotatedAntlersNodes(
            '<optgroup>{{ outer }}<option>{{ option }}<optgroup>{{ nested }}'
                .'<select><optgroup>{{ selected_outer }}<optgroup>{{ selected_next }}'
        );

        $this->assertSame(['optgroup'], $outer->htmlContext->elementStack);
        $this->assertSame(['optgroup', 'option'], $option->htmlContext->elementStack);
        $this->assertSame(['optgroup', 'optgroup'], $nestedGroup->htmlContext->elementStack);
        $this->assertSame(['optgroup', 'optgroup', 'select', 'optgroup'], $selectedOuter->htmlContext->elementStack);
        $this->assertSame(['optgroup', 'optgroup', 'select', 'optgroup'], $selectedNext->htmlContext->elementStack);
    }

    public function test_form_end_tags_generate_implied_end_tags_before_removing_the_form_pointer()
    {
        [$option, $after, $paragraph, $final] = $this->annotatedAntlersNodes(
            '<form><section><option>{{ option }}</form>{{ after }}'
                .'<form><div><p>{{ paragraph }}</form>{{ final }}'
        );

        $this->assertSame(['form', 'section', 'option'], $option->htmlContext->elementStack);
        $this->assertSame(['section'], $after->htmlContext->elementStack);
        $this->assertSame(['section', 'form', 'div', 'p'], $paragraph->htmlContext->elementStack);
        $this->assertSame(['section', 'div'], $final->htmlContext->elementStack);
    }

    public function test_item_start_recovery_stops_at_special_element_boundaries()
    {
        [$first, $next, $nested, $nestedItem, $nestedDescription] = $this->annotatedAntlersNodes(
            '<ul><li><span>{{ first }}<li>{{ next }}</ul>'
                .'<ul><li><button><span>{{ nested }}<li>{{ nested_item }}</ul>'
                .'<dl><dt><button><span><dd>{{ nested_description }}</dl>'
        );

        $this->assertSame(['ul', 'li', 'span'], $first->htmlContext->elementStack);
        $this->assertSame(['ul', 'li'], $next->htmlContext->elementStack);
        $this->assertSame(['ul', 'li', 'button', 'span'], $nested->htmlContext->elementStack);
        $this->assertSame(['ul', 'li', 'button', 'span', 'li'], $nestedItem->htmlContext->elementStack);
        $this->assertSame(['dl', 'dt', 'button', 'span', 'dd'], $nestedDescription->htmlContext->elementStack);
    }

    public function test_table_foster_parenting_contexts_are_not_safe_for_comment_markers()
    {
        [$table, $body, $row, $cell, $caption, $fosteredElement] = $this->annotatedAntlersNodes(
            '<table>{{ table }}<tbody>{{ body }}<tr>{{ row }}<td>{{ cell }}</td></tr></tbody>'
            .'<caption>{{ caption }}</caption><div>{{ fostered_element }}</div></table>'
        );

        $this->assertTrue($table->htmlContext->isFosterParentingContext());
        $this->assertTrue($body->htmlContext->isFosterParentingContext());
        $this->assertTrue($row->htmlContext->isFosterParentingContext());
        $this->assertFalse($cell->htmlContext->isFosterParentingContext());
        $this->assertFalse($caption->htmlContext->isFosterParentingContext());
        $this->assertFalse($fosteredElement->htmlContext->isFosterParentingContext());
        $this->assertFalse($table->htmlContext->isSafeForHtmlComments());
        $this->assertTrue($cell->htmlContext->isSafeForHtmlComments());
    }

    public function test_select_recovery_ignores_disallowed_elements_and_exits_for_form_controls()
    {
        [$inside, $option, $after] = $this->annotatedAntlersNodes(
            '<select><div>{{ inside }}</div><option>{{ option }}</option><input>{{ after }}</select>'
        );

        $this->assertSame(['select'], $inside->htmlContext->elementStack);
        $this->assertSame('select', $inside->htmlContext->elementName);
        $this->assertSame(['select', 'option'], $option->htmlContext->elementStack);
        $this->assertSame([], $after->htmlContext->elementStack);
        $this->assertNull($after->htmlContext->elementName);
    }

    public function test_nested_form_start_tags_do_not_create_a_second_context_boundary()
    {
        [$before, $inside, $after] = $this->annotatedAntlersNodes(
            '<form><div>{{ before }}<form>{{ inside }}</form>{{ after }}</div></form>'
        );

        $this->assertSame(['form', 'div'], $before->htmlContext->elementStack);
        $this->assertSame(['form', 'div'], $inside->htmlContext->elementStack);
        $this->assertSame(['div'], $after->htmlContext->elementStack);
    }

    public function test_template_boundaries_allow_independent_form_contexts()
    {
        [$inner, $template, $outer] = $this->annotatedAntlersNodes(
            '<form><template><form>{{ inner }}</form>{{ template }}</template>{{ outer }}</form>'
        );

        $this->assertSame(['form', 'template', 'form'], $inner->htmlContext->elementStack);
        $this->assertSame(['form', 'template'], $template->htmlContext->elementStack);
        $this->assertSame(['form'], $outer->htmlContext->elementStack);
    }

    public function test_template_boundaries_prevent_description_items_from_closing_outer_items()
    {
        [$inside, $after] = $this->annotatedAntlersNodes(
            '<dd><template><dt>{{ inside }}</template>{{ after }}'
        );

        $this->assertSame(['dd', 'template', 'dt'], $inside->htmlContext->elementStack);
        $this->assertSame(['dd'], $after->htmlContext->elementStack);
    }

    public function test_table_forms_retain_the_form_pointer_after_stack_recovery()
    {
        [$fostered, $second] = $this->annotatedAntlersNodes(
            '<div><table><form><form>{{ fostered }}</form></table><form>{{ second }}</form></div>'
        );

        $this->assertSame(['div', 'table'], $fostered->htmlContext->elementStack);
        $this->assertSame(['div', 'form'], $second->htmlContext->elementStack);
    }

    public function test_table_mode_forms_leave_the_stack_and_form_ends_preserve_fostered_descendants()
    {
        [$emptyForm] = $this->annotatedAntlersNodes(
            '<table><em><form>{{ after_form }}'
        );
        [$formEnd] = $this->annotatedAntlersNodes(
            '<form><table><option></form>{{ after_end }}'
        );

        $this->assertSame(['table', 'em'], $emptyForm->htmlContext->elementStack);
        $this->assertSame(['table', 'option'], $formEnd->htmlContext->elementStack);
    }

    public function test_nested_anchor_recovery_keeps_the_new_anchor_in_the_table_open_element_context()
    {
        [$marker] = $this->annotatedAntlersNodes(
            '<table><a><a>{{ marker }}'
        );

        $this->assertSame(['table', 'a'], $marker->htmlContext->elementStack);
    }

    public function test_head_elements_remain_in_their_allowed_table_contexts()
    {
        [$row, $column] = $this->annotatedAntlersNodes(
            '<table><tbody><style></style><script></script>'
                .'<template><tr><td>{{ row }}</td></tr></template></tbody>'
                .'<colgroup><template>{{ column }}</template></colgroup></table>'
        );

        $this->assertSame(
            ['table', 'tbody', 'template', 'tr', 'td'],
            $row->htmlContext->elementStack
        );
        $this->assertSame(
            ['table', 'colgroup', 'template'],
            $column->htmlContext->elementStack
        );
    }

    public function test_table_structure_exits_select_mode_and_is_reprocessed()
    {
        [$before, $cell, $inside, $after] = $this->annotatedAntlersNodes(
            '<table><select>{{ before }}<tr><td>{{ cell }}</td></tr></select></table>'
                .'<table><tbody><tr><td><select>{{ inside }}</td>{{ after }}</tr></tbody></table>'
        );

        $this->assertSame(['table', 'select'], $before->htmlContext->elementStack);
        $this->assertSame(['table', 'tr', 'td'], $cell->htmlContext->elementStack);
        $this->assertSame(['table', 'tbody', 'tr', 'td', 'select'], $inside->htmlContext->elementStack);
        $this->assertSame(['table', 'tbody', 'tr'], $after->htmlContext->elementStack);
        $this->assertTrue($after->htmlContext->isFosterParentingContext());
    }

    public function test_table_structure_recovers_through_fostered_elements_and_respects_formatting_markers()
    {
        [$fostered, $cell, $before, $formattedCell, $after] = $this->annotatedAntlersNodes(
            '<table><div>{{ fostered }}<tr><td>{{ cell }}</td></tr></div></table>'
                .'<table><b>{{ before }}<tr><td>{{ formatted_cell }}</td></tr></b></table>{{ after }}'
        );

        $this->assertSame(['table', 'div'], $fostered->htmlContext->elementStack);
        $this->assertSame(['table', 'tr', 'td'], $cell->htmlContext->elementStack);
        $this->assertSame(['table', 'b'], $before->htmlContext->elementStack);
        $this->assertSame(['table', 'tr', 'td'], $formattedCell->htmlContext->elementStack);
        $this->assertFalse($formattedCell->htmlContext->requiresFormattingReconstruction);
        $this->assertSame([], $after->htmlContext->elementStack);
        $this->assertFalse($after->htmlContext->requiresFormattingReconstruction);
    }

    public function test_table_structure_start_tags_are_ignored_without_a_table_or_template()
    {
        [$row, $cell, $body, $afterCol, $templateCell] = $this->annotatedAntlersNodes(
            '<div><tr>{{ row }}</tr><td>{{ cell }}</td><tbody>{{ body }}</tbody>'
                .'<col>{{ after_col }}</div><template><td>{{ template_cell }}</td></template>'
        );

        $this->assertSame(['div'], $row->htmlContext->elementStack);
        $this->assertSame(['div'], $cell->htmlContext->elementStack);
        $this->assertSame(['div'], $body->htmlContext->elementStack);
        $this->assertSame(['div'], $afterCol->htmlContext->elementStack);
        $this->assertSame(['template', 'td'], $templateCell->htmlContext->elementStack);
    }

    public function test_table_structural_recovery_updates_later_marker_safety()
    {
        [$cell, $caption, $after] = $this->annotatedAntlersNodes(
            '<div><table><tbody><tr><td>{{ cell }}<caption>{{ caption }}</caption>{{ after }}</td></table></div>'
        );

        $this->assertSame(['div', 'table', 'tbody', 'tr', 'td'], $cell->htmlContext->elementStack);
        $this->assertSame(['div', 'table', 'caption'], $caption->htmlContext->elementStack);
        $this->assertSame(['div', 'table'], $after->htmlContext->elementStack);
        $this->assertTrue($after->htmlContext->isFosterParentingContext());
        $this->assertFalse($after->htmlContext->isSafeForHtmlComments());
    }

    public function test_pending_active_formatting_reconstruction_is_not_comment_safe()
    {
        [$inside, $after] = $this->annotatedAntlersNodes(
            '<p><b>before<div>{{ inside }}</b>{{ after }}</div>'
        );

        $this->assertTrue($inside->htmlContext->requiresFormattingReconstruction);
        $this->assertFalse($inside->htmlContext->isSafeForHtmlComments());
        $this->assertFalse($after->htmlContext->requiresFormattingReconstruction);
        $this->assertTrue($after->htmlContext->isSafeForHtmlComments());
    }

    public function test_adoption_recovery_preserves_surrounding_context_after_the_formatting_end_tag()
    {
        [$before, $after, $deepBefore, $deepAfter] = $this->annotatedAntlersNodes(
            '<b><i><p>{{ before }}</b>{{ after }}</p></i>'
                .'<b><div><section>{{ deep_before }}</b>{{ deep_after }}</section></div>'
        );

        $this->assertSame(['b', 'i', 'p'], $before->htmlContext->elementStack);
        $this->assertSame(['i', 'p'], $after->htmlContext->elementStack);
        $this->assertFalse($after->htmlContext->requiresFormattingReconstruction);
        $this->assertSame(['b', 'div', 'section'], $deepBefore->htmlContext->elementStack);
        $this->assertSame(['div', 'section'], $deepAfter->htmlContext->elementStack);
        $this->assertFalse($deepAfter->htmlContext->requiresFormattingReconstruction);
    }

    public function test_formatting_reconstruction_waits_until_content_after_a_block_start()
    {
        [$before, $after] = $this->annotatedAntlersNodes(
            '<i><em>{{ before }}</i><div><b>{{ after }}'
        );

        $this->assertSame(['i', 'em'], $before->htmlContext->elementStack);
        $this->assertSame(['div', 'em', 'b'], $after->htmlContext->elementStack);
        $this->assertFalse($after->htmlContext->requiresFormattingReconstruction);
    }

    public function test_active_formatting_limits_three_equivalent_entries_but_keeps_distinct_attributes()
    {
        [, $equivalent] = $this->annotatedAntlersNodes(
            '<div><b><b><b><b><i>{{ before }}</div><span>{{ after }}'
        );
        [, $distinct] = $this->annotatedAntlersNodes(
            '<div><b data-n="1"><b data-n="2"><b data-n="3"><b data-n="4"><i>{{ before }}</div><span>{{ after }}'
        );

        $this->assertSame(['b', 'b', 'b', 'i', 'span'], $equivalent->htmlContext->elementStack);
        $this->assertSame(['b', 'b', 'b', 'b', 'i', 'span'], $distinct->htmlContext->elementStack);
    }

    public function test_formatting_end_tags_target_the_active_element_identity_not_an_older_matching_name()
    {
        [$strike] = $this->annotatedAntlersNodes(
            '<strike><div><strike></div></strike><span>{{ marker }}'
        );
        [$font] = $this->annotatedAntlersNodes(
            '<font><blockquote><font><i></blockquote></font><span>{{ marker }}'
        );

        $this->assertSame(['strike', 'span'], $strike->htmlContext->elementStack);
        $this->assertSame(['font', 'i', 'span'], $font->htmlContext->elementStack);
    }

    public function test_formatting_end_tags_below_a_table_scope_boundary_are_ignored()
    {
        [$marker] = $this->annotatedAntlersNodes(
            '<b><table></b></table><span>{{ marker }}'
        );

        $this->assertSame(['b', 'span'], $marker->htmlContext->elementStack);
    }

    public function test_paired_tags_spanning_content_report_element_content()
    {
        $nodes = $this->annotatedAntlersNodes('<ul>{{ items }}<li>{{ name }}</li>{{ /items }}</ul>');

        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $nodes[0]->htmlContext->kind);
        $this->assertSame('ul', $nodes[0]->htmlContext->elementName);
        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $nodes[1]->htmlContext->kind);
        $this->assertSame('li', $nodes[1]->htmlContext->elementName);
        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $nodes[2]->htmlContext->kind);
        $this->assertSame('ul', $nodes[2]->htmlContext->elementName);
    }

    public function test_unquoted_attribute_values_are_detected()
    {
        [$width] = $this->annotatedAntlersNodes('<td width={{ width }}>Cell</td>');

        $this->assertSame(Context::KIND_ATTRIBUTE_VALUE, $width->htmlContext->kind);
        $this->assertSame('width', $width->htmlContext->attributeName);
    }

    public function test_expressions_containing_angle_brackets_do_not_corrupt_the_state()
    {
        $nodes = $this->annotatedAntlersNodes('<div>{{ if count > 3 }}<span>{{ count }}</span>{{ /if }}</div>');

        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $nodes[0]->htmlContext->kind);
        $this->assertSame('div', $nodes[0]->htmlContext->elementName);
        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $nodes[1]->htmlContext->kind);
        $this->assertSame('span', $nodes[1]->htmlContext->elementName);
    }

    public function test_doctype_regions_are_detected()
    {
        [$lang] = $this->annotatedAntlersNodes('<!DOCTYPE html><html lang="{{ lang }}"><body>Hi</body></html>');

        $this->assertSame(Context::KIND_ATTRIBUTE_VALUE, $lang->htmlContext->kind);
        $this->assertSame('html', $lang->htmlContext->elementName);
        $this->assertSame('lang', $lang->htmlContext->attributeName);
    }

    public function test_whitespace_around_attribute_equals_still_reports_attribute_value()
    {
        [$x] = $this->annotatedAntlersNodes('<div data-x = ">{{ x }}">Content</div>');

        $this->assertSame(Context::KIND_ATTRIBUTE_VALUE, $x->htmlContext->kind);
        $this->assertSame('div', $x->htmlContext->elementName);
        $this->assertSame('data-x', $x->htmlContext->attributeName);
        $this->assertSame(0, $x->htmlContext->elementStartOffset);
        $this->assertFalse($x->htmlContext->isSafeForHtmlComments());
    }

    public function test_whitespace_after_attribute_equals_still_reports_attribute_value()
    {
        [$x] = $this->annotatedAntlersNodes('<div class= ">{{ x }}">Content</div>');

        $this->assertSame(Context::KIND_ATTRIBUTE_VALUE, $x->htmlContext->kind);
        $this->assertSame('class', $x->htmlContext->attributeName);
    }

    public function test_angle_brackets_inside_quoted_attribute_values_do_not_end_the_tag()
    {
        [$x, $y] = $this->annotatedAntlersNodes('<img alt="a > b {{ x }}"><p>{{ y }}</p>');

        $this->assertSame(Context::KIND_ATTRIBUTE_VALUE, $x->htmlContext->kind);
        $this->assertSame('alt', $x->htmlContext->attributeName);
        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $y->htmlContext->kind);
        $this->assertSame(['p'], $y->htmlContext->elementStack);
    }

    public function test_lt_before_non_letters_is_plain_text()
    {
        [$x] = $this->annotatedAntlersNodes('Price < 5 {{ x }} more');

        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $x->htmlContext->kind);
        $this->assertTrue($x->htmlContext->isSafeForHtmlComments());
    }

    public function test_repeated_lt_characters_report_the_real_tag_start_offset()
    {
        [$x] = $this->annotatedAntlersNodes('<<div {{ x }}>');

        $this->assertSame(Context::KIND_TAG_OPEN, $x->htmlContext->kind);
        $this->assertSame('div', $x->htmlContext->elementName);
        $this->assertSame(1, $x->htmlContext->elementStartOffset);
    }

    public function test_cdata_syntax_is_a_bogus_comment_in_the_html_namespace()
    {
        [$x, $y] = $this->annotatedAntlersNodes('<![CDATA[ a > b {{ x }} ]]>done{{ y }}');

        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $x->htmlContext->kind);
        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $y->htmlContext->kind);
    }

    public function test_cdata_sections_remain_opaque_in_foreign_content()
    {
        [$x, $y] = $this->annotatedAntlersNodes(
            '<svg><![CDATA[ a > b {{ x }} ]]><g>{{ y }}</g></svg>'
        );

        $this->assertSame(Context::KIND_DOCTYPE, $x->htmlContext->kind);
        $this->assertSame(['svg'], $x->htmlContext->elementStack);
        $this->assertFalse($x->htmlContext->isSafeForHtmlComments());
        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $y->htmlContext->kind);
        $this->assertSame(['svg', 'g'], $y->htmlContext->elementStack);
    }

    public function test_raw_text_close_tags_require_a_name_delimiter()
    {
        [$x, $y] = $this->annotatedAntlersNodes('<script>a = "</scriptx>"; {{ x }}</script>{{ y }}');

        $this->assertSame(Context::KIND_RAW_TEXT, $x->htmlContext->kind);
        $this->assertSame('script', $x->htmlContext->elementName);
        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $y->htmlContext->kind);
    }

    public function test_raw_text_close_tags_may_contain_whitespace_and_mixed_case()
    {
        [$x, $y] = $this->annotatedAntlersNodes('<SCRIPT>var a = {{ x }};</SCRIPT >{{ y }}');

        $this->assertSame(Context::KIND_RAW_TEXT, $x->htmlContext->kind);
        $this->assertSame('script', $x->htmlContext->elementName);
        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $y->htmlContext->kind);
    }

    public function test_script_escaped_regions_do_not_end_the_element_early()
    {
        [$x, $y] = $this->annotatedAntlersNodes(
            '<script><!-- document.write("<script></script>") {{ x }} --></script>{{ y }}'
        );

        $this->assertSame(Context::KIND_RAW_TEXT, $x->htmlContext->kind);
        $this->assertSame('script', $x->htmlContext->elementName);
        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $y->htmlContext->kind);
    }

    public function test_unquoted_values_ending_in_a_slash_do_not_self_close_the_element()
    {
        [$x, $y] = $this->annotatedAntlersNodes('<a href=/foo/>{{ x }}</a>{{ y }}');

        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $x->htmlContext->kind);
        $this->assertSame(['a'], $x->htmlContext->elementStack);
        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $y->htmlContext->kind);
        $this->assertSame([], $y->htmlContext->elementStack);
    }

    public function test_slash_inside_a_quoted_value_does_not_self_close_the_element()
    {
        [$x] = $this->annotatedAntlersNodes('<a href="/">{{ x }}</a>');

        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $x->htmlContext->kind);
        $this->assertSame(['a'], $x->htmlContext->elementStack);
    }

    public function test_raw_text_contexts_include_their_owning_element_in_the_stack()
    {
        // The reported stack is uniform across kinds: every enclosing
        // element, owner included — raw-text owners are not an exception.
        [$script] = $this->annotatedAntlersNodes('<div><script>{{ script }}</script></div>');
        [$area] = $this->annotatedAntlersNodes('<div><textarea>{{ area }}</textarea></div>');
        [$plain] = $this->annotatedAntlersNodes('<div><plaintext>{{ plain }}');

        $this->assertSame(['div', 'script'], $script->htmlContext->elementStack);
        $this->assertSame('script', $script->htmlContext->elementName);
        $this->assertSame(['div', 'textarea'], $area->htmlContext->elementStack);
        $this->assertSame(['div', 'plaintext'], $plain->htmlContext->elementStack);
    }

    public function test_an_html_image_start_tag_is_treated_as_img()
    {
        // The in-body rules rewrite `image` to `img`, so it is void in the
        // HTML namespace: nothing nests beneath it.
        [$x] = $this->annotatedAntlersNodes('<image src=a>{{ x }}');

        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $x->htmlContext->kind);
        $this->assertSame([], $x->htmlContext->elementStack);

        // SVG's image element is a normal foreign element and stays open.
        [$x] = $this->annotatedAntlersNodes('<svg><image href=a>{{ x }}</image></svg>');

        $this->assertSame(['svg', 'image'], $x->htmlContext->elementStack);
    }

    public function test_svg_tag_names_are_case_adjusted_in_reported_contexts()
    {
        [$x, $y] = $this->annotatedAntlersNodes('<svg><clippath id="{{ x }}"><textpath href="{{ y }}">');

        $this->assertSame('clipPath', $x->htmlContext->elementName);
        $this->assertSame('textPath', $y->htmlContext->elementName);
        $this->assertSame(['svg', 'clipPath'], $y->htmlContext->elementStack);

        [$x] = $this->annotatedAntlersNodes('<svg><foreignObject><div>{{ x }}</div></foreignObject></svg>');

        $this->assertSame(['svg', 'foreignObject', 'div'], $x->htmlContext->elementStack);

        // Outside SVG the name is an ordinary unknown HTML element and is
        // reported as written.
        [$x] = $this->annotatedAntlersNodes('<foreignobject>{{ x }}</foreignobject>');

        $this->assertSame(['foreignobject'], $x->htmlContext->elementStack);
    }

    public function test_a_stray_slash_before_whitespace_does_not_self_close_foreign_elements()
    {
        // `/ >` is a stray solidus, not self-closing syntax, so the foreign
        // element stays open and x sits inside circle.
        [$x] = $this->annotatedAntlersNodes('<svg><circle r="1"/ >{{ x }}</svg>');

        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $x->htmlContext->kind);
        $this->assertSame(['svg', 'circle'], $x->htmlContext->elementStack);
    }

    public function test_element_start_offsets_are_character_based_for_multibyte_documents()
    {
        $template = 'héllo <div class="{{ x }}">Content</div>';

        [$x] = $this->annotatedAntlersNodes($template);

        $this->assertSame(Context::KIND_ATTRIBUTE_VALUE, $x->htmlContext->kind);
        $this->assertSame(6, $x->htmlContext->elementStartOffset);
        $this->assertSame('<', mb_substr($template, $x->htmlContext->elementStartOffset, 1));
    }

    public function test_attributes_without_values_report_tag_open()
    {
        [$x] = $this->annotatedAntlersNodes('<input disabled {{ x }}>');

        $this->assertSame(Context::KIND_TAG_OPEN, $x->htmlContext->kind);
        $this->assertSame('input', $x->htmlContext->elementName);
    }

    public function test_processing_instructions_are_not_element_content()
    {
        [$v, $y] = $this->annotatedAntlersNodes('<?xml version="{{ v }}"?>{{ y }}');

        $this->assertSame(Context::KIND_DOCTYPE, $v->htmlContext->kind);
        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $y->htmlContext->kind);
    }

    public function test_comments_containing_gt_and_dash_runs_are_handled()
    {
        [$x, $y] = $this->annotatedAntlersNodes('<!-- a > b {{ x }} -->{{ y }}');

        $this->assertSame(Context::KIND_COMMENT, $x->htmlContext->kind);
        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $y->htmlContext->kind);

        [$z] = $this->annotatedAntlersNodes('<!----->{{ z }}');

        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $z->htmlContext->kind);
    }

    public function test_adjacent_regions_inside_an_attribute_value()
    {
        [$a, $b] = $this->annotatedAntlersNodes('<div class="{{ a }}{{ b }}">Content</div>');

        $this->assertSame(Context::KIND_ATTRIBUTE_VALUE, $a->htmlContext->kind);
        $this->assertSame(Context::KIND_ATTRIBUTE_VALUE, $b->htmlContext->kind);
        $this->assertSame('class', $b->htmlContext->attributeName);
    }

    public function test_alpine_and_livewire_attribute_names_are_reported_verbatim()
    {
        [$title, $event, $class, $key, $body] = $this->annotatedAntlersNodes(
            '<div x-data="{ label: \'{{ title }}\' }" @click.outside="run({{ event }})" :class="{{ classes }}" wire:key="post-{{ id }}">{{ body }}</div>'
        );

        foreach ([$title, $event, $class, $key] as $node) {
            $this->assertSame(Context::KIND_ATTRIBUTE_VALUE, $node->htmlContext->kind);
            $this->assertSame('div', $node->htmlContext->elementName);
        }

        $this->assertSame('x-data', $title->htmlContext->attributeName);
        $this->assertSame('@click.outside', $event->htmlContext->attributeName);
        $this->assertSame(':class', $class->htmlContext->attributeName);
        $this->assertSame('wire:key', $key->htmlContext->attributeName);
        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $body->htmlContext->kind);
        $this->assertSame(['div'], $body->htmlContext->elementStack);
    }

    public function test_nested_interpolation_tags_inherit_the_outer_html_context()
    {
        [$content, $attribute] = $this->annotatedAntlersNodes(
            '<article><div>{{ content = {collection:count from="blog"} }}</div><p title="{{ label = {collection:count from=\'news\'} }}">x</p></article>'
        );
        $contentInterpolation = array_values($content->processedInterpolationRegions)[0][0];
        $attributeInterpolation = array_values($attribute->processedInterpolationRegions)[0][0];

        $this->assertNotNull($contentInterpolation->htmlContext);
        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $contentInterpolation->htmlContext->kind);
        $this->assertSame('div', $contentInterpolation->htmlContext->elementName);
        $this->assertSame(['article', 'div'], $contentInterpolation->htmlContext->elementStack);

        $this->assertNotNull($attributeInterpolation->htmlContext);
        $this->assertSame(Context::KIND_ATTRIBUTE_VALUE, $attributeInterpolation->htmlContext->kind);
        $this->assertSame('p', $attributeInterpolation->htmlContext->elementName);
        $this->assertSame('title', $attributeInterpolation->htmlContext->attributeName);
        $this->assertSame(['article'], $attributeInterpolation->htmlContext->elementStack);
    }

    public function test_regions_at_the_document_boundaries()
    {
        [$x, $y] = $this->annotatedAntlersNodes('{{ x }}<p>hi</p>{{ y }}');

        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $x->htmlContext->kind);
        $this->assertSame([], $x->htmlContext->elementStack);
        $this->assertSame(Context::KIND_ELEMENT_CONTENT, $y->htmlContext->kind);
        $this->assertSame([], $y->htmlContext->elementStack);
    }

    public function test_dynamic_tag_names_are_detected_as_element_names()
    {
        [$tag] = $this->annotatedAntlersNodes('<{{ tag }} id="a">Content');

        $this->assertSame(Context::KIND_ELEMENT_NAME, $tag->htmlContext->kind);
        $this->assertSame(0, $tag->htmlContext->elementStartOffset);
    }

    public function test_element_start_offsets_are_correct_in_literals_between_antlers_regions()
    {
        $template = '{{ a }}<div {{ attrs }}>x</div>';

        [, $attrs] = $this->annotatedAntlersNodes($template);

        $this->assertSame(Context::KIND_TAG_OPEN, $attrs->htmlContext->kind);
        $this->assertSame('div', $attrs->htmlContext->elementName);
        $this->assertSame(7, $attrs->htmlContext->elementStartOffset);
        $this->assertSame('<', mb_substr($template, $attrs->htmlContext->elementStartOffset, 1));
    }

    public function test_annotation_is_skipped_when_disabled()
    {
        $this->assertFalse(ContextScanner::$enabled);

        foreach ($this->parseNodes('<p>{{ title }}</p>') as $node) {
            if ($node instanceof AntlersNode) {
                $this->assertNull($node->htmlContext);
            }
        }
    }

    public function test_documents_are_annotated_during_parse_when_enabled()
    {
        ContextScanner::$enabled = true;

        try {
            $parser = new DocumentParser();
            $parser->parse('<div class="{{ classes }}">{{ title }}</div>');

            $antlersNodes = [];

            foreach ($parser->getNodes() as $node) {
                if ($node instanceof AntlersNode) {
                    $antlersNodes[] = $node;
                }
            }

            $this->assertSame(Context::KIND_ATTRIBUTE_VALUE, $antlersNodes[0]->htmlContext->kind);
            $this->assertSame(Context::KIND_ELEMENT_CONTENT, $antlersNodes[1]->htmlContext->kind);
        } finally {
            ContextScanner::$enabled = false;
        }
    }
}
