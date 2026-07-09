<?php

namespace Statamic\View\Blade;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;

/**
 * A deferred Blade slot.
 *
 * Captures the (compiled) slot content and the scope it was written in, then
 * renders lazily. Rendering as a string uses the captured scope alone; invoking
 * it with props (a scoped slot) merges those props on top, so the view can hand
 * the slot per-iteration data via {{ <s:slot:row :prop="..." /> }}.
 */
class BladeSlot implements Htmlable
{
    public function __construct(
        protected string $template,
        protected array $data = [],
    ) {
    }

    public function __invoke(array $props = []): string
    {
        return Blade::render($this->template, array_merge($this->data, $props));
    }

    public function toHtml(): string
    {
        return $this();
    }

    public function __toString(): string
    {
        return $this();
    }

    /**
     * Renders a slot at an output site, passing along any scoped props. Plain
     * values (a non-scoped slot, or nothing) are echoed as-is.
     */
    public static function output(mixed $slot, array $props = []): string
    {
        if ($slot instanceof self) {
            return $slot($props);
        }

        return (string) ($slot ?? '');
    }
}
