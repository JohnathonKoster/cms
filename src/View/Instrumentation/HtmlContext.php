<?php

namespace Statamic\View\Instrumentation;

/**
 * Describes where a dynamic template region sits within the surrounding HTML
 * document structure. Engine-neutral: the Antlers ContextScanner produces
 * these today, and any future engine front-end emits the same shape.
 */
class HtmlContext
{
    /**
     * Sentinel used in elementStack when an element name contains Antlers
     * and therefore cannot be classified safely before rendering.
     */
    const DYNAMIC_ELEMENT = '*';

    const KIND_ELEMENT_CONTENT = 'element-content';

    const KIND_TAG_OPEN = 'tag-open';

    const KIND_ELEMENT_NAME = 'element-name';

    const KIND_ATTRIBUTE_NAME = 'attribute-name';

    const KIND_ATTRIBUTE_VALUE = 'attribute-value';

    const KIND_RAW_TEXT = 'raw-text';

    const KIND_COMMENT = 'comment';

    const KIND_DOCTYPE = 'doctype';

    /**
     * One of the KIND_* constants.
     *
     * @var string
     */
    public $kind;

    /**
     * For tag-open/attribute-value contexts, the element whose open tag the
     * node sits inside; for element-content/raw-text, the innermost open
     * element (null at the document root).
     *
     * @var string|null
     */
    public $elementName;

    /**
     * The attribute whose value the node sits inside, when known.
     *
     * @var string|null
     */
    public $attributeName;

    /**
     * Names of the open ancestor elements, outermost first.
     *
     * @var string[]
     */
    public $elementStack = [];

    /**
     * Document offset of the `<` that opened the owning or containing
     * element. Lets tooling stamp that static element when the Antlers node
     * itself cannot safely be wrapped.
     *
     * @var int|null
     */
    public $elementStartOffset;

    /**
     * Whether the node belongs to a closing element tag. This is relevant to
     * tools that stamp the owning element with an attribute: attributes may
     * only be added to opening tags.
     *
     * @var bool
     */
    public $isClosingTag;

    /** @var bool */
    public $requiresFormattingReconstruction;

    public function __construct(
        string $kind,
        ?string $elementName = null,
        ?string $attributeName = null,
        array $elementStack = [],
        ?int $elementStartOffset = null,
        bool $isClosingTag = false,
        bool $requiresFormattingReconstruction = false
    ) {
        $this->kind = $kind;
        $this->elementName = $elementName;
        $this->attributeName = $attributeName;
        $this->elementStack = $elementStack;
        $this->elementStartOffset = $elementStartOffset;
        $this->isClosingTag = $isClosingTag;
        $this->requiresFormattingReconstruction = $requiresFormattingReconstruction;
    }

    /**
     * Whether an HTML comment could be emitted at this position without
     * corrupting the document. Normal element content is eligible except for
     * raw/RCDATA and whitespace-sensitive elements.
     *
     * @return bool
     */
    public function isSafeForHtmlComments()
    {
        if ($this->kind !== self::KIND_ELEMENT_CONTENT) {
            return false;
        }

        if ($this->requiresFormattingReconstruction) {
            return false;
        }

        // A dynamic ancestor could render as title, pre, script, or another
        // context where an HTML comment changes the output. Unknown is unsafe.
        if (in_array(self::DYNAMIC_ELEMENT, $this->elementStack, true)) {
            return false;
        }

        if ($this->isFosterParentingContext()) {
            return false;
        }

        // Comments inside these elements are either text (RCDATA/raw text)
        // or can alter whitespace-sensitive output. Do not use comments as
        // instrumentation markers there.
        $unsafeElements = [
            'pre', 'textarea', 'title', 'script', 'style', 'xmp', 'iframe',
            'listing', 'noembed', 'noframes', 'noscript', 'plaintext',
        ];

        return count(array_intersect($this->elementStack, $unsafeElements)) === 0;
    }

    public function isFosterParentingContext()
    {
        $table = null;

        for ($index = count($this->elementStack) - 1; $index >= 0; $index--) {
            if ($this->elementStack[$index] === 'table') {
                $table = $index;

                break;
            }
        }

        if ($table === null) {
            return false;
        }

        $tableModes = [
            'thead' => true, 'tbody' => true, 'tfoot' => true,
            'tr' => true, 'colgroup' => true,
        ];

        for ($index = $table + 1, $count = count($this->elementStack); $index < $count; $index++) {
            $name = $this->elementStack[$index];

            if ($name === 'td' || $name === 'th' || $name === 'caption') {
                return false;
            }

            if (! isset($tableModes[$name])) {
                return false;
            }
        }

        return true;
    }

    public function isAttributeContext()
    {
        return in_array($this->kind, [self::KIND_ATTRIBUTE_NAME, self::KIND_ATTRIBUTE_VALUE], true);
    }

    public function isTagContext()
    {
        return in_array($this->kind, [
            self::KIND_TAG_OPEN,
            self::KIND_ELEMENT_NAME,
            self::KIND_ATTRIBUTE_NAME,
            self::KIND_ATTRIBUTE_VALUE,
        ], true);
    }

    public function isElementContent()
    {
        return $this->kind === self::KIND_ELEMENT_CONTENT;
    }

    /**
     * Whether the opening element that owns this node can be instrumented by
     * adding an HTML attribute at elementStartOffset.
     */
    public function canInstrumentOwningElement()
    {
        return ! $this->isClosingTag
            && $this->elementName !== null
            && $this->elementStartOffset !== null
            && ! in_array(self::DYNAMIC_ELEMENT, $this->elementStack, true)
            && in_array($this->kind, [self::KIND_TAG_OPEN, self::KIND_ATTRIBUTE_VALUE], true);
    }

    /**
     * Whether a node in element content can be represented safely by an
     * attribute on its nearest static containing element. This includes raw
     * and whitespace-sensitive content where comments are intentionally
     * forbidden but changing the opening tag is safe.
     */
    public function canInstrumentContainingElement()
    {
        return ! $this->isClosingTag
            && $this->elementName !== null
            && $this->elementStartOffset !== null
            && ! in_array(self::DYNAMIC_ELEMENT, $this->elementStack, true)
            && in_array($this->kind, [self::KIND_ELEMENT_CONTENT, self::KIND_RAW_TEXT], true);
    }

    /**
     * Whether either the owning opening tag or containing element is a safe
     * target for an HTML attribute instrumentation layer.
     */
    public function canInstrumentElement()
    {
        return $this->canInstrumentOwningElement() || $this->canInstrumentContainingElement();
    }

    /**
     * Whether this context and another context describe boundaries in the
     * same static element container. Comment pairs need this so their DOM
     * boundary nodes cannot land under different parents.
     */
    public function sharesElementContainerWith(HtmlContext $other)
    {
        return $this->elementStack === $other->elementStack
            && $this->elementStartOffset === $other->elementStartOffset
            && ! $this->requiresFormattingReconstruction
            && ! $other->requiresFormattingReconstruction
            && ! in_array(self::DYNAMIC_ELEMENT, $this->elementStack, true);
    }
}
