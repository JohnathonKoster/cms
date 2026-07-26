<?php

namespace Statamic\View\Antlers\Language\Analyzers\Html;

use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Tappable;

/**
 * A source-preserving node in an Antlers template's lazy HTML sidecar graph.
 */
abstract class Node
{
    use Conditionable, Tappable;

    /** @var Document */
    protected $document;

    /** @var int|null */
    protected $arenaId;

    public function __construct(Document $document, $arenaId = null)
    {
        $this->document = $document;
        $this->arenaId = $arenaId;
    }

    public function arenaId()
    {
        return $this->arenaId;
    }

    public function document()
    {
        return $this->document;
    }

    public function parent()
    {
        $parent = $this->arenaId === null ? null : $this->document->arena()->parent($this->arenaId);

        return $parent === null ? null : $this->document->handle($parent);
    }

    public function parentElement()
    {
        $parent = $this->parent();

        while ($parent !== null && ! $parent instanceof Element) {
            $parent = $parent->parent();
        }

        return $parent;
    }

    public function previousSibling()
    {
        return $this->siblingAt(-1);
    }

    public function nextSibling()
    {
        return $this->siblingAt(1);
    }

    public function isSourceAnchored()
    {
        return $this->arenaId !== null
            && isset($this->document->arena()->sourceAnchor[$this->arenaId]);
    }

    public function sourceAnchor()
    {
        $anchor = $this->arenaId === null
            ? null
            : ($this->document->arena()->sourceAnchor[$this->arenaId] ?? null);

        return $anchor === null ? null : $this->document->handle($anchor);
    }

    public function anchorSourceAt(SourceAnchor $anchor)
    {
        if ($this->arenaId === null || $anchor->arenaId() === null) {
            throw new \LogicException('Source anchors must belong to an HTML arena.');
        }

        $arena = $this->document->arena();
        $arena->sourceAnchor[$this->arenaId] = $anchor->arenaId();
        $arena->anchorTarget[$anchor->arenaId()] = $this->arenaId;

        return $this;
    }

    public function releaseSourceAnchor()
    {
        if ($this->arenaId === null) {
            return $this;
        }

        $arena = $this->document->arena();
        $anchor = $arena->sourceAnchor[$this->arenaId] ?? null;
        unset($arena->sourceAnchor[$this->arenaId]);

        if ($anchor !== null) {
            $arena->anchorTarget[$anchor] = null;
        }

        return $this;
    }

    public function releaseSourceAnchors()
    {
        $anchor = $this->sourceAnchor();

        if ($anchor !== null) {
            $this->releaseSourceAnchor();
        }
    }

    public function closest($name)
    {
        $element = $this instanceof Element ? $this : $this->parentElement();

        while ($element !== null) {
            if ($element->is($name)) {
                return $element;
            }

            $element = $element->parentElement();
        }

        return null;
    }

    public function before($content)
    {
        $this->assertMutableSibling();
        $this->parent()->insertBefore($this, $content);

        return $this;
    }

    public function after($content)
    {
        $this->assertMutableSibling();
        $this->parent()->insertAfter($this, $content);

        return $this;
    }

    public function replaceWith($content)
    {
        $this->assertMutableSibling();
        $this->parent()->replaceChild($this, $content);

        return $this;
    }

    /**
     * Wraps this node with the given element, which takes over the node's
     * position (and source anchor) in the graph.
     *
     * @return $this
     */
    public function wrapWith(Element $element)
    {
        if ($element === $this) {
            throw new \LogicException('HTML graph nodes cannot wrap themselves.');
        }

        $this->assertMutableSibling();
        $this->replaceWith($element);
        $element->append($this);

        return $this;
    }

    public function remove()
    {
        $this->assertMutableSibling();
        $this->parent()->removeChild($this);

        return $this;
    }

    public function toHtml()
    {
        return $this->render();
    }

    public function __toString()
    {
        return $this->toHtml();
    }

    abstract protected function render();

    protected function siblingAt($direction)
    {
        if ($this->arenaId === null) {
            return null;
        }

        $owner = $this->document->arena()->embeddedOwner[$this->arenaId] ?? null;

        if ($owner !== null) {
            $siblings = $this->document->arena()->embedded[$owner];
            $index = array_search($this->arenaId, $siblings, true);
            $sibling = $index === false ? null : ($siblings[$index + $direction] ?? null);

            return $sibling === null ? null : $this->document->handle($sibling);
        }

        $arena = $this->document->arena();
        $sibling = $direction < 0 ? $arena->previous($this->arenaId) : $arena->next($this->arenaId);

        return $sibling === null ? null : $this->document->handle($sibling);
    }

    protected function assertMutableSibling()
    {
        $parent = $this->parent();

        if ($parent === null) {
            throw new \LogicException('Detached HTML graph nodes cannot be mutated relative to siblings.');
        }

        if (! $parent->hasChild($this)) {
            throw new \LogicException('Antlers regions inside an HTML tag cannot be moved as DOM children. Mutate the owning element instead.');
        }
    }

    protected function touchDocument()
    {
        if ($this->arenaId !== null && $this->document->arena()->parent($this->arenaId) !== null) {
            $this->document->markDirty();
        }
    }
}
