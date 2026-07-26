<?php

namespace Statamic\View\Antlers\Language\Runtime\Tracing;

use Statamic\View\Antlers\Language\Nodes\AbstractNode;
use Statamic\View\Antlers\Language\Runtime\NodeProcessor;
use Statamic\View\Instrumentation\Span;
use Statamic\View\Instrumentation\TracerContract;

class TraceManager
{
    /**
     * The configured Antlers-specific runtime tracers.
     *
     * @var RuntimeTracerContract[]
     */
    protected $tracers = [];

    /**
     * Engine-neutral tracers, receiving Spans instead of raw nodes.
     *
     * @var TracerContract[]
     */
    protected $spanTracers = [];

    /**
     * Open span frames for engine-neutral tracers, stacked in enter order.
     * Each frame records its node's identity so exits can re-synchronize if
     * the runtime skips an exit path, and pairs every handle with the tracer
     * that produced it so registration order can never misroute one.
     *
     * @var array<int, array{0: int, 1: Span, 2: array<int, array{0: TracerContract, 1: mixed}>}>
     */
    protected $frames = [];

    /**
     * Frame counts captured when each (possibly nested) render begins.
     * Completion may only abandon frames opened by that render; callers
     * outside it can still be waiting for a nested partial or component.
     *
     * @var int[]
     */
    protected $renderBoundaries = [];

    /**
     * Tracers implementing both contracts are registered once, through the
     * Antlers-specific contract, which carries full engine fidelity.
     *
     * @param  RuntimeTracerContract|TracerContract  $tracer
     */
    public function registerTracer($tracer)
    {
        if ($tracer instanceof RuntimeTracerContract) {
            if (in_array($tracer, $this->tracers, true)) {
                return;
            }

            $this->tracers[] = $tracer;

            return;
        }

        if ($tracer instanceof TracerContract) {
            if (in_array($tracer, $this->spanTracers, true)) {
                return;
            }

            $this->spanTracers[] = $tracer;

            return;
        }

        throw new \InvalidArgumentException(
            'Tracers must implement RuntimeTracerContract or the engine-neutral TracerContract.'
        );
    }

    /**
     * Removes a previously registered tracer of either contract. Frames
     * already open keep their own tracer references, so a removal mid-render
     * cannot leave an onEnter without its onExit.
     *
     * @param  RuntimeTracerContract|TracerContract  $tracer
     * @return void
     */
    public function removeTracer($tracer)
    {
        $this->tracers = array_values(array_filter(
            $this->tracers,
            fn ($registered) => $registered !== $tracer
        ));

        $this->spanTracers = array_values(array_filter(
            $this->spanTracers,
            fn ($registered) => $registered !== $tracer
        ));
    }

    /**
     * Removes every registered tracer.
     *
     * @return void
     */
    public function clearTracers()
    {
        $this->tracers = [];
        $this->spanTracers = [];
    }

    /**
     * @return RuntimeTracerContract[]
     */
    public function tracers()
    {
        return $this->tracers;
    }

    /**
     * @return TracerContract[]
     */
    public function spanTracers()
    {
        return $this->spanTracers;
    }

    public function traceOnEnter(AbstractNode $node, ?NodeProcessor $processor = null)
    {
        foreach ($this->tracers as $tracer) {
            if ($processor !== null && $tracer instanceof ProcessorAwareTracerContract) {
                $tracer->setNodeProcessor($processor);
            }

            $tracer->onEnter($node);
        }

        if ($this->spanTracers !== []) {
            $span = Span::antlersNode($node, $processor);
            $handles = [];

            foreach ($this->spanTracers as $tracer) {
                $handles[] = [$tracer, $tracer->onEnter($span)];
            }

            $this->frames[] = [spl_object_id($node), $span, $handles];
        }
    }

    public function traceOnExit(AbstractNode $node, $runtimeContent)
    {
        foreach ($this->tracers as $tracer) {
            $tracer->onExit($node, $runtimeContent);
        }

        if ($this->spanTracers === [] || $this->frames === []) {
            return;
        }

        $nodeId = spl_object_id($node);
        $targetIndex = null;

        for ($index = count($this->frames) - 1; $index >= 0; $index--) {
            if ($this->frames[$index][0] === $nodeId) {
                $targetIndex = $index;

                break;
            }
        }

        if ($targetIndex === null) {
            return;
        }

        // Frames above the target were entered on paths that skipped their
        // exit; close them with null output so every onEnter is balanced.
        while (count($this->frames) - 1 > $targetIndex) {
            $this->closeFrame(array_pop($this->frames), null);
        }

        $this->closeFrame(array_pop($this->frames), $runtimeContent);
    }

    public function traceRenderStart()
    {
        $this->renderBoundaries[] = count($this->frames);
    }

    public function traceRenderComplete()
    {
        // Antlers-specific tracers are notified once per parse, nested
        // partials included.
        foreach ($this->tracers as $tracer) {
            $tracer->onRenderComplete();
        }

        $boundary = array_pop($this->renderBoundaries) ?? 0;

        while (count($this->frames) > $boundary) {
            $this->closeFrame(array_pop($this->frames), null);
        }

        // Engine-neutral tracers are told rendering completed only when the
        // outermost render unwinds; a partial finishing is not the end of the
        // render that asked for it.
        if ($this->renderBoundaries !== []) {
            return;
        }

        foreach ($this->spanTracers as $tracer) {
            $tracer->onRenderComplete();
        }
    }

    /**
     * @param  array{0: int, 1: Span, 2: array<int, array{0: TracerContract, 1: mixed}>}  $frame
     */
    protected function closeFrame(array $frame, $runtimeContent)
    {
        [, $span, $handles] = $frame;

        // Reverse registration order, so tracers unwind the way they nested.
        for ($index = count($handles) - 1; $index >= 0; $index--) {
            [$tracer, $handle] = $handles[$index];

            $tracer->onExit($span, $handle, $runtimeContent);
        }
    }
}
