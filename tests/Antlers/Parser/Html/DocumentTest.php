<?php

namespace Tests\Antlers\Parser\Html;

use Statamic\Facades\Antlers;
use Statamic\View\Antlers\Language\Analyzers\Html\AntlersNode as HtmlAntlersNode;
use Statamic\View\Antlers\Language\Analyzers\Html\Element;
use Statamic\View\Antlers\Language\Analyzers\Html\OpaqueNode;
use Statamic\View\Antlers\Language\Analyzers\Html\Text;
use Statamic\View\Antlers\Language\Analyzers\Html\TextEdge;
use Statamic\View\Antlers\Language\Nodes\AntlersNode;
use Statamic\View\Antlers\Language\Parser\DocumentParser;
use Tests\Antlers\ParserTestCase;
use Tests\Antlers\Runtime\Support\WhitespaceTrimmer;

class DocumentTest extends ParserTestCase
{
    public function test_antlers_facade_exposes_the_fluent_html_graph()
    {
        $html = Antlers::html('<main><p>{{ title }}</p></main>');

        $html->first('p')->addClass('lead');

        $this->assertSame('<main><p class="lead">{{ title }}</p></main>', $html->toHtml());
    }

    public function test_paired_antlers_regions_expose_lazy_content_owned_elements_and_unwrapping()
    {
        $html = Antlers::html(
            'Before{{ wrapper }} <section><p>Outer</p>'
            .'{{ wrapper }}<code>Inner</code>{{ /wrapper }}'
            .'</section> {{ /wrapper }}After'
        );
        $handles = new \ReflectionProperty($html, 'handles');
        $regions = $html->regions('wrapper');

        $this->assertInstanceOf(\Illuminate\Support\LazyCollection::class, $regions);
        $this->assertSame([], $handles->getValue($html));

        $outer = $regions->first();

        $this->assertSame('wrapper', $outer->name());
        $this->assertSame(
            ['section', 'p'],
            $outer->ownedElements()->map(fn (Element $element) => $element->name())->all()
        );
        $this->assertCount(3, $outer->content()->nodes());

        $outer->unwrap();

        $this->assertSame(
            'Before <section><p>Outer</p>{{ wrapper }}<code>Inner</code>{{ /wrapper }}</section> After',
            $html->toHtml()
        );
    }

    public function test_node_sequences_rewrite_text_edges_without_owning_rewrite_policy()
    {
        $html = Antlers::html('<main><!-- before --><span>alpha</span><span>omega</span><!-- after --></main>');
        $main = $html->first('main');

        $main->content()->rewriteTextEdges(
            fn ($text, $edge) => $edge === TextEdge::LEADING ? '['.$text : $text.']'
        );

        $this->assertSame(
            '<main><!-- before --><span>[alpha</span><span>omega]</span><!-- after --></main>',
            $html->toHtml()
        );
    }

    public function test_regions_offer_direct_edge_rewriting_and_static_parameter_access()
    {
        $html = Antlers::html(
            '{{ wrapper mode="compact" }} <p> content </p> {{ /wrapper }}'
        );
        $region = $html->regions('wrapper')->first();

        $this->assertSame('compact', $region->staticParameterValue('MODE'));
        $this->assertSame('fallback', $region->staticParameterValue('missing', 'fallback'));

        $result = $region->rewriteTextEdges(
            fn ($text, $edge) => $edge === TextEdge::LEADING
                ? ltrim($text)
                : rtrim($text)
        );

        $this->assertSame($region, $result);
        $this->assertSame(
            '{{ wrapper mode="compact" }}<p>content</p>{{ /wrapper }}',
            $html->toHtml()
        );
    }

    public function test_element_content_is_a_fluent_getter_and_setter()
    {
        $html = Antlers::html('<main><p>Old <em>content</em>.</p></main>');
        $paragraph = $html->first('p');
        $replacement = $html->createElement('strong')->append('New content');

        $result = $paragraph->content($replacement);

        $this->assertSame($paragraph, $result);
        $this->assertSame([$replacement], $paragraph->content()->nodes()->all());
        $this->assertSame(
            '<main><p><strong>New content</strong></p></main>',
            $html->toHtml()
        );

        $paragraph->content('Plain text');
        $this->assertSame('<main><p>Plain text</p></main>', $html->toHtml());

        $paragraph->content(null);
        $this->assertSame('<main><p></p></main>', $html->toHtml());
    }

    public function test_region_content_can_be_replaced_and_then_unwrapped_fluently()
    {
        $html = Antlers::html(
            'Before{{ wrapper }}<p>Old</p>{{ /wrapper }}After'
        );
        $region = $html->regions('wrapper')->first();
        $replacement = $html->createElement('strong')->append('New');

        $result = $region->content($replacement)->unwrap();

        $this->assertSame($region, $result);
        $this->assertSame('Before<strong>New</strong>After', $html->toHtml());
    }

    public function test_failed_content_replacement_keeps_existing_content_intact()
    {
        $html = Antlers::html('<main><p>Original</p></main>');
        $other = Antlers::html('<strong>Foreign</strong>');
        $paragraph = $html->first('p');

        try {
            $paragraph->content($other->first('strong'));
            $this->fail('Expected cross-document content to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame(
                'HTML graph nodes cannot be moved between documents.',
                $exception->getMessage()
            );
        }

        $this->assertSame('<main><p>Original</p></main>', $html->toHtml());
    }

    public function test_node_sequence_edge_descent_is_caller_controlled()
    {
        $html = Antlers::html('<main><x-card>component</x-card><span>ordinary</span></main>');
        $main = $html->first('main');

        $main->content()->rewriteTextEdges(
            fn ($text, $edge) => strtoupper($text),
            fn (Element $element) => ! str_contains((string) $element->name(), '-')
        );

        $this->assertSame(
            '<main><x-card>component</x-card><span>ORDINARY</span></main>',
            $html->toHtml()
        );
    }

    public function test_element_ancestor_queries_are_lazy_and_include_self_when_requested()
    {
        $html = Antlers::html('<main><section><p>Text</p></section></main>');
        $paragraph = $html->first('p');

        $this->assertInstanceOf(
            \Illuminate\Support\LazyCollection::class,
            $paragraph->ancestorsAndSelf()
        );
        $this->assertSame(
            ['p', 'section', 'main'],
            $paragraph->ancestorsAndSelf()->map(fn (Element $element) => $element->name())->all()
        );
        $this->assertSame(
            ['section', 'main'],
            $paragraph->ancestors()->map(fn (Element $element) => $element->name())->all()
        );
    }

    public function test_boundary_whitespace_trimming_can_target_elements_and_follow_nested_inline_content()
    {
        $html = Antlers::html(
            '<main>'
            .'<p data-trim><span>  <em>  Hello  </em>  </span></p>'
            .'<p data-trim> {{ prefix }} body {{ suffix }} </p>'
            .'<p>  Not selected.  </p>'
            .'</main>'
        );
        (new WhitespaceTrimmer)->trim(
            $html->elements()->filter(
                fn (Element $element) => $element->hasAttribute('data-trim')
            )
        );

        $this->assertSame(
            '<main>'
            .'<p data-trim><span><em>Hello</em></span></p>'
            .'<p data-trim>{{ prefix }} body {{ suffix }}</p>'
            .'<p>  Not selected.  </p>'
            .'</main>',
            $html->toHtml()
        );
    }

    public function test_selected_element_rewrites_can_remain_lazy()
    {
        $html = Antlers::html('<p>  Hello.  </p>');
        $handles = new \ReflectionProperty($html, 'handles');
        $selected = (function () use ($html) {
            yield $html->first('p');
        })();

        $trivia = new WhitespaceTrimmer;

        $this->assertSame([], $handles->getValue($html));
        $this->assertFalse($html->isDirty());

        $trivia->trim($selected);

        $this->assertNotSame([], $handles->getValue($html));
        $this->assertSame('<p>Hello.</p>', $html->toHtml());
    }

    public function test_boundary_whitespace_trimming_is_conservative_at_sensitive_and_semantic_boundaries()
    {
        $html = Antlers::html(
            '<main>'
            .'<pre><span data-trim>  Native preservation.  </span></pre>'
            .'<p data-trim data-preserve-whitespace>  Custom preservation.  </p>'
            .'<p data-trim><x-label></x-label>  After component.  </p>'
            .'<svg><text data-trim>  Foreign whitespace.  </text></svg>'
            .'</main>'
        );
        (new WhitespaceTrimmer(
            [],
            fn (Element $element) => $element->hasAttribute('data-preserve-whitespace')
        ))->trim(
            $html->elements()->filter(
                fn (Element $element) => $element->hasAttribute('data-trim')
            )
        );

        $this->assertSame(
            '<main>'
            .'<pre><span data-trim>  Native preservation.  </span></pre>'
            .'<p data-trim data-preserve-whitespace>  Custom preservation.  </p>'
            .'<p data-trim><x-label></x-label>  After component.</p>'
            .'<svg><text data-trim>  Foreign whitespace.  </text></svg>'
            .'</main>',
            $html->toHtml()
        );
    }

    public function test_dirty_rendering_does_not_materialize_untouched_arena_nodes()
    {
        $html = Antlers::html('<main><p>one</p><p>two</p></main>');
        $handles = new \ReflectionProperty($html, 'handles');

        $this->assertSame([], $handles->getValue($html));

        $html->first('p')->addClass('lead');

        $this->assertSame('<main><p class="lead">one</p><p>two</p></main>', $html->toHtml());
        $this->assertCount(1, $handles->getValue($html));
    }

    public function test_antlers_nodes_lazily_resolve_their_parent_elements_and_siblings()
    {
        [$parser, $nodes] = $this->parseHtml('<section><p>before {{ title }} <em>{{ value }}</em> after</p></section>');
        [$title, $value] = $nodes;

        $this->assertSame('p', $title->parentElement()->name());
        $this->assertSame('em', $value->parentElement()->name());
        $this->assertInstanceOf(HtmlAntlersNode::class, $title->htmlNode());
        $this->assertSame($title->htmlNode(), $parser->html()->node($title));
        $this->assertSame($title->htmlNode(), $title->htmlNode());
        $this->assertSame('before ', $title->previousHtmlSibling()->toHtml());
        $this->assertSame(' ', $title->nextHtmlSibling()->toHtml());
    }

