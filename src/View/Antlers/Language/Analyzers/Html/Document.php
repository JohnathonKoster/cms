<?php

namespace Statamic\View\Antlers\Language\Analyzers\Html;

use Illuminate\Support\LazyCollection;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Tappable;
use Statamic\View\Antlers\Language\Nodes\AntlersNode as LanguageAntlersNode;
use Statamic\View\Antlers\Language\Parser\DocumentParser;
use Statamic\View\Instrumentation\HtmlSpec;

/**
 * Lazy, source-preserving HTML graph for an Antlers template.
 *
 * The parser is deliberately error tolerant. Unknown and malformed markup is
 * retained verbatim, while recognized elements are recovered into a useful
 * parent/child graph. An untouched document always prints byte-for-byte as it
 * was parsed.
 */
class Document
{
    use Concerns\AdoptsFormattingElements;
    use Concerns\AnchorsSourceNodes;
    use Concerns\BuildsDocumentGraph;
    use Concerns\ClosesImpliedElements;
    use Concerns\ParsesForeignContent;
    use Concerns\RecoversTables;
    use Concerns\ScansMarkup;

    use Conditionable, Container, Tappable;

    protected static $voidElements = HtmlSpec::VOID_ELEMENTS;

    protected static $rawTextElements = HtmlSpec::RAW_TEXT_ELEMENTS;

    protected static $pClosingElements = HtmlSpec::P_CLOSING_ELEMENTS;

    protected static $impliedCloseTargets = HtmlSpec::IMPLIED_CLOSE_TARGETS;

    protected static $scopeBoundaries = HtmlSpec::SCOPE_BOUNDARIES;

    protected static $listItemScopeBoundaries = HtmlSpec::LIST_ITEM_SCOPE_BOUNDARIES;

    protected static $buttonScopeBoundaries = HtmlSpec::BUTTON_SCOPE_BOUNDARIES;

    protected static $tableScopeBoundaries = HtmlSpec::TABLE_SCOPE_BOUNDARIES;

    protected static $tableScopedStarts = HtmlSpec::TABLE_SCOPED_STARTS;

    protected static $tableStructureStarts = HtmlSpec::TABLE_STRUCTURE_STARTS;

    protected static $headingElements = HtmlSpec::HEADING_ELEMENTS;

    protected static $scopedEndTags = HtmlSpec::SCOPED_END_TAGS;

    protected static $tableSections = HtmlSpec::TABLE_SECTIONS;

    protected static $tableStructuralElements = HtmlSpec::TABLE_STRUCTURAL_ELEMENTS;

    protected static $allowedInTable = HtmlSpec::ALLOWED_IN_TABLE;

    protected static $selectTableElements = HtmlSpec::SELECT_TABLE_ELEMENTS;

    protected static $svgHtmlIntegrationPoints = HtmlSpec::SVG_HTML_INTEGRATION_POINTS;

    protected static $mathMlTextIntegrationPoints = HtmlSpec::MATHML_TEXT_INTEGRATION_POINTS;

    protected static $foreignBreakoutElements = HtmlSpec::FOREIGN_BREAKOUT_ELEMENTS;

    protected static $formattingElements = HtmlSpec::FORMATTING_ELEMENTS;

    protected static $formattingMarkerElements = HtmlSpec::FORMATTING_MARKER_ELEMENTS;

    protected static $formattingBreakStarts = HtmlSpec::FORMATTING_BREAK_STARTS;

    protected static $svgTagNames = HtmlSpec::SVG_TAG_NAMES;

    /** @var Document */
    protected $document;

    /** @var Arena */
    protected $arena;

    protected $source;

    protected $dirty = false;

    /** @var array<int, int> */
    protected $antlersNodes = [];

    /** @var array<int, Node> */
    protected $handles = [];

    /**
     * Region byte-extent columns, keyed by region start byte offset. Two
     * parallel maps instead of one map of arrays keeps per-region memory to
     * two hash entries. The ends column is only needed while building and is
     * released afterwards.
     *
     * @var array<int, int>
     */
    protected $regionEnds = [];

    /** @var array<int, LanguageAntlersNode> */
    protected $regionNodes = [];

    /** @var int[] */
    protected $regionStarts = [];

    protected $regionCursor = 0;

    /** @var int[] */
    protected $stack = [];

    /** @var array<int, int|null> Null entries are active-formatting scope markers. */
    protected $activeFormatting = [];

    protected $scriptEscape = 0;

    /** @var int|null */
    protected $openSelect;

    protected $selectInTable = false;

    /** @var int|null */
    protected $formElement;

    /** @var int|null */
    protected $textElement;

    protected $hasSeenTable = false;

    /** @var array<int, int> */
    protected $fosterCharacterTails = [];

