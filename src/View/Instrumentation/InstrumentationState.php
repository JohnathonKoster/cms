<?php

namespace Statamic\View\Instrumentation;

/**
 * Tracks component render phases that change what HTML instrumentation can
 * classify safely.
 *
 * @internal
 */
class InstrumentationState
{
    protected static $componentContentDepth = 0;

    protected static $componentViewDepth = 0;

    protected static $viewInstrumentationDepth = 0;

    public static function parsingComponentContent()
    {
        return self::$componentContentDepth > 0;
    }

    public static function renderingComponentView()
    {
        return self::$componentViewDepth > 0;
    }

    public static function instrumentingView()
    {
        return self::$viewInstrumentationDepth > 0;
    }

    public static function whileParsingComponentContent(callable $callback)
    {
        self::$componentContentDepth++;

        try {
            return $callback();
        } finally {
            self::$componentContentDepth--;
        }
    }

    public static function whileRenderingComponentView(callable $callback)
    {
        self::$componentViewDepth++;

        try {
            return $callback();
        } finally {
            self::$componentViewDepth--;
        }
    }

    public static function whileInstrumentingView(callable $callback)
    {
        self::$viewInstrumentationDepth++;

        try {
            return $callback();
        } finally {
            self::$viewInstrumentationDepth--;
        }
    }
}
