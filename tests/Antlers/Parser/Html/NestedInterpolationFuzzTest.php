<?php

namespace Tests\Antlers\Parser\Html;

use Statamic\View\Antlers\Language\Nodes\AntlersNode;
use Statamic\View\Antlers\Language\Parser\DocumentParser;
use Statamic\View\Instrumentation\Antlers\ContextScanner;
use Statamic\View\Instrumentation\HtmlContext as Context;
use Tests\Antlers\ParserTestCase;

class NestedInterpolationFuzzTest extends ParserTestCase
{
    public function test_nested_interpolations_keep_the_outer_regions_html_location()
    {
        mt_srand(8675309);

        for ($iteration = 0; $iteration < 750; $iteration++) {
            $deep = mt_rand(0, 2) === 0;
            $expression = $deep
                ? '{{ outer_'.$iteration.' = {test value={collection:count from="blog"}} }}'
                : '{{ outer_'.$iteration.' = {collection:count from="blog"} }}';
            [$template, $expected] = $this->contextFixture(mt_rand(0, 6), $expression);
            $parser = new DocumentParser();
            $parser->parse($template);
            $nodes = $parser->getNodes();
            (new ContextScanner)->annotate($nodes);
            $outer = $this->outerNode($nodes);
            $interpolations = $this->interpolationNodes($outer);

            $this->assertNotEmpty($interpolations, 'No interpolation parsed in: '.$template);
            $this->assertSame($template, $parser->html()->toHtml(), 'Graph changed source: '.$template);
            $this->assertContext($expected, $outer, $template);

            foreach ($interpolations as $interpolation) {
                $this->assertContext($expected, $interpolation, $template);
                $this->assertSame($outer->htmlNode(), $interpolation->htmlNode(), 'Different HTML node in: '.$template);
                $this->assertSame($outer->parentElement(), $interpolation->parentElement(), 'Different nearest element in: '.$template);
            }

            $expectedCount = $deep ? 2 : 1;
            $this->assertCount($expectedCount, $interpolations, 'Wrong interpolation depth in: '.$template);
        }
    }

    private function contextFixture(int $kind, string $expression): array
    {
        switch ($kind) {
            case 0:
                return [
                    '<section><div>'.$expression.'</div></section>',
                    [Context::KIND_ELEMENT_CONTENT, 'div', null, ['section', 'div'], 'div'],
                ];
            case 1:
                return [
                    '<section><button wire:key="before-'.$expression.'-after">x</button></section>',
                    [Context::KIND_ATTRIBUTE_VALUE, 'button', 'wire:key', ['section'], 'button'],
                ];
            case 2:
                return [
                    "<section><button @click='run(".$expression.")'>x</button></section>",
                    [Context::KIND_ATTRIBUTE_VALUE, 'button', '@click', ['section'], 'button'],
                ];
            case 3:
                return [
                    '<section><button '.$expression.'>x</button></section>',
                    [Context::KIND_TAG_OPEN, 'button', null, ['section'], 'button'],
                ];
            case 4:
                return [
                    '<section><script>'.$expression.'</script></section>',
                    [Context::KIND_RAW_TEXT, 'script', null, ['section', 'script'], 'script'],
                ];
            case 5:
                return [
                    '<section><div><!-- '.$expression.' --></div></section>',
                    [Context::KIND_COMMENT, null, null, ['section', 'div'], 'div'],
                ];
            default:
                return [
                    '<section><textarea>'.$expression.'</textarea></section>',
                    [Context::KIND_RAW_TEXT, 'textarea', null, ['section', 'textarea'], 'textarea'],
                ];
        }
    }

    private function outerNode(array $nodes): AntlersNode
    {
        foreach ($nodes as $node) {
            if ($node instanceof AntlersNode && ! $node->isComment) {
                return $node;
            }
        }

        $this->fail('No outer Antlers node was parsed.');
    }

    /** @return AntlersNode[] */
    private function interpolationNodes(AntlersNode $node): array
    {
        $found = [];

        foreach ($node->processedInterpolationRegions as $nodes) {
            foreach ($nodes as $interpolation) {
                if (! $interpolation instanceof AntlersNode) {
                    continue;
                }

                $found[] = $interpolation;
                array_push($found, ...$this->interpolationNodes($interpolation));
            }
        }

        return $found;
    }

    private function assertContext(array $expected, AntlersNode $node, string $template): void
    {
        [$kind, $element, $attribute, $stack, $parent] = $expected;
        $context = $node->htmlContext;

        $this->assertNotNull($context, 'Missing context in: '.$template);
        $this->assertSame($kind, $context->kind, 'Wrong context kind in: '.$template);
        $this->assertSame($element, $context->elementName, 'Wrong context element in: '.$template);
        $this->assertSame($attribute, $context->attributeName, 'Wrong context attribute in: '.$template);
        $this->assertSame($stack, $context->elementStack, 'Wrong context stack in: '.$template);
        $this->assertSame($parent, $node->parentElement()?->name(), 'Wrong nearest graph element in: '.$template);
    }
}
