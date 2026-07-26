<?php

namespace Statamic\View\Antlers\Language\Analyzers\Html\Concerns;

use Statamic\View\Antlers\Language\Analyzers\Html\Arena;
use Statamic\View\Antlers\Language\Analyzers\Html\Element;

/**
 * The HTML5 adoption agency algorithm and active formatting element
 * reconstruction for mis-nested inline formatting.
 */
trait AdoptsFormattingElements
{
    protected function closeFormattingElement($name, $closingMarkup = null, $start = null, $end = null)
    {
        $activeIndex = $this->activeFormattingIndex($name);

        if ($activeIndex === null) {
            return false;
        }

        $formatting = $this->activeFormatting[$activeIndex];
        $match = array_search($formatting, $this->stack, true);

        if ($match === false) {
            array_splice($this->activeFormatting, $activeIndex, 1);

            if ($closingMarkup !== null) {
                $this->appendOpaqueMarkup($start, $end, $closingMarkup);
            }

            return true;
        }

        if (! $this->stackIndexIsInScope($match, self::$scopeBoundaries)) {
            if ($closingMarkup !== null) {
                $this->appendOpaqueMarkup($start, $end, $closingMarkup);
            }

            return true;
        }

        if ($this->adoptFormattingAroundBlocks($match, $formatting, $closingMarkup, $start, $end)) {
            return true;
        }

        if ($closingMarkup !== null) {
            $this->attachEmbeddedRegions($formatting, $start, $end);
            $this->setElementClosingMarkup($formatting, $closingMarkup);
        }

        $this->sliceOpenStack($match);

        if ($activeIndex !== null) {
            array_splice($this->activeFormatting, $activeIndex, 1);
        }

        return true;
    }

    protected function adoptFormattingAroundBlocks($match, $formatting, $closingMarkup, $start, $end)
    {
        $transformed = false;
        $closingOwner = null;

        // The HTML adoption-agency algorithm deliberately caps this loop.
        // Keeping the same bound makes malformed formatting deterministic.
        for ($outer = 0; $outer < 8; $outer++) {
            $activeIndex = $this->activeFormattingIndex($this->arena->name[$formatting]);

            if ($activeIndex === null) {
                break;
            }

            $formatting = $this->activeFormatting[$activeIndex];
            $match = array_search($formatting, $this->stack, true);

            if ($match === false) {
                array_splice($this->activeFormatting, $activeIndex, 1);
                break;
            }

            $furthestIndex = null;

            for ($index = $match + 1, $count = count($this->stack); $index < $count; $index++) {
                $name = strtolower((string) $this->arena->name[$this->stack[$index]]);

                if (isset(self::$formattingBreakStarts[$name])) {
                    $furthestIndex = $index;
                    break;
                }
            }

            if ($furthestIndex === null) {
                if (! $transformed) {
                    return false;
                }

                $closingOwner = $formatting;
                $this->sliceOpenStack($match);
                array_splice($this->activeFormatting, $activeIndex, 1);
                break;
            }

            $transformed = true;
            $furthest = $this->stack[$furthestIndex];
            $commonAncestor = $match === 0 ? 0 : $this->stack[$match - 1];
            $bookmark = $activeIndex;
            $lastNode = $furthest;
            $inner = 0;

            for ($index = $furthestIndex - 1; $index > $match; $index--) {
                $inner++;
                $node = $this->stack[$index];
                $nodeActiveIndex = array_search($node, $this->activeFormatting, true);

                if ($inner > 3 && $nodeActiveIndex !== false) {
                    array_splice($this->activeFormatting, $nodeActiveIndex, 1);
                    $nodeActiveIndex = false;
                }

                if ($nodeActiveIndex === false) {
                    array_splice($this->stack, $index, 1);
                    $furthestIndex--;

                    continue;
                }

                $copy = $this->reconstructedCopy($node);
                $this->activeFormatting[$nodeActiveIndex] = $copy;
                $this->stack[$index] = $copy;

                if ($lastNode === $furthest) {
                    $bookmark = $nodeActiveIndex + 1;
                }

                $this->anchorArenaNodeForMove($lastNode);
                $this->arena->append($copy, $lastNode);
                $lastNode = $copy;
            }

            $this->anchorArenaNodeForMove($lastNode);

            if ($this->arena->parent($formatting) === $commonAncestor) {
                $this->arena->insertAfter($formatting, $lastNode);
            } elseif ($this->isTableFosterParent($commonAncestor)) {
                $tableIndex = $this->nearestTableIndex();
                $table = $tableIndex === null ? null : $this->stack[$tableIndex];

                if ($table !== null && $this->arena->parent($table) !== null) {
                    $this->arena->insertBefore($table, $lastNode);
                } else {
                    $this->arena->append($commonAncestor, $lastNode);
                }
            } else {
                $this->arena->append($commonAncestor, $lastNode);
            }

            $newFormatting = $this->reconstructedCopy($formatting);

            foreach ($this->arena->children($furthest) as $child) {
                $this->anchorArenaNodeForMove($child);
                $this->arena->append($newFormatting, $child);
            }

            $this->arena->append($furthest, $newFormatting);
            $closingOwner = $newFormatting;

            $formattingActiveIndex = array_search($formatting, $this->activeFormatting, true);

            if ($formattingActiveIndex !== false) {
                array_splice($this->activeFormatting, $formattingActiveIndex, 1);

                if ($formattingActiveIndex < $bookmark) {
                    $bookmark--;
                }
            }

            array_splice($this->activeFormatting, $bookmark, 0, [$newFormatting]);
            $formattingStackIndex = array_search($formatting, $this->stack, true);

            if ($formattingStackIndex !== false) {
                array_splice($this->stack, $formattingStackIndex, 1);
            }

            $furthestStackIndex = array_search($furthest, $this->stack, true);

            if ($furthestStackIndex === false) {
                $this->stack[] = $newFormatting;
            } else {
                array_splice($this->stack, $furthestStackIndex + 1, 0, [$newFormatting]);
            }
        }

        if (! $transformed || $closingOwner === null) {
            return false;
        }

        if ($closingMarkup !== null) {
            $this->setElementClosingMarkup($closingOwner, $closingMarkup);
            $this->attachEmbeddedRegions($closingOwner, $start, $end);
        }

        return true;
    }

