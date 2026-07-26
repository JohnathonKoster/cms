<?php

namespace Statamic\View\Instrumentation\Antlers\Concerns;

/**
 * The HTML5 adoption agency algorithm and active formatting element
 * reconstruction, mirrored over the scanner's parallel name stacks.
 */
trait AdoptsFormattingElements
{
    protected function sliceStacks($length)
    {
        if ($this->activeFormatting) {
            for ($index = count($this->elementStack) - 1; $index >= $length; $index--) {
                if (isset(self::$formattingMarkerElements[$this->elementStack[$index]])) {
                    $this->clearActiveFormattingToLastMarker();
                }
            }
        }

        $this->elementStack = array_slice($this->elementStack, 0, $length);
        $this->elementOffsetStack = array_slice($this->elementOffsetStack, 0, $length);
        $this->namespaceStack = array_slice($this->namespaceStack, 0, $length);
        $this->elementEncodingStack = array_slice($this->elementEncodingStack, 0, $length);
        if ($this->activeFormatting) {
            $this->refreshFormattingReconstructionPending();
        } else {
            $this->formattingReconstructionPending = false;
        }
    }

    protected function removeActiveFormatting($name)
    {
        for ($index = count($this->activeFormatting) - 1; $index >= 0; $index--) {
            if ($this->activeFormatting[$index] === null) {
                break;
            }

            if ($this->activeFormatting[$index] === $name) {
                array_splice($this->activeFormatting, $index, 1);
                array_splice($this->activeFormattingSignatures, $index, 1);
                array_splice($this->activeFormattingOffsets, $index, 1);
                break;
            }
        }

        $this->refreshFormattingReconstructionPending();
    }

    protected function adoptFormattingStack($name)
    {
        $transformed = false;

        for ($outer = 0; $outer < 8; $outer++) {
            $activeIndex = $this->activeFormattingNameIndex($name);

            if ($activeIndex === null) {
                break;
            }

            $activeOffset = $this->activeFormattingOffsets[$activeIndex];
            $match = null;

            for ($index = count($this->elementStack) - 1; $index >= 0; $index--) {
                if ($this->namespaceStack[$index] === 'html'
                    && $this->elementStack[$index] === $name
                    && $this->elementOffsetStack[$index] === $activeOffset) {
                    $match = $index;
                    break;
                }
            }

            if ($match === null) {
                array_splice($this->activeFormatting, $activeIndex, 1);
                array_splice($this->activeFormattingSignatures, $activeIndex, 1);
                array_splice($this->activeFormattingOffsets, $activeIndex, 1);
                $this->refreshFormattingReconstructionPending();

                return true;
            }

            if (! $this->stackIndexIsInScope($match, self::$scopeBoundaries)) {
                return true;
            }

            $furthestIndex = null;

            for ($index = $match + 1, $count = count($this->elementStack); $index < $count; $index++) {
                if (isset(self::$formattingBreakStarts[$this->elementStack[$index]])) {
                    $furthestIndex = $index;
                    break;
                }
            }

            if ($furthestIndex === null) {
                if (! $transformed) {
                    return false;
                }

                array_splice($this->activeFormatting, $activeIndex, 1);
                array_splice($this->activeFormattingSignatures, $activeIndex, 1);
                array_splice($this->activeFormattingOffsets, $activeIndex, 1);
                $this->sliceStacks($match);

                return true;
            }

            $transformed = true;
            $bookmark = $activeIndex;
            $inner = 0;

            for ($index = $furthestIndex - 1; $index > $match; $index--) {
                $inner++;
                $nodeActiveIndex = $this->activeFormattingElementIndex(
                    $this->elementStack[$index],
                    $this->elementOffsetStack[$index]
                );

                if ($inner > 3 && $nodeActiveIndex !== null) {
                    array_splice($this->activeFormatting, $nodeActiveIndex, 1);
                    array_splice($this->activeFormattingSignatures, $nodeActiveIndex, 1);
                    array_splice($this->activeFormattingOffsets, $nodeActiveIndex, 1);
                    $nodeActiveIndex = null;
                }

                if ($nodeActiveIndex === null) {
                    $this->spliceElementStacks($index, 1);
                    $furthestIndex--;

                    continue;
                }

                if ($index === $furthestIndex - 1) {
                    $bookmark = $nodeActiveIndex + 1;
                }
            }

            $formattingOffset = $this->elementOffsetStack[$match];
            $formattingEncoding = $this->elementEncodingStack[$match];
            $formattingSignature = $this->activeFormattingSignatures[$activeIndex];
            $activeFormattingOffset = $this->activeFormattingOffsets[$activeIndex];
            $this->spliceElementStacks($match, 1);
            $furthestIndex--;
            array_splice($this->activeFormatting, $activeIndex, 1);
            array_splice($this->activeFormattingSignatures, $activeIndex, 1);
            array_splice($this->activeFormattingOffsets, $activeIndex, 1);

            if ($activeIndex < $bookmark) {
                $bookmark--;
            }

            array_splice($this->activeFormatting, $bookmark, 0, [$name]);
            array_splice($this->activeFormattingSignatures, $bookmark, 0, [$formattingSignature]);
            array_splice($this->activeFormattingOffsets, $bookmark, 0, [$activeFormattingOffset]);
            $this->spliceElementStacks(
                $furthestIndex + 1,
                0,
                [$name],
                [$formattingOffset],
                ['html'],
                [$formattingEncoding]
            );
        }

        $this->refreshFormattingReconstructionPending();

        return $transformed;
    }