    /** @var int */
    protected $tableStartDepth = 0;

    private function __construct($source)
    {
        $this->document = $this;
        $this->arena = new Arena();
        $this->source = $source;
    }

    /** @internal */
    public function arena()
    {
        return $this->arena;
    }

    /** @internal */
    public function handle($id)
    {
        if ($id === 0) {
            return $this;
        }

        if (isset($this->handles[$id])) {
            return $this->handles[$id];
        }

        switch ($this->arena->kind($id) & Arena::KIND_MASK) {
            case Arena::ELEMENT:
                $handle = Element::fromArena($this, $id);
                break;
            case Arena::TEXT:
                $handle = Text::fromArena($this, $id);
                break;
            case Arena::ANTLERS:
                $handle = AntlersNode::fromArena($this, $id);
                break;
            case Arena::OPAQUE:
                $handle = OpaqueNode::fromArena($this, $id);
                break;
            case Arena::SOURCE_ANCHOR:
                $handle = SourceAnchor::fromArena($this, $id);
                break;
            default:
                throw new \LogicException('Unknown HTML arena node kind.');
        }

        return $this->handles[$id] = $handle;
    }

    public static function fromParser(DocumentParser $parser)
    {
        $document = new self($parser->getParsedContent());
        $document->build($parser->getNodes());

        return $document;
    }

    public static function parse($template)
    {
        $parser = new DocumentParser();
        $parser->parse($template);

        return $parser->html();
    }

    public static function isVoidElement($name)
    {
        return isset(self::$voidElements[strtolower($name)]);
    }

    /** @internal */
    public function createArenaElement($name)
    {
        if (! is_string($name) || ! preg_match('/^[^\x00-\x20\x7f"\'<>\/=]+$/D', $name)) {
            throw new \InvalidArgumentException('Invalid HTML element name.');
        }

        $id = $this->arena->element($name, '<'.$name.'>');

        if (! self::isVoidElement($name)) {
            $this->arena->closing[$id] = '</'.$name.'>';
        }

        return $this->handle($id);
    }

    public function parent()
    {
        return null;
    }

    public function node(LanguageAntlersNode $node)
    {
        $id = $this->antlersNodes[spl_object_id($node)] ?? null;

        return $id === null ? null : $this->handle($id);
    }

    public function elements($name = null)
    {
        return $this->descendants($name);
    }

    /**
     * @return LazyCollection<int, Region>
     */
    public function regions($name = null)
    {
        return new LazyCollection(function () use ($name) {
            foreach ($this->regionStarts as $start) {
                $node = $this->regionNodes[$start];

                if (! $node instanceof LanguageAntlersNode
                    || $node->isClosingTag
                    || ! $node->isClosedBy instanceof LanguageAntlersNode
                    || ($name !== null && ($node->name === null || strcasecmp($node->name->compound, $name) !== 0))) {
                    continue;
                }

                $opening = $this->node($node);
                $closing = $this->node($node->isClosedBy);

                if ($opening instanceof AntlersNode && $closing instanceof AntlersNode) {
                    yield new Region($opening, $closing);
                }
            }
        });
    }

    public function first($name = null)
    {
        return $this->firstDescendant($name);
    }

    public function isDirty()
    {
        return $this->dirty;
    }

    public function markDirty()
    {
        $this->dirty = true;

        return $this;
    }

    public function toHtml()
    {
        return $this->dirty ? $this->renderChildren() : $this->source;
    }

    /** @internal */
    public function renderArenaChildren($parent)
    {
        $html = '';

        for ($child = $this->arena->firstChild($parent); $child !== null; $child = $this->arena->next($child)) {
            if (isset($this->arena->sourceAnchor[$child])) {
                continue;
            }

            $html .= $this->renderArenaNode($child);
        }

        return $html;
    }

    /** @internal */
    public function renderArenaNode($id)
    {
        $kind = $this->arena->kind($id);

        if (($kind & Arena::KIND_MASK) === Arena::SOURCE_ANCHOR) {
            $target = $this->arena->anchorTarget[$id] ?? null;

            if ($target === null
                || ($this->arena->sourceAnchor[$target] ?? null) !== $id
                || $this->arena->parent($target) === null) {
                return '';
            }

            return $this->renderArenaNode($target);
        }

        if (isset($this->handles[$id])) {
            return $this->handles[$id]->toHtml();
        }

        if (($kind & Arena::KIND_MASK) === Arena::ELEMENT) {
            $children = $this->renderArenaChildren($id);

            return ($kind & Arena::SYNTHETIC ? '' : $this->arena->value[$id])
                .$children
                .$this->arena->closing[$id];
        }

        return $this->arena->value[$id];
    }

    public function __toString()
    {
        return $this->toHtml();
    }
}
