<?php

namespace Tests\Antlers\Runtime;

use Tests\Antlers\ParserTestCase;
use Tests\Antlers\Runtime\Support\SpacelessPrepass;

class SpacelessPrepassTest extends ParserTestCase
{
    public function test_deeply_nested_element_content_is_trimmed()
    {
        $source = 'Value';
        $expected = 'Value';

        for ($depth = 0; $depth < 128; $depth++) {
            $source = "<div> \n\t{$source}\r\n </div>";
            $expected = "<div>{$expected}</div>";
        }

        $result = (new SpacelessPrepass)(
            "{{ spaceless }} \n{$source}\n {{ /spaceless }}"
        );

        $this->assertSame($expected, $result);
    }

    public function test_nested_pairs_keep_their_whitespace_policy_scoped()
    {
        $result = (new SpacelessPrepass)(<<<'ANTLERS'
{{ spaceless }}
    <div>
        <code>  Trimmed by the outer pair.  </code>
        {{ spaceless sensitive="code" }}
            <code>  Preserved by the inner pair.  </code>
            <span>  Trimmed by the inner pair.  </span>
        {{ /spaceless }}
    </div>
{{ /spaceless }}
ANTLERS);

        $this->assertSame(<<<'HTML'
<div><code>Trimmed by the outer pair.</code>
        <code>  Preserved by the inner pair.  </code>
            <span>Trimmed by the inner pair.</span></div>
HTML, $result);
    }

    public function test_deeply_nested_spaceless_pairs_all_disappear()
    {
        $source = '<p>  Deep value.  </p>';

        for ($depth = 0; $depth < 64; $depth++) {
            $source = "{{ spaceless }} \n{$source}\n {{ /spaceless }}";
        }

        $result = (new SpacelessPrepass)($source);

        $this->assertSame('<p>Deep value.</p>', $result);
        $this->assertStringNotContainsString('spaceless', $result);
    }

    public function test_mixed_inline_text_keeps_semantic_spaces()
    {
        $result = (new SpacelessPrepass)(
            '{{ spaceless }} <p>  Hello <strong>  wonderful  </strong> world!  </p> {{ /spaceless }}'
        );

        $this->assertSame(
            '<p>Hello <strong>wonderful</strong> world!</p>',
            $result
        );
    }

    public function test_sensitivity_is_inherited_by_all_nested_descendants()
    {
        $result = (new SpacelessPrepass)(
            '{{ spaceless sensitive="code" }} '
            .'<div> '
            .'<pre><span>  Native.  </span></pre>'
            .'<code><em>  Pair-specific.  </em></code>'
            .'<svg><text><tspan>  Foreign.  </tspan></text></svg>'
            .'<p><span>  Trimmed.  </span></p>'
            .' </div> '
            .'{{ /spaceless }}'
        );

        $this->assertSame(
            '<div>'
            .'<pre><span>  Native.  </span></pre>'
            .'<code><em>  Pair-specific.  </em></code>'
            .'<svg><text><tspan>  Foreign.  </tspan></text></svg>'
            .'<p><span>Trimmed.</span></p>'
            .'</div>',
            $result
        );
    }

    public function test_comments_do_not_hide_region_boundary_trivia()
    {
        $result = (new SpacelessPrepass)(
            '{{ spaceless }}  <!-- before -->  <p>  Hello.  </p>  <!-- after -->  {{ /spaceless }}'
        );

        $this->assertSame(
            '<!-- before --><p>Hello.</p><!-- after -->',
            $result
        );
    }

    public function test_cross_container_pairs_are_rejected_instead_of_rewriting_ambiguous_source()
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'HTML regions must begin and end as children of the same container.'
        );

        (new SpacelessPrepass)(
            '{{ spaceless }}<div>{{ /spaceless }}</div>'
        );
    }

    public function test_many_nested_regions_match_a_reference_rewrite()
    {
        $state = 0x5EED;
        $source = '';
        $expected = '';

        for ($case = 0; $case < 500; $case++) {
            [$caseSource, $caseExpected] = $this->nestedCase($state, 0, 6);
            $source .= '{{ spaceless }}'.$this->whitespace($state)
                .$caseSource.$this->whitespace($state).'{{ /spaceless }}|';
            $expected .= $caseExpected.'|';
        }

        $this->assertSame($expected, (new SpacelessPrepass)($source));
    }

    public function test_recovered_html_regions_are_stable_and_idempotent()
    {
        $fragments = [
            '<p><b>  one <i> two </b> three </i>  </p>',
            '<table> stray <tr><td>  cell  </table>',
            '<select><option>  one <div> two </select>',
            '<div><form>  outer <form> inner </form>  </div>',
            '<svg><foreignObject><p>  HTML island.  </p></foreignObject></svg>',
            '<math><mtext><p>  HTML integration point.  </p></mtext></math>',
            '<!-- leading --><div>  value  </div><!-- trailing -->',
            '<!bogus declaration><section>  value  </section>',
        ];
        $source = '';

        for ($case = 0; $case < 200; $case++) {
            $fragment = $fragments[$case % count($fragments)];
            $source .= '{{ spaceless }} '.$fragment.' {{ /spaceless }}|';
        }

        $prepass = new SpacelessPrepass;
        $once = $prepass($source);
        $twice = $prepass($once);

        $this->assertStringNotContainsString('spaceless', $once);
        $this->assertSame($once, $twice);
    }

    protected function nestedCase(&$state, $depth, $maximumDepth)
    {
        if ($depth === $maximumDepth || $this->random($state) % 4 === 0) {
            $leafTags = ['code', 'em', 'kbd', 'p', 'span', 'strong'];
            $tag = $leafTags[$this->random($state) % count($leafTags)];
            $value = 'value-'.$this->random($state);

            return [
                '<'.$tag.'>'.$this->whitespace($state).$value.$this->whitespace($state).'</'.$tag.'>',
                '<'.$tag.'>'.$value.'</'.$tag.'>',
            ];
        }

        // Keep the generated corpus within HTML's content model. Recovery for
        // malformed markup is covered separately; a structural reference
        // string is only meaningful when the browser tree is unambiguous.
        $containerTags = ['article', 'aside', 'div', 'main', 'nav', 'section'];
        $tag = $containerTags[$this->random($state) % count($containerTags)];
        $children = 1 + ($this->random($state) % 3);
        $source = '<'.$tag.'>'.$this->whitespace($state);
        $expected = '<'.$tag.'>';

        for ($child = 0; $child < $children; $child++) {
            [$childSource, $childExpected] = $this->nestedCase($state, $depth + 1, $maximumDepth);
            $source .= $childSource;
            $expected .= $childExpected;

            if ($child + 1 < $children) {
                $separator = $this->whitespace($state);
                $source .= $separator;
                $expected .= $separator;
            }
        }

        $source .= $this->whitespace($state).'</'.$tag.'>';
        $expected .= '</'.$tag.'>';

        return [$source, $expected];
    }

    protected function whitespace(&$state)
    {
        $whitespace = [' ', '  ', "\n", "\n  ", "\t", "\n\t"];

        return $whitespace[$this->random($state) % count($whitespace)];
    }

    protected function random(&$state)
    {
        $state = (int) (($state * 1103515245 + 12345) & 0x7FFFFFFF);

        return $state;
    }
}
