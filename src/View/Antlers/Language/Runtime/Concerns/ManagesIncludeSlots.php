<?php

namespace Statamic\View\Antlers\Language\Runtime\Concerns;

use Statamic\Support\Arr;
use Statamic\Tags\TagNotFoundException;
use Statamic\View\Antlers\Language\Exceptions\RuntimeException;
use Statamic\View\Antlers\Language\Exceptions\SyntaxErrorException;
use Statamic\View\Antlers\Language\Nodes\AbstractNode;
use Statamic\View\Antlers\Language\Nodes\AntlersNode;
use Statamic\View\Antlers\Language\Nodes\LiteralNode;
use Statamic\View\Antlers\Language\Runtime\Slot;
use Throwable;

trait ManagesIncludeSlots
{
    const INCLUDE_SLOTS_KEY = '__antlers_include_slots';

    const INCLUDE_CONTROL_PARAMS = [
        'src', 'when', 'unless',
        'cascade', 'params',
        'handle_prefix',
    ];

    protected function captureIncludeSlots(AntlersNode $node, array $tagActiveData, array $tagParameters): array
    {
        $includeParams = Arr::except($tagParameters, self::INCLUDE_CONTROL_PARAMS);

        $tagActiveData[self::INCLUDE_SLOTS_KEY] = $this->buildIncludeSlots($node, $tagActiveData, $includeParams);

        return $tagActiveData;
    }

    /**
     * Builds deferred slot objects for an include tag.
     *
     * The default slot is composed of every child that is not a named slot
     * declaration. Each slot captures the caller scope and the include's
     * params so it can be rendered lazily by the included view.
     *
     * @return array<string, Slot>
     */
    protected function buildIncludeSlots(AntlersNode $node, array $callerData, array $params): array
    {
        $namedSlots = [];
        $defaultChildren = [];

        foreach ($node->children as $child) {
            if ($child instanceof AntlersNode && $child->isClosingTag) {
                continue;
            }

            if ($child instanceof AntlersNode && ! $child->isComment &&
                $child->name != null && $child->name->name == 'slot' &&
                $child->name->methodPart != null) {
                $namedSlots[$child->name->methodPart] = $child;

                continue;
            }

            $defaultChildren[] = $child;
        }

        $slots = [];

        if ($this->slotHasContent($defaultChildren)) {
            $slots['slot'] = new Slot('slot', $defaultChildren, $callerData, $params, $this);
        }

        foreach ($namedSlots as $slotName => $slotNode) {
            if ($this->slotHasContent($slotNode->children)) {
                $slots['slot:'.$slotName] = new Slot($slotName, $slotNode->children, $callerData, $params, $this);
            }
        }

        return $slots;
    }

    /**
     * @param  AbstractNode[]  $children
     */
    protected function slotHasContent(array $children): bool
    {
        foreach ($children as $child) {
            if ($child instanceof LiteralNode) {
                if (trim($child->content) !== '') {
                    return true;
                }

                continue;
            }

            if ($child instanceof AntlersNode && ($child->isComment || $child->isClosingTag)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Resolves any scoped props supplied at a slot's output site.
     *
     * Allows the included view to expose its own data to slot content, e.g.
     * {{ slot:row :row="row" :index="count" }}.
     *
     * @throws TagNotFoundException
     * @throws RuntimeException
     * @throws SyntaxErrorException
     * @throws Throwable
     */
    protected function getSlotOutputProps(AntlersNode $node): array
    {
        if (! $node->hasParameters) {
            return [];
        }

        $lockData = $this->data;
        $props = $node->getParameterValues($this, $this->getActiveData());
        $this->data = $lockData;

        return $props;
    }
}
