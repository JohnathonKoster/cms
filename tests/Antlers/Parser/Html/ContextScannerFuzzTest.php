<?php

namespace Tests\Antlers\Parser\Html;

use Statamic\View\Antlers\Language\Nodes\AntlersNode;
use Statamic\View\Antlers\Language\Nodes\LiteralNode;
use Statamic\View\Antlers\Language\Parser\DocumentParser;
use Statamic\View\Instrumentation\Antlers\ContextScanner;
use Statamic\View\Instrumentation\HtmlContext as Context;
use Tests\Antlers\ParserTestCase;

/**
 * Property-based tests for the ContextScanner.
 *
 * Two strategies are used: a chaos fuzzer that feeds randomly assembled (and
 * frequently malformed) markup through the scanner and asserts structural
 * invariants, and a grammar fuzzer that generates well-formed documents while
 * recording ground truth for every Antlers region it places, then asserts the
 * scanner reaches the same conclusion.
 */
class ContextScannerFuzzTest extends ParserTestCase
{
    private const VALID_KINDS = [
        Context::KIND_ELEMENT_CONTENT,
        Context::KIND_TAG_OPEN,
        Context::KIND_ELEMENT_NAME,
        Context::KIND_ATTRIBUTE_NAME,
        Context::KIND_ATTRIBUTE_VALUE,
        Context::KIND_RAW_TEXT,
        Context::KIND_COMMENT,
        Context::KIND_DOCTYPE,
    ];

    private $markerCount = 0;

    public function test_chaos_documents_uphold_scanner_invariants()
    {
        mt_srand(20260709);

        $junk = [
            '<div class="a">', '</div>', '<img src=x>', 'plain text & stuff',
            '<<<', '>>>', '<a b=">\' c>', '</', '<!-- junk -->', '<!--', '-->',
            '<script>var a=1;</script>', '<script>', '</script>',
            '<style>.a color:red</style>', '<textarea>', '</textarea>',
            '<!doctype html>', '<![CDATA[ x ]]>', '<![CDATA[', ']]>',
            '<?xml?>', "'", '"', '=', '= "', 'a < 5', 'b > 3', '<em', 'href=',
            '/>', '<br/ >', '<div/ >', '<a href = ', '&amp; & <',
            '<SCRIPT>', '</SCRIPT >', 'héllo ünïcode', '<div data = "', '" >',
            '<input disabled', '>', '<p', ' ', '<!---->', '<!----->',
            '<table>', '<tbody>', '<tr>', '<td>', '</td>', '</tr>', '</table>',
            '<caption>', '<colgroup>', '<col>', '<select>', '<option>', '</select>',
            '<form>', '</form>', '<template>', '</template>', '<b>', '</b>',
            '<i>', '</i>', '<a href="#">', '</a>', '<nobr>', '</nobr>',
            '<svg>', '<foreignObject>', '</foreignObject>', '</svg>',
            '<math>', '<mtext>', '</mtext>', '</math>',
        ];

        $antlers = [
            '{{ var }}', '{{ x }}', '{{ a > b }}', '{{ "str with <div>" }}',
            '{{ if y }}ok{{ /if }}', '{{ items }}mid{{ /items }}',
            '{{# a comment #}}', '@{{ escaped }}',
            // Ends at the `}}` inside the string; the trailing `" }}` becomes
            // literal text and must not desynchronize the scanner.
            '{{ frag == "}}',
        ];

        $parsed = 0;

        for ($iteration = 0; $iteration < 1500; $iteration++) {
            $pieces = [];
            $junkCount = mt_rand(2, 8);

            for ($i = 0; $i < $junkCount; $i++) {
                $pieces[] = $junk[mt_rand(0, count($junk) - 1)];
            }

            $antlersCount = mt_rand(1, 4);

            for ($i = 0; $i < $antlersCount; $i++) {
                array_splice($pieces, mt_rand(0, count($pieces)), 0, [
                    $antlers[mt_rand(0, count($antlers) - 1)],
                ]);
            }

            $template = implode('', $pieces);

            try {
                $parser = new DocumentParser();
                $parser->parse($template);
            } catch (\Exception $e) {
                continue;
            }

            $parsed++;
            $nodes = $parser->getNodes();

            (new ContextScanner)->annotate($nodes);

            $firstPass = [];

            foreach ($nodes as $node) {
                if ($node instanceof LiteralNode) {
                    continue;
                }

                $this->assertNotNull($node->htmlContext, 'Missing context in: '.$template);
                $this->assertContains($node->htmlContext->kind, self::VALID_KINDS, 'Invalid kind in: '.$template);
                $this->assertIsArray($node->htmlContext->elementStack);

                $firstPass[] = [
                    $node->htmlContext->kind,
                    $node->htmlContext->elementName,
                    $node->htmlContext->attributeName,
                    $node->htmlContext->elementStack,
                    $node->htmlContext->elementStartOffset,
                ];
            }

            (new ContextScanner)->annotate($nodes);

            $secondPass = [];

            foreach ($nodes as $node) {
                if ($node instanceof LiteralNode) {
                    continue;
                }

                $secondPass[] = [
                    $node->htmlContext->kind,
                    $node->htmlContext->elementName,
                    $node->htmlContext->attributeName,
                    $node->htmlContext->elementStack,
                    $node->htmlContext->elementStartOffset,
                ];
            }

            $this->assertSame($firstPass, $secondPass, 'Annotation is not deterministic for: '.$template);
        }

        $this->assertGreaterThan(1200, $parsed, 'Too many chaos documents failed to parse.');
    }

