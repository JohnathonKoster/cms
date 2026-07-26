<?php

namespace Statamic\View\Instrumentation\Antlers;

use Statamic\View\Antlers\Language\Nodes\AntlersNode;
use Statamic\View\Antlers\Language\Nodes\EscapedContentNode;
use Statamic\View\Antlers\Language\Parser\DocumentParser;
use Statamic\View\Instrumentation\CharacterOffsets;
use Statamic\View\Instrumentation\ComponentPrefixes;
use Statamic\View\Instrumentation\HtmlContext;
use Statamic\View\Instrumentation\Span;
use Statamic\View\Instrumentation\TemplateRegion;

/**
 * The Antlers front-end for template instrumentation: parses a template,
 * annotates HTML contexts, and normalizes every instrumentable node into
 * engine-neutral TemplateRegions, plus the element facts the emitter needs
 * to apply attribute layers.
 *
 * @internal
 */
class TemplateAnalyzer
{
    /** @var ElementInspector */
    protected $inspector;

    /** @var string[] */
    protected $componentPrefixes;

    /**
     * @param  string[]|null  $componentPrefixes
     */
    public function __construct(?array $componentPrefixes = null)
    {
        $this->inspector = new ElementInspector;
        $this->componentPrefixes = $componentPrefixes ?? ComponentPrefixes::DEFAULTS;
    }

    /**
     * @param  string  $template
     * @param  bool  $collectElements  Whether attribute layers are configured.
     * @return array{regions: TemplateRegion[], elements: array<int, array{insertAt: int, hasAttribute: callable}>}
     */
    public function analyze($template, $collectElements = true, $lineOffset = 0, $commentsAtDocumentRoot = true)
    {
        $parser = new DocumentParser;
        $parser->inheritRuntimeLineSeed(false);
        $parser->parse($template);
        $nodes = $parser->getNodes();

        (new ContextScanner)
            ->treatComponentsAsDynamic()
            ->withComponentPrefixes($this->componentPrefixes)
            ->annotate($nodes);

        $pending = [];
        $characterOffsets = [];

        foreach ($nodes as $node) {
            if (! $node instanceof AntlersNode
                || $node instanceof EscapedContentNode
                || $node->isComment
                || $node->isClosingTag
                || $this->isComponentProxyNode($node)
                || ($node->name->name ?? null) === 'noparse') {
                continue;
            }

            $context = $node->htmlContext;

            if ($context === null) {
                continue;
            }

            $start = $node->startPosition->offset;
            $end = $node->endPosition->offset + 1;
            $commentSafe = $context->isSafeForHtmlComments()
                && ($commentsAtDocumentRoot || $context->elementStack !== []);
            $isPaired = $node->isPaired();

            if ($isPaired) {
                // Pair boundaries live outside the Antlers control structure
                // so loops and if/else branches always emit one balanced
                // marker pair — and only when both boundaries share one
                // static container.
                $closer = $this->closingBoundaryFor($node);
                $closingContext = $closer !== null ? $closer->htmlContext : null;

                if ($closer !== null) {
                    $end = $closer->endPosition->offset + 1;
                }

                $commentSafe = $commentSafe
                    && $closingContext !== null
                    && $closingContext->isSafeForHtmlComments()
                    && $context->sharesElementContainerWith($closingContext);
            }

            $characterOffsets[] = $start;
            $characterOffsets[] = $end;

            if ($collectElements && $context->elementStartOffset !== null && $context->canInstrumentElement()) {
                $characterOffsets[] = $context->elementStartOffset;
            }

            $pending[] = [$node, $context, $start, $end, $commentSafe, $isPaired];
        }

        if ($pending === []) {
            return ['regions' => [], 'elements' => []];
        }

        $byteOffsets = CharacterOffsets::normalizedToBytes($template, $characterOffsets);
        $sourceCharacterOffsets = CharacterOffsets::toCharacters($template, array_values($byteOffsets));
        $regions = [];
        $elements = [];

        foreach ($pending as [$node, $context, $start, $end, $commentSafe, $isPaired]) {
            $regions[] = new TemplateRegion(
                Span::ENGINE_ANTLERS,
                $isPaired ? TemplateRegion::TYPE_PAIR : TemplateRegion::TYPE_SINGLE,
                trim($node->content),
                $node->startPosition->line + $lineOffset,
                $sourceCharacterOffsets[$byteOffsets[$start]],
                $sourceCharacterOffsets[$byteOffsets[$end]],
                $byteOffsets[$start],
                $byteOffsets[$end],
                $context,
                $node,
                $commentSafe
            );

            if ($collectElements
                && $context->elementStartOffset !== null
                && $context->canInstrumentElement()
                && ! isset($elements[$context->elementStartOffset])) {
                $elements[$context->elementStartOffset] = $this->elementFacts(
                    $template,
                    $context,
                    $byteOffsets[$context->elementStartOffset]
                );
            }
        }

        return ['regions' => $regions, 'elements' => $elements];
    }

    protected function isComponentProxyNode(AntlersNode $node)
    {
        return str_starts_with(ltrim((string) $node->content), '%component_proxy:')
            || str_starts_with(ltrim((string) $node->content), '/%component_proxy:');
    }

    /**
     * @param  string  $template
     * @param  int  $byteStart
     * @return array{insertAt: int, hasAttribute: callable}
     */
    protected function elementFacts($template, HtmlContext $context, $byteStart)
    {
        $openingTag = $this->inspector->openingTagAt($template, $context->elementStartOffset, $byteStart);
        $inspector = $this->inspector;

        return [
            // The element name immediately follows `<`, so its byte length
            // positions the insertion point even for multibyte names.
            'insertAt' => $byteStart + 1 + strlen($context->elementName),
            'hasAttribute' => fn ($name) => $inspector->hasAttribute($openingTag, $name),
        ];
    }

    /**
     * Conditional branch separators are both the previous branch's closer
     * and the next branch's opener. Follow that chain to the real terminal
     * closing node so one marker pair encloses the complete condition.
     *
     * @return AntlersNode|null
     */
    protected function closingBoundaryFor(AntlersNode $node)
    {
        $closer = $node->isClosedBy;
        $visited = [];

        while ($closer instanceof AntlersNode && $closer->isClosedBy instanceof AntlersNode) {
            $identity = spl_object_id($closer);

            if (isset($visited[$identity])) {
                return null;
            }

            $visited[$identity] = true;
            $closer = $closer->isClosedBy;
        }

        return $closer instanceof AntlersNode ? $closer : null;
    }
}
