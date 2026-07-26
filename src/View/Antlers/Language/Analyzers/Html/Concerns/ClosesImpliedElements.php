<?php

namespace Statamic\View\Antlers\Language\Analyzers\Html\Concerns;

use Statamic\View\Antlers\Language\Analyzers\Html\Arena;
use Statamic\View\Antlers\Language\Analyzers\Html\Element;

/**
 * Implied end tags, scope queries, and open element stack management
 * following the HTML5 tree construction rules.
 */
trait ClosesImpliedElements
{
    protected function isInHtmlSelectMode()
    {
        if ($this->openSelect === null) {
            return false;
        }

        $selectIndex = array_search($this->openSelect, $this->stack, true);

        if ($selectIndex === false) {
            $this->openSelect = null;
            $this->selectInTable = false;

            return false;
        }

        for ($index = count($this->stack) - 1; $index >= $selectIndex; $index--) {
            $element = $this->stack[$index];

            if ($this->arena->kind($element) & (Arena::SVG | Arena::MATHML)) {
                return false;
            }

            $name = $this->arena->name[$element];

            if ($name === 'select') {
                return true;
            }

            if ($name === 'template') {
                return false;
            }
        }

        return false;
    }

    protected function hasOpenHtmlTemplate()
    {
        for ($index = count($this->stack) - 1; $index >= 0; $index--) {
            $element = $this->stack[$index];

            if (! ($this->arena->kind($element) & (Arena::SVG | Arena::MATHML))
                && $this->arena->name[$element] === 'template') {
                return true;
            }
        }

        return false;
    }

    protected function closeOpenElement($name)
    {
        for ($index = count($this->stack) - 1; $index >= 0; $index--) {
            $element = $this->stack[$index];

            if (! ($this->arena->kind($element) & (Arena::SVG | Arena::MATHML))
                && $this->arena->name[$element] === $name) {
                $this->sliceOpenStack($index);

                if ($name === 'select') {
                    $this->openSelect = null;
                    $this->selectInTable = false;
                }

                return;
            }
        }
    }

    protected function hasOpenElementInTableScope($name)
    {
        for ($index = count($this->stack) - 1; $index >= 0; $index--) {
            $element = $this->stack[$index];

            if ($this->arena->kind($element) & (Arena::SVG | Arena::MATHML)) {
                continue;
            }

            $stackName = $this->arena->name[$element];

            if ($stackName === $name) {
                return true;
            }

            if (isset(self::$tableScopeBoundaries[$stackName])) {
                return false;
            }
        }

        return false;
    }

    protected function stackIndex($name)
    {
        for ($index = count($this->stack) - 1; $index >= 0; $index--) {
            if (strcasecmp((string) $this->arena->name[$this->stack[$index]], $name) === 0) {
                return $index;
            }
        }

        return null;
    }

    protected function htmlEndTagMatchIndex($name)
    {
        if ($name === 'template') {
            for ($index = count($this->stack) - 1; $index >= 0; $index--) {
                $element = $this->stack[$index];

                if (! ($this->arena->kind($element) & (Arena::SVG | Arena::MATHML))
                    && $this->arena->name[$element] === 'template') {
                    return $index;
                }
            }

            return null;
        }

        if (isset(self::$headingElements[$name])) {
            return $this->htmlElementInScopeIndex(self::$headingElements, self::$scopeBoundaries);
        }

        if ($name === 'p') {
            return $this->htmlElementInScopeIndex(['p' => true], self::$buttonScopeBoundaries);
        }

        if ($name === 'li') {
            return $this->htmlElementInScopeIndex(['li' => true], self::$listItemScopeBoundaries);
        }

        if ($name === 'dt' || $name === 'dd') {
            return $this->htmlElementInScopeIndex([$name => true], self::$scopeBoundaries);
        }

        if (isset(self::$scopedEndTags[$name])) {
            return $this->htmlElementInScopeIndex([$name => true], self::$scopeBoundaries);
        }

        if ($name === 'table'
            || isset(self::$tableSections[$name])
            || in_array($name, ['caption', 'colgroup', 'tr', 'td', 'th'], true)) {
            return $this->htmlElementInScopeIndex([$name => true], self::$tableScopeBoundaries);
        }

        for ($index = count($this->stack) - 1; $index >= 0; $index--) {
            $element = $this->stack[$index];

            if ($this->arena->kind($element) & (Arena::SVG | Arena::MATHML)) {
                return null;
            }

            $stackName = strtolower((string) $this->arena->name[$element]);

            if ($stackName === $name) {
                return $index;
            }

            if ($this->isSpecialElement($stackName)) {
                return null;
            }
        }

        return null;
    }

    protected function htmlElementInScopeIndex(array $targets, array $boundaries)
    {
        for ($index = count($this->stack) - 1; $index >= 0; $index--) {
            $element = $this->stack[$index];

            if ($this->arena->kind($element) & (Arena::SVG | Arena::MATHML)) {
                return null;
            }

            $name = strtolower((string) $this->arena->name[$element]);

            if (isset($targets[$name])) {
                return $index;
            }

            if (isset($boundaries[$name])) {
                return null;
            }
        }

        return null;
    }

