<?php

namespace Statamic\View\Antlers\Language\Analyzers\Html;

/**
 * Keeps source-order printing separate from recovered DOM graph order.
 *
 * HTML table foster parenting can place a node before a table in the graph
 * even though its source appeared inside that table. The graph parent owns
 * navigation; this hidden anchor owns the original printing location.
 */
class SourceAnchor extends Node
{
    private function __construct(Document $document, $arenaId)
    {
        parent::__construct($document, $arenaId);
    }

    /** @internal */
    public static function fromArena(Document $document, $id)
    {
        return new self($document, $id);
    }

    public function target()
    {
        $target = $this->document->arena()->anchorTarget[$this->arenaId] ?? null;

        return $target === null ? null : $this->document->handle($target);
    }

    public function replaceTarget(Node $target)
    {
        if ($target->document() !== $this->document || $target->arenaId() === null) {
            throw new \InvalidArgumentException('Source-anchor targets must belong to the same HTML document.');
        }

        $this->releaseTarget();
        $target->anchorSourceAt($this);

        return $this;
    }

    public function releaseTarget()
    {
        $arena = $this->document->arena();
        $target = $arena->anchorTarget[$this->arenaId] ?? null;

        if ($target !== null && ($arena->sourceAnchor[$target] ?? null) === $this->arenaId) {
            unset($arena->sourceAnchor[$target]);
        }

        $arena->anchorTarget[$this->arenaId] = null;
    }

    public function releaseSourceAnchors()
    {
        $this->releaseTarget();
    }

    protected function render()
    {
        return $this->document->renderArenaNode($this->arenaId);
    }
}
