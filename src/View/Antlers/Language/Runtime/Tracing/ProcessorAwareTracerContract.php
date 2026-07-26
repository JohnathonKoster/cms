<?php

namespace Statamic\View\Antlers\Language\Runtime\Tracing;

use Statamic\View\Antlers\Language\Runtime\NodeProcessor;

interface ProcessorAwareTracerContract
{
    /**
     * Invoked before onEnter with the NodeProcessor that is about to
     * process the node, giving tracers access to the active scope
     * (e.g. via getActiveData()).
     *
     * @param  NodeProcessor  $processor  The processor evaluating the node.
     */
    public function setNodeProcessor(NodeProcessor $processor);
}
