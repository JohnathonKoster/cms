<?php

namespace Statamic\View\Instrumentation\Antlers\Concerns;

use Statamic\View\Instrumentation\HtmlContext;

/**
 * Applies HTML5 tree construction when a tag token completes: implied
 * end tags, scope queries, and open element stack management.
 */
trait AppliesTreeConstruction
{
    protected function finishTag($selfClosing)
    {
        $tagSignature = $this->currentTagSignature;
        $name = $this->currentElementNameDynamic
            ? HtmlContext::DYNAMIC_ELEMENT
            : strtolower($this->currentElementName);

        if ($this->currentElementIsClosing) {
            if ($this->hasOpenSelect
                && $this->selectInTable
                && $this->isInHtmlSelectMode()
                && isset(self::$selectTableElements[$name])) {
                if (! $this->hasOpenElementInTableScope($name)) {
                    $this->resetTagState();

                    return;
                }

                $this->closeOpenElement('select');
            }

            if ($this->hasOpenSelect
                && $this->isInHtmlSelectMode()
                && ! in_array($name, ['option', 'optgroup', 'select', 'template'], true)) {
                $this->resetTagState();

                return;
            }

            if ($name === 'form' && ! $this->hasOpenHtmlTemplate()) {
                if ($this->hasFormPointer) {
                    $formIndex = $this->openElementIndex('form');

                    if ($formIndex !== null) {
                        $tableIndex = $this->nearestTableIndex();

                        if ($tableIndex === null || $formIndex > $tableIndex) {
                            $this->generateImpliedEndTags();
                        }

                        $this->removeOpenElement('form');
                    }

                    $this->hasFormPointer = false;
                }

                $this->resetTagState();

                return;
            }

            if (isset(self::$formattingElements[$name])) {
                if ($this->adoptFormattingStack($name)) {
                    $this->resetTagState();

                    return;
                }

                $this->removeActiveFormatting($name);
            }

            $currentNamespace = $this->namespaceStack
                ? $this->namespaceStack[count($this->namespaceStack) - 1]
                : 'html';

            if ($currentNamespace === 'html') {
                $match = $this->htmlEndTagMatchIndex($name);
            } else {
                $match = null;

                for ($index = count($this->elementStack) - 1; $index >= 0; $index--) {
                    if ($this->elementStack[$index] === $name) {
                        $match = $index;
                        break;
                    }
                }
            }

            if ($match !== null) {
                $this->sliceStacks($match);

                if ($name === 'select') {
                    $this->hasOpenSelect = false;
                    $this->selectInTable = false;
                }
            }
        } elseif ($name !== '') {
            $currentIsForeign = $this->namespaceStack
                && $this->namespaceStack[count($this->namespaceStack) - 1] !== 'html';

            if ($currentIsForeign) {
                if ($name === 'font') {
                    $this->recordForeignFontBreakoutAttribute($this->currentAttributeName);
                    $this->recordForeignFontBreakoutAttribute($this->pendingAttributeName);
                    $this->recordForeignFontBreakoutAttribute($this->lastAttributeName);
                }

                if (isset(self::$foreignBreakoutElements[$name])
                    || ($name === 'font' && $this->hasForeignFontBreakoutAttribute)) {
                    $this->exitForeignContent();
                }
            }

            $namespace = $this->namespaceForElement($name);
            $isHtml = $namespace === 'html';
            $tableForm = $isHtml
                && $name === 'form'
                && in_array($this->tableStructureMode(), ['table', 'body', 'row'], true);
            $selectInTable = $isHtml
                && $name === 'select'
                && in_array($this->tableStructureMode(), ['table', 'body', 'row', 'cell', 'caption', 'colgroup'], true);

            if ($isHtml) {
                if (isset(self::$tableStructureStarts[$name])
                    && $this->tableStructureMode() === null
                    && ! $this->hasOpenHtmlTemplate()) {
                    $this->resetTagState();

                    return;
                }

                if ($this->hasOpenSelect && $this->isInHtmlSelectMode()) {
                    if ($this->selectInTable && isset(self::$selectTableElements[$name])) {
                        $this->closeOpenElement('select');
                    } elseif ($name === 'select') {
                        $this->closeOpenElement('select');
                        $this->resetTagState();

                        return;
                    } elseif (in_array($name, ['input', 'keygen', 'textarea'], true)) {
                        $this->closeOpenElement('select');
                    } elseif (! in_array($name, ['option', 'optgroup', 'hr', 'script', 'template'], true)) {
                        $this->resetTagState();

                        return;
                    }
                }

                if ($name === 'form' && $this->hasFormPointer && ! $this->hasOpenHtmlTemplate()) {
                    $this->resetTagState();

                    return;
                }

                if (($name === 'a' || $name === 'nobr')
                    && $this->activeFormattingNameIndex($name) !== null) {
                    $formattingIndex = $this->activeFormattingNameIndex($name);
                    $formattingOffset = $this->activeFormattingOffsets[$formattingIndex];

                    if (! $this->adoptFormattingStack($name)) {
                        $this->removeActiveFormatting($name);
                        $match = $this->htmlEndTagMatchIndex($name);

                        if ($match !== null) {
                            $this->sliceStacks($match);
                        }
                    }

                    $formattingIndex = $this->activeFormattingElementIndex($name, $formattingOffset);

                    if ($formattingIndex !== null) {
                        array_splice($this->activeFormatting, $formattingIndex, 1);
                        array_splice($this->activeFormattingSignatures, $formattingIndex, 1);
                        array_splice($this->activeFormattingOffsets, $formattingIndex, 1);
                    }

                    for ($index = count($this->elementStack) - 1; $index >= 0; $index--) {
                        if ($this->namespaceStack[$index] === 'html'
                            && $this->elementStack[$index] === $name
                            && $this->elementOffsetStack[$index] === $formattingOffset) {
                            $this->spliceElementStacks($index, 1);

                            break;
                        }
                    }
                }

                $this->prepareTableStart($name);

                if ($name === 'form') {
                    $tableForm = in_array($this->tableStructureMode(), ['table', 'body', 'row'], true);
                }

                if (! $tableForm) {
                    $this->closeImpliedElements($name);
                }

                if ($this->activeFormatting
                    && (! isset(self::$formattingBreakStarts[$name]) || in_array($name, ['button', 'select'], true))) {
                    $this->reconstructActiveFormattingStack();
                }
            }

            // An `image` start tag is rewritten to `img` by the in-body
            // insertion rules, so it is void in the HTML namespace only;
            // SVG's image element is a normal foreign element.
            if ($isHtml && (isset(self::$voidElements[$name]) || $name === 'image')) {
                $this->resetTagState();

                return;
            }

            if (! $isHtml && $selfClosing) {
                $this->resetTagState();

                return;
            }

            if ($isHtml && $name === 'plaintext') {
                $this->rawTextElement = $name;
                $this->rawTextElementStartOffset = $this->currentElementStartOffset;
                $this->state = self::STATE_PLAINTEXT;
                $this->resetCurrentTag();

                return;
            }

            if ($isHtml && isset(self::$rawTextElements[$name])) {
                $this->rawTextElement = $name;
                $this->rawTextElementStartOffset = $this->currentElementStartOffset;
                $this->rawTextEscape = 0;
                $this->state = self::STATE_RAW_TEXT;
                $this->resetCurrentTag();

                return;
            }

            if (! $tableForm) {
                $this->elementStack[] = $name;
                $this->elementOffsetStack[] = $this->currentElementStartOffset;
                $this->namespaceStack[] = $namespace;
                $this->elementEncodingStack[] = $this->currentAnnotationEncoding;
            }

            if (! $tableForm && $isHtml && isset(self::$formattingMarkerElements[$name])) {
                $this->activeFormatting[] = null;
                $this->activeFormattingSignatures[] = null;
                $this->activeFormattingOffsets[] = null;
                $this->refreshFormattingReconstructionPending();
            }

            if ($isHtml && $name === 'form' && ! $this->hasOpenHtmlTemplate()) {
                $this->hasFormPointer = true;
            }

            if ($isHtml && $name === 'select') {
                $this->hasOpenSelect = true;
                $this->selectInTable = $selectInTable;
            }

            if (! $tableForm && $isHtml && isset(self::$formattingElements[$name])) {
                $this->pushActiveFormatting($name, $tagSignature, $this->currentElementStartOffset);
            }
        }

        $this->resetTagState();
    }