    public function test_ordinary_parsing_does_not_eagerly_build_the_html_graph()
    {
        [$parser, $nodes] = $this->parseHtml('<p>{{ title }}</p>');
        $documentProperty = new \ReflectionProperty($parser, 'htmlDocument');
        $nodeProperty = new \ReflectionProperty($nodes[0], 'htmlGraphNode');

        $this->assertNull($documentProperty->getValue($parser));
        $this->assertNull($nodeProperty->getValue($nodes[0]));

        $this->assertSame('p', $nodes[0]->parentElement()->name());
        $this->assertNotNull($documentProperty->getValue($parser));
        $this->assertSame($nodes[0]->htmlNode(), $nodeProperty->getValue($nodes[0]));
    }

    public function test_antlers_regions_in_an_opening_tag_belong_to_the_owning_element()
    {
        [, $nodes] = $this->parseHtml('<a href="{{ url }}" {{ attrs }}>{{ label }}</a>');
        [$url, $attrs, $label] = $nodes;

        $this->assertSame('a', $url->parentElement()->name());
        $this->assertSame('a', $attrs->parentElement()->name());
        $this->assertSame('a', $label->parentElement()->name());
        $this->assertSame($attrs->htmlNode(), $url->nextHtmlSibling());
        $this->assertSame($url->htmlNode(), $attrs->previousHtmlSibling());
    }

    public function test_the_graph_recovers_useful_parents_from_bogus_nesting_and_round_trips_source()
    {
        $template = '<main><p>{{ one }}<div>{{ two }}</bogus><span>{{ three }}</main>';
        [$parser, $nodes] = $this->parseHtml($template);

        $this->assertSame('p', $nodes[0]->parentElement()->name());
        $this->assertSame('div', $nodes[1]->parentElement()->name());
        $this->assertSame('span', $nodes[2]->parentElement()->name());
        $this->assertSame($template, $parser->html()->toHtml());
    }

    public function test_html_end_tags_respect_special_and_scope_boundaries()
    {
        [, $special] = $this->parseHtml('<x-box><div>{{ before }}</x-box>{{ after }}');
        [, $inline] = $this->parseHtml('<x-box><span>{{ before }}</x-box>{{ after }}');
        [, $table] = $this->parseHtml('<div><table>{{ before }}</div>{{ after }}');
        [, $heading] = $this->parseHtml('<h1><span>{{ before }}</h2>{{ after }}');
        [, $template] = $this->parseHtml('<template><table><tr><td>{{ before }}</template>{{ after }}');
        [$paragraphParser, $paragraph] = $this->parseHtml('<div></p>{{ after }}');

        $this->assertSame($special[0]->parentElement(), $special[1]->parentElement());
        $this->assertSame('div', $special[1]->parentElement()->name());
        $this->assertSame('span', $inline[0]->parentElement()->name());
        $this->assertNull($inline[1]->parentElement());
        $this->assertSame('div', $table[0]->parentElement()->name());
        $this->assertSame('div', $table[1]->parentElement()->name());
        $this->assertSame('span', $heading[0]->parentElement()->name());
        $this->assertNull($heading[1]->parentElement());
        $this->assertSame('td', $template[0]->parentElement()->name());
        $this->assertNull($template[1]->parentElement());
        $this->assertSame('div', $paragraph[0]->parentElement()->name());
        $this->assertTrue($paragraphParser->html()->first('p')->isSynthetic());
        $this->assertSame('<div></p>{{ after }}', $paragraphParser->html()->toHtml());
    }

    public function test_unrelated_mutations_preserve_bogus_markup_and_missing_end_tags()
    {
        [$parser] = $this->parseHtml('<main><p id="old">Text<div></bogus><span>More</main>');

        $parser->html()->first('p')->setAttribute('id', 'new');

        $this->assertSame(
            '<main><p id="new">Text<div></bogus><span>More</main>',
            $parser->html()->toHtml()
        );
    }

    public function test_heading_start_tags_close_open_headings_like_the_html_tree_builder()
    {
        [, $nodes] = $this->parseHtml('<h1>{{ one }}<em>text</em><h2>{{ two }}</h2>');

        $this->assertSame('h1', $nodes[0]->parentElement()->name());
        $this->assertSame('h2', $nodes[1]->parentElement()->name());
        $this->assertSame($nodes[0]->parentElement(), $nodes[1]->parentElement()->previousSibling());
    }

    public function test_heading_starts_do_not_close_through_intervening_elements()
    {
        [, $nested] = $this->parseHtml(
            '<h1><section><span>{{ outer }}<h2>{{ inner }}'
        );
        [, $paragraph] = $this->parseHtml('<h1><p>{{ paragraph }}<h2>{{ next }}');

        $this->assertSame('h2', $nested[1]->parentElement()->name());
        $this->assertSame('section', $nested[1]->parentElement()->closest('section')->name());
        $this->assertSame('h1', $nested[1]->parentElement()->closest('h1')->name());
        $this->assertSame('p', $paragraph[0]->parentElement()->name());
        $this->assertSame('h2', $paragraph[1]->parentElement()->name());
        $this->assertNull($paragraph[1]->parentElement()->closest('h1'));
    }

    public function test_nested_button_recovery_reconstructs_formatting_before_the_new_button()
    {
        [, $nodes] = $this->parseHtml(
            '<button><em><b><span>{{ before }}<button>{{ after }}'
        );

        $this->assertSame('span', $nodes[0]->parentElement()->name());
        $this->assertSame('button', $nodes[1]->parentElement()->name());
        $this->assertSame('b', $nodes[1]->parentElement()->parentElement()->name());
        $this->assertSame('em', $nodes[1]->parentElement()->parentElement()->parentElement()->name());
        $this->assertNull($nodes[1]->parentElement()->closest('span'));
    }

    public function test_nested_anchor_start_uses_formatting_adoption_before_inserting_the_new_anchor()
    {
        [, $block] = $this->parseHtml(
            '<a><section>{{ block_before }}<a>{{ block_after }}'
        );
        [, $inline] = $this->parseHtml(
            '<a><span>{{ inline_before }}<a>{{ inline_after }}'
        );
        [, $layered] = $this->parseHtml(
            '<a><div><i>{{ layered_before }}<a>{{ layered_after }}'
        );

        $this->assertSame('a', $block[0]->parentElement()->name());
        $this->assertSame('section', $block[0]->parentElement()->parentElement()->name());
        $this->assertSame('a', $block[1]->parentElement()->name());
        $this->assertSame('section', $block[1]->parentElement()->parentElement()->name());
        $this->assertNotSame($block[0]->parentElement(), $block[1]->parentElement());

        $this->assertSame('span', $inline[0]->parentElement()->name());
        $this->assertSame('a', $inline[0]->parentElement()->parentElement()->name());
        $this->assertSame('a', $inline[1]->parentElement()->name());
        $this->assertNull($inline[1]->parentElement()->closest('span'));

        $this->assertSame('i', $layered[0]->parentElement()->name());
        $this->assertSame('a', $layered[0]->parentElement()->parentElement()->name());
        $this->assertSame('a', $layered[1]->parentElement()->name());
        $this->assertSame('i', $layered[1]->parentElement()->parentElement()->name());
        $this->assertSame('div', $layered[1]->parentElement()->parentElement()->parentElement()->name());
    }

    public function test_optional_list_item_end_tags_respect_list_item_scope()
    {
        [, $nodes] = $this->parseHtml(
            '<ul><li>{{ outer }}<table><tr><td><ul><li>{{ inner }}</ul></table><li>{{ next }}</ul>'
        );

        $this->assertSame('li', $nodes[0]->parentElement()->name());
        $this->assertSame('li', $nodes[1]->parentElement()->name());
        $this->assertSame('li', $nodes[2]->parentElement()->name());
        $this->assertNotSame($nodes[0]->parentElement(), $nodes[1]->parentElement());
        $this->assertNotSame($nodes[0]->parentElement(), $nodes[2]->parentElement());
        $this->assertSame('ul', $nodes[1]->parentElement()->parentElement()->name());
    }

    public function test_list_item_and_description_item_starts_close_open_paragraphs()
    {
        [, $nodes] = $this->parseHtml(
            '<p>{{ paragraph }}<li>{{ item }}<p>{{ next_paragraph }}<dt>{{ term }}'
        );

        $this->assertSame('p', $nodes[0]->parentElement()->name());
        $this->assertSame('li', $nodes[1]->parentElement()->name());
        $this->assertSame('p', $nodes[2]->parentElement()->name());
        $this->assertSame('dt', $nodes[3]->parentElement()->name());
        $this->assertNull($nodes[1]->parentElement()->closest('p'));
        $this->assertNull($nodes[3]->parentElement()->closest('p'));
    }

    public function test_option_start_only_closes_an_option_when_it_is_current()
    {
        [, $nodes] = $this->parseHtml(
            '<option>{{ first }}<option>{{ second }}<p>{{ paragraph }}<option>{{ nested }}'
        );
        [, $groups] = $this->parseHtml(
            '<optgroup>{{ outer }}<option>{{ option }}<optgroup>{{ nested }}'
                .'<select><optgroup>{{ selected_outer }}<optgroup>{{ selected_next }}'
        );

        $this->assertSame('option', $nodes[0]->parentElement()->name());
        $this->assertSame('option', $nodes[1]->parentElement()->name());
        $this->assertNotSame($nodes[0]->parentElement(), $nodes[1]->parentElement());
        $this->assertSame('p', $nodes[2]->parentElement()->name());
        $this->assertSame('option', $nodes[3]->parentElement()->name());
        $this->assertSame($nodes[2]->parentElement(), $nodes[3]->parentElement()->parentElement());
        $this->assertSame($groups[0]->parentElement(), $groups[2]->parentElement()->parentElement());
        $this->assertSame('optgroup', $groups[2]->parentElement()->name());
        $this->assertNotSame($groups[3]->parentElement(), $groups[4]->parentElement());
        $this->assertSame('select', $groups[4]->parentElement()->parentElement()->name());
    }

