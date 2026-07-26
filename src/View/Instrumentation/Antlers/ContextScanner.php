<?php

namespace Statamic\View\Instrumentation\Antlers;

use Statamic\View\Antlers\Language\Nodes\AbstractNode;
use Statamic\View\Antlers\Language\Nodes\AntlersNode as LanguageAntlersNode;
use Statamic\View\Antlers\Language\Nodes\EscapedContentNode;
use Statamic\View\Antlers\Language\Nodes\LiteralNode;
use Statamic\View\Instrumentation\ComponentPrefixes;
use Statamic\View\Instrumentation\HtmlContext;
use Statamic\View\Instrumentation\HtmlSpec;

/**
 * Annotates parsed Antlers nodes with their surrounding HTML context in a
 * single pass over the document's literal text. Antlers regions are treated
 * as opaque spans (their inner content — which may contain `<`, `>`, or
 * quotes inside expressions — never feeds the HTML state machine), so each
 * non-literal node receives the HTML state at the offset where it appears.
 *
 * The scanner is deliberately conservative: when markup is ambiguous it
 * prefers reporting an "unsafe" context (tag-open, attribute-value, raw-text,
 * comment, doctype) over element-content, since consumers use element-content
 * to decide whether extra markup may be emitted at a node's position.
 *
 * Opt-in: enable eager annotation via the statamic.antlers.htmlContext config
 * key (wired up in the ViewServiceProvider), call AntlersNode::htmlContext()
 * to resolve one lazily, or invoke annotate() directly on a DocumentParser's
 * node list for one-off analysis.
 */
class ContextScanner
{
    use Concerns\AdoptsFormattingElements;
    use Concerns\AppliesTreeConstruction;
    use Concerns\ParsesForeignContent;
    use Concerns\ReadsTagTokens;
    use Concerns\RecoversTables;

    /**
     * Whether parsed documents are annotated automatically.
     *
     * Process-wide fallback for DocumentParser instances used outside the
     * Antlers runtime. Prefer RuntimeConfiguration::$annotateHtmlContext (set
     * from the statamic.antlers.htmlContext config key), which scopes the
     * setting to a parser instead of the process.
     *
     * @var bool
     */
    public static $enabled = false;

    const STATE_TEXT = 0;

    const STATE_TAG_OPEN = 1;

    const STATE_ATTRIBUTE_VALUE = 2;

    const STATE_CLOSING_TAG = 3;

    const STATE_COMMENT = 4;

    const STATE_RAW_TEXT = 5;

    const STATE_DOCTYPE = 6;

    const STATE_BEFORE_ATTRIBUTE_VALUE = 7;

    const STATE_CDATA = 8;

    const STATE_PLAINTEXT = 9;

    protected static $voidElements = HtmlSpec::VOID_ELEMENTS;

    protected static $rawTextElements = HtmlSpec::RAW_TEXT_ELEMENTS;

    protected static $svgHtmlIntegrationPoints = HtmlSpec::SVG_HTML_INTEGRATION_POINTS;

    protected static $svgTagNames = HtmlSpec::SVG_TAG_NAMES;

    protected static $mathMlTextIntegrationPoints = HtmlSpec::MATHML_TEXT_INTEGRATION_POINTS;

    protected static $foreignBreakoutElements = HtmlSpec::FOREIGN_BREAKOUT_ELEMENTS;

    protected static $pClosingElements = HtmlSpec::P_CLOSING_ELEMENTS;

    protected static $impliedCloseTargets = HtmlSpec::IMPLIED_CLOSE_TARGETS;

    protected static $scopeBoundaries = HtmlSpec::SCOPE_BOUNDARIES;

    protected static $listItemScopeBoundaries = HtmlSpec::LIST_ITEM_SCOPE_BOUNDARIES;

    protected static $buttonScopeBoundaries = HtmlSpec::BUTTON_SCOPE_BOUNDARIES;

    protected static $tableScopeBoundaries = HtmlSpec::TABLE_SCOPE_BOUNDARIES;

    protected static $tableScopedStarts = HtmlSpec::TABLE_SCOPED_STARTS;

    protected static $tableStructureStarts = HtmlSpec::TABLE_STRUCTURE_STARTS;

    protected static $tableSections = HtmlSpec::TABLE_SECTIONS;

    protected static $tableStructuralElements = HtmlSpec::TABLE_STRUCTURAL_ELEMENTS;

    protected static $allowedInTable = HtmlSpec::ALLOWED_IN_TABLE;