    protected function isSpecialElement($name)
    {
        return isset(self::$formattingBreakStarts[$name])
            || isset(self::$rawTextElements[$name])
            || in_array($name, ['body', 'frameset', 'head', 'html', 'summary'], true);
    }

    /**
     * Keep a lazily materialized element handle in sync with its arena slot.
     * Formatting-equivalence checks may materialize handles while the source
     * is still being scanned, before their eventual closing tags are known.
     */
    protected function setElementClosingMarkup($element, $markup)
    {
        $this->arena->closing[$element] = $markup;

        if (isset($this->handles[$element]) && $this->handles[$element] instanceof Element) {
            $this->handles[$element]->closingTag($markup);
        }
    }

    protected function closeImpliedElements($name)
    {
        if (empty($this->stack)) {
            return;
        }

        if ($name === 'option' || $name === 'optgroup') {
            $index = count($this->stack) - 1;
            $current = $this->stack[$index];

            if (! ($this->arena->kind($current) & (Arena::SVG | Arena::MATHML))
                && $this->arena->name[$current] === 'option') {
                $this->sliceOpenStack($index);
            }

            if ($name === 'optgroup' && $this->stack && $this->isInHtmlSelectMode()) {
                $index = count($this->stack) - 1;
                $current = $this->stack[$index];

                if (! ($this->arena->kind($current) & (Arena::SVG | Arena::MATHML))
                    && $this->arena->name[$current] === 'optgroup') {
                    $this->sliceOpenStack($index);
                }
            }

            return;
        }

        if (isset(self::$pClosingElements[$name])) {
            $this->closeElementInScope(['p' => true], self::$buttonScopeBoundaries);
        }

        if (isset(self::$headingElements[$name])) {
            if ($this->stack) {
                $index = count($this->stack) - 1;
                $current = $this->stack[$index];

                if (! ($this->arena->kind($current) & (Arena::SVG | Arena::MATHML))
                    && isset(self::$headingElements[strtolower((string) $this->arena->name[$current])])) {
                    $this->sliceOpenStack($index);
                }
            }

            return;
        }

        if ($name === 'li') {
            $this->closeItemBeforeSpecialBoundary(['li' => true]);

            return;
        }

        if ($name === 'dt' || $name === 'dd') {
            $this->closeItemBeforeSpecialBoundary(['dt' => true, 'dd' => true]);

            return;
        }

        $targets = self::$impliedCloseTargets[$name] ?? null;
        $boundaries = self::$scopeBoundaries;

        if ($name === 'li') {
            $boundaries = self::$listItemScopeBoundaries;
        } elseif (isset(self::$tableScopedStarts[$name])) {
            $boundaries = self::$tableScopeBoundaries;
        } elseif ($name === 'button') {
            $targets = ['button' => true];
            $boundaries = self::$buttonScopeBoundaries;
        }

        if ($targets !== null) {
            $this->closeElementInScope($targets, $boundaries);
        }
    }

    protected function generateImpliedEndTags($except = null)
    {
        $implied = [
            'dd' => true, 'dt' => true, 'li' => true, 'optgroup' => true,
            'option' => true, 'p' => true, 'rp' => true, 'rt' => true,
        ];

        while ($this->stack) {
            $index = count($this->stack) - 1;
            $element = $this->stack[$index];

            if ($this->arena->kind($element) & (Arena::SVG | Arena::MATHML)) {
                return;
            }

            $name = strtolower((string) $this->arena->name[$element]);

            if ($name === $except || ! isset($implied[$name])) {
                return;
            }

            $this->sliceOpenStack($index);
        }
    }

    protected function closeItemBeforeSpecialBoundary(array $targets)
    {
        for ($index = count($this->stack) - 1; $index >= 0; $index--) {
            $element = $this->stack[$index];

            if ($this->arena->kind($element) & (Arena::SVG | Arena::MATHML)) {
                return;
            }

            $name = strtolower((string) $this->arena->name[$element]);

            if (isset($targets[$name])) {
                $this->sliceOpenStack($index);

                return;
            }

            if (isset(self::$formattingBreakStarts[$name])
                && ! in_array($name, ['address', 'div', 'p'], true)) {
                return;
            }
        }
    }

    protected function closeElementInScope(array $targets, array $boundaries)
    {
        for ($index = count($this->stack) - 1; $index >= 0; $index--) {
            $stackElement = $this->stack[$index];
            $stackName = $this->arena->name[$stackElement];

            if (isset($targets[$stackName])) {
                $this->sliceOpenStack($index);

                return;
            }

            if (isset($boundaries[$stackName])) {
                return;
            }

            if ($this->isForeignScopeBoundary($stackElement)) {
                return;
            }
        }
    }
}
