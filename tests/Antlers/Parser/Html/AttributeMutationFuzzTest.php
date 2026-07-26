<?php

namespace Tests\Antlers\Parser\Html;

use Statamic\View\Antlers\Language\Nodes\AntlersNode;
use Statamic\View\Antlers\Language\Parser\DocumentParser;
use Statamic\View\Instrumentation\Antlers\ContextScanner;
use Statamic\View\Instrumentation\HtmlContext as Context;
use Tests\Antlers\ParserTestCase;

class AttributeMutationFuzzTest extends ParserTestCase
{
    private const ATTRIBUTE_NAMES = [
        'id',
        'class',
        'data-state',
        'aria-label',
        'x-data',
        'x-init',
        '@click',
        '@click.outside',
        ':class',
        'x-bind:aria-label',
        'x-transition:enter.duration.500ms',
        'wire:key',
        'wire:click',
        'wire:model.live.debounce.250ms',
        'hx-get',
        'hx-on::after-request',
        'v-bind:class',
        'v-on:click.stop',
    ];

    public function test_generated_framework_attributes_round_trip_mutate_and_reparse()
    {
        mt_srand(20260719);

        for ($iteration = 0; $iteration < 1000; $iteration++) {
            [$template, $elementName, $attributes, $contexts] = $this->documentFixture($iteration);
            $parser = new DocumentParser();
            $parser->parse($template);
            $document = $parser->html();
            $element = $document->first($elementName);

            $this->assertNotNull($element, 'Missing generated element in: '.$template);
            $this->assertSame($template, $document->toHtml(), 'Clean round trip changed: '.$template);

            foreach ($attributes as $name => $value) {
                $this->assertSame($value, $element->attr($name), 'Wrong initial value for '.$name.' in: '.$template);
            }

            $names = array_keys($attributes);
            $changedName = $names[0];
            $removedName = $names[1];
            $replacement = 'next & {{ value == "x" ? "yes" : "no" }} "quoted"';
            $serializedReplacement = 'next &amp; {{ value == "x" ? "yes" : "no" }} &quot;quoted&quot;';

            $element
                ->setAttribute($changedName, $replacement)
                ->removeAttribute($removedName)
                ->setAttribute('data-fuzz-added', 'A & B');

            $mutated = $document->toHtml();
            $this->assertStringContainsString('{{ value == "x" ? "yes" : "no" }}', $mutated);

            $reparsed = new DocumentParser();
            $reparsed->parse($mutated);
            $reparsedElement = $reparsed->html()->first($elementName);

            $this->assertNotNull($reparsedElement, 'Mutation lost element in: '.$mutated);
            $this->assertSame($serializedReplacement, $reparsedElement->attr($changedName));
            $this->assertFalse($reparsedElement->hasAttribute($removedName));
            $this->assertSame('A &amp; B', $reparsedElement->attr('data-fuzz-added'));
            $this->assertSame($mutated, $reparsed->html()->toHtml(), 'Mutated output was not stable: '.$mutated);

            foreach ($attributes as $name => $value) {
                if ($name !== $changedName && $name !== $removedName) {
                    $this->assertSame($value, $reparsedElement->attr($name), 'Neighbor changed for '.$name.' from: '.$template.' into: '.$mutated);
                }
            }

            $this->assertRecordedContexts($parser, $contexts, $elementName, $template);
        }
    }

    private function documentFixture(int $iteration): array
    {
        $elementName = ['div', 'button', 'a', 'x-panel'][mt_rand(0, 3)];
        $names = self::ATTRIBUTE_NAMES;
        shuffle($names);
        $names = array_slice($names, 0, mt_rand(3, 8));
        $attributes = [];
        $contexts = [];
        $opening = '<'.$elementName;

        foreach ($names as $index => $name) {
            $marker = 'fuzz_'.$iteration.'_'.$index;
            [$source, $value, $hasMarker] = $this->attributeFixture($marker);
            $whitespace = [' ', '  ', "\n    ", "\t"][mt_rand(0, 3)];
            $opening .= $whitespace.$name.$source;
            $attributes[strtolower($name)] = $value;

            if ($hasMarker) {
                $contexts[$marker] = strtolower($name);
            }
        }

        $bodyMarker = 'body_'.$iteration;
        $template = $opening.'>{{ '.$bodyMarker.' }}</'.$elementName.'>';
        $contexts[$bodyMarker] = null;

        return [$template, $elementName, $attributes, $contexts];
    }

    private function attributeFixture(string $marker): array
    {
        $kind = mt_rand(0, 6);

        if ($kind === 0) {
            return ['', null, false];
        }

        $quote = $kind === 1 ? null : ($kind % 2 === 0 ? '"' : "'");
        $withMarker = $kind >= 3;

        if ($withMarker) {
            // A single closing brace inside the string keeps the expression
            // within one region: the runtime parser ends regions at the first
            // `}}` regardless of quotes.
            $value = $kind === 6
                ? 'before-{{ '.$marker.' == "}" ? "a > b" : "c" }}-after'
                : 'before-{{ '.$marker.' }}-after';
        } else {
            $value = ['alpha', 'a-b', 'open = ! open', '{ active: true }'][mt_rand(0, 3)];
        }

        if ($quote === null) {
            $value = str_replace(' ', '-', $value);
            $equals = ['=', ' =', '= '][mt_rand(0, 2)];

            return [$equals.$value, $value, $withMarker];
        }

        $equals = ['=', ' = ', '=  '][mt_rand(0, 2)];

        return [$equals.$quote.$value.$quote, $value, $withMarker];
    }

    private function assertRecordedContexts(
        DocumentParser $parser,
        array $contexts,
        string $elementName,
        string $template
    ): void {
        $nodes = $parser->getNodes();
        (new ContextScanner)->annotate($nodes);
        $seen = [];

        foreach ($nodes as $node) {
            if (! $node instanceof AntlersNode || $node->isComment) {
                continue;
            }

            $marker = null;

            foreach ($contexts as $candidate => $_) {
                if (str_contains($node->content, $candidate)) {
                    $marker = $candidate;

                    break;
                }
            }

            if ($marker === null) {
                continue;
            }

            $attribute = $contexts[$marker];
            $seen[$marker] = true;
            $this->assertSame($elementName, $node->htmlContext->elementName, 'Wrong element for '.$marker.' in: '.$template);
            $this->assertSame($elementName, $node->parentElement()->name(), 'Wrong graph parent for '.$marker.' in: '.$template);

            if ($attribute === null) {
                $this->assertSame(Context::KIND_ELEMENT_CONTENT, $node->htmlContext->kind);
                $this->assertNull($node->htmlContext->attributeName);
            } else {
                $this->assertSame(Context::KIND_ATTRIBUTE_VALUE, $node->htmlContext->kind);
                $this->assertSame($attribute, $node->htmlContext->attributeName);
            }
        }

        $this->assertSame(array_keys($contexts), array_keys($seen), 'Not every marker was parsed in: '.$template);
    }
}
