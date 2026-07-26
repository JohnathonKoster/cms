<?php

namespace Statamic\View\Instrumentation;

/**
 * Component tag prefixes treated as dynamic HTML boundaries by every
 * template analyzer. The rendered component, rather than its call site,
 * owns the HTML structure beneath these elements.
 *
 * Only Blade's and Statamic's own prefixes are built in. Projects using a
 * component library with its own naming add it through the instrumentation
 * config's `componentPrefixes` key, or with
 * HtmlInstrumentation::componentPrefixes(). Treating an unknown prefix as
 * dynamic is not free: everything beneath it becomes ineligible for markers.
 */
final class ComponentPrefixes
{
    const DEFAULTS = [
        'x-', 'x:',
        's-', 's:',
        'statamic-', 'statamic:',
    ];

    /**
     * @deprecated Use ComponentPrefixes::DEFAULTS, or pass an explicit list.
     */
    const ALL = self::DEFAULTS;

    /**
     * @param  string  $name
     * @param  string[]|null  $prefixes  Defaults to the built-in prefixes.
     * @return bool
     */
    public static function matches($name, ?array $prefixes = null)
    {
        $name = strtolower((string) $name);

        foreach ($prefixes ?? self::DEFAULTS as $prefix) {
            if ($prefix !== '' && str_starts_with($name, strtolower($prefix))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalizes a configured prefix list, merging it with the built-ins.
     *
     * @param  string[]  $prefixes
     * @return string[]
     */
    public static function merge(array $prefixes)
    {
        $merged = self::DEFAULTS;

        foreach ($prefixes as $prefix) {
            if (is_string($prefix) && $prefix !== '' && ! in_array($prefix, $merged, true)) {
                $merged[] = $prefix;
            }
        }

        return $merged;
    }
}
