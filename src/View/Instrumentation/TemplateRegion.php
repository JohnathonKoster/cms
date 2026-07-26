<?php

namespace Statamic\View\Instrumentation;

/**
 * One instrumentable region of a template, described the same way regardless
 * of which engine produced it. Filter and skip callbacks receive these, so
 * tooling never handles engine node types directly — the raw node stays
 * reachable through raw() when engine-specific detail is needed.
 */
final class TemplateRegion
{
    const TYPE_SINGLE = 'single';

    const TYPE_PAIR = 'pair';

    const TYPE_ANTLERS_BLOCK = 'antlers-block';

    public function __construct(
        public readonly string $engine,
        public readonly string $type,
        public readonly string $expression,
        public readonly int $line,
        public readonly int $start,
        public readonly int $end,
        public readonly int $byteStart,
        public readonly int $byteEnd,
        private readonly HtmlContext $context,
        private readonly mixed $raw,
        public readonly bool $commentSafe = false,
        public readonly ?int $innerByteStart = null,
        public readonly ?int $innerByteEnd = null,
    ) {
    }

    public function context(): HtmlContext
    {
        return $this->context;
    }

    /**
     * The engine node behind this region: an Antlers AntlersNode or a Forte
     * node.
     */
    public function raw(): mixed
    {
        return $this->raw;
    }

    public function isPair(): bool
    {
        return $this->type !== self::TYPE_SINGLE;
    }
}