    public function test_generated_documents_match_recorded_ground_truth()
    {
        mt_srand(987654321);

        for ($iteration = 0; $iteration < 1000; $iteration++) {
            $this->markerCount = 0;
            $expectations = [];
            $blocks = mt_rand(2, 6);
            $template = '';

            for ($i = 0; $i < $blocks; $i++) {
                $template .= $this->buildBlock($expectations, 0);
            }

            $nodes = $this->parseNodes($template);

            (new ContextScanner)->annotate($nodes);

            $antlersNodes = [];

            foreach ($nodes as $node) {
                if ($node instanceof AntlersNode && ! $node->isComment) {
                    $antlersNodes[] = $node;
                }
            }

            $this->assertCount(count($expectations), $antlersNodes, 'Node/expectation mismatch for: '.$template);

            foreach ($antlersNodes as $index => $node) {
                $expected = $expectations[$index];
                $context = $node->htmlContext;
                $label = sprintf('marker #%d (%s) in: %s', $index, trim($node->content), $template);

                $this->assertNotNull($context, 'Missing context for '.$label);
                $this->assertSame($expected['kind'], $context->kind, 'Wrong kind for '.$label);

                if (array_key_exists('element', $expected)) {
                    $this->assertSame($expected['element'], $context->elementName, 'Wrong element for '.$label);
                }

                if (array_key_exists('attribute', $expected)) {
                    $this->assertSame($expected['attribute'], $context->attributeName, 'Wrong attribute for '.$label);
                }

                if ($context->kind === Context::KIND_TAG_OPEN || $context->kind === Context::KIND_ATTRIBUTE_VALUE) {
                    $this->assertNotNull($context->elementStartOffset, 'Missing element start for '.$label);
                    $this->assertSame('<', mb_substr($template, $context->elementStartOffset, 1), 'Element start is not a < for '.$label);

                    if ($context->elementName !== null) {
                        $sourceName = mb_substr($template, $context->elementStartOffset + 1, strlen($context->elementName));

                        $this->assertSame(
                            $context->elementName,
                            strtolower($sourceName),
                            'Element name does not follow the start offset for '.$label
                        );
                    }
                }
            }
        }
    }

    private function buildBlock(array &$expectations, int $depth): string
    {
        $choice = mt_rand(0, 11);

        if ($depth > 2 && $choice > 5) {
            $choice = mt_rand(0, 5);
        }

        switch ($choice) {
            case 0:
            case 1:
                return $this->buildTextBlock($expectations);
            case 2:
                return $this->buildCommentBlock($expectations);
            case 3:
                return $this->buildVoidElement($expectations);
            case 4:
                return $this->buildRawTextBlock($expectations);
            case 5:
                return $this->buildCdataBlock($expectations);
            case 6:
                return $this->buildDoctypeBlock($expectations);
            default:
                return $this->buildElementBlock($expectations, $depth);
        }
    }

    private function buildTextBlock(array &$expectations): string
    {
        $text = $this->randomText();

        if (mt_rand(0, 1) === 1) {
            $text .= $this->marker($expectations, ['kind' => Context::KIND_ELEMENT_CONTENT]).$this->randomText();
        }

        return $text;
    }

    private function buildCommentBlock(array &$expectations): string
    {
        $inner = $this->randomText(['>', '<']);

        if (mt_rand(0, 1) === 1) {
            $inner .= ' '.$this->marker($expectations, ['kind' => Context::KIND_COMMENT]).' '.$this->randomText();
        }

        return '<!-- '.$inner.' -->';
    }

    private function buildVoidElement(array &$expectations): string
    {
        $name = ['img', 'br', 'input', 'hr'][mt_rand(0, 3)];
        $tag = '<'.$name;

        $tag .= $this->buildAttributes($expectations, strtolower($name));

        return $tag.(mt_rand(0, 1) === 1 ? '/>' : '>');
    }

    private function buildRawTextBlock(array &$expectations): string
    {
        $names = ['script', 'style', 'textarea', 'title', 'noscript', 'iframe', 'xmp'];
        $name = $names[mt_rand(0, count($names) - 1)];
        $inner = $this->randomRawText();

        if (mt_rand(0, 2) > 0) {
            $inner .= $this->marker($expectations, [
                'kind' => Context::KIND_RAW_TEXT,
                'element' => $name,
            ]).$this->randomRawText();
        }

        $close = mt_rand(0, 3) === 0 ? '</'.$name.' >' : '</'.$name.'>';

        return '<'.$name.'>'.$inner.$close;
    }