    protected function reconstructActiveFormatting()
    {
        $count = count($this->activeFormatting);

        if ($count === 0
            || $this->activeFormatting[$count - 1] === null
            || in_array($this->activeFormatting[$count - 1], $this->stack, true)) {
            return;
        }

        $index = $count - 1;

        while ($index > 0
            && $this->activeFormatting[$index - 1] !== null
            && ! in_array($this->activeFormatting[$index - 1], $this->stack, true)) {
            $index--;
        }

        for (; $index < $count; $index++) {
            $copy = $this->reconstructedCopy($this->activeFormatting[$index]);
            $container = $this->currentContainer();

            if ($this->hasSeenTable && $this->shouldFosterContent()) {
                $this->fosterNode($copy, $container);
            } else {
                $this->arena->append($container, $copy);
            }

            $this->stack[] = $copy;
            $this->activeFormatting[$index] = $copy;
        }
    }

    protected function activeFormattingIndex($name)
    {
        for ($index = count($this->activeFormatting) - 1; $index >= 0; $index--) {
            if ($this->activeFormatting[$index] === null) {
                return null;
            }

            if (strcasecmp((string) $this->arena->name[$this->activeFormatting[$index]], $name) === 0) {
                return $index;
            }
        }

        return null;
    }

    protected function stackIndexIsInScope($targetIndex, array $boundaries)
    {
        for ($index = count($this->stack) - 1; $index >= 0; $index--) {
            if ($index === $targetIndex) {
                return true;
            }

            $element = $this->stack[$index];

            if ($this->arena->kind($element) & (Arena::SVG | Arena::MATHML)) {
                if ($this->isForeignScopeBoundary($element)) {
                    return false;
                }

                continue;
            }

            if (isset($boundaries[strtolower((string) $this->arena->name[$element])])) {
                return false;
            }
        }

        return false;
    }

    protected function pushActiveFormattingElement($element)
    {
        $equivalent = [];

        for ($index = count($this->activeFormatting) - 1; $index >= 0; $index--) {
            $candidate = $this->activeFormatting[$index];

            if ($candidate === null) {
                break;
            }

            if ($this->formattingElementsAreEquivalent($candidate, $element)) {
                $equivalent[] = $index;
            }
        }

        if (count($equivalent) >= 3) {
            array_splice($this->activeFormatting, $equivalent[count($equivalent) - 1], 1);
        }

        $this->activeFormatting[] = $element;
    }

    protected function formattingElementsAreEquivalent($left, $right)
    {
        if ($this->arena->name[$left] !== $this->arena->name[$right]
            || $this->arena->namespace($left) !== $this->arena->namespace($right)) {
            return false;
        }

        $leftMarkup = $this->arena->value[$left];
        $rightMarkup = $this->arena->value[$right];

        if (strpos($leftMarkup, '{{') !== false || strpos($rightMarkup, '{{') !== false) {
            return $leftMarkup === $rightMarkup;
        }

        $leftAttributes = $this->handle($left)->attributes();
        $rightAttributes = $this->handle($right)->attributes();
        ksort($leftAttributes);
        ksort($rightAttributes);

        return $leftAttributes === $rightAttributes;
    }

    protected function sliceOpenStack($length)
    {
        if ($this->activeFormatting) {
            for ($index = count($this->stack) - 1; $index >= $length; $index--) {
                $removed = $this->stack[$index];

                if (! ($this->arena->kind($removed) & (Arena::SVG | Arena::MATHML))
                    && isset(self::$formattingMarkerElements[$this->arena->name[$removed]])) {
                    $this->clearActiveFormattingToLastMarker();
                }
            }
        }

        $this->stack = array_slice($this->stack, 0, $length);
    }

    protected function clearActiveFormattingToLastMarker()
    {
        for ($index = count($this->activeFormatting) - 1; $index >= 0; $index--) {
            if ($this->activeFormatting[$index] === null) {
                $this->activeFormatting = array_slice($this->activeFormatting, 0, $index);

                return;
            }
        }
    }

    protected function reconstructedCopy($element)
    {
        return $this->arena->element(
            $this->arena->name[$element],
            $this->arena->value[$element],
            false,
            $this->arena->namespace($element),
            true
        );
    }
}