    protected static $selectTableElements = HtmlSpec::SELECT_TABLE_ELEMENTS;

    protected static $headingElements = HtmlSpec::HEADING_ELEMENTS;

    protected static $scopedEndTags = HtmlSpec::SCOPED_END_TAGS;

    protected static $formattingElements = HtmlSpec::FORMATTING_ELEMENTS;

    protected static $formattingMarkerElements = HtmlSpec::FORMATTING_MARKER_ELEMENTS;

    protected static $formattingBreakStarts = HtmlSpec::FORMATTING_BREAK_STARTS;

    /**
     * States whose spans can be fast-forwarded to the next delimiter byte.
     *
     * @var array<int, bool>
     */
    protected static $skippableStates = [
        self::STATE_TEXT => true,
        self::STATE_RAW_TEXT => true,
        self::STATE_COMMENT => true,
        self::STATE_DOCTYPE => true,
        self::STATE_CDATA => true,
        self::STATE_PLAINTEXT => true,
        self::STATE_ATTRIBUTE_VALUE => true,
    ];

    protected $state = self::STATE_TEXT;

    /**
     * @var string[]
     */
    protected $elementStack = [];

    /** @var string[] */
    protected $namespaceStack = [];

    /** @var array<int, string|null> */
    protected $elementEncodingStack = [];

    /** @var array<int, string|null> Null entries are active-formatting scope markers. */
    protected $activeFormatting = [];

    /** @var array<int, string|null> */
    protected $activeFormattingSignatures = [];

    /** @var array<int, int|null> */
    protected $activeFormattingOffsets = [];

    protected $formattingReconstructionPending = false;

    /** @var array<int, int|null> */
    protected $elementOffsetStack = [];

    protected $currentElementName = '';

    protected $currentElementStartOffset = null;

    protected $currentElementIsClosing = false;

    protected $currentElementNameComplete = false;

    /** @var bool */
    protected $currentElementNameDynamic = false;

    protected $currentElementIsComponent = false;

    protected $treatComponentsAsDynamic = false;

    /**
     * Element name prefixes treated as component boundaries.
     *
     * @var string[]
     */
    protected $componentPrefixes = ComponentPrefixes::DEFAULTS;

    /** @var string|null */
    protected $currentTagSignature;

    protected $currentAttributeName = '';

    protected $pendingAttributeName = '';

    /**
     * The most recent attribute name that was followed by whitespace, kept so
     * `class = "value"` (whitespace around the equals sign) still associates
     * the value with its attribute instead of desynchronizing the machine.
     *
     * @var string
     */
    protected $lastAttributeName = '';

    protected $attributeQuote = null;

    protected $currentAttributeValue = '';

    protected $currentAttributeValueDynamic = false;

    protected $captureCurrentAttributeValue = false;

    /** @var string|null */
    protected $currentAnnotationEncoding;

    protected $currentAnnotationEncodingSeen = false;

    protected $hasForeignFontBreakoutAttribute = false;

    protected $rawTextElement = '';

    /** @var int|null */
    protected $rawTextElementStartOffset = null;

    /**
     * Script-data escape depth (0 = none, 1 = inside `<!--`, 2 = inside a
     * nested `<script` while escaped), mirroring the HTML5 script data
     * escaped states so `</script>` inside `<!-- <script> ... -->` does not
     * end the element prematurely.
     *
     * @var int
     */
    protected $rawTextEscape = 0;

    protected $hasOpenSelect = false;

    protected $selectInTable = false;

    protected $hasFormPointer = false;

    /** @var int|null */
    protected $commentDataStartOffset = null;

    protected $componentProxyDepth = 0;

    public function treatComponentsAsDynamic($dynamic = true)
    {
        $this->treatComponentsAsDynamic = $dynamic;

        return $this;
    }

    /**
     * Replaces the element name prefixes treated as component boundaries.
     *
     * @param  string[]  $prefixes
     * @return $this
     */
    public function withComponentPrefixes(array $prefixes)
    {
        $this->componentPrefixes = $prefixes;

        return $this;
    }

