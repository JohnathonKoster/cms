<?php

namespace Statamic\View\Instrumentation;

/**
 * Engine-neutral execution tracer. Implementations receive a Span for every
 * unit of template work on every engine they are registered with — Antlers
 * runtime nodes and Statamic tags invoked through Blade components speak the
 * same contract.
 *
 * The handle returned from onEnter() is passed back to the matching onExit()
 * call, which also receives the unit's output. That output is null both when
 * execution threw and when the runtime path reported no result — many Antlers
 * node types (tags and conditions among them) do not surface their buffer —
 * so null means "no output available", not "failed".
 *
 * onRenderComplete() fires on engines that have a completion moment; the
 * Antlers runtime provides one when its outermost render unwinds, so a
 * partial or nested parse finishing does not trigger it. Blade tag execution
 * has no completion moment and never calls it.
 *
 * Engine-specific tracers remain first-class: implement
 * {@see \Statamic\View\Antlers\Language\Runtime\Tracing\RuntimeTracerContract}
 * to receive raw Antlers nodes only.
 */
interface TracerContract
{
    public function onEnter(Span $span): mixed;

    public function onExit(Span $span, mixed $handle, mixed $output): void;

    public function onRenderComplete(): void;
}
