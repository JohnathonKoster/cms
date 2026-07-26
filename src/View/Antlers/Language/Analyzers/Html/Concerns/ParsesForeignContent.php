<?php

namespace Statamic\View\Antlers\Language\Analyzers\Html\Concerns;

use Statamic\View\Antlers\Language\Analyzers\Html\Arena;

/**
 * SVG and MathML foreign content: namespaces, integration points, and
 * breakout back to the HTML namespace.
 */
trait ParsesForeignContent
{
    protected function isForeignScopeBoundary($element)
    {
        $name = strtolower((string) $this->arena->name[$element]);
        $namespace = $this->arena->namespace($element);

        return ($namespace === 'svg' && isset(self::$svgHtmlIntegrationPoints[$name]))
            || ($namespace === 'mathml'
                && ($name === 'annotation-xml' || isset(self::$mathMlTextIntegrationPoints[$name])));
    }

    protected function namespaceForForeignChild($name, $container)
    {
        $namespace = $this->arena->namespace($container);

        if ($namespace === 'svg'
            && isset(self::$svgHtmlIntegrationPoints[strtolower((string) $this->arena->name[$container])])) {
            $namespace = 'html';
        }

        if ($namespace === 'mathml') {
            $containerName = strtolower((string) $this->arena->name[$container]);

            if (isset(self::$mathMlTextIntegrationPoints[$containerName])
                && ! in_array($name, ['mglyph', 'malignmark'], true)) {
                $namespace = 'html';
            } elseif ($containerName === 'annotation-xml') {
                $encoding = strtolower((string) $this->handle($container)->attr('encoding'));

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

    protected function currentContainerIsForeign()
    {
        $container = $this->currentContainer();

        return $container !== 0
            && (bool) ($this->arena->kind($container) & (Arena::SVG | Arena::MATHML));
    }

    protected function shouldExitForeignContent($name, $markup)
    {
        if (isset(self::$foreignBreakoutElements[$name])) {
            return true;
        }

        return $name === 'font'
            && preg_match('/\s(?:color|face|size)(?=[\x00-\x20=\/>])/i', $markup);
    }

    protected function exitForeignContent()
    {
        while ($this->stack) {
            $container = $this->currentContainer();

            if (! ($this->arena->kind($container) & (Arena::SVG | Arena::MATHML))
                || $this->isForeignIntegrationPoint($container)) {
                return;
            }

            $this->sliceOpenStack(count($this->stack) - 1);
        }
    }

    protected function isForeignIntegrationPoint($element)
    {
        $name = strtolower((string) $this->arena->name[$element]);
        $namespace = $this->arena->namespace($element);

        if ($namespace === 'svg') {
            return isset(self::$svgHtmlIntegrationPoints[$name]);
        }

        if (isset(self::$mathMlTextIntegrationPoints[$name])) {
            return true;
        }

        if ($name !== 'annotation-xml') {
            return false;
        }

        $encoding = strtolower((string) $this->handle($element)->attr('encoding'));

        return $encoding === 'text/html' || $encoding === 'application/xhtml+xml';
    }
}