    /**
     * Annotates every non-literal node in the (document-ordered) node list
     * with an HtmlContext describing where it sits in the HTML structure.
     *
     * Offsets reported on the contexts are character offsets, matching the
     * parser's startPosition/endPosition offsets.
     *
     * @param  AbstractNode[]  $nodes  The parser's flat node list.
     * @return void
     */
    public function annotate($nodes)
    {
        $nodes = array_values($nodes);

        // Annotation only stamps non-literal nodes; a literal-only document
        // (no Antlers at all) has nothing to receive a context, so skip the
        // HTML scan entirely.
        $hasAnnotatableNodes = false;

        foreach ($nodes as $node) {
            if (! $node instanceof LiteralNode) {
                $hasAnnotatableNodes = true;

                break;
            }
        }

        if (! $hasAnnotatableNodes) {
            return;
        }

        $this->resetState();

        $count = count($nodes);

        for ($index = 0; $index < $count; $index++) {
            $node = $nodes[$index];

            if ($node instanceof LanguageAntlersNode && $this->isComponentProxyNode($node)) {
                $context = $this->componentProxyDepth > 0
                    ? $this->dynamicComponentContext()
                    : $this->contextSnapshot();

                $this->applyContext($node, $context);

                if ($node->isClosingTag) {
                    $this->componentProxyDepth = max(0, $this->componentProxyDepth - 1);
                } elseif (! $node->isSelfClosing) {
                    $this->componentProxyDepth++;
                }

                continue;
            }

            if ($this->componentProxyDepth > 0) {
                if (! $node instanceof LiteralNode) {
                    $this->applyContext($node, $this->dynamicComponentContext());
                }

                continue;
            }

            if ($node instanceof LanguageAntlersNode
                && ! ($node instanceof EscapedContentNode)
                && ($node->name->name ?? null) === 'noparse') {
                $this->applyContext($node, $this->contextSnapshot());

                if (! $node->isClosingTag && $node->isClosedBy instanceof LanguageAntlersNode) {
                    $parser = $node->getParser();

                    if ($parser !== null) {
                        $this->consumeLiteral(
                            $parser->getText(
                                $node->endPosition->index + 1,
                                $node->isClosedBy->startPosition->index
                            ),
                            ($node->endPosition->offset ?? 0) + 1
                        );
                    }
                }

                continue;
            }

            if ($node instanceof EscapedContentNode) {
                $this->applyContext($node, $this->contextSnapshot());

                if (($this->state === self::STATE_TAG_OPEN || $this->state === self::STATE_CLOSING_TAG)
                    && ! $this->currentElementNameComplete) {
                    // Escaped Antlers renders literal braces. Braces can be
                    // part of an HTML tag name, but they are outside the
                    // scanner's static name grammar; never rewrite that tag
                    // using a guessed prefix.
                    $this->currentElementNameDynamic = true;
                }

                [$content, $start] = $this->escapedOutput($node);
                $this->consumeLiteral($content, $start);

                continue;
            }

            if (! ($node instanceof LiteralNode)) {
                $this->applyContext($node, $this->contextSnapshot());

                if ($this->currentTagSignature !== null) {
                    $this->currentTagSignature = null;
                }

                if ($this->captureCurrentAttributeValue
                    && ($this->state === self::STATE_ATTRIBUTE_VALUE
                    || $this->state === self::STATE_BEFORE_ATTRIBUTE_VALUE)) {
                    $this->currentAttributeValueDynamic = true;
                }

                // Once Antlers contributes any part of an element name, the
                // final name (and whether it is raw/whitespace-sensitive) is
                // unknowable until runtime. Keep that uncertainty through the
                // opening element and its descendants.
                if ((! $node instanceof LanguageAntlersNode || (! $node->isComment && ! $node->isClosingTag))
                    && ($this->state === self::STATE_TAG_OPEN || $this->state === self::STATE_CLOSING_TAG)
                    && ! $this->currentElementNameComplete) {
                    $this->currentElementNameDynamic = true;
                }

                continue;
            }

            $start = $node->startPosition->offset ?? 0;

            // The parser's literal start offsets can lag the true document
            // position for literals that sit between two Antlers regions
            // (and literal content may be shortened by escape sequences or
            // trimming). The following Antlers node's start offset is
            // authoritative, so anchor the literal against it when possible.
            if ($index + 1 < $count && ! ($nodes[$index + 1] instanceof LiteralNode)) {
                $nextStart = $nodes[$index + 1]->startPosition->offset ?? null;

                if ($nextStart !== null) {
                    $start = max(0, $nextStart - mb_strlen((string) $node->content));
                }
            }

            $this->consumeLiteral((string) $node->content, $start);
        }
    }

