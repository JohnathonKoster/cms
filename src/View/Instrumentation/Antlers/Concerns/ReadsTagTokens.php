<?php

namespace Statamic\View\Instrumentation\Antlers\Concerns;

/**
 * Character-level tag tokenization: names, attributes, delimiters, and
 * per-tag scan state.
 */
trait ReadsTagTokens
{
    protected function resetTagState()
    {
        $this->state = self::STATE_TEXT;
        $this->resetCurrentTag();
    }

    protected function resetCurrentTag()
    {
        $this->currentElementName = '';
        $this->currentElementStartOffset = null;
        $this->currentElementIsClosing = false;
        $this->currentElementNameComplete = false;
        $this->currentElementNameDynamic = false;
        $this->currentElementIsComponent = false;
        $this->currentTagSignature = null;
        $this->currentAttributeName = '';
        $this->pendingAttributeName = '';
        $this->lastAttributeName = '';
        $this->currentAttributeValue = '';
        $this->currentAttributeValueDynamic = false;
        $this->captureCurrentAttributeValue = false;
        $this->currentAnnotationEncoding = null;
        $this->currentAnnotationEncodingSeen = false;
        $this->hasForeignFontBreakoutAttribute = false;
    }

    protected function commitCurrentAttribute()
    {
        if ($this->captureCurrentAttributeValue
            && ! $this->currentAttributeValueDynamic
            && $this->currentAnnotationEncoding === null) {
            $this->currentAnnotationEncoding = $this->currentAttributeValue;
        }

        $this->currentAttributeValue = '';
        $this->currentAttributeValueDynamic = false;
        $this->captureCurrentAttributeValue = false;
    }

    protected function isTagNameChar($char)
    {
        return ! $this->isHtmlSpace($char) && $char !== '>' && $char !== '/';
    }

    protected function isAttributeNameChar($char)
    {
        return ! $this->isHtmlSpace($char) && ! in_array($char, ['>', '/', '='], true);
    }

    protected function isHtmlSpace($char)
    {
        return $char === ' ' || $char === "\t" || $char === "\n" || $char === "\r" || $char === "\f";
    }

    /**
     * Whether the character at the given index terminates a tag name (per
     * HTML5: whitespace, `/`, or `>`). Indexes past the end of the literal
     * report false so a name split across an Antlers region is not treated
     * as complete.
     */
    protected function isTagNameDelimiter($content, $index, $length)
    {
        if ($index >= $length) {
            return false;
        }

        $char = $content[$index];

        return $char === '>' || $char === '/' || $this->isHtmlSpace($char);
    }

    protected function nameJustStarted($content, $index)
    {
        $previous = $index > 0 ? $content[$index - 1] : '';

        return $previous === '<' || $previous === '/';
    }

    protected function readName($content, $start)
    {
        $name = '';
        $length = strlen($content);

        for ($i = $start; $i < $length; $i++) {
            if (! $this->isTagNameChar($content[$i])) {
                break;
            }

            $name .= $content[$i] === "\0" ? "\xEF\xBF\xBD" : $content[$i];
        }

        return strtolower($name);
    }
}