    protected function isInHtmlSelectMode()
    {
        if (! $this->hasOpenSelect) {
            return false;
        }

        for ($index = count($this->elementStack) - 1; $index >= 0; $index--) {
            if ($this->namespaceStack[$index] !== 'html') {
                return false;
            }

            $name = $this->elementStack[$index];

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
        for ($index = count($this->elementStack) - 1; $index >= 0; $index--) {
            if ($this->namespaceStack[$index] === 'html' && $this->elementStack[$index] === 'template') {
                return true;
            }
        }

        return false;
    }

    protected function htmlEndTagMatchIndex($name)
    {
        if ($name === 'template') {
            for ($index = count($this->elementStack) - 1; $index >= 0; $index--) {
                if ($this->namespaceStack[$index] === 'html' && $this->elementStack[$index] === 'template') {
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

        for ($index = count($this->elementStack) - 1; $index >= 0; $index--) {
            if ($this->namespaceStack[$index] !== 'html') {
                return null;
            }

            $stackName = $this->elementStack[$index];

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
        for ($index = count($this->elementStack) - 1; $index >= 0; $index--) {
            if ($this->namespaceStack[$index] !== 'html') {
                return null;
            }

            $name = $this->elementStack[$index];

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

    protected function closeOpenElement($name)
    {
        for ($index = count($this->elementStack) - 1; $index >= 0; $index--) {
            if ($this->namespaceStack[$index] === 'html' && $this->elementStack[$index] === $name) {
                $this->sliceStacks($index);

                if ($name === 'select') {
                    $this->hasOpenSelect = false;
                    $this->selectInTable = false;
                }

                return;
            }
        }
    }

    protected function removeOpenElement($name)
    {
        $index = $this->openElementIndex($name);

        if ($index !== null) {
            array_splice($this->elementStack, $index, 1);
            array_splice($this->elementOffsetStack, $index, 1);
            array_splice($this->namespaceStack, $index, 1);
            array_splice($this->elementEncodingStack, $index, 1);

            return true;
        }

        return false;
    }

    protected function openElementIndex($name)
    {
        for ($index = count($this->elementStack) - 1; $index >= 0; $index--) {
            if ($this->namespaceStack[$index] === 'html' && $this->elementStack[$index] === $name) {
                return $index;
            }
        }

        return null;
    }

    protected function hasOpenElementInTableScope($name)
    {
        for ($index = count($this->elementStack) - 1; $index >= 0; $index--) {
            if ($this->namespaceStack[$index] !== 'html') {
                continue;
            }

            $stackName = $this->elementStack[$index];

            if ($stackName === $name) {
                return true;
            }

            if (isset(self::$tableScopeBoundaries[$stackName])) {
                return false;
            }
        }

        return false;
    }

    protected function closeImpliedElements($name)
    {
        if (empty($this->elementStack)) {
            return;
        }

        if ($name === 'option' || $name === 'optgroup') {
            $index = count($this->elementStack) - 1;

            if ($this->namespaceStack[$index] === 'html' && $this->elementStack[$index] === 'option') {
                $this->sliceStacks($index);
            }

            if ($name === 'optgroup' && $this->elementStack && $this->isInHtmlSelectMode()) {
                $index = count($this->elementStack) - 1;

                if ($this->namespaceStack[$index] === 'html' && $this->elementStack[$index] === 'optgroup') {
                    $this->sliceStacks($index);
                }
            }

            return;
        }

        if (isset(self::$pClosingElements[$name])) {
            $this->closeElementInScope(['p' => true], self::$buttonScopeBoundaries);
        }

        if (isset(self::$headingElements[$name])) {
            if ($this->elementStack) {
                $index = count($this->elementStack) - 1;

                if ($this->namespaceStack[$index] === 'html'
                    && isset(self::$headingElements[$this->elementStack[$index]])) {
                    $this->sliceStacks($index);
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

        while ($this->elementStack) {
            $index = count($this->elementStack) - 1;

            if ($this->namespaceStack[$index] !== 'html') {
                return;
            }

            $name = $this->elementStack[$index];

            if ($name === $except || ! isset($implied[$name])) {
                return;
            }

            $this->sliceStacks($index);
        }
    }

    protected function closeItemBeforeSpecialBoundary(array $targets)
    {
        for ($index = count($this->elementStack) - 1; $index >= 0; $index--) {
            if ($this->namespaceStack[$index] !== 'html') {
                return;
            }

            $name = $this->elementStack[$index];

            if (isset($targets[$name])) {
                $this->sliceStacks($index);

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
        for ($index = count($this->elementStack) - 1; $index >= 0; $index--) {
            $stackName = $this->elementStack[$index];

            if (isset($targets[$stackName])) {
                $this->sliceStacks($index);

                return;
            }

            if (isset($boundaries[$stackName])) {
                return;
            }
        }
    }
}