    protected function resetState()
    {
        $this->state = self::STATE_TEXT;
        $this->elementStack = [];
        $this->namespaceStack = [];
        $this->elementEncodingStack = [];
        $this->activeFormatting = [];
        $this->activeFormattingSignatures = [];
        $this->activeFormattingOffsets = [];
        $this->formattingReconstructionPending = false;
        $this->elementOffsetStack = [];
        $this->currentElementName = '';
        $this->currentElementStartOffset = null;
        $this->currentElementIsClosing = false;
        $this->currentElementNameComplete = false;
        $this->currentElementNameDynamic = false;
        $this->currentElementIsComponent = false;
        $this->currentTagSignature = null;
        $this->currentAttributeName = '';
        $this->pendingAttributeName = '';
        $this->lastAttributeName = '';
        $this->attributeQuote = null;
        $this->currentAttributeValue = '';
        $this->currentAttributeValueDynamic = false;
        $this->captureCurrentAttributeValue = false;
        $this->currentAnnotationEncoding = null;
        $this->currentAnnotationEncodingSeen = false;
        $this->hasForeignFontBreakoutAttribute = false;
        $this->rawTextElement = '';
        $this->rawTextElementStartOffset = null;
        $this->rawTextEscape = 0;
        $this->hasOpenSelect = false;
        $this->selectInTable = false;
        $this->hasFormPointer = false;
        $this->commentDataStartOffset = null;
        $this->componentProxyDepth = 0;
    }

    protected function isComponentProxyNode(LanguageAntlersNode $node)
    {
        return str_starts_with(ltrim((string) $node->content), '%component_proxy:')
            || str_starts_with(ltrim((string) $node->content), '/%component_proxy:');
    }

    protected function dynamicComponentContext()
    {
        $context = $this->contextSnapshot();
        $stack = $context->elementStack;
        $stack[] = HtmlContext::DYNAMIC_ELEMENT;

        return new HtmlContext(
            HtmlContext::KIND_ELEMENT_CONTENT,
            null,
            null,
            $stack
        );
    }

    protected function isComponentElementName($name)
    {
        return ComponentPrefixes::matches($name, $this->componentPrefixes);
    }

    protected function applyContext(AbstractNode $node, HtmlContext $context)
    {
        $node->htmlContext = $context;

        if (! $node instanceof LanguageAntlersNode) {
            return;
        }

        foreach ($node->processedInterpolationRegions as $nodes) {
            foreach ($nodes as $interpolationNode) {
                if ($interpolationNode instanceof LiteralNode) {
                    continue;
                }

                $this->applyContext($interpolationNode, clone $context);
            }
        }
    }

    /**
     * The element stack with SVG tag names case-adjusted for reporting.
     * Internal stacks stay lowercase (end tags and spec tables compare
     * lowercase); the adjustment happens only at the snapshot boundary so
     * reported names match the sidecar graph's.
     *
     * @return string[]
     */
    protected function reportableElementStack()
    {
        $stack = $this->elementStack;

        foreach ($this->namespaceStack as $index => $namespace) {
            if ($namespace === 'svg' && isset(self::$svgTagNames[$stack[$index]])) {
                $stack[$index] = self::$svgTagNames[$stack[$index]];
            }
        }

        return $stack;
    }

    /**
     * @param  string|null  $name
     * @return string|null
     */
    protected function reportableElementName($name)
    {
        if ($name === null || ! isset(self::$svgTagNames[$name])) {
            return $name;
        }

        return $this->namespaceForElement($name) === 'svg' ? self::$svgTagNames[$name] : $name;
    }

