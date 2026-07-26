<?php

namespace Tests\Antlers\Parser;

use Statamic\View\Antlers\Language\Nodes\AntlersNode;
use Statamic\View\Antlers\Language\Parser\DocumentParser;
use Statamic\View\Instrumentation\Antlers\ContextScanner;
use Statamic\View\Instrumentation\HtmlContext;
use Tests\Antlers\ParserTestCase;

class HtmlContextResolutionTest extends ParserTestCase
{
    protected function firstAntlersNode(DocumentParser $parser): AntlersNode
    {
        foreach ($parser->getNodes() as $node) {
            if ($node instanceof AntlersNode) {
                return $node;
            }
        }

        $this->fail('The template produced no Antlers nodes.');
    }

    public function test_a_lazily_resolved_context_survives_the_parser_moving_on()
    {
        $parser = new DocumentParser;
        $parser->parse('<title>{{ alpha }}</title>');

        $node = $this->firstAntlersNode($parser);

        $this->assertSame(HtmlContext::KIND_RAW_TEXT, $node->htmlContext()->kind);
        $this->assertSame('title', $node->htmlContext()->elementName);

        // Runtime parsers reuse one DocumentParser for every render while
        // nodes persist in the render node cache.
        $parser->parse('<div>{{ beta }}</div>');

        $this->assertSame(HtmlContext::KIND_RAW_TEXT, $node->htmlContext()->kind);
        $this->assertSame('title', $node->htmlContext()->elementName);
    }

    public function test_deferred_resolution_never_describes_a_different_document()
    {
        $parser = new DocumentParser;
        $parser->parse('<title>{{ alpha }}</title>');

        $node = $this->firstAntlersNode($parser);

        $parser->parse('<div>{{ beta }}</div>');

        // The parser no longer holds this node's source, so there is nothing
        // truthful to report.
        $this->assertNull($node->htmlContext());
        $this->assertNull($node->htmlNode());
        $this->assertNull($node->parentElement());
    }

    public function test_a_context_that_resolves_to_null_does_not_rescan_on_every_call()
    {
        $parser = new DocumentParser;
        $parser->parse('{{ alpha }}');

        $node = $this->firstAntlersNode($parser);

        // The first call scans the document once.
        $this->assertNotNull($node->htmlContext());

        // Clearing the stamp stands in for any node the scanner leaves
        // without a context. The scan is not repeated, so it stays null
        // instead of walking the whole document again on every call.
        $node->htmlContext = null;

        $this->assertNull($node->htmlContext());
        $this->assertNull($node->htmlContext());
        $this->assertNull($node->htmlContext);
    }

    public function test_eager_annotation_stamps_nodes_at_parse_time()
    {
        $parser = new DocumentParser;
        $parser->annotateHtmlContext();
        $parser->parse('<title>{{ alpha }}</title>');

        $this->assertSame(
            HtmlContext::KIND_RAW_TEXT,
            $this->firstAntlersNode($parser)->htmlContext->kind
        );
    }

    public function test_the_process_wide_toggle_still_enables_annotation()
    {
        ContextScanner::$enabled = true;

        try {
            $parser = new DocumentParser;
            $parser->parse('<title>{{ alpha }}</title>');

            $this->assertNotNull($this->firstAntlersNode($parser)->htmlContext);
        } finally {
            ContextScanner::$enabled = false;
        }
    }
}