    private function buildCdataBlock(array &$expectations): string
    {
        $inner = $this->randomText(['<']);

        if (mt_rand(0, 1) === 1) {
            $inner .= ' '.$this->marker($expectations, ['kind' => Context::KIND_DOCTYPE]).' ';
        }

        return '<![CDATA['.$inner.']]>';
    }

    private function buildDoctypeBlock(array &$expectations): string
    {
        if (mt_rand(0, 1) === 1) {
            return '<!DOCTYPE '.$this->marker($expectations, ['kind' => Context::KIND_DOCTYPE]).' html>';
        }

        return '<!DOCTYPE html>';
    }

    private function buildElementBlock(array &$expectations, int $depth): string
    {
        $names = ['div', 'span', 'p', 'a', 'section', 'x-card', 'SpAn', 'DIV', 'ul', 'li'];
        $name = $names[mt_rand(0, count($names) - 1)];
        $tag = '<'.$name;

        $tag .= $this->buildAttributes($expectations, strtolower($name));

        if (mt_rand(0, 4) === 0) {
            $tag .= ' '.$this->marker($expectations, [
                'kind' => Context::KIND_TAG_OPEN,
                'element' => strtolower($name),
            ]);
        }

        $tag .= '>';

        $children = mt_rand(0, 2);

        for ($i = 0; $i < $children; $i++) {
            $tag .= $this->buildBlock($expectations, $depth + 1);
        }

        if (mt_rand(0, 3) === 0) {
            $tag .= $this->marker($expectations, ['kind' => Context::KIND_ELEMENT_CONTENT]);
        }

        $close = mt_rand(0, 4) === 0 ? '</'.$name.' >' : '</'.$name.'>';

        return $tag.$close;
    }

    private function buildAttributes(array &$expectations, string $elementName): string
    {
        $attributes = '';
        $names = ['class', 'data-x', 'title', 'href', 'x-on:click', '@click'];
        $count = mt_rand(0, 2);

        for ($i = 0; $i < $count; $i++) {
            $attribute = $names[mt_rand(0, count($names) - 1)];
            $style = mt_rand(0, 5);

            if ($style === 0) {
                $attributes .= ' '.$attribute;

                continue;
            }

            $equals = mt_rand(0, 3) === 0 ? ' = ' : '=';
            $quote = mt_rand(0, 2) === 0 ? "'" : '"';
            $value = $this->randomAttributeValue($quote);

            if ($style === 1) {
                $attributes .= ' '.$attribute.$equals.$this->randomAttributeValue(null);

                continue;
            }

            if (mt_rand(0, 2) === 0) {
                $value .= $this->marker($expectations, [
                    'kind' => Context::KIND_ATTRIBUTE_VALUE,
                    'element' => $elementName,
                    'attribute' => $attribute,
                ]).$this->randomAttributeValue($quote);
            }

            $attributes .= ' '.$attribute.$equals.$quote.$value.$quote;
        }

        return $attributes;
    }

    private function marker(array &$expectations, array $expected): string
    {
        $name = 'v'.$this->markerCount;
        $this->markerCount++;
        $expectations[] = $expected;

        return '{{ '.$name.' }}';
    }

    private function randomText(array $extras = []): string
    {
        $words = ['alpha', 'beta', 'gamma', 'héllo', 'ünïcode', 'text', '5', 'and', 'a &amp; b'];
        $count = mt_rand(1, 4);
        $out = [];

        for ($i = 0; $i < $count; $i++) {
            $out[] = $words[mt_rand(0, count($words) - 1)];
        }

        foreach ($extras as $extra) {
            if (mt_rand(0, 1) === 1) {
                $out[] = $extra === '<' ? 'a < 5' : 'b '.$extra.' 3';
            }
        }

        return ' '.implode(' ', $out).' ';
    }

    private function randomRawText(): string
    {
        $snippets = [
            'var a = 1;', 'if (a > 2) call(a);', "b = 'x';", 'c = "y > z";',
            '.rule color: red;', 'plain content é', 'a && b',
        ];

        return ' '.$snippets[mt_rand(0, count($snippets) - 1)].' ';
    }

    private function randomAttributeValue(?string $quote): string
    {
        if ($quote === null) {
            $values = ['x', 'foo', '12', 'a-b', '/path/to'];

            return $values[mt_rand(0, count($values) - 1)];
        }

        $values = ['hero', 'a b c', '/path?x=1', 'é ü', 'a > b', 'x < y'];
        $value = $values[mt_rand(0, count($values) - 1)];

        return str_replace($quote, '', $value);
    }
}
