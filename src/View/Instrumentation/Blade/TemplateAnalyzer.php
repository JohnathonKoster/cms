<?php

namespace Statamic\View\Instrumentation\Blade;

use Forte\Ast\Components\ComponentNode;
use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Parser\ParserOptions;
use Statamic\View\Instrumentation\CharacterOffsets;
use Statamic\View\Instrumentation\ComponentPrefixes;
use Statamic\View\Instrumentation\HtmlContext;
use Statamic\View\Instrumentation\HtmlSpec;
use Statamic\View\Instrumentation\Span;
use Statamic\View\Instrumentation\TemplateRegion;

/**
 * Normalizes a Forte-parsed Blade template into the engine-neutral shapes
 * the instrumentation core consumes: instrumentable regions with byte
 * extents and HtmlContext descriptions, plus the element facts needed to
 * apply attribute layers.
 *
 * This is the only class that touches Forte types; nothing here loads
 * unless Forte is installed.
 *
 * @internal
 */
class TemplateAnalyzer
{
    /**
     * Paired directives that never become marker pairs.
     *
     * `antlers` is handled by its own pipeline. The rest either hold content
     * that is not HTML (`php`), must reach the browser verbatim (`verbatim`),
     * or describe a component's interface rather than its output.
     */
    private const UNINSTRUMENTED_DIRECTIVES = [
        'antlers', 'verbatim', 'php', 'slot', 'props', 'aware', 'error',
    ];

    /** @var string[] */
    protected array $componentPrefixes;

    /**
     * Indexes of nodes belonging to an element's tag markup, for the current
     * analysis.
     *
     * @var array<int, true>
     */
    protected array $internalNodeIndexes = [];

    /**
     * @param  string[]|null  $componentPrefixes
     */
    public function __construct(?array $componentPrefixes = null)
    {
        $this->componentPrefixes = $componentPrefixes ?? ComponentPrefixes::DEFAULTS;
    }

    public static function isAvailable(): bool
    {
        return class_exists(Document::class);
    }

    /**
     * @return array{regions: TemplateRegion[], elements: array<int, array{insertAt: int, hasAttribute: callable}>}
     */
    public function analyze(string $template, bool $commentsAtDocumentRoot = true): array
    {
        // Accepting unknown directives lets Forte pair custom blocks —
        // including @antlers/@endantlers — instead of treating them as text.
        $options = ParserOptions::make()->acceptAllDirectives();

        foreach ($this->componentPrefixes as $prefix) {
            $options->withComponentPrefix($prefix);
        }

        $document = Document::parse($template, $options);

        $elements = [];
        $pending = [];
        $antlersRanges = [];
        $directiveBlocks = [];

        // @antlers blocks are handed to the Antlers instrumentation pipeline
        // wholesale; echoes Forte lexed inside them are Antlers syntax, not
        // Blade, and are excluded from the echo walk below.
        foreach ($document->allOfType(DirectiveBlockNode::class, true) as $block) {
            if (! $block->isDirectiveNamed('antlers')) {
                $directiveBlocks[] = $block;

                continue;
            }

            $innerStart = $block->startDirective()?->endOffset();
            $innerEnd = $block->endDirective()?->startOffset();

            if ($innerStart === null || $innerEnd === null || $innerEnd <= $innerStart) {
                continue;
            }

            $antlersRanges[] = [$block->startOffset(), $block->endOffset()];

            $pending[] = [
                'type' => TemplateRegion::TYPE_ANTLERS_BLOCK,
                'expression' => '@antlers',
                'line' => $block->startLine(),
                'start' => $block->startOffset(),
                'end' => $block->endOffset(),
                'innerStart' => $innerStart,
                'innerEnd' => $innerEnd,
                'context' => $this->contextFor($block, null, $elements),
                'node' => $block,
            ];
        }

        // Nodes appearing inside an element's own tag markup (its name or
        // attributes) are "internal" — they belong to the tag, not its
        // content, even though their ancestor chain says otherwise.
        $internalOwners = [];
        $this->internalNodeIndexes = [];

        foreach ($document->allOfType(ElementNode::class, true) as $element) {
            foreach ($element->getInternalNodes() as $internal) {
                $this->internalNodeIndexes[$internal->index()] = true;
            }

            foreach ($element->getInternalEchoes() as $internal) {
                $internalOwners[$internal->index()] = $element;
            }
        }

        // Paired directives bracket their body the way an Antlers tag pair
        // does, so they become pair regions with one marker at each boundary.
        foreach ($directiveBlocks as $block) {
            $pair = $this->directivePair($block, $antlersRanges, $elements);

            if ($pair !== null) {
                $pending[] = $pair;
            }
        }

        foreach ($document->allEchoes(true) as $echo) {
            $start = $echo->startOffset();

            if ($this->withinRanges($start, $antlersRanges)) {
                continue;
            }

            $owner = $internalOwners[$echo->index()] ?? null;

            $pending[] = [
                'type' => TemplateRegion::TYPE_SINGLE,
                'expression' => trim($echo->expression()),
                'line' => $echo->startLine(),
                'start' => $start,
                'end' => $echo->endOffset(),
                'innerStart' => null,
                'innerEnd' => null,
                'context' => $this->contextFor($echo, $owner, $elements),
                'node' => $echo,
            ];
        }

        usort($pending, fn ($left, $right) => $left['start'] <=> $right['start']);

        // Forte reports byte offsets; metadata carries character offsets on
        // every engine, so convert the extents once.
        $byteExtents = [];

        foreach ($pending as $entry) {
            $byteExtents[] = $entry['start'];
            $byteExtents[] = $entry['end'];
        }

        $characterOffsets = CharacterOffsets::toCharacters($template, $byteExtents);
        $regions = [];

        foreach ($pending as $entry) {
            $regions[] = new TemplateRegion(
                Span::ENGINE_BLADE,
                $entry['type'],
                $entry['expression'],
                $entry['line'],
                $characterOffsets[$entry['start']],
                $characterOffsets[$entry['end']],
                $entry['start'],
                $entry['end'],
                $entry['context'],
                $entry['node'],
                $this->isCommentSafe($entry, $commentsAtDocumentRoot),
                $entry['innerStart'],
                $entry['innerEnd']
            );
        }

        return ['regions' => $regions, 'elements' => $elements];
    }

