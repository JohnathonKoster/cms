<?php

namespace Statamic\View\Instrumentation;

use Statamic\Tags\Tags;
use Statamic\View\Antlers\Language\Nodes\AbstractNode;
use Statamic\View\Antlers\Language\Runtime\GlobalRuntimeState;
use Statamic\View\Antlers\Language\Runtime\NodeProcessor;

/**
 * One unit of executed template work, described the same way regardless of
 * which engine produced it. Engine-specific detail remains reachable through
 * raw() and meta(), so tools that need it are never boxed out.
 */
final class Span
{
    public const ENGINE_ANTLERS = 'antlers';

    public const ENGINE_BLADE = 'blade';

    public const KIND_NODE = 'node';

    public const KIND_TAG = 'tag';

    /**
     * The scope captured the first time it was requested. A NodeProcessor's
     * active data changes as rendering advances, so the value is frozen on
     * first access rather than re-read.
     *
     * @var array<string, mixed>|null
     */
    private ?array $capturedScope = null;

    private bool $scopeCaptured = false;

    private function __construct(
        public readonly string $engine,
        public readonly string $kind,
        public readonly string $expression,
        public readonly ?string $view,
        public readonly ?int $line,
        private readonly mixed $raw,
        private readonly array $meta = [],
    ) {
    }

    public static function antlersNode(AbstractNode $node, ?NodeProcessor $processor = null): self
    {
        $view = GlobalRuntimeState::$currentExecutionFile;

        return new self(
            self::ENGINE_ANTLERS,
            self::KIND_NODE,
            trim((string) $node->content),
            is_string($view) && $view !== '' ? $view : null,
            $node->startPosition->line ?? null,
            $node,
            ['processor' => $processor],
        );
    }

    public static function bladeTag(Tags $tag, string $method): self
    {
        $expression = is_string($tag->tag) && $tag->tag !== ''
            ? $tag->tag
            : strtolower(class_basename($tag)).':'.$method;

        return new self(
            self::ENGINE_BLADE,
            self::KIND_TAG,
            $expression,
            null,
            null,
            $tag,
            ['method' => $method],
        );
    }

    /**
     * The engine object behind this span: an Antlers AbstractNode or a
     * Statamic Tags instance.
     */
    public function raw(): mixed
    {
        return $this->raw;
    }

    public function meta(?string $key = null): mixed
    {
        return $key === null ? $this->meta : ($this->meta[$key] ?? null);
    }

    /**
     * The runtime scope, when the engine can provide one.
     *
     * Captured the first time it is requested and stable afterwards: calling
     * this in onEnter() reports the scope at entry, and calling it only in
     * onExit() reports the scope at exit. A span held past the moment it
     * describes keeps what it captured.
     */
    public function scope(): ?array
    {
        if ($this->scopeCaptured) {
            return $this->capturedScope;
        }

        $this->scopeCaptured = true;

        $processor = $this->meta['processor'] ?? null;

        return $this->capturedScope = $processor instanceof NodeProcessor
            ? $processor->getActiveData()
            : null;
    }
}