    protected function activeFormattingNameIndex($name)
    {
        for ($index = count($this->activeFormatting) - 1; $index >= 0; $index--) {
            if ($this->activeFormatting[$index] === null) {
                return null;
            }

            if ($this->activeFormatting[$index] === $name) {
                return $index;
            }
        }

        return null;
    }

    protected function stackIndexIsInScope($targetIndex, array $boundaries)
    {
        for ($index = count($this->elementStack) - 1; $index >= 0; $index--) {
            if ($index === $targetIndex) {
                return true;
            }

            if ($this->namespaceStack[$index] !== 'html') {
                $name = $this->elementStack[$index];
                $namespace = $this->namespaceStack[$index];
                $scopeBoundary = ($namespace === 'svg' && isset(self::$svgHtmlIntegrationPoints[$name]))
                    || ($namespace === 'mathml'
                        && ($name === 'annotation-xml' || isset(self::$mathMlTextIntegrationPoints[$name])));

                if ($scopeBoundary) {
                    return false;
                }

                continue;
            }

            if (isset($boundaries[$this->elementStack[$index]])) {
                return false;
            }
        }

        return false;
    }

    protected function activeFormattingElementIndex($name, $offset)
    {
        for ($index = count($this->activeFormatting) - 1; $index >= 0; $index--) {
            if ($this->activeFormatting[$index] === null) {
                return null;
            }

            if ($this->activeFormatting[$index] === $name
                && $this->activeFormattingOffsets[$index] === $offset) {
                return $index;
            }
        }

        return null;
    }

    protected function pushActiveFormatting($name, $signature, $offset)
    {
        if ($signature !== null) {
            $equivalent = [];

            for ($index = count($this->activeFormatting) - 1; $index >= 0; $index--) {
                if ($this->activeFormatting[$index] === null) {
                    break;
                }

                if ($this->activeFormatting[$index] === $name
                    && $this->activeFormattingSignatures[$index] === $signature) {
                    $equivalent[] = $index;
                }
            }

            if (count($equivalent) >= 3) {
                $remove = $equivalent[count($equivalent) - 1];
                array_splice($this->activeFormatting, $remove, 1);
                array_splice($this->activeFormattingSignatures, $remove, 1);
                array_splice($this->activeFormattingOffsets, $remove, 1);
            }
        }

        $this->activeFormatting[] = $name;
        $this->activeFormattingSignatures[] = $signature;
        $this->activeFormattingOffsets[] = $offset;
    }

    protected function spliceElementStacks(
        $offset,
        $length,
        array $elements = [],
        array $elementOffsets = [],
        array $namespaces = [],
        array $encodings = []
    ) {
        array_splice($this->elementStack, $offset, $length, $elements);
        array_splice($this->elementOffsetStack, $offset, $length, $elementOffsets);
        array_splice($this->namespaceStack, $offset, $length, $namespaces);
        array_splice($this->elementEncodingStack, $offset, $length, $encodings);
    }

    protected function clearActiveFormattingToLastMarker()
    {
        for ($index = count($this->activeFormatting) - 1; $index >= 0; $index--) {
            if ($this->activeFormatting[$index] === null) {
                $this->activeFormatting = array_slice($this->activeFormatting, 0, $index);
                $this->activeFormattingSignatures = array_slice($this->activeFormattingSignatures, 0, $index);
                $this->activeFormattingOffsets = array_slice($this->activeFormattingOffsets, 0, $index);

                return;
            }
        }
    }

    protected function refreshFormattingReconstructionPending()
    {
        $this->formattingReconstructionPending = false;

        for ($index = count($this->activeFormatting) - 1; $index >= 0; $index--) {
            $formatting = $this->activeFormatting[$index];

            if ($formatting === null) {
                break;
            }

            if (! $this->activeFormattingIsOnStack($index)) {
                $this->formattingReconstructionPending = true;

                break;
            }
        }
    }

    protected function reconstructActiveFormattingStack()
    {
        $count = count($this->activeFormatting);

        if ($count === 0
            || $this->activeFormatting[$count - 1] === null
            || $this->activeFormattingIsOnStack($count - 1)) {
            return;
        }

        $index = $count - 1;

        while ($index > 0
            && $this->activeFormatting[$index - 1] !== null
            && ! $this->activeFormattingIsOnStack($index - 1)) {
            $index--;
        }

        for (; $index < $count; $index++) {
            $this->elementStack[] = $this->activeFormatting[$index];
            $this->elementOffsetStack[] = $this->activeFormattingOffsets[$index];
            $this->namespaceStack[] = 'html';
            $this->elementEncodingStack[] = null;
        }

        $this->formattingReconstructionPending = false;
    }

    protected function activeFormattingIsOnStack($activeIndex)
    {
        $name = $this->activeFormatting[$activeIndex];
        $offset = $this->activeFormattingOffsets[$activeIndex];

        for ($index = count($this->elementStack) - 1; $index >= 0; $index--) {
            if ($this->namespaceStack[$index] === 'html'
                && $this->elementStack[$index] === $name
                && $this->elementOffsetStack[$index] === $offset) {
                return true;
            }
        }

        return false;
    }
}