    public function test_form_end_tags_generate_implied_end_tags_before_removing_the_form_pointer()
    {
        [, $nodes] = $this->parseHtml(
            '<form><section><option>{{ option }}</form>{{ after }}'
                .'<form><div><p>{{ paragraph }}</form>{{ final }}'
        );

        $this->assertSame('option', $nodes[0]->parentElement()->name());
        $this->assertSame('section', $nodes[1]->parentElement()->name());
        $this->assertSame('p', $nodes[2]->parentElement()->name());
        $this->assertSame('div', $nodes[3]->parentElement()->name());
    }

    public function test_item_start_recovery_stops_at_special_element_boundaries()
    {
        [, $nodes] = $this->parseHtml(
            '<ul><li><span>{{ first }}<li>{{ next }}</ul>'
                .'<ul><li><button><span>{{ nested }}<li>{{ nested_item }}</ul>'
                .'<dl><dt><button><span><dd>{{ nested_description }}</dl>'
        );

        $this->assertNotSame($nodes[0]->parentElement()->closest('li'), $nodes[1]->parentElement());
        $this->assertSame('span', $nodes[2]->parentElement()->name());
        $this->assertSame($nodes[2]->parentElement(), $nodes[3]->parentElement()->parentElement());
        $this->assertSame('button', $nodes[3]->parentElement()->closest('button')->name());
        $this->assertSame('button', $nodes[4]->parentElement()->closest('button')->name());
        $this->assertSame('dt', $nodes[4]->parentElement()->closest('dt')->name());
    }

    public function test_new_table_sections_close_previous_rows_cells_and_sections()
    {
        [$parser, $nodes] = $this->parseHtml(
            '<table><tbody><tr><td>{{ one }}<tbody><tr><td>{{ two }}</table>'
        );
        $sections = $parser->html()->elements('tbody');

        $this->assertCount(2, $sections);
        $this->assertSame('td', $nodes[0]->parentElement()->name());
        $this->assertSame('td', $nodes[1]->parentElement()->name());
        $this->assertSame($sections[0]->parent(), $sections[1]->parent());
        $this->assertSame($sections[0], $sections[1]->previousSibling());
    }

    public function test_missing_table_bodies_and_rows_are_synthetic_and_source_transparent()
    {
        [$parser, $nodes] = $this->parseHtml('<table><td>{{ cell }}</td></table>');
        $document = $parser->html();
        $cell = $nodes[0]->parentElement();
        $row = $cell->parentElement();
        $body = $row->parentElement();

        $this->assertSame('td', $cell->name());
        $this->assertSame('tr', $row->name());
        $this->assertSame('tbody', $body->name());
        $this->assertTrue($row->isSynthetic());
        $this->assertTrue($body->isSynthetic());
        $this->assertSame('<table><td>{{ cell }}</td></table>', $document->toHtml());

        $cell->setAttribute('data-cell', 'yes');

        $this->assertSame(
            '<table><td data-cell="yes">{{ cell }}</td></table>',
            $document->toHtml()
        );
    }

    public function test_table_structure_start_tags_are_ignored_without_a_table_or_template()
    {
        $template = '<div><tr>{{ row }}</tr><td>{{ cell }}</td><tbody>{{ body }}</tbody>'
            .'<col>{{ after_col }}</div><template><td>{{ template_cell }}</td></template>';
        [$parser, $nodes] = $this->parseHtml($template);

        $this->assertSame('div', $nodes[0]->parentElement()->name());
        $this->assertSame('div', $nodes[1]->parentElement()->name());
        $this->assertSame('div', $nodes[2]->parentElement()->name());
        $this->assertSame('div', $nodes[3]->parentElement()->name());
        $this->assertSame('td', $nodes[4]->parentElement()->name());
        $this->assertSame('template', $nodes[4]->parentElement()->parentElement()->name());
        $this->assertSame($template, $parser->html()->toHtml());
    }

    public function test_table_content_is_foster_parented_in_the_graph_but_printed_at_its_source_anchor()
    {
        $template = '<div><table>before {{ value }}<div class="inside">{{ nested }}</div>'
            .'<tr><td>{{ cell }}</td></tr> after</table></div>';
        [$parser, $nodes] = $this->parseHtml($template);
        $document = $parser->html();
        $table = $document->first('table');

        $this->assertSame('div', $nodes[0]->parentElement()->name());
        $this->assertSame('div', $nodes[1]->parentElement()->name());
        $this->assertTrue($nodes[1]->parentElement()->hasAttribute('class'));
        $this->assertSame('td', $nodes[2]->parentElement()->name());
        $this->assertSame($table->parent(), $nodes[0]->htmlNode()->parent());
        $this->assertTrue($nodes[0]->htmlNode()->isSourceAnchored());
        $this->assertSame($template, $document->toHtml());

        $nodes[2]->parentElement()->setAttribute('data-cell', 'yes');

        $this->assertSame(
            '<div><table>before {{ value }}<div class="inside">{{ nested }}</div>'
            .'<tr><td data-cell="yes">{{ cell }}</td></tr> after</table></div>',
            $document->toHtml()
        );
    }

    public function test_removing_a_table_releases_fostered_nodes_to_their_graph_location()
    {
        [$parser] = $this->parseHtml('<div><table>before <strong>content</strong> after</table></div>');
        $document = $parser->html();

        $document->first('table')->remove();

        $this->assertSame('<div><strong>content</strong>before  after</div>', $document->toHtml());
    }

    public function test_foster_parented_elements_precede_the_table_and_character_data_follows_it()
    {
        [$parser] = $this->parseHtml('<div><table>before <strong>content</strong> after</table></div>');
        $children = $parser->html()->first('div')->children();

        $this->assertSame('strong', $children[0]->name());
        $this->assertSame('table', $children[1]->name());
        $this->assertSame('before ', $children[2]->content());
        $this->assertSame(' after', $children[3]->content());
    }

    public function test_hidden_inputs_remain_inside_tables()
    {
        [$parser] = $this->parseHtml('<div><table><input type="hidden" name="token"><tr><td>Cell</table></div>');
        $input = $parser->html()->first('input');

        $this->assertSame('table', $input->parentElement()->name());
        $this->assertSame('token', $input->attr('name'));
    }

    public function test_forms_in_table_modes_are_inserted_empty_without_owning_following_rows()
    {
        [$parser, $nodes] = $this->parseHtml(
            '<div><table><form id="filters"><tr><td>{{ cell }}</td></tr></form></table></div>'
        );
        $document = $parser->html();
        $form = $document->first('form');

        $this->assertSame('table', $form->parentElement()->name());
        $this->assertCount(0, $form->children());
        $this->assertSame('td', $nodes[0]->parentElement()->name());
        $this->assertNull($nodes[0]->parentElement()->closest('form'));
    }

    public function test_non_whitespace_after_colgroup_is_reprocessed_in_table_mode()
    {
        [$parser] = $this->parseHtml('<div><table><colgroup> x<tr><td>Cell</table></div>');
        $document = $parser->html();
        $columnGroup = $document->first('colgroup');
        $divisionChildren = $document->first('div')->children();

        $this->assertSame(' ', $columnGroup->firstChild()->content());
        $this->assertSame('table', $divisionChildren[0]->name());
        $this->assertSame('x', $divisionChildren[1]->content());
    }

    public function test_table_structural_starts_close_an_open_cell_before_reprocessing()
    {
        [, $nodes] = $this->parseHtml(
            '<div><table><tbody><tr><td>{{ cell }}<caption>{{ caption }}</caption>{{ after }}</td></table></div>'
        );

        $this->assertSame('td', $nodes[0]->parentElement()->name());
        $this->assertSame('caption', $nodes[1]->parentElement()->name());
        $this->assertSame('table', $nodes[1]->parentElement()->parentElement()->name());
        $this->assertSame('div', $nodes[2]->parentElement()->name());
    }

    public function test_relative_mutations_on_fostered_nodes_use_their_source_location()
    {
        [$parser, $nodes] = $this->parseHtml('<div><table>before {{ value }} after</table></div>');
        $document = $parser->html();
        $value = $nodes[0]->htmlNode();

        $value->before('[')->after(']');

        $this->assertSame(
            '<div><table>before [{{ value }}] after</table></div>',
            $document->toHtml()
        );

        $value->replaceWith('{{ replacement }}');

        $this->assertSame(
            '<div><table>before [{{ replacement }}] after</table></div>',
            $document->toHtml()
        );
    }

    public function test_a_table_start_inside_table_mode_closes_the_previous_table()
    {
        [$parser, $nodes] = $this->parseHtml('<div><table><table><tr><td>{{ cell }}</td></tr></table></div>');
        $tables = $parser->html()->elements('table');

        $this->assertCount(2, $tables);
        $this->assertSame($tables[0]->parent(), $tables[1]->parent());
        $this->assertSame($tables[0], $tables[1]->previousSibling());
        $this->assertSame($tables[1], $nodes[0]->parentElement()->closest('table'));
    }

    public function test_block_starts_close_paragraphs_through_inline_descendants()
    {
        [, $nodes] = $this->parseHtml('<p><strong>{{ one }}</strong><section>{{ two }}</section>');

        $this->assertSame('strong', $nodes[0]->parentElement()->name());
        $this->assertSame('section', $nodes[1]->parentElement()->name());
        $this->assertNull($nodes[1]->parentElement()->closest('p'));
    }

    public function test_misnested_formatting_elements_are_reconstructed_without_changing_source()
    {
        $template = '<p><b><i>{{ one }}</b>{{ two }}</i>{{ three }}</p>';
        [$parser, $nodes] = $this->parseHtml($template);

        $this->assertSame('i', $nodes[0]->parentElement()->name());
        $this->assertSame('i', $nodes[1]->parentElement()->name());
        $this->assertNotSame($nodes[0]->parentElement(), $nodes[1]->parentElement());
        $this->assertTrue($nodes[1]->parentElement()->isSynthetic());
        $this->assertSame('p', $nodes[2]->parentElement()->name());
        $this->assertSame($template, $parser->html()->toHtml());

        $nodes[1]->htmlNode()->source('{{ changed }}');

        $this->assertSame(
            '<p><b><i>{{ one }}</b>{{ changed }}</i>{{ three }}</p>',
            $parser->html()->toHtml()
        );
    }

