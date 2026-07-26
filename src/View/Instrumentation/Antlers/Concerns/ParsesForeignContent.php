<?php

namespace Statamic\View\Instrumentation\Antlers\Concerns;

/**
 * SVG and MathML foreign content: namespaces, integration points, and
 * breakout back to the HTML namespace.
 */
trait ParsesForeignContent
{
    protected function namespaceForElement($name)
    {
        $namespace = empty($this->namespaceStack)
            ? 'html'
            : $this->namespaceStack[count($this->namespaceStack) - 1];
        $parentName = empty($this->elementStack)
            ? null
            : $this->elementStack[count($this->elementStack) - 1];

        if ($namespace === 'svg' && isset(self::$svgHtmlIntegrationPoints[$parentName])) {
            $namespace = 'html';
        }

        if ($namespace === 'mathml') {
            if (isset(self::$mathMlTextIntegrationPoints[$parentName])
                && ! in_array($name, ['mglyph', 'malignmark'], true)) {
                $namespace = 'html';
            } elseif ($parentName === 'annotation-xml') {
                $encoding = empty($this->elementEncodingStack)
                    ? ''
                    : strtolower((string) $this->elementEncodingStack[count($this->elementEncodingStack) - 1]);

                if ($encoding === 'text/html' || $encoding === 'application/xhtml+xml') {
                    $namespace = 'html';
                }
            }
        }

        if ($namespace === 'html') {
            if ($name === 'svg') {
                return 'svg';
            }

            if ($name === 'math') {
                return 'mathml';
            }
        }

        return $namespace;
    }

    protected function recordForeignFontBreakoutAttribute($name)
    {
        if ($this->currentElementName === 'font'
            && in_array($name, ['color', 'face', 'size'], true)) {
            $this->hasForeignFontBreakoutAttribute = true;
        }
    }

    protected function currentNamespaceIsForeign()
    {
        return $this->namespaceStack
            && $this->namespaceStack[count($this->namespaceStack) - 1] !== 'html';
    }

    protected function exitForeignContent()
    {
        while ($this->elementStack) {
            $index = count($this->elementStack) - 1;

            if ($this->namespaceStack[$index] === 'html' || $this->isForeignIntegrationPoint($index)) {
                return;
            }

            $this->sliceStacks($index);
        }
    }

    protected function isForeignIntegrationPoint($index)
    {
        $name = $this->elementStack[$index];
        $namespace = $this->namespaceStack[$index];

        if ($namespace === 'svg') {
            return isset(self::$svgHtmlIntegrationPoints[$name]);
        }

        if (isset(self::$mathMlTextIntegrationPoints[$name])) {
            return true;
        }

        if ($name !== 'annotation-xml') {
            return false;
        }

        $encoding = strtolower((string) ($this->elementEncodingStack[$index] ?? ''));

        return $encoding === 'text/html' || $encoding === 'application/xhtml+xml';
    }
}