    /**
     * @return HtmlContext
     */
    protected function contextSnapshot()
    {
        $elementName = $this->currentElementNameDynamic
            ? null
            : ($this->currentElementName !== '' ? $this->currentElementName : null);
        $elementName = $this->reportableElementName($elementName);
        $elementStack = $this->reportableElementStack();

        if ($this->currentElementIsComponent
            && ($elementStack === [] || $elementStack[count($elementStack) - 1] !== HtmlContext::DYNAMIC_ELEMENT)) {
            $elementStack[] = HtmlContext::DYNAMIC_ELEMENT;
        }

        switch ($this->state) {
            case self::STATE_TAG_OPEN:
            case self::STATE_CLOSING_TAG:
                if (! $this->currentElementNameComplete) {
                    return new HtmlContext(
                        HtmlContext::KIND_ELEMENT_NAME,
                        $elementName,
                        null,
                        $elementStack,
                        $this->currentElementStartOffset,
                        $this->currentElementIsClosing
                    );
                }

                if ($this->pendingAttributeName !== '') {
                    return new HtmlContext(
                        HtmlContext::KIND_ATTRIBUTE_NAME,
                        $elementName,
                        $this->pendingAttributeName,
                        $elementStack,
                        $this->currentElementStartOffset,
                        $this->currentElementIsClosing
                    );
                }

                return new HtmlContext(
                    HtmlContext::KIND_TAG_OPEN,
                    $elementName,
                    null,
                    $elementStack,
                    $this->currentElementStartOffset,
                    $this->currentElementIsClosing
                );
            case self::STATE_ATTRIBUTE_VALUE:
            case self::STATE_BEFORE_ATTRIBUTE_VALUE:
                return new HtmlContext(
                    HtmlContext::KIND_ATTRIBUTE_VALUE,
                    $elementName,
                    $this->currentAttributeName !== '' ? $this->currentAttributeName : null,
                    $elementStack,
                    $this->currentElementStartOffset,
                    $this->currentElementIsClosing
                );
            case self::STATE_COMMENT:
                return new HtmlContext(
                    HtmlContext::KIND_COMMENT,
                    null,
                    null,
                    $elementStack
                );
            case self::STATE_RAW_TEXT:
            case self::STATE_PLAINTEXT:
                // The owning raw-text element is never on the open-element
                // stack (no tree construction happens inside it), but the
                // reported stack is uniform: every enclosing element, owner
                // included.
                if ($this->rawTextElement !== '') {
                    $elementStack[] = $this->rawTextElement;
                }

                return new HtmlContext(
                    HtmlContext::KIND_RAW_TEXT,
                    $this->rawTextElement !== '' ? $this->rawTextElement : null,
                    null,
                    $elementStack,
                    $this->rawTextElementStartOffset
                );
            case self::STATE_DOCTYPE:
            case self::STATE_CDATA:
                return new HtmlContext(
                    HtmlContext::KIND_DOCTYPE,
                    null,
                    null,
                    $elementStack
                );
            default:
                $innermost = count($elementStack) > 0
                    ? $elementStack[count($elementStack) - 1]
                    : null;
                $innermostOffset = count($this->elementOffsetStack) > 0
                    ? $this->elementOffsetStack[count($this->elementOffsetStack) - 1]
                    : null;

                if ($innermost === HtmlContext::DYNAMIC_ELEMENT) {
                    $innermost = null;
                }

                return new HtmlContext(
                    HtmlContext::KIND_ELEMENT_CONTENT,
                    $innermost,
                    null,
                    $elementStack,
                    $innermostOffset,
                    false,
                    $this->formattingReconstructionPending
                );
        }
    }

