<?php

namespace Statamic\View\Antlers\Language\Analyzers\Html;

trait Container
{
    /**
     * Return a reusable lazy sequence, or replace all direct content.
     *
     * @return NodeSequence|$this
     */
    public function content($content = null)
    {
        if (func_num_args() !== 0) {
            $replacement = $this->normalizeArenaNode($content);
            $this->assertArenaInsertable($replacement);
            $existing = $this->children();

            $this->append($replacement);

            foreach ($existing as $node) {
                if ($node !== $replacement) {
                    $this->removeChild($node);
                }
            }

            return $this;
        }

        return NodeSequence::childrenOf($this->document, $this->arenaContainerId());
    }

    public function children()
    {
        return array_map([$this->document, 'handle'], $this->document->arena()->children($this->arenaContainerId()));
    }

    public function descendants($name = null)
    {
        return $this->arenaDescendants($name);
    }

    public function firstDescendant($name = null)
    {
        return $this->arenaFirstDescendant($name);
    }

    public function firstChild()
    {
        $child = $this->document->arena()->firstChild($this->arenaContainerId());

        return $child === null ? null : $this->document->handle($child);
    }

    public function lastChild()
    {
        $child = $this->document->arena()->lastChild($this->arenaContainerId());

        return $child === null ? null : $this->document->handle($child);
    }

    public function append($content)
    {
        $node = $this->normalizeArenaNode($content);
        $this->assertArenaInsertable($node);
        $this->detachArenaNodeForInsert($node);
        $this->document->arena()->append($this->arenaContainerId(), $node->arenaId());
        $this->markDirty();

        return $this;
    }

    public function prepend($content)
    {
        $node = $this->normalizeArenaNode($content);
        $this->assertArenaInsertable($node);
        $this->detachArenaNodeForInsert($node);
        $arena = $this->document->arena();
        $first = $arena->firstChild($this->arenaContainerId());

        if ($first === null) {
            $arena->append($this->arenaContainerId(), $node->arenaId());
        } else {
            $arena->insertBefore($first, $node->arenaId());
        }

        $this->markDirty();

        return $this;
    }

    public function hasChild(Node $node)
    {
        return $this->document->arena()->hasChild($this->arenaContainerId(), $node->arenaId());
    }

    public function siblingsFor(Node $node)
    {
        return $this->children();
    }

    public function insertBefore(Node $reference, $content)
    {
        $this->insertArenaRelative($reference, $content, false);
    }

    public function insertAfter(Node $reference, $content)
    {
        $this->insertArenaRelative($reference, $content, true);
    }

    public function replaceChild(Node $reference, $content)
    {
        $this->replaceArenaChild($reference, $content);
    }

    public function removeChild(Node $node)
    {
        if (! $this->hasChild($node)) {
            return;
        }

        $this->releaseArenaSourceAnchors($node->arenaId());
        $this->document->arena()->detach($node->arenaId());
        $this->markDirty();
    }

    public function createElement($name)
    {
        return $this->document->createArenaElement($name);
    }

    protected function renderChildren()
    {
        return $this->document->renderArenaChildren($this->arenaContainerId());
    }

    protected function sourceChildren()
    {
        return $this->children();
    }

    protected function normalizeArenaNode($content)
    {
        if ($content instanceof Node) {
            if ($content->document() !== $this->document) {
                throw new \InvalidArgumentException('HTML graph nodes cannot be moved between documents.');
            }

            if ($content->arenaId() === null) {
                throw new \InvalidArgumentException('Only nodes created by this HTML document can be inserted.');
            }

            return $content;
        }

        $id = $this->document->arena()->text((string) $content);

        return $this->document->handle($id);
    }

    protected function assertArenaInsertable(Node $node)
    {
        $arena = $this->document->arena();

        for ($container = $this->arenaContainerId(); $container !== null; $container = $arena->parent($container)) {
            if ($container === $node->arenaId()) {
                throw new \LogicException('HTML graph nodes cannot be inserted into themselves or their descendants.');
            }
        }
    }

    protected function detachArenaNodeForInsert(Node $node)
    {
        $id = $node->arenaId();
        $arena = $this->document->arena();

        if ($arena->parent($id) !== null && ! isset($arena->embeddedOwner[$id])) {
            $this->releaseArenaSourceAnchors($id);
            $arena->detach($id);
        }
    }