    public function test_formatting_reconstruction_waits_until_content_after_a_block_start()
    {
        [, $nodes] = $this->parseHtml(
            '<i><em>{{ before }}</i><div><b>{{ after }}'
        );

        $this->assertSame('em', $nodes[0]->parentElement()->name());
        $this->assertSame('i', $nodes[0]->parentElement()->parentElement()->name());
        $this->assertSame('b', $nodes[1]->parentElement()->name());
        $this->assertSame('em', $nodes[1]->parentElement()->parentElement()->name());
        $this->assertSame('div', $nodes[1]->parentElement()->parentElement()->parentElement()->name());
        $this->assertNull($nodes[1]->parentElement()->closest('i'));
    }

    public function test_active_formatting_limits_three_equivalent_entries_but_keeps_distinct_attributes()
    {
        [, $equivalent] = $this->parseHtml(
            '<div><b><b><b><b><i>{{ before }}</div>{{ after }}'
        );
        [, $distinct] = $this->parseHtml(
            '<div><b data-n="1"><b data-n="2"><b data-n="3"><b data-n="4"><i>{{ before }}</div>{{ after }}'
        );

        $equivalentNames = [];
        $parent = $equivalent[1]->parentElement();

        while ($parent !== null) {
            $equivalentNames[] = $parent->name();
            $parent = $parent->parentElement();
        }

        $distinctNames = [];
        $parent = $distinct[1]->parentElement();

        while ($parent !== null) {
            $distinctNames[] = $parent->name();
            $parent = $parent->parentElement();
        }

        $this->assertSame(['i', 'b', 'b', 'b'], $equivalentNames);
        $this->assertSame(['i', 'b', 'b', 'b', 'b'], $distinctNames);
    }

    public function test_cached_formatting_handles_receive_late_closing_markup()
    {
        $template = '<i><i></i>';
        [$parser] = $this->parseHtml($template);

        // Comparing equivalent formatting attributes materializes both
        // handles before the closing tag is encountered.
        $this->assertSame($template, $parser->html()->markDirty()->toHtml());
    }

    public function test_formatting_end_tags_target_the_active_element_identity_not_an_older_matching_name()
    {
        [, $strike] = $this->parseHtml(
            '<strike><div><strike></div></strike><span>{{ marker }}'
        );
        [, $font] = $this->parseHtml(
            '<font><blockquote><font><i></blockquote></font><span>{{ marker }}'
        );

        $this->assertSame('span', $strike[0]->parentElement()->name());
        $this->assertSame('strike', $strike[0]->parentElement()->parentElement()->name());
        $this->assertSame('span', $font[0]->parentElement()->name());
        $this->assertSame('i', $font[0]->parentElement()->parentElement()->name());
        $this->assertSame('font', $font[0]->parentElement()->parentElement()->parentElement()->name());
    }

    public function test_formatting_end_tags_below_a_table_scope_boundary_are_ignored()
    {
        $template = '<b><table></b></table><span>{{ marker }}';
        [$parser, $nodes] = $this->parseHtml($template);

        $this->assertSame('span', $nodes[0]->parentElement()->name());
        $this->assertSame('b', $nodes[0]->parentElement()->parentElement()->name());
        $this->assertSame($template, $parser->html()->markDirty()->toHtml());
    }

    public function test_ignored_formatting_end_tags_after_foster_parenting_remain_in_source_order()
    {
        $template = '<table><i></table></i>{{ marker }}';
        [$parser, $nodes] = $this->parseHtml($template);

        $this->assertNull($nodes[0]->parentElement());
        $this->assertSame($template, $parser->html()->markDirty()->toHtml());
    }

    public function test_elements_after_fostered_formatting_reconstruction_keep_their_source_anchor()
    {
        $template = '<table><b><i><svg><select></b><p>{{ marker }}';
        [$parser, $nodes] = $this->parseHtml($template);

        $this->assertSame('p', $nodes[0]->parentElement()->name());
        $this->assertSame($template, $parser->html()->markDirty()->toHtml());
    }

    public function test_nested_table_starts_follow_the_latest_fostered_source_anchor()
    {
        $template = '<table><b></table>{{ first }}<table>{{ second }}<g><table>';
        [$parser] = $this->parseHtml($template);

        $this->assertSame($template, $parser->html()->markDirty()->toHtml());
    }

    public function test_ignored_end_tags_keep_their_source_position_after_table_recovery()
    {
        $template = '<table><i><b><p></i></p></td>';
        [$parser] = $this->parseHtml($template);

        $this->assertSame($template, $parser->html()->markDirty()->toHtml());
    }

    public function test_antlers_regions_keep_their_source_position_after_table_recovery()
    {
        $template = '<table><i><b><p></i></p>{{ marker }}';
        [$parser] = $this->parseHtml($template);

        $this->assertSame($template, $parser->html()->markDirty()->toHtml());
    }

    public function test_table_starts_follow_a_source_anchored_select_that_they_close()
    {
        $template = '<i><div><b><p></i><table>{{ marker }}<select><table>';
        [$parser] = $this->parseHtml($template);

        $this->assertSame($template, $parser->html()->markDirty()->toHtml());
    }

    public function test_foreign_table_like_names_cannot_loop_table_reprocessing()
    {
        $template = '<table><svg><td><div></svg>{{ marker }}<form><td><table>';
        [$parser] = $this->parseHtml($template);

        $this->assertSame($template, $parser->html()->markDirty()->toHtml());
    }

    public function test_descendants_of_reconstructed_formatting_reuse_its_nested_source_anchor()
    {
        $template = '<table><b><tr><option></tr>';
        [$parser] = $this->parseHtml($template);

        $this->assertSame($template, $parser->html()->markDirty()->toHtml());
    }

    public function test_source_anchor_sequences_do_not_cross_an_explicit_table_close()
    {
        $template = '<div><b></div> text <table>{{ marker }}</table><tbody>';
        [$parser] = $this->parseHtml($template);

        $this->assertSame($template, $parser->html()->markDirty()->toHtml());
    }

    public function test_formatting_end_tags_cross_non_scoping_foreign_descendants()
    {
        $template = '<b><svg><g></b>{{ marker }}';
        [$parser, $nodes] = $this->parseHtml($template);

        $this->assertNull($nodes[0]->parentElement());
        $this->assertSame($template, $parser->html()->markDirty()->toHtml());
    }

    public function test_active_formatting_is_reconstructed_after_an_implied_block_boundary()
    {
        $template = '<p><b>{{ before }}<div class="old">{{ inside }}</b>{{ after }}</div>{{ tail }}';
        [$parser, $nodes] = $this->parseHtml($template);
        $document = $parser->html();

        $this->assertSame('b', $nodes[0]->parentElement()->name());
        $this->assertSame('b', $nodes[1]->parentElement()->name());
        $this->assertTrue($nodes[1]->parentElement()->isSynthetic());
        $this->assertSame('div', $nodes[1]->parentElement()->parentElement()->name());
        $this->assertSame('div', $nodes[2]->parentElement()->name());
        $this->assertNull($nodes[3]->parentElement());
        $this->assertSame($template, $document->toHtml());

        $document->first('div')->setAttribute('class', 'new');

        $this->assertSame(
            '<p><b>{{ before }}<div class="new">{{ inside }}</b>{{ after }}</div>{{ tail }}',
            $document->toHtml()
        );
    }

    public function test_formatting_directly_around_a_block_is_adopted_into_that_block()
    {
        $template = '<b class="old"><p>{{ inside }}</b>{{ after }}</p>{{ tail }}';
        [$parser, $nodes] = $this->parseHtml($template);
        $document = $parser->html();
        $formatting = $document->elements('b');

        $this->assertCount(2, $formatting);
        $this->assertSame($document, $formatting[0]->parent());
        $this->assertSame('p', $formatting[1]->parentElement()->name());
        $this->assertTrue($formatting[1]->isSynthetic());
        $this->assertSame($formatting[1], $nodes[0]->parentElement());
        $this->assertSame('p', $nodes[1]->parentElement()->name());
        $this->assertNull($nodes[2]->parentElement());
        $this->assertSame($template, $document->toHtml());

        $formatting[0]->setAttribute('class', 'new');

        $this->assertSame(
            '<b class="new"><p>{{ inside }}</b>{{ after }}</p>{{ tail }}',
            $document->toHtml()
        );
    }

    public function test_formatting_adoption_walks_through_inline_and_block_layers()
    {
        $template = '<b class="old"><i><p class="intro">{{ nested }}</b>{{ after }}</p>';
        [$parser, $nodes] = $this->parseHtml($template);
        $document = $parser->html();
        $formatting = $document->elements('b');

        $this->assertCount(2, $formatting);
        $this->assertSame($document, $formatting[0]->parent());
        $this->assertTrue($formatting[1]->isSynthetic());
        $this->assertSame($formatting[1], $nodes[0]->parentElement());
        $this->assertSame('p', $formatting[1]->parentElement()->name());
        $this->assertSame('i', $formatting[1]->parentElement()->parentElement()->name());
        $this->assertSame('p', $nodes[1]->parentElement()->name());

        $formatting[0]->setAttribute('class', 'new');
        $document->first('p')->setAttribute('class', 'changed');

        $this->assertSame(
            '<b class="new"><i><p class="changed">{{ nested }}</b>{{ after }}</p>',
            $document->toHtml()
        );
    }

    public function test_formatting_adoption_repeats_across_nested_blocks()
    {
        $template = '<b><div><section class="old">{{ nested }}</b>{{ after }}</section></div>';
        [$parser, $nodes] = $this->parseHtml($template);
        $document = $parser->html();
        $formatting = $document->elements('b');

        $this->assertCount(3, $formatting);
        $this->assertSame('section', $nodes[0]->parentElement()->parentElement()->name());
        $this->assertSame('b', $nodes[0]->parentElement()->name());
        $this->assertTrue($nodes[0]->parentElement()->isSynthetic());
        $this->assertSame('section', $nodes[1]->parentElement()->name());
        $this->assertSame('div', $nodes[1]->parentElement()->parentElement()->name());

        $document->first('section')->setAttribute('class', 'new');

        $this->assertSame(
            '<b><div><section class="new">{{ nested }}</b>{{ after }}</section></div>',
            $document->toHtml()
        );
    }

    public function test_raw_text_does_not_create_phantom_elements()
    {
        [$parser, $nodes] = $this->parseHtml('<script>if (x < y) {{ value }}</script><p>{{ after }}</p>');

        $this->assertSame('script', $nodes[0]->parentElement()->name());
        $this->assertSame('p', $nodes[1]->parentElement()->name());
        $this->assertCount(2, $parser->html()->elements());
    }

