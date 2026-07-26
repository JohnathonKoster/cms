<?php

namespace Statamic\View\Antlers\Language\Analyzers\Html\Concerns;

use Statamic\View\Antlers\Language\Analyzers\Html\Arena;

/**
 * Source anchoring: tracks which arena nodes still map onto original
 * source bytes so untouched regions round-trip exactly.
 */
trait AnchorsSourceNodes
{
    protected function arenaNodeIsDescendantOf($node, $ancestor)
    {
        $seen = [];

        for ($parent = $this->arena->parent($node); $parent !== null; $parent = $this->arena->parent($parent)) {
            if (isset($seen[$parent])) {
                throw new \LogicException('Cycle detected while resolving an HTML arena ancestor.');
            }

            $seen[$parent] = true;

            if ($parent === $ancestor) {
                return true;
            }
        }

        return false;
    }

    protected function arenaNodeHasSourceAnchoredAncestor($node)
    {
        return $this->arenaNodeAncestorSourceAnchor($node) !== null;
    }

    protected function arenaNodeAncestorSourceAnchor($node)
    {
        $seen = [];

        for ($parent = $this->arena->parent($node); $parent !== null; $parent = $this->arena->parent($parent)) {
            if (isset($seen[$parent])) {
                throw new \LogicException('Cycle detected while resolving an HTML arena source anchor.');
            }

            $seen[$parent] = true;

            if (isset($this->arena->sourceAnchor[$parent])) {
                return $this->arena->sourceAnchor[$parent];
            }
        }

        return null;
    }

    protected function anchorRecoveredSourceNode(
        $node,
        $container,
        $sourceTable,
        $sourceTableTail,
        $previous = null,
        $sourcePositionAnchor = null
    ) {
        $containerIsSynthetic = (bool) ($this->arena->kind($container) & Arena::SYNTHETIC);

        if ($sourcePositionAnchor === null && $sourceTable === null && ! $containerIsSynthetic) {
            return;
        }

        $sourcePositionTarget = $sourcePositionAnchor === null
            ? null
            : ($this->arena->anchorTarget[$sourcePositionAnchor] ?? null);

        if ($sourcePositionTarget !== null) {
            $ancestorSourceAnchor = $this->arenaNodeAncestorSourceAnchor($node);
            $ancestorAlreadyInsideSourceTarget = $ancestorSourceAnchor !== null
                && $this->arenaNodeIsDescendantOf($ancestorSourceAnchor, $sourcePositionTarget);

            if (! $this->arenaNodeIsDescendantOf($node, $sourcePositionTarget)
                && ! $ancestorAlreadyInsideSourceTarget) {
                $anchor = $this->arena->anchor($node);
                $this->arena->insertAfter($sourcePositionAnchor, $anchor);

                return;
            }
        }

        if ($sourceTable !== null
            && ! $this->arenaNodeIsDescendantOf($node, $sourceTable)
            && ! $this->arenaNodeHasSourceAnchoredAncestor($node)) {
            $anchor = $this->arena->anchor($node);

            if ($sourceTableTail !== null && $this->arena->parent($sourceTableTail) === $sourceTable) {
                $this->arena->insertAfter($sourceTableTail, $anchor);
            } else {
                $this->arena->append($sourceTable, $anchor);
            }

            return;
        }

        $previous = $previous === null ? $this->arena->previous($node) : $previous;

        if ($containerIsSynthetic
            && $previous !== null
            && isset($this->arena->sourceAnchor[$previous])) {
            $previousAnchor = $this->arena->sourceAnchor[$previous];
            $anchorParent = $this->arena->parent($previousAnchor);

            if ($anchorParent !== null
                && ($this->arena->kind($anchorParent) & Arena::KIND_MASK) === Arena::ELEMENT
                && $this->arena->closing[$anchorParent] !== '') {
                return;
            }

            $anchor = $this->arena->anchor($node);
            $this->arena->insertAfter($previousAnchor, $anchor);
        }
    }

    protected function anchorArenaNodeForMove($node)
    {
        if ($this->arena->parent($node) === null) {
            return;
        }

        if (! isset($this->arena->sourceAnchor[$node])) {
            $this->arena->anchorExisting($node);

            return;
        }

        $this->arena->detach($node);
    }

    protected function markDirtyFromContainer()
    {
        $this->markDirty();
    }
}
