<?php

namespace Tests\Antlers\Parser\Html;

use Statamic\View\Antlers\Language\Nodes\AntlersNode;
use Statamic\View\Antlers\Language\Parser\DocumentParser;
use Tests\Antlers\ParserTestCase;

/**
 * Deterministic recovery properties for the lazy HTML sidecar graph.
 */
class DocumentFuzzTest extends ParserTestCase
{
    public function test_forced_recovery_render_preserves_every_source_byte()
    {
        $state = 0x71AB;
        $tokens = [
            '<div>', '</div>', '<p>', '</p>', '<b>', '</b>', '<i>', '</i>',
            '<table>', '<tbody>', '<tr>', '<td>', '</td>', '</tr>', '</table>',
            '<select>', '<option>', '</select>', '<form>', '</form>',
            '<template>', '</template>', '<svg>', '<g>', '</g>', '</svg>',
            '<!--x-->', ' text ',
        ];

        for ($case = 0; $case < 1000; $case++) {
            $parts = [];
            $length = 10 + $this->nextRandom($state, 30);

            for ($index = 0; $index < $length; $index++) {
                $parts[] = $index % 6 === 3
                    ? '{{ forced_'.$case.'_'.$index.' }}'
                    : $tokens[$this->nextRandom($state, count($tokens))];
            }

            $template = implode('', $parts);
            $parser = new DocumentParser();
            $parser->parse($template);

            // Bypass the clean-document source shortcut so this exercises
            // recovered tree serialization and every source anchor.
            $this->assertSame(
                $template,
                $parser->html()->markDirty()->toHtml(),
                'Forced graph render changed case '.$case
            );
        }
    }

    public function test_bogus_html_always_round_trips_and_maps_every_antlers_node()
    {
        $state = 0x5EED1234;

        for ($case = 0; $case < 125; $case++) {
            $template = $this->randomTemplate($state, $case);
            $parser = new DocumentParser();
            $parser->parse($template);
            $document = $parser->html();

            $this->assertSame($template, $document->toHtml(), 'Round trip failed for case '.$case);

            foreach ($parser->getNodes() as $node) {
                if (! $node instanceof AntlersNode) {
                    continue;
                }

                $graphNode = $node->htmlNode();

                $this->assertNotNull($graphNode, 'Missing Antlers graph node for case '.$case);
                $this->assertSame($graphNode, $document->node($node));

                // Navigation must terminate even when the source contains
                // arbitrarily mismatched and implicitly closed elements.
                $ancestor = $graphNode;
                $depth = 0;

                while ($ancestor->parent() !== null) {
                    $ancestor = $ancestor->parent();
                    $depth++;

                    $this->assertLessThan(64, $depth, 'Parent cycle detected for case '.$case);
                }
            }
        }
    }

    protected function randomTemplate(&$state, $case)
    {
        $tokens = [
            '<div>', '</div>', '<p>', '</p>', '<span>', '</span>',
            '<ul>', '<li>', '</li>', '</ul>', '<table>', '<tbody>',
            '<tr>', '<td>', '</td>', '</tr>', '</tbody>', '</table>',
            '<caption>', '</caption>', '<colgroup>', '<col>', '</colgroup>',
            '<select>', '<option>', '</option>', '<input>', '</select>',
            '<form>', '</form>', '<template>', '</template>',
            '<b>', '</b>', '<i>', '</i>', '<a href="#">', '</a>',
            '<strong>', '</strong>', '<nobr>', '</nobr>',
            '<svg>', '<g>', '</g>', '<foreignObject>', '</foreignObject>', '</svg>',
            '<math>', '<mtext>', '</mtext>', '</math>',
            '<h1>', '<h2>', '</h1>', '</h2>', '<button>', '</button>',
            '<script>', '</script>', '</scripture>', '<textarea>', '</textarea>',
            '<!-- comment -->', '<!-->', '<!--->', '<!broken "still > here">',
            '<?bogus?>', '<![CDATA[not html]]>', '<>', '<', '1 < 2',
            '<img>', '<br/>', '<x-card data-value="a > b">', '</x-card>',
            ' text ', "\n", '&amp;', '<div title="unterminated >',
            // Regions that end at a `}}` inside a quoted string exercise the
            // early-termination recovery path.
            '<i data-q="{{ q == "}}">', '{{ frag == "}}', '" }}-tail"></i>',
        ];
        $parts = [];
        $length = 20 + $this->nextRandom($state, 25);

        for ($index = 0; $index < $length; $index++) {
            if ($index % 5 === 2 || $this->nextRandom($state, 7) === 0) {
                $parts[] = '{{ fuzz_'.$case.'_'.$index.' }}';
            } else {
                $parts[] = $tokens[$this->nextRandom($state, count($tokens))];
            }
        }

        return implode('', $parts);
    }

    protected function nextRandom(&$state, $maximum)
    {
        $state = (int) (($state * 1103515245 + 12345) & 0x7FFFFFFF);

        return $state % $maximum;
    }
}