    public function test_script_double_escaped_regions_do_not_close_the_element_early()
    {
        [, $nodes] = $this->parseHtml(
            '<script><!-- <script> ignored </script> --> {{ inside }}</script><p>{{ after }}</p>'
        );

        $this->assertSame('script', $nodes[0]->parentElement()->name());
        $this->assertSame('p', $nodes[1]->parentElement()->name());
    }

    public function test_svg_namespaces_do_not_inherit_html_raw_text_rules()
    {
        [, $nodes] = $this->parseHtml(
            '<svg><linearGradient>{{ gradient }}</linearGradient>'
            .'<foreignObject><div>{{ html }}</div></foreignObject>'
            .'<title><span>{{ title }}</span></title></svg>'
        );

        $this->assertSame('linearGradient', $nodes[0]->parentElement()->name());
        $this->assertSame('svg', $nodes[0]->parentElement()->namespace());
        $this->assertSame('div', $nodes[1]->parentElement()->name());
        $this->assertSame('html', $nodes[1]->parentElement()->namespace());
        $this->assertSame('span', $nodes[2]->parentElement()->name());
        $this->assertSame('html', $nodes[2]->parentElement()->namespace());
    }

    public function test_html_breakout_tags_exit_foreign_content()
    {
        $template = '<svg><g>{{ vector }}<div class="old">{{ html }}</div></svg>'
            .'<math><mrow><p>{{ paragraph }}</p></math>'
            .'<svg><g><font color="red">{{ font }}</font></g></svg>'
            .'<svg><g><font data-color="red">{{ foreign_font }}</font></g></svg>';
        [$parser, $nodes] = $this->parseHtml($template);
        $document = $parser->html();

        $this->assertSame('g', $nodes[0]->parentElement()->name());
        $this->assertSame('div', $nodes[1]->parentElement()->name());
        $this->assertNull($nodes[1]->parentElement()->closest('svg'));
        $this->assertSame('p', $nodes[2]->parentElement()->name());
        $this->assertNull($nodes[2]->parentElement()->closest('math'));
        $this->assertSame('font', $nodes[3]->parentElement()->name());
        $this->assertNull($nodes[3]->parentElement()->closest('svg'));
        $this->assertSame('g', $nodes[4]->parentElement()->parentElement()->name());
        $this->assertSame('svg', $nodes[4]->parentElement()->closest('svg')->name());

        $document->first('div')->setAttribute('class', 'new');

        $this->assertSame(
            '<svg><g>{{ vector }}<div class="new">{{ html }}</div></svg>'
                .'<math><mrow><p>{{ paragraph }}</p></math>'
                .'<svg><g><font color="red">{{ font }}</font></g></svg>'
                .'<svg><g><font data-color="red">{{ foreign_font }}</font></g></svg>',
            $document->toHtml()
        );
    }

    public function test_svg_attribute_mutation_preserves_case_sensitive_names()
    {
        [$parser] = $this->parseHtml('<svg viewBox="0 0 10 10"><path></path></svg>');

        $parser->html()->first('svg')->setAttribute('viewBox', '0 0 20 20');

        $this->assertSame(
            '<svg viewBox="0 0 20 20"><path></path></svg>',
            $parser->html()->toHtml()
        );
    }

    public function test_mathml_integration_points_switch_children_back_to_html()
    {
        [, $nodes] = $this->parseHtml(
            '<math><mtext><span>{{ text }}</span></mtext>'
            .'<mi><mglyph>{{ glyph }}</mglyph></mi>'
            .'<annotation-xml encoding="text/html"><div>{{ html }}</div></annotation-xml></math>'
        );

        $this->assertSame('html', $nodes[0]->parentElement()->namespace());
        $this->assertSame('mathml', $nodes[1]->parentElement()->namespace());
        $this->assertSame('html', $nodes[2]->parentElement()->namespace());
    }

    public function test_foreign_end_tags_respect_html_integration_point_boundaries()
    {
        [$titleParser, $titleNodes] = $this->parseHtml(
            '<svg><title><span>{{ before }}</title>{{ after }}</svg>'
        );
        [, $mathNodes] = $this->parseHtml('<math><mrow></p>{{ value }}</math>');
        [, $objectNodes] = $this->parseHtml(
            '<svg><foreignObject><p>{{ before }}</foreignObject>{{ after }}</svg>'
        );
        [, $htmlAncestorNodes] = $this->parseHtml('<div><svg><g></div>{{ after }}');

        $this->assertSame('span', $titleNodes[0]->parentElement()->name());
        $this->assertSame($titleNodes[0]->parentElement(), $titleNodes[1]->parentElement());
        $this->assertSame('mrow', $mathNodes[0]->parentElement()->name());
        $this->assertSame('p', $objectNodes[0]->parentElement()->name());
        $this->assertSame($objectNodes[0]->parentElement(), $objectNodes[1]->parentElement());
        $this->assertNull($htmlAncestorNodes[0]->parentElement());

        $titleParser->html()->first('span')->setAttribute('data-state', 'open');

        $this->assertSame(
            '<svg><title><span data-state="open">{{ before }}</title>{{ after }}</svg>',
            $titleParser->html()->toHtml()
        );
    }

    public function test_plain_less_than_text_does_not_consume_later_elements()
    {
        [$parser, $nodes] = $this->parseHtml('1 < 2 and 3 < 4 <p>{{ value }}</p>');

        $this->assertSame('p', $nodes[0]->parentElement()->name());
        $this->assertCount(1, $parser->html()->elements());
    }

    public function test_parse_error_characters_remain_part_of_started_tag_names()
    {
        $replacement = "\xEF\xBF\xBD";
        $template = '<x=y>{{ equals }}</x=y><x"y>{{ quoted }}</x"y>'
            ."<x\0y>{{ null }}</x\0y>";
        [$parser, $nodes] = $this->parseHtml($template);
        $document = $parser->html();

        $this->assertSame('x=y', $nodes[0]->parentElement()->name());
        $this->assertSame('x"y', $nodes[1]->parentElement()->name());
        $this->assertSame('x'.$replacement.'y', $nodes[2]->parentElement()->name());

        $document->first('x=y')->setAttribute('data-state', 'ready');

        $this->assertSame(
            '<x=y data-state="ready">{{ equals }}</x=y><x"y>{{ quoted }}</x"y>'
                ."<x\0y>{{ null }}</x\0y>",
            $document->toHtml()
        );
    }

    public function test_unclosed_comments_consume_markup_conservatively_without_losing_source()
    {
        $template = '<div><!-- bogus > <span>{{ value }}</span></div>';
        [$parser, $nodes] = $this->parseHtml($template);

        $this->assertSame('div', $nodes[0]->parentElement()->name());
        $this->assertCount(1, $parser->html()->elements());
        $this->assertSame($template, $parser->html()->toHtml());
    }

    public function test_bang_closed_comments_recover_to_normal_element_content()
    {
        [, $nodes] = $this->parseHtml('<div><!-- bogus --!><p>{{ value }}</p></div>');

        $this->assertSame('p', $nodes[0]->parentElement()->name());
    }

    public function test_comment_terminators_cannot_overlap_the_opening_dashes()
    {
        [, $bang] = $this->parseHtml('<main><div><!--!>{{ first }}</div></main>');
        [, $dashBang] = $this->parseHtml('<main><section><!---!></section>{{ second }}</main>');

        $this->assertSame('comment', $bang[0]->htmlNode()->parent()->type());
        $this->assertSame('div', $bang[0]->parentElement()->name());
        $this->assertSame('comment', $dashBang[0]->htmlNode()->parent()->type());
        $this->assertSame('section', $dashBang[0]->parentElement()->name());
    }

    public function test_antlers_regions_inside_comments_have_an_opaque_local_graph_parent()
    {
        [, $nodes] = $this->parseHtml('<div><!-- {{ first }} / {{ second }} --><p>{{ after }}</p></div>');

        $this->assertInstanceOf(OpaqueNode::class, $nodes[0]->htmlNode()->parent());
        $this->assertSame('comment', $nodes[0]->htmlNode()->parent()->type());
        $this->assertSame('div', $nodes[0]->parentElement()->name());
        $this->assertSame($nodes[1]->htmlNode(), $nodes[0]->nextHtmlSibling());
        $this->assertSame($nodes[0]->htmlNode(), $nodes[1]->previousHtmlSibling());
        $this->assertSame('p', $nodes[2]->parentElement()->name());
    }

    public function test_html_declarations_end_at_the_first_gt_while_foreign_cdata_remains_opaque()
    {
        [, $nodes] = $this->parseHtml(
            '<!DOCTYPE {{ type }}>'
            .'<!DOCTYPE svg [ <!ENTITY example "value"> {{ entity }} ]>'
            .'<![CDATA[ a > b {{ html_cdata }} ]]>'
            .'<svg><![CDATA[ a > b {{ foreign_cdata }} ]]><g>{{ body }}</g></svg>'
        );

        $this->assertSame('doctype', $nodes[0]->htmlNode()->parent()->type());
        $this->assertNull($nodes[1]->parentElement());
        $this->assertNull($nodes[2]->parentElement());
        $this->assertSame('declaration', $nodes[3]->htmlNode()->parent()->type());
        $this->assertSame('svg', $nodes[3]->parentElement()->name());
        $this->assertSame('g', $nodes[4]->parentElement()->name());
    }

    public function test_dynamic_element_names_are_represented_as_unknown_elements()
    {
        [, $nodes] = $this->parseHtml('<{{ element }} class="card">{{ value }}</{{ element }}>');

        $this->assertNull($nodes[0]->parentElement()->name());
        $this->assertSame($nodes[0]->parentElement(), $nodes[1]->parentElement());
        $this->assertSame($nodes[1]->parentElement(), $nodes[2]->parentElement());
    }

    public function test_dynamic_elements_can_mutate_static_attributes_without_corrupting_the_name()
    {
        [$parser] = $this->parseHtml('<x-{{ kind }} class="old">Content</x-{{ kind }}>');
        $element = $parser->html()->first();

        $element->setAttribute('class', 'new')->setAttribute('data-ready', 'yes');

        $this->assertSame(
            '<x-{{ kind }} data-ready="yes" class="new">Content</x-{{ kind }}>',
            $parser->html()->toHtml()
        );
    }