    protected function consumeLiteral($content, $startOffset)
    {
        $length = strlen($content);
        $charOffset = $startOffset;

        // Element names may be split across literal nodes by an Antlers
        // comment, which renders nothing. Preserve the static suffix when it
        // begins at this literal boundary; executable Antlers regions still
        // keep currentElementNameDynamic set and remain conservative.
        if (($this->state === self::STATE_TAG_OPEN || $this->state === self::STATE_CLOSING_TAG)
            && ! $this->currentElementNameComplete
            && $this->currentElementName !== ''
            && $length > 0
            && $this->isTagNameChar($content[0])) {
            $this->currentElementName .= $this->readName($content, 0);
        }

        for ($i = 0; $i < $length; $i++) {
            // States that only react to a small delimiter set (text, raw
            // text, comments, doctypes, quoted values) can jump straight to
            // the next byte of interest, accounting for skipped characters in
            // bulk. Tag signatures are never accumulating in these states.
            if (isset(self::$skippableStates[$this->state]) && $this->currentTagSignature === null) {
                $stop = $this->inertSpanEnd($content, $i, $length);

                if ($stop > $i) {
                    $span = substr($content, $i, $stop - $i);
                    $charOffset += strlen($span) - preg_match_all('/[\x80-\xBF]/', $span);

                    if ($stop >= $length) {
                        return;
                    }

                    $i = $stop;
                }
            }

            $iterationStart = $i;
            $char = $content[$i];

            if ($this->currentTagSignature !== null) {
                $this->currentTagSignature .= $char;
            }

            switch ($this->state) {
                case self::STATE_TEXT:
                    if ($char === '<') {
                        $next = $i + 1 < $length ? $content[$i + 1] : null;

                        if ($next === '!') {
                            if (substr($content, $i + 1, 3) === '!--') {
                                // HTML's comment-start states allow the two
                                // abruptly closed empty forms `<!-->` and
                                // `<!--->`. Handle them here while the opener
                                // and terminator are known to be contiguous.
                                if (substr($content, $i + 4, 1) === '>') {
                                    $this->state = self::STATE_TEXT;
                                    $i += 4;
                                } elseif (substr($content, $i + 4, 2) === '->') {
                                    $this->state = self::STATE_TEXT;
                                    $i += 5;
                                } else {
                                    $this->state = self::STATE_COMMENT;
                                    $this->commentDataStartOffset = $charOffset + 4;
                                    $i += 3;
                                }
                            } elseif ($this->currentNamespaceIsForeign()
                                && substr($content, $i + 1, 8) === '![CDATA[') {
                                $this->state = self::STATE_CDATA;
                                $i += 8;
                            } else {
                                $this->state = self::STATE_DOCTYPE;
                                $i += 1;
                            }
                        } elseif ($next === '/') {
                            $this->state = self::STATE_CLOSING_TAG;
                            $this->currentTagSignature = null;
                            $this->currentElementName = '';
                            $this->currentElementStartOffset = $charOffset;
                            $this->currentElementIsClosing = true;
                            $this->currentElementNameComplete = false;
                            $this->currentElementNameDynamic = false;
                            $this->currentElementIsComponent = false;
                            $this->pendingAttributeName = '';
                            $this->lastAttributeName = '';
                            $i += 1;
                        } elseif ($next === '?') {
                            // Processing instructions are bogus comments in
                            // HTML5 and run until the next `>`.
                            $this->state = self::STATE_DOCTYPE;
                            $i += 1;
                        } elseif ($next === null || ctype_alpha($next)) {
                            // A null next character means the literal ends at
                            // the `<` and the tag name may be dynamic
                            // (`<{{ tag }}`), so the tag state is entered
                            // conservatively; any other non-letter means the
                            // `<` is plain text per HTML5.
                            $this->state = self::STATE_TAG_OPEN;
                            $this->currentTagSignature = '<';
                            $this->currentElementName = '';
                            $this->currentElementStartOffset = $charOffset;
                            $this->currentElementIsClosing = false;
                            $this->currentElementNameComplete = false;
                            $this->currentElementNameDynamic = false;
                            $this->currentElementIsComponent = false;
                            $this->pendingAttributeName = '';
                            $this->lastAttributeName = '';
                        }
                    }
                    break;

                case self::STATE_TAG_OPEN:
                case self::STATE_CLOSING_TAG:
                    if (! $this->currentElementNameComplete && ($char === '{' || $char === '}')) {
                        // Escaped Antlers is normalized from `@{{` to `{{` in
                        // LiteralNode content, so source offsets no longer map
                        // safely onto this element name.
                        $this->currentElementNameDynamic = true;
                    }

                    if (! $this->currentElementNameComplete) {
                        if ($char === '>') {
                            $this->finishTag($i > 0 && $content[$i - 1] === '/');
                        } elseif ($char === '/' || $this->isHtmlSpace($char)) {
                            $this->currentElementNameComplete = true;
                            $this->pendingAttributeName = '';
                            $this->lastAttributeName = '';
                        } elseif ($this->currentElementName === ''
                            && $this->isTagNameChar($char)
                            && $this->nameJustStarted($content, $i)) {
                            $this->currentElementName = $this->readName($content, $i);

                            if ($this->treatComponentsAsDynamic && $this->isComponentElementName($this->currentElementName)) {
                                $this->currentElementNameDynamic = true;
                                $this->currentElementIsComponent = true;
                            }

                            if (! isset(self::$formattingElements[strtolower($this->currentElementName)])) {
                                $this->currentTagSignature = null;
                            }
                        }

                        break;
                    }

                    if ($char === '>') {
                        $this->finishTag($i > 0 && $content[$i - 1] === '/');
                    } elseif ($char === '=') {
                        if ($this->pendingAttributeName === '' && $this->lastAttributeName === '') {
                            $this->pendingAttributeName = '=';

                            break;
                        }

                        // Whitespace is allowed around `=`; the most recently
                        // seen attribute name owns the upcoming value. The
                        // value state is entered even when no name is known so
                        // a quoted value containing `>` can never terminate
                        // the tag early.
                        $this->currentAttributeName = $this->pendingAttributeName !== ''
                            ? $this->pendingAttributeName
                            : $this->lastAttributeName;
                        if ($this->currentElementName === 'font') {
                            $this->recordForeignFontBreakoutAttribute($this->currentAttributeName);
                        }
                        $this->pendingAttributeName = '';
                        $this->lastAttributeName = '';
                        $this->attributeQuote = null;
                        $this->currentAttributeValue = '';
                        $this->currentAttributeValueDynamic = false;
                        $isAnnotationEncoding = ! $this->currentElementNameDynamic
                            && strtolower($this->currentElementName) === 'annotation-xml'
                            && $this->currentAttributeName === 'encoding';
                        $this->captureCurrentAttributeValue = $isAnnotationEncoding
                            && ! $this->currentAnnotationEncodingSeen;

                        if ($isAnnotationEncoding) {
                            $this->currentAnnotationEncodingSeen = true;
                        }
                        $this->state = self::STATE_BEFORE_ATTRIBUTE_VALUE;
                    } elseif ($this->isHtmlSpace($char)) {
                        if ($this->pendingAttributeName !== '') {
                            if ($this->currentElementName === 'font') {
                                $this->recordForeignFontBreakoutAttribute($this->pendingAttributeName);
                            }
                            $this->lastAttributeName = $this->pendingAttributeName;
                            $this->pendingAttributeName = '';
                        }
                    } elseif ($char === '/') {
                        if ($this->pendingAttributeName !== '') {
                            if ($this->currentElementName === 'font') {
                                $this->recordForeignFontBreakoutAttribute($this->pendingAttributeName);
                            }
                            $this->lastAttributeName = $this->pendingAttributeName;
                            $this->pendingAttributeName = '';
                        }
                    } else {
                        if ($this->isAttributeNameChar($char)) {
                            $this->pendingAttributeName .= $char === "\0"
                                ? "\xEF\xBF\xBD"
                                : strtolower($char);
                        }
                    }
                    break;

                case self::STATE_BEFORE_ATTRIBUTE_VALUE:
                    if ($this->isHtmlSpace($char)) {
                        break;
                    }

                    if ($char === '"' || $char === "'") {
                        $this->attributeQuote = $char;
                        $this->state = self::STATE_ATTRIBUTE_VALUE;
                    } elseif ($char === '>') {
                        $this->commitCurrentAttribute();
                        $this->currentAttributeName = '';
                        $this->finishTag($i > 0 && $content[$i - 1] === '/');
                    } else {
                        $this->attributeQuote = null;
                        if ($this->captureCurrentAttributeValue) {
                            $this->currentAttributeValue .= $char;
                        }
                        $this->state = self::STATE_ATTRIBUTE_VALUE;
                    }
                    break;

                case self::STATE_ATTRIBUTE_VALUE:
                    if ($this->attributeQuote !== null) {
                        if ($char === $this->attributeQuote) {
                            $this->commitCurrentAttribute();
                            $this->state = $this->currentElementIsClosing
                                ? self::STATE_CLOSING_TAG
                                : self::STATE_TAG_OPEN;
                            $this->currentAttributeName = '';
                            $this->attributeQuote = null;
                            $this->pendingAttributeName = '';
                            $this->lastAttributeName = '';
                        } elseif ($this->captureCurrentAttributeValue) {
                            $this->currentAttributeValue .= $char;
                        }
                    } elseif ($this->isHtmlSpace($char)) {
                        $this->commitCurrentAttribute();
                        $this->state = $this->currentElementIsClosing
                            ? self::STATE_CLOSING_TAG
                            : self::STATE_TAG_OPEN;
                        $this->currentAttributeName = '';
                        $this->pendingAttributeName = '';
                        $this->lastAttributeName = '';
                    } elseif ($char === '>') {
                        // Per HTML5 a `/` at the end of an unquoted value is
                        // part of the value, never a self-closing marker.
                        $this->commitCurrentAttribute();
                        $this->currentAttributeName = '';
                        $this->finishTag(false);
                    } elseif ($this->captureCurrentAttributeValue) {
                        $this->currentAttributeValue .= $char;
                    }
                    break;

                case self::STATE_COMMENT:
                    if ($char === '>' && (
                        ($i >= 2
                            && $charOffset - 2 >= $this->commentDataStartOffset
                            && $content[$i - 1] === '-'
                            && $content[$i - 2] === '-')
                        || ($i >= 3
                            && $charOffset - 3 >= $this->commentDataStartOffset
                            && $content[$i - 1] === '!'
                            && $content[$i - 2] === '-'
                            && $content[$i - 3] === '-')
                    )) {
                        $this->state = self::STATE_TEXT;
                        $this->commentDataStartOffset = null;
                    }
                    break;

                case self::STATE_DOCTYPE:
                    if ($char === '>') {
                        $this->state = self::STATE_TEXT;
                    }
                    break;

                case self::STATE_CDATA:
                    if ($char === '>' && $i >= 2 && $content[$i - 1] === ']' && $content[$i - 2] === ']') {
                        $this->state = self::STATE_TEXT;
                    }
                    break;

                case self::STATE_RAW_TEXT:
                    if ($char === '<' && $i + 1 < $length && $content[$i + 1] === '/') {
                        $nameLength = strlen($this->rawTextElement);
                        $closing = strtolower(substr($content, $i + 2, $nameLength));

                        if ($closing === $this->rawTextElement && $this->isTagNameDelimiter($content, $i + 2 + $nameLength, $length)) {
                            if ($this->rawTextEscape === 2) {
                                // `</script>` inside a double-escaped region
                                // returns to the escaped state without ending
                                // the element.
                                $this->rawTextEscape = 1;
                                $i += 1 + $nameLength;
                            } else {
                                $this->state = self::STATE_CLOSING_TAG;
                                $this->currentTagSignature = null;
                                $this->currentElementName = $this->rawTextElement;
                                $this->currentElementStartOffset = $charOffset;
                                $this->currentElementIsClosing = true;
                                $this->currentElementNameComplete = true;
                                $this->rawTextElement = '';
                                $this->rawTextElementStartOffset = null;
                                $this->rawTextEscape = 0;
                                $i += 1;
                            }
                        }
                    } elseif ($this->rawTextElement === 'script') {
                        if ($char === '<') {
                            if ($this->rawTextEscape === 0 && substr($content, $i + 1, 3) === '!--') {
                                $this->rawTextEscape = 1;
                                $i += 3;
                            } elseif ($this->rawTextEscape === 1 && strtolower(substr($content, $i + 1, 6)) === 'script' && $this->isTagNameDelimiter($content, $i + 7, $length)) {
                                $this->rawTextEscape = 2;
                                $i += 6;
                            }
                        } elseif ($char === '>' && $this->rawTextEscape !== 0 && $i >= 2 && $content[$i - 1] === '-' && $content[$i - 2] === '-') {
                            $this->rawTextEscape = 0;
                        }
                    }
                    break;

                case self::STATE_PLAINTEXT:
                    // The obsolete plaintext element consumes the remainder
                    // of the document as text; even </plaintext> is text.
                    break;
            }

            $charOffset += $i - $iterationStart;

            if ((ord($char) & 0xC0) !== 0x80) {
                $charOffset++;
            }
        }
    }