    protected function insertArenaRelative(Node $reference, $content, $after)
    {
        if (! $this->hasChild($reference)) {
            return;
        }

        $node = $this->normalizeArenaNode($content);

        if ($node === $reference) {
            return;
        }

        $this->assertArenaInsertable($node);
        $this->detachArenaNodeForInsert($node);
        $arena = $this->document->arena();
        $referenceId = $reference->arenaId();
        $nodeId = $node->arenaId();
        $anchor = $arena->sourceAnchor[$referenceId] ?? null;

        if ($after) {
            $arena->insertAfter($referenceId, $nodeId);
        } else {
            $arena->insertBefore($referenceId, $nodeId);
        }

        if ($anchor !== null) {
            $sourceAnchor = $arena->anchor($nodeId);

            if ($after) {
                $arena->insertAfter($anchor, $sourceAnchor);
            } else {
                $arena->insertBefore($anchor, $sourceAnchor);
            }
        }

        $this->markDirty();
    }

    protected function replaceArenaChild(Node $reference, $content)
    {
        if (! $this->hasChild($reference)) {
            return;
        }

        $node = $this->normalizeArenaNode($content);

        if ($node === $reference) {
            return;
        }

        $this->assertArenaInsertable($node);
        $this->detachArenaNodeForInsert($node);
        $arena = $this->document->arena();
        $referenceId = $reference->arenaId();
        $nodeId = $node->arenaId();
        $anchor = $arena->sourceAnchor[$referenceId] ?? null;
        $arena->insertBefore($referenceId, $nodeId);
        $arena->detach($referenceId);

        if ($anchor !== null) {
            unset($arena->sourceAnchor[$referenceId]);
            $arena->anchorTarget[$anchor] = $nodeId;
            $arena->sourceAnchor[$nodeId] = $anchor;
        }

        $this->releaseArenaSourceAnchors($referenceId);
        $this->markDirty();
    }

    protected function releaseArenaSourceAnchors($id)
    {
        $arena = $this->document->arena();
        $anchor = $arena->sourceAnchor[$id] ?? null;

        if ($anchor !== null) {
            unset($arena->sourceAnchor[$id]);
            $arena->anchorTarget[$anchor] = null;
        }

        for ($child = $arena->firstChild($id); $child !== null; $child = $arena->next($child)) {
            if (($arena->kind($child) & Arena::KIND_MASK) === Arena::SOURCE_ANCHOR) {
                $target = $arena->anchorTarget[$child] ?? null;

                if ($target !== null) {
                    unset($arena->sourceAnchor[$target]);
                    $arena->anchorTarget[$child] = null;
                }
            } else {
                $this->releaseArenaSourceAnchors($child);
            }
        }
    }

    protected function arenaContainerId()
    {
        return $this instanceof Document ? 0 : $this->arenaId;
    }

    protected function arenaDescendants($name)
    {
        $arena = $this->document->arena();
        $elements = [];
        $queue = $arena->children($this->arenaContainerId());

        for ($index = 0; isset($queue[$index]); $index++) {
            $id = $queue[$index];

            if (($arena->kind($id) & Arena::KIND_MASK) !== Arena::ELEMENT) {
                continue;
            }

            if ($name === null || strcasecmp($arena->name[$id], $name) === 0) {
                $elements[] = $this->document->handle($id);
            }

            for ($child = $arena->firstChild($id); $child !== null; $child = $arena->next($child)) {
                $queue[] = $child;
            }
        }

        return collect($elements);
    }

    protected function arenaFirstDescendant($name)
    {
        $arena = $this->document->arena();
        $queue = $arena->children($this->arenaContainerId());

        for ($index = 0; isset($queue[$index]); $index++) {
            $id = $queue[$index];

            if (($arena->kind($id) & Arena::KIND_MASK) !== Arena::ELEMENT) {
                continue;
            }

            if ($name === null || strcasecmp($arena->name[$id], $name) === 0) {
                return $this->document->handle($id);
            }

            for ($child = $arena->firstChild($id); $child !== null; $child = $arena->next($child)) {
                $queue[] = $child;
            }
        }

        return null;
    }
}