    public function test_utf8_before_antlers_regions_keeps_graph_offsets_aligned()
    {
        [$parser, $nodes] = $this->parseHtml('<café title="déjà">Crème {{ title }}</café>');

        $this->assertSame('café', $nodes[0]->parentElement()->name());
        $this->assertSame('<café title="déjà">Crème {{ title }}</café>', $parser->html()->toHtml());
    }

    public function test_elements_can_be_fluently_modified_and_printed()
    {
        [$parser] = $this->parseHtml('<div><p id="old" class="lead">{{ title }}</p></div>');
        $document = $parser->html();
        $paragraph = $document->first('p');
        $rule = $document->createElement('hr')->setAttribute('data-kind', 'break');

        $paragraph
            ->removeAttribute('id')
            ->addClass('featured')
            ->setAttribute('data-source', 'Antlers & HTML')
            ->append('!')
            ->after($rule);

        $this->assertSame(
            '<div><p data-source="Antlers &amp; HTML" class="lead featured">{{ title }}!</p><hr data-kind="break"></div>',
            $document->toHtml()
        );
    }

    public function test_attribute_mutation_treats_antlers_expressions_as_opaque()
    {
        [$parser] = $this->parseHtml('<div title="{{ value ? "many > one" : "one" }}" class="old">Content</div>');

        $parser->html()->first('div')
            ->setAttribute('class', 'new')
            ->setAttribute('data-ready', 'yes');

        $this->assertSame(
            '<div data-ready="yes" title="{{ value ? "many > one" : "one" }}" class="new">Content</div>',
            $parser->html()->toHtml()
        );
    }

    public function test_attribute_mutation_recovers_when_a_region_ends_inside_a_quoted_string()
    {
        // The runtime parser ends this region at the `}}` inside the string,
        // so the recovered opening tag stops at the `>` that follows it. The
        // HTML graph mirrors those extents rather than redefining them:
        // mutations apply to the recovered tag and the remaining source
        // prints untouched.
        $template = '<div title="{{ value == "}}" ? "many > one" : "one" }}" class="old">Content</div>';
        [$parser] = $this->parseHtml($template);

        $this->assertSame($template, $parser->html()->toHtml());

        $parser->html()->first('div')
            ->setAttribute('class', 'new')
            ->setAttribute('data-ready', 'yes');

        $this->assertSame(
            '<div class="new" data-ready="yes" title="{{ value == "}}" ? "many > one" : "one" }}" class="old">Content</div>',
            $parser->html()->toHtml()
        );
    }

    public function test_duplicate_attributes_follow_first_wins_and_are_normalized_on_mutation()
    {
        [$parser] = $this->parseHtml('<p class="first" CLASS="second">Text</p>');
        $paragraph = $parser->html()->first('p');

        $this->assertSame('first', $paragraph->attr('class'));

        $paragraph->setAttribute('class', 'final');

        $this->assertSame('<p class="final">Text</p>', $parser->html()->toHtml());
    }

    public function test_malformed_attribute_names_and_stray_slashes_follow_html_tokenization()
    {
        $template = "<div a/b=c b=d =x \"q\"=1 a\vb=2>Text</div>";
        [$parser] = $this->parseHtml($template);
        $element = $parser->html()->first('div');

        $this->assertTrue($element->hasAttribute('a'));
        $this->assertSame('c', $element->attr('b'));
        $this->assertTrue($element->hasAttribute('=x'));
        $this->assertSame('1', $element->attr('"q"'));
        $this->assertSame('2', $element->attr("a\vb"));

        $element
            ->setAttribute('b', 'final')
            ->removeAttribute('=x')
            ->removeAttribute('"q"');

        $this->assertSame("<div a/ b=\"final\" a\vb=2>Text</div>", $parser->html()->toHtml());
    }

    public function test_null_bytes_in_attribute_names_and_values_are_replaced_without_changing_source()
    {
        $replacement = "\xEF\xBF\xBD";
        $template = "<div a\0b=one quoted=\"x\0y\" unquoted=x\0y>Text</div>";
        [$parser] = $this->parseHtml($template);
        $element = $parser->html()->first('div');

        $this->assertSame('one', $element->attr('a'.$replacement.'b'));
        $this->assertSame('x'.$replacement.'y', $element->attr('quoted'));
        $this->assertSame('x'.$replacement.'y', $element->attr('unquoted'));
        $this->assertSame($template, $parser->html()->toHtml());
    }

    public function test_boolean_and_neighbor_attribute_edits_use_disjoint_source_spans()
    {
        [$parser] = $this->parseHtml(
            "<div  @click.outside\n    v-bind:class='{{ classes }}'  x-init='{{ init }}'>Content</div>"
        );

        $parser->html()->first('div')
            ->setAttribute('@click.outside', 'open = false')
            ->removeAttribute('v-bind:class');

        $this->assertSame(
            "<div @click.outside=\"open = false\"  x-init='{{ init }}'>Content</div>",
            $parser->html()->toHtml()
        );
    }

    public function test_regions_ending_at_braces_inside_strings_recover_on_the_html_side()
    {
        // Core Antlers semantics end the region at the first `}}` even inside
        // a quoted string. The HTML graph accepts those extents: the attribute
        // captures the truncated region, everything after it is recovered as
        // ordinary tag syntax, and the source always round-trips.
        $template = '<div title="{{ "}}" == "}}" ? "yes" : "no" }}"></div>';
        [$parser, $nodes] = $this->parseHtml($template);

        $this->assertSame('"', trim($nodes[0]->content));
        $this->assertSame('{{ "}}', $parser->html()->first('div')->attr('title'));
        $this->assertSame($template, $parser->html()->toHtml());
        $this->assertSame($template, $parser->html()->markDirty()->toHtml());
    }

    public function test_dynamic_attribute_names_do_not_confuse_static_attribute_mutation()
    {
        [$parser] = $this->parseHtml('<div data-{{ suffix }}="value" class="old">Content</div>');

        $parser->html()->first('div')->setAttribute('class', 'new');

        $this->assertSame(
            '<div data-{{ suffix }}="value" class="new">Content</div>',
            $parser->html()->toHtml()
        );
    }

    public function test_alpine_and_livewire_attributes_can_be_read_and_mutated_without_rewriting_neighbors()
    {
        $template = <<<'HTML'
<div x-data="{ open: false, label: '{{ title }}', nested: { count: 1 } }" @click.outside="open = false" x-bind:class="{ 'is-open': open }" :aria-expanded="open" x-transition:enter.duration.500ms wire:model.live.debounce.250ms="search" wire:key="post-{{ id }}" class="base">{{ body }}</div>
HTML;
        [$parser] = $this->parseHtml($template);
        $element = $parser->html()->first('div');

        $this->assertSame("{ open: false, label: '{{ title }}', nested: { count: 1 } }", $element->attr('x-data'));
        $this->assertSame('open = false', $element->attr('@click.outside'));
        $this->assertSame("{ 'is-open': open }", $element->attr('x-bind:class'));
        $this->assertSame('open', $element->attr(':aria-expanded'));
        $this->assertTrue($element->hasAttribute('x-transition:enter.duration.500ms'));
        $this->assertSame('search', $element->attr('wire:model.live.debounce.250ms'));
        $this->assertSame('post-{{ id }}', $element->attr('wire:key'));

        $element
            ->removeAttribute('wire:model.live.debounce.250ms')
            ->setAttribute('class', 'base changed')
            ->setAttribute('data-state', 'ready');

        $this->assertSame(
            '<div data-state="ready" x-data="{ open: false, label: \'{{ title }}\', nested: { count: 1 } }" @click.outside="open = false" x-bind:class="{ \'is-open\': open }" :aria-expanded="open" x-transition:enter.duration.500ms wire:key="post-{{ id }}" class="base changed">{{ body }}</div>',
            $parser->html()->toHtml()
        );
    }

    public function test_setting_an_attribute_escapes_literal_html_but_preserves_antlers_expression_source()
    {
        [$parser] = $this->parseHtml('<div></div>');

        $parser->html()->first('div')->setAttribute(
            'data-label',
            'A & {{ value == "x" ? "yes" : "no" }} "label"'
        );

        $this->assertSame(
            '<div data-label="A &amp; {{ value == "x" ? "yes" : "no" }} &quot;label&quot;"></div>',
            $parser->html()->toHtml()
        );
        $this->assertSame(
            '<div data-label="A &amp; yes &quot;label&quot;"></div>',
            $this->renderString($parser->html()->toHtml(), ['value' => 'x'])
        );
    }

    public function test_nested_interpolation_tags_share_the_outer_region_html_node()
    {
        [, $nodes] = $this->parseHtml(
            '<article><div>{{ _var = {collection:count from="blog"} }}</div></article>'
        );
        $outer = $nodes[0];
        $inner = array_values($outer->processedInterpolationRegions)[0][0];

        $this->assertSame('collection', $inner->name->name);
        $this->assertSame($outer->htmlNode(), $inner->htmlNode());
        $this->assertSame($outer->parentElement(), $inner->parentElement());
        $this->assertSame('div', $inner->parentElement()->name());
    }

    public function test_created_non_void_elements_receive_closing_tags()
    {
        [$parser] = $this->parseHtml('<main></main>');
        $document = $parser->html();

        $document->first('main')->append(
            $document->createElement('span')->append('Hello')
        );

        $this->assertSame('<main><span>Hello</span></main>', $document->toHtml());
    }

    public function test_self_closing_syntax_is_ignored_for_non_void_html_elements()
    {
        [, $nodes] = $this->parseHtml('<div/><span>{{ value }}</span>{{ after }}');

        $this->assertSame('span', $nodes[0]->parentElement()->name());
        $this->assertSame('div', $nodes[0]->parentElement()->parentElement()->name());
        $this->assertSame('div', $nodes[1]->parentElement()->name());
    }

    public function test_self_closing_syntax_is_honored_in_svg()
    {
        [, $nodes] = $this->parseHtml('<svg><path/><text>{{ value }}</text></svg>');

        $this->assertSame('text', $nodes[0]->parentElement()->name());
        $this->assertSame('svg', $nodes[0]->parentElement()->parentElement()->name());
    }