    /**
     * Whether a pending region's boundaries can carry comment markers.
     *
     * @param  array<string, mixed>  $entry
     */
    protected function isCommentSafe(array $entry, bool $commentsAtDocumentRoot): bool
    {
        /** @var HtmlContext $context */
        $context = $entry['context'];

        if (! $context->isSafeForHtmlComments()) {
            return false;
        }

        // Root-level markers are withheld for registered views, whose final
        // HTML parent belongs to whatever included them. An @antlers block is
        // analyzed as its own document, so it is exempt.
        if ($entry['type'] !== TemplateRegion::TYPE_ANTLERS_BLOCK
            && ! $commentsAtDocumentRoot
            && $context->elementStack === []) {
            return false;
        }

        $closingContext = $entry['closingContext'] ?? null;

        if ($closingContext === null) {
            return true;
        }

        return $closingContext->isSafeForHtmlComments()
            && $context->sharesElementContainerWith($closingContext);
    }

    /**
     * Normalizes a paired Blade directive into a pending pair region, or null
     * when it is not one this analyzer instruments.
     *
     * @param  array<int, array{0: int, 1: int}>  $antlersRanges
     * @param  array<int, array{insertAt: int, hasAttribute: callable}>  $elements
     * @return array<string, mixed>|null
     */
    protected function directivePair(DirectiveBlockNode $block, array $antlersRanges, array &$elements): ?array
    {
        if ($this->withinRanges($block->startOffset(), $antlersRanges)) {
            return null;
        }

        if (in_array(strtolower($block->nameText()), self::UNINSTRUMENTED_DIRECTIVES, true)) {
            return null;
        }

        $start = $block->startDirective();
        $end = $this->closingDirectiveFor($block, $start);

        // Without a closing directive there is no second boundary to mark.
        // The block's own endOffset() is not a substitute: when the closer was
        // not paired it runs to wherever the parse recovered, which can be
        // past the enclosing element.
        if ($start === null || $end === null || $end->endOffset() <= $start->startOffset()) {
            return null;
        }

        // A directive in an element's tag markup is not element content, even
        // though its ancestor chain looks like it. Marking there would put a
        // comment inside the opening tag.
        if ($this->isInternalToAnElement($start) || $this->isInternalToAnElement($end)) {
            return null;
        }

        // Both boundaries must be independently safe and land under the same
        // static container, or the pair's markers could end up with different
        // parents — the same rule Antlers tag pairs follow.
        $startContext = $this->contextFor($start, null, $elements);
        $endContext = $this->contextFor($end, null, $elements);

        $expression = trim((string) $block->arguments());

        return [
            'type' => TemplateRegion::TYPE_PAIR,
            'expression' => rtrim('@'.$block->nameText().' '.$expression),
            'line' => $block->startLine(),
            'start' => $start->startOffset(),
            'end' => $end->endOffset(),
            'innerStart' => null,
            'innerEnd' => null,
            'context' => $startContext,
            'closingContext' => $endContext,
            'node' => $block,
        ];
    }

    /**
     * The directive that closes a block: its last child directive that is
     * neither the opener nor an intermediate branch such as @else or @empty.
     */
    protected function closingDirectiveFor(DirectiveBlockNode $block, ?DirectiveNode $start): ?DirectiveNode
    {
        $closer = null;

        foreach ($block->children() as $child) {
            if (! $child instanceof DirectiveNode
                || $child === $start
                || $child->isIntermediate()) {
                continue;
            }

            $closer = $child;
        }

        return $closer;
    }