    /**
     * Returns the end of the span (starting at $index) that the current state
     * cannot react to, allowing the caller to skip it in one step. Returns
     * $index when the current state inspects every character.
     *
     * @return int
     */
    protected function inertSpanEnd($content, $index, $length)
    {
        switch ($this->state) {
            case self::STATE_TEXT:
                $next = strpos($content, '<', $index);

                return $next === false ? $length : $next;
            case self::STATE_RAW_TEXT:
                if ($this->rawTextElement === 'script') {
                    return $index + strcspn($content, '<>', $index);
                }

                $next = strpos($content, '<', $index);

                return $next === false ? $length : $next;
            case self::STATE_COMMENT:
            case self::STATE_DOCTYPE:
            case self::STATE_CDATA:
                $next = strpos($content, '>', $index);

                return $next === false ? $length : $next;
            case self::STATE_PLAINTEXT:
                return $length;
            case self::STATE_ATTRIBUTE_VALUE:
                if ($this->captureCurrentAttributeValue) {
                    return $index;
                }

                if ($this->attributeQuote !== null) {
                    $next = strpos($content, $this->attributeQuote, $index);

                    return $next === false ? $length : $next;
                }

                return $index + strcspn($content, " \t\n\r\f>", $index);
            default:
                return $index;
        }
    }

    /**
     * Escaped Antlers and noparse regions render literal output, so their HTML
     * must advance the scanner just like a LiteralNode would.
     *
     * @return array{0: string, 1: int}
     */
    protected function escapedOutput(EscapedContentNode $node)
    {
        $start = $node->startPosition->offset ?? 0;
        $original = $node->originalNode;

        if ($original instanceof LanguageAntlersNode && $original->isClosedBy instanceof LanguageAntlersNode) {
            $parser = $node->getParser();

            if ($parser !== null) {
                $start = ($original->endPosition->offset ?? $start) + 1;

                return [
                    $parser->getText(
                        $original->endPosition->index + 1,
                        $original->isClosedBy->startPosition->index
                    ),
                    $start,
                ];
            }
        }

        return [(string) $node->innerContent(), $start];
    }
}