    public function test_a_slash_in_an_unquoted_foreign_attribute_is_not_self_closing_syntax()
    {
        [, $nodes] = $this->parseHtml('<svg><path data-value=x/><text>{{ nested }}</text></svg>');

        $this->assertSame('text', $nodes[0]->parentElement()->name());
        $this->assertSame('path', $nodes[0]->parentElement()->parentElement()->name());

        [, $nodes] = $this->parseHtml('<svg><path data-value=x /><text>{{ sibling }}</text></svg>');

        $this->assertSame('text', $nodes[0]->parentElement()->name());
        $this->assertSame('svg', $nodes[0]->parentElement()->parentElement()->name());
    }

    public function test_an_html_image_element_never_holds_children_and_round_trips()
    {
        // The in-body rules rewrite `image` to `img`; the recovered graph
        // keeps the authored name but never nests content beneath it.
        $template = '<image src=a>{{ x }}</image>{{ y }}';
        [$parser] = $this->parseHtml($template);
        $document = $parser->html();

        $this->assertCount(0, $document->first('image')->children());
        $this->assertSame($template, $document->toHtml());
        $this->assertSame($template, $document->markDirty()->toHtml());

        // SVG's image element is a normal foreign element and keeps children.
        [$parser] = $this->parseHtml('<svg><image href=a><title>t</title></image></svg>');
        $svgImage = $parser->html()->first('image');

        $this->assertCount(1, $svgImage->children());
    }

    public function test_a_stray_slash_before_whitespace_is_not_self_closing_syntax_and_round_trips()
    {
        // The self-closing flag only sets when `>` immediately follows the
        // solidus; `/ >` leaves the foreign element open, so rect nests.
        $template = '<svg><circle r="1"/ ><rect/></svg>';
        [$parser] = $this->parseHtml($template);
        $document = $parser->html();

        $circle = $document->first('circle');

        $this->assertFalse($circle->isSelfClosing());
        $this->assertCount(1, $circle->children());
        $this->assertSame('rect', $circle->children()[0]->name());

        // The stray-slash markup must survive both the clean-source shortcut
        // and a forced recovered-tree serialization byte-for-byte.
        $this->assertSame($template, $document->toHtml());
        $this->assertSame($template, $document->markDirty()->toHtml());
    }

    public function test_queries_return_laravel_collections_and_nodes_are_conditionable_and_tappable()
    {
        [$parser] = $this->parseHtml('<main><p>One</p><section><p>Two</p></section></main>');
        $document = $parser->html();
        $tapped = null;

        $paragraphs = $document->elements('p');
        $sectionParagraphs = $document->first('section')->descendants('p');

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $paragraphs);
        $this->assertCount(2, $paragraphs);
        $this->assertCount(1, $sectionParagraphs);

        $paragraphs->first()
            ->when(true, fn ($element) => $element->addClass('first'))
            ->unless(false, fn ($element) => $element->setAttribute('data-ready', 'yes'))
            ->tap(function ($element) use (&$tapped) {
                $tapped = $element;
            });