    /**
     * Whether a node belongs to an element's tag name or attributes rather
     * than to its content.
     */
    protected function isInternalToAnElement($node): bool
    {
        return isset($this->internalNodeIndexes[$node->index()]);
    }

    /**
     * Builds the HtmlContext for a node from its ancestor chain. Blade
     * components are recorded as dynamic elements: a component renders
     * arbitrary markup, so nothing beneath one can be classified safely —
     * the same stance the Antlers scanner takes for `<{{ tag }}>`.
     *
     * @param  array<int, array{nameEnd: int, attributeNames: array<int, string>}>  $elements
     */
    protected function contextFor($node, ?ElementNode $owner, array &$elements): HtmlContext
    {
        $stack = [];
        $offsets = [];

        for ($ancestor = $node->getParent(); $ancestor !== null; $ancestor = $ancestor->getParent()) {
            if ($this->isComponent($ancestor)) {
                array_unshift($stack, HtmlContext::DYNAMIC_ELEMENT);
                array_unshift($offsets, null);

                continue;
            }

            if ($ancestor instanceof ElementNode) {
                array_unshift($stack, strtolower($ancestor->tagNameText()));
                array_unshift($offsets, $this->registerElement($ancestor, $elements));
            }
        }

        if ($owner !== null) {
            if ($this->isComponent($owner)) {
                return new HtmlContext(HtmlContext::KIND_TAG_OPEN, null, null, [HtmlContext::DYNAMIC_ELEMENT]);
            }

            [$kind, $attributeName] = $this->attributePositionFor($node, $owner);

            return new HtmlContext(
                $kind,
                strtolower($owner->tagNameText()),
                $attributeName,
                $stack,
                $this->registerElement($owner, $elements)
            );
        }

        // The nearest raw-text ancestor governs: markup inside it is
        // character data to the browser.
        for ($index = count($stack) - 1; $index >= 0; $index--) {
            if (isset(HtmlSpec::RAW_TEXT_ELEMENTS[$stack[$index]])) {
                return new HtmlContext(
                    HtmlContext::KIND_RAW_TEXT,
                    $stack[$index],
                    null,
                    $stack,
                    $offsets[$index]
                );
            }
        }

        $innermost = $stack === [] ? null : $stack[count($stack) - 1];
        $innermostOffset = $offsets === [] ? null : $offsets[count($offsets) - 1];

        return new HtmlContext(
            HtmlContext::KIND_ELEMENT_CONTENT,
            $innermost === HtmlContext::DYNAMIC_ELEMENT ? null : $innermost,
            null,
            $stack,
            $innermostOffset
        );
    }

    /**
     * Locates which attribute value holds an internal echo, when it sits in
     * one; otherwise the echo is loose tag markup.
     *
     * @return array{0: string, 1: string|null}
     */
    protected function attributePositionFor($node, ElementNode $owner): array
    {
        foreach ($owner->attributes()->all() as $attribute) {
            $value = $attribute->value();

            if ($value === null) {
                continue;
            }

            foreach ($value->getParts() as $part) {
                // Static value fragments are plain strings; only dynamic
                // parts are nodes.
                if (is_object($part) && method_exists($part, 'index') && $part->index() === $node->index()) {
                    return [HtmlContext::KIND_ATTRIBUTE_VALUE, strtolower($attribute->nameText())];
                }
            }
        }

        return [HtmlContext::KIND_TAG_OPEN, null];
    }

    /**
     * Records the element facts the attribute-layer emitter needs, keyed by
     * the element's start byte offset (which doubles as the context's
     * element identity).
     *
     * @param  array<int, array{insertAt: int, hasAttribute: callable}>  $elements
     */
    protected function registerElement(ElementNode $element, array &$elements): int
    {
        $start = $element->startOffset();

        if (! isset($elements[$start])) {
            $names = [];

            foreach ($element->attributes()->all() as $attribute) {
                $names[] = strtolower($attribute->nameText());
            }

            $elements[$start] = [
                'insertAt' => $element->tagName()->endOffset(),
                'hasAttribute' => fn ($name) => in_array(strtolower($name), $names, true),
            ];
        }

        return $start;
    }

    protected function isComponent($element): bool
    {
        return $element instanceof ComponentNode
            || ($element instanceof ElementNode
                && ComponentPrefixes::matches($element->tagNameText(), $this->componentPrefixes));
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $ranges
     */
    protected function withinRanges(int $offset, array $ranges): bool
    {
        foreach ($ranges as [$start, $end]) {
            if ($offset >= $start && $offset < $end) {
                return true;
            }
        }

        return false;
    }
}