        $this->assertSame($paragraphs->first(), $tapped);
        $this->assertSame(
            '<main><p class="first" data-ready="yes">One</p><section><p>Two</p></section></main>',
            $document->toHtml()
        );
    }

    public function test_mutating_a_detached_element_does_not_dirty_or_reprint_the_document()
    {
        $template = '<b><i></b></i>';
        [$parser] = $this->parseHtml($template);
        $document = $parser->html();

        $document->createElement('span')->setAttribute('class', 'unused')->append('Unused');

        $this->assertFalse($document->isDirty());
        $this->assertSame($template, $document->toHtml());
    }

    public function test_dynamic_colgroup_content_is_foster_parented_before_the_table()
    {
        $template = '<div><table><colgroup>{{ columns }}<col></colgroup></table></div>';
        [$parser, $nodes] = $this->parseHtml($template);
        $document = $parser->html();

        $this->assertSame('div', $nodes[0]->parentElement()->name());
        $this->assertTrue($nodes[0]->htmlNode()->isSourceAnchored());

        $document->first('table')->setAttribute('data-table', 'true');

        $this->assertSame(
            '<div><table data-table="true"><colgroup>{{ columns }}<col></colgroup></table></div>',
            $document->toHtml()
        );
    }

    public function test_disallowed_select_elements_are_ignored_and_form_controls_exit_select_mode()
    {
        $template = '<select><div>{{ ignored_parent }}</div><option>{{ option }}</option>'
            .'<input>{{ after }}</select>';
        [$parser, $nodes] = $this->parseHtml($template);
        $document = $parser->html();

        $this->assertSame('select', $nodes[0]->parentElement()->name());
        $this->assertSame('option', $nodes[1]->parentElement()->name());
        $this->assertNull($nodes[2]->parentElement());
        $this->assertNull($document->first('select')->firstDescendant('div'));

        $document->first('select')->setAttribute('data-select', 'true');

        $this->assertSame(
            '<select data-select="true"><div>{{ ignored_parent }}</div><option>{{ option }}</option>'
                .'<input>{{ after }}</select>',
            $document->toHtml()
        );
    }

    public function test_nested_form_start_tags_are_ignored_like_the_html_tree_builder()
    {
        $template = '<form><div>{{ before }}<form data-nested>{{ inside }}</form>{{ after }}</div></form>';
        [$parser, $nodes] = $this->parseHtml($template);
        $document = $parser->html();

        $this->assertSame('div', $nodes[0]->parentElement()->name());
        $this->assertSame('div', $nodes[1]->parentElement()->name());
        $this->assertSame('div', $nodes[2]->parentElement()->name());
        $this->assertCount(1, $document->elements('form'));

        $document->first('form')->setAttribute('data-outer', 'true');

        $this->assertSame(
            '<form data-outer="true"><div>{{ before }}<form data-nested>{{ inside }}</form>'
                .'{{ after }}</div></form>',
            $document->toHtml()
        );
    }

    public function test_template_boundaries_allow_independent_form_recovery()
    {
        $template = '<form><template><form>{{ inner }}</form>{{ template }}</template>{{ outer }}</form>';
        [$parser, $nodes] = $this->parseHtml($template);
        $document = $parser->html();

        $this->assertSame('form', $nodes[0]->parentElement()->name());
        $this->assertSame('template', $nodes[1]->parentElement()->name());
        $this->assertSame('form', $nodes[2]->parentElement()->name());
        $this->assertNotSame($nodes[0]->parentElement(), $nodes[2]->parentElement());
        $this->assertCount(2, $document->elements('form'));

        $this->assertSame($template, $document->toHtml());
    }

    public function test_template_boundaries_prevent_description_items_from_closing_outer_items()
    {
        [, $nodes] = $this->parseHtml(
            '<dd><template><dt>{{ inside }}</template>{{ after }}'
        );

        $this->assertSame('dt', $nodes[0]->parentElement()->name());
        $this->assertSame('template', $nodes[0]->parentElement()->parentElement()->name());
        $this->assertSame('dd', $nodes[1]->parentElement()->name());
    }

    public function test_table_forms_keep_the_form_pointer_after_leaving_the_open_element_stack()
    {
        $template = '<div><table><form id="first"><form id="ignored">{{ fostered }}</form></table>'
            .'<form id="second">{{ second }}</form></div>';
        [$parser, $nodes] = $this->parseHtml($template);
        $document = $parser->html();
        $forms = $document->elements('form');
        $formsById = [];

        foreach ($forms as $form) {
            $formsById[$form->attr('id')] = $form;
        }

        $this->assertCount(2, $forms);
        $this->assertArrayHasKey('first', $formsById);
        $this->assertArrayHasKey('second', $formsById);
        $this->assertSame('div', $nodes[0]->parentElement()->name());
        $this->assertSame($formsById['second'], $nodes[1]->parentElement());

        $formsById['second']->setAttribute('data-form', 'true');

        $this->assertSame(
            '<div><table><form id="first"><form id="ignored">{{ fostered }}</form></table>'
                .'<form data-form="true" id="second">{{ second }}</form></div>',
            $document->toHtml()
        );
    }

    public function test_table_mode_forms_are_empty_and_form_ends_preserve_fostered_descendants()
    {
        [$parser, $emptyForm] = $this->parseHtml(
            '<table><em><form>{{ after_form }}'
        );
        [, $formEnd] = $this->parseHtml(
            '<form><table><option></form>{{ after_end }}'
        );

        $this->assertSame('em', $emptyForm[0]->parentElement()->name());
        $this->assertNull($emptyForm[0]->parentElement()->closest('form'));
        $this->assertCount(0, $parser->html()->first('form')->children());
        $this->assertSame('option', $formEnd[0]->parentElement()->name());
        $this->assertSame('form', $formEnd[0]->parentElement()->parentElement()->name());
    }

    public function test_nested_anchor_recovery_rechecks_foster_parenting_after_closing_the_old_anchor()
    {
        [, $nodes] = $this->parseHtml(
            '<table><a><a>{{ marker }}'
        );

        $this->assertSame('a', $nodes[0]->parentElement()->name());
        $this->assertNull($nodes[0]->parentElement()->closest('table'));
    }

    public function test_head_elements_remain_in_their_allowed_table_contexts()
    {
        $template = '<table><tbody><style id="styles"></style><script id="script"></script>'
            .'<template id="rows"><tr><td>{{ row }}</td></tr></template></tbody>'
            .'<colgroup><template id="columns">{{ column }}</template></colgroup></table>';
        [$parser, $nodes] = $this->parseHtml($template);
        $document = $parser->html();
        $templates = $document->elements('template');

        $this->assertSame('tbody', $document->first('style')->parentElement()->name());
        $this->assertSame('tbody', $document->first('script')->parentElement()->name());
        $this->assertSame('rows', $templates[0]->attr('id'));
        $this->assertSame('tbody', $templates[0]->parentElement()->name());
        $this->assertSame('columns', $templates[1]->attr('id'));
        $this->assertSame('colgroup', $templates[1]->parentElement()->name());
        $this->assertSame('td', $nodes[0]->parentElement()->name());
        $this->assertSame('template', $nodes[1]->parentElement()->name());

        $templates[0]->setAttribute('data-template', 'true');

        $this->assertSame(
            '<table><tbody><style id="styles"></style><script id="script"></script>'
                .'<template data-template="true" id="rows"><tr><td>{{ row }}</td></tr></template></tbody>'
                .'<colgroup><template id="columns">{{ column }}</template></colgroup></table>',
            $document->toHtml()
        );
    }

    public function test_table_structure_exits_select_mode_and_is_reprocessed()
    {
        $template = '<table><select>{{ before }}<tr><td>{{ cell }}</td></tr></select></table>';
        [$parser, $nodes] = $this->parseHtml($template);
        $document = $parser->html();

        $this->assertSame('select', $nodes[0]->parentElement()->name());
        $this->assertSame('td', $nodes[1]->parentElement()->name());
        $this->assertNull($document->first('select')->parentElement());
        $this->assertSame('table', $document->first('tbody')->parentElement()->name());

        $document->first('td')->setAttribute('data-cell', 'true');

        $this->assertSame(
            '<table><select>{{ before }}<tr><td data-cell="true">{{ cell }}</td></tr></select></table>',
            $document->toHtml()
        );
    }

    public function test_fostered_elements_do_not_capture_following_table_structure()
    {
        $template = '<table><div class="old">{{ fostered }}<tr><td>{{ cell }}</td></tr></div></table>';
        [$parser, $nodes] = $this->parseHtml($template);
        $document = $parser->html();
        $division = $document->first('div');

        $this->assertNull($division->parentElement());
        $this->assertSame($division, $nodes[0]->parentElement());
        $this->assertSame('td', $nodes[1]->parentElement()->name());
        $this->assertSame('table', $nodes[1]->parentElement()->closest('table')->name());

        $division->setAttribute('class', 'new');

        $this->assertSame(
            '<table><div class="new">{{ fostered }}<tr><td>{{ cell }}</td></tr></div></table>',
            $document->toHtml()
        );
    }

    public function test_table_cells_clear_preceding_active_formatting_and_foreign_roots_are_fostered()
    {
        $template = '<table><b>{{ before }}<tr><td>{{ cell }}</td></tr></b></table>'
            .'<table><svg><g>{{ vector }}</g></svg><tr><td>{{ second }}</td></tr></table>';
        [$parser, $nodes] = $this->parseHtml($template);
        $document = $parser->html();

        $this->assertSame('b', $nodes[0]->parentElement()->name());
        $this->assertSame('td', $nodes[1]->parentElement()->name());
        $this->assertNull($nodes[1]->parentElement()->closest('b'));
        $this->assertSame('g', $nodes[2]->parentElement()->name());
        $this->assertNull($nodes[2]->parentElement()->closest('table'));
        $this->assertSame('td', $nodes[3]->parentElement()->name());
        $this->assertSame($template, $document->toHtml());
    }

    public function test_foster_parenting_keeps_reconstructed_formatting_around_content_and_selects()
    {
        $template = '<p><b><table>{{ content }}</table>'
            .'<b><table><select>{{ selection }}</select></table>';
        [$parser, $nodes] = $this->parseHtml($template);

        $this->assertSame('b', $nodes[0]->parentElement()->name());
        $this->assertNull($nodes[0]->parentElement()->closest('table'));
        $this->assertSame('select', $nodes[1]->parentElement()->name());
        $this->assertSame('b', $nodes[1]->parentElement()->parentElement()->name());
        $this->assertNull($nodes[1]->parentElement()->closest('table'));
        $this->assertSame($template, $parser->html()->toHtml());
    }

    public function test_foster_parenting_is_rechecked_after_implied_end_tags_change_table_mode()
    {
        [, $options] = $this->parseHtml(
            '<b><table><option><option><optgroup>{{ options }}'
        );
        [, $block] = $this->parseHtml(
            '<b><table><p><div>{{ block }}'
        );

        $this->assertSame('optgroup', $options[0]->parentElement()->name());
        $this->assertSame('b', $options[0]->parentElement()->parentElement()->name());
        $this->assertNull($options[0]->parentElement()->closest('table'));
        $this->assertSame('div', $block[0]->parentElement()->name());
        $this->assertSame('b', $block[0]->parentElement()->parentElement()->name());
        $this->assertNull($block[0]->parentElement()->closest('table'));
    }

    public function test_col_starts_leave_table_sections_before_reprocessing_the_token()
    {
        [, $fostered] = $this->parseHtml(
            '<table><thead><div><col>{{ after_col }}'
        );
        [, $cell] = $this->parseHtml(
            '<table><tfoot><col><td>{{ cell }}'
        );

        $this->assertNull($fostered[0]->parentElement());
        $this->assertSame('td', $cell[0]->parentElement()->name());
        $this->assertSame('tr', $cell[0]->parentElement()->parentElement()->name());
        $this->assertSame('tbody', $cell[0]->parentElement()->parentElement()->parentElement()->name());
    }

    public function test_table_end_tags_exit_select_mode_before_closing_the_table_structure()
    {
        $template = '<table><tbody><tr><td><select>{{ inside }}</td>{{ after }}</tr></tbody></table>';
        [$parser, $nodes] = $this->parseHtml($template);

        $this->assertSame('select', $nodes[0]->parentElement()->name());
        $this->assertNull($nodes[1]->parentElement());
        $this->assertSame($template, $parser->html()->toHtml());
    }

    public function test_graph_nodes_can_be_removed_and_replaced()
    {
        [$parser, $nodes] = $this->parseHtml('<p>Hello {{ name }}.</p><aside>Remove me</aside>');
        $document = $parser->html();

        $nodes[0]->htmlNode()->replaceWith('<strong>friend</strong>');
        $document->first('aside')->remove();

        $this->assertSame('<p>Hello <strong>friend</strong>.</p>', $document->toHtml());
    }

    public function test_graph_rejects_moves_that_would_create_cycles()
    {
        [$parser] = $this->parseHtml('<main><section><p>Text</p></section></main>');
        $document = $parser->html();
        $main = $document->first('main');
        $paragraph = $document->first('p');

        try {
            $paragraph->append($main);
            $this->fail('Expected an ancestor-into-descendant move to be rejected.');
        } catch (\LogicException $exception) {
            $this->assertSame(
                'HTML graph nodes cannot be inserted into themselves or their descendants.',
                $exception->getMessage()
            );
        }

        $this->assertFalse($document->isDirty());
        $this->assertSame('<main><section><p>Text</p></section></main>', $document->toHtml());
    }

    public function test_multibyte_characters_beside_regions_keep_clean_node_boundaries()
    {
        $template = '<p>日本語{{ title }}é more — 🎉{{ emoji }}中文</p>';
        [$parser, $nodes] = $this->parseHtml($template);
        $document = $parser->html();

        $this->assertSame('{{ title }}', $nodes[0]->htmlNode()->source());
        $this->assertSame('{{ emoji }}', $nodes[1]->htmlNode()->source());

        foreach ($document->first('p')->content()->nodes() as $node) {
            if ($node instanceof Text) {
                $this->assertTrue(
                    mb_check_encoding($node->content(), 'UTF-8'),
                    'Text node split a multibyte character: '.bin2hex($node->content())
                );
            }
        }

        $this->assertSame($template, $document->markDirty()->toHtml());
    }

    public function test_mutating_regions_beside_multibyte_characters_does_not_corrupt_output()
    {
        [$parser, $nodes] = $this->parseHtml('<p>{{ title }}é</p>');

        $nodes[0]->htmlNode()->source('{{ replaced }}');

        $output = $parser->html()->toHtml();

        $this->assertTrue(mb_check_encoding($output, 'UTF-8'));
        $this->assertSame('<p>{{ replaced }}é</p>', $output);
    }

    public function test_regions_closing_a_multibyte_document_resolve_their_extents()
    {
        $template = '日本語{{ title }}';
        [$parser, $nodes] = $this->parseHtml($template);

        $this->assertSame('{{ title }}', $nodes[0]->htmlNode()->source());
        $this->assertSame($template, $parser->html()->markDirty()->toHtml());
    }

    public function test_nodes_can_be_wrapped_with_created_elements()
    {
        [$parser, $nodes] = $this->parseHtml('<p>before {{ title }} after</p>');
        $document = $parser->html();

        $mark = $document->createElement('mark')->setAttribute('data-probe', 'title');
        $nodes[0]->htmlNode()->wrapWith($mark);

        $this->assertSame(
            '<p>before <mark data-probe="title">{{ title }}</mark> after</p>',
            $document->toHtml()
        );
    }

    public function test_regions_can_be_wrapped_with_created_elements()
    {
        [$parser] = $this->parseHtml('<p>{{ if a }}x<b>y</b>{{ /if }}<span>z</span></p>');
        $document = $parser->html();

        $document->regions('if')->first()->wrapWith(
            $document->createElement('div')->setAttribute('data-probe', 'if')
        );

        $this->assertSame(
            '<p><div data-probe="if">{{ if a }}x<b>y</b>{{ /if }}</div><span>z</span></p>',
            $document->toHtml()
        );
    }

    public function test_wrapping_a_region_whose_boundaries_cross_containers_is_rejected()
    {
        [$parser] = $this->parseHtml('<section>{{ if a }}<div>{{ /if }}</section>');
        $document = $parser->html();
        $region = $document->regions('if')->first();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('HTML regions must begin and end as children of the same container.');

        $region->wrapWith($document->createElement('div'));
    }

    public function test_wrapping_an_embedded_region_is_rejected()
    {
        [$parser, $nodes] = $this->parseHtml('<div {{ attrs }}>Content</div>');
        $document = $parser->html();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Antlers regions inside an HTML tag cannot be moved as DOM children.');

        $nodes[0]->htmlNode()->wrapWith($document->createElement('span'));
    }

    public function test_wrapping_with_a_region_member_or_ancestor_is_rejected()
    {
        [$parser] = $this->parseHtml('<p>{{ if a }}<b>x</b>{{ /if }}</p>');
        $document = $parser->html();
        $region = $document->regions('if')->first();

        try {
            $region->wrapWith($document->first('b'));
            $this->fail('Expected wrapping with a region member to be rejected.');
        } catch (\LogicException $exception) {
            $this->assertSame(
                'An HTML region cannot be wrapped with one of its own members.',
                $exception->getMessage()
            );
        }

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('HTML graph nodes cannot be inserted into themselves or their descendants.');

        $region->wrapWith($document->first('p'));
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
