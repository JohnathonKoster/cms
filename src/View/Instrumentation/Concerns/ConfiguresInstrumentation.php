<?php

namespace Statamic\View\Instrumentation\Concerns;

use Statamic\Support\Str;
use Statamic\View\Instrumentation\ComponentPrefixes;
use Statamic\View\Instrumentation\TemplateRegion;

/**
 * The fluent configuration surface: strategies, marker factories,
 * filters, and metadata.
 */
trait ConfiguresInstrumentation
{
    /**
     * Emit paired HTML comment markers around safe template output.
     *
     * @param  string  $prefix
     * @return $this
     */
    public function comments($prefix = 'antlers')
    {
        $this->prefix($prefix);
        $this->commentsEnabled = true;

        return $this;
    }

    /**
     * Set the default comment prefix without changing whether comments are
     * enabled.
     *
     * @param  string  $prefix
     * @return $this
     */
    public function prefix($prefix)
    {
        if (! is_string($prefix)
            || ! preg_match('/^[A-Za-z0-9_-]+$/D', $prefix)
            || str_contains($prefix, '--')) {
            throw new \InvalidArgumentException('HTML instrumentation comment prefixes may contain letters, numbers, underscores, and single hyphens.');
        }

        $this->commentPrefix = $prefix;
        $this->flushResultCache();

        return $this;
    }

    /**
     * Disable comment markers while retaining any configured attribute layers.
     *
     * @return $this
     */
    public function withoutComments()
    {
        $this->commentsEnabled = false;
        $this->flushResultCache();

        return $this;
    }

    /**
     * Use a custom marker factory. It receives the node metadata and returns
     * [startMarker, endMarker].
     *
     * @return $this
     */
    public function markersUsing(callable $factory)
    {
        $this->commentFactory = $factory;
        $this->flushResultCache();

        return $this;
    }

    /**
     * Instrument only regions accepted by the callback. It receives the
     * metadata payload and the engine-neutral TemplateRegion (whose raw()
     * exposes the underlying Antlers or Forte node).
     *
     * @return $this
     */
    public function filter(callable $filter)
    {
        $this->nodeFilter = $filter;
        $this->flushResultCache();

        return $this;
    }

    /**
     * Observe regions that produced no output at all — no comment markers
     * and no attribute-layer entry. The callback receives the metadata
     * payload, the TemplateRegion, and one of the SKIP_* reason constants.
     *
     * Skips are reported when a template is actually analyzed; memoized
     * repeat calls return the cached result without re-reporting.
     *
     * @return $this
     */
    public function onSkip(callable $callback)
    {
        $this->skipCallback = $callback;
        $this->flushResultCache();

        return $this;
    }

    /**
     * Instrument only expressions matching one of the wildcard patterns.
     * Examples: `partial:*`, `collection:*`, `$title`.
     *
     * @param  array<string>  $patterns
     * @return $this
     */
    public function matching(array $patterns)
    {
        return $this->filter(static function ($metadata) use ($patterns) {
            foreach ($patterns as $pattern) {
                if (is_string($pattern) && Str::is($pattern, $metadata['expression'])) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * Add an attribute layer that stores all eligible region metadata for an
     * element as base64-encoded JSON.
     *
     * @param  string  $attributeName
     * @return $this
     */
    public function attributes($attributeName = 'data-antlers')
    {
        return $this->attribute($attributeName);
    }

    /**
     * Add an attribute layer. The value factory receives the collected
     * metadata for one static HTML element and returns an attribute value. Return
     * null to omit that attribute for the element.
     *
     * @param  string  $attributeName
     * @return $this
     */
    public function attribute($attributeName, ?callable $valueFactory = null)
    {
        if (! is_string($attributeName)
            || $attributeName === ''
            || ! mb_check_encoding($attributeName, 'UTF-8')
            || preg_match('/[\x00-\x20\x7F"\'<>\/=]/', $attributeName)) {
            throw new \InvalidArgumentException('Invalid HTML instrumentation attribute name.');
        }

        $this->attributeLayers[$attributeName] = $valueFactory ?: static function ($metadata) {
            $json = json_encode($metadata, JSON_INVALID_UTF8_SUBSTITUTE);

            return base64_encode($json === false ? '[]' : $json);
        };
        $this->flushResultCache();

        return $this;
    }

    /**
     * Add a readable JSON attribute layer instead of a base64-encoded one.
     *
     * @param  string  $attributeName
     * @return $this
     */
    public function jsonAttribute($attributeName = 'data-antlers')
    {
        return $this->attribute($attributeName, static function ($metadata) {
            $json = json_encode($metadata, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);

            return $json === false ? '[]' : $json;
        });
    }

    /**
     * Treat additional element name prefixes as component boundaries, on top
     * of Blade's and Statamic's own. Everything beneath a component is
     * dynamic markup, so only add prefixes that really do render arbitrary
     * HTML — anything listed here becomes ineligible for markers.
     *
     * @param  array<string>  $prefixes
     * @return $this
     */
    public function componentPrefixes(array $prefixes)
    {
        $this->componentPrefixes = ComponentPrefixes::merge($prefixes);
        $this->flushResultCache();

        return $this;
    }

    /**
     * Include extra values in every marker's metadata.
     *
     * @param  array<string, mixed>  $metadata
     * @return $this
     */
    public function withMetadata(array $metadata)
    {
        $this->extraMetadata = array_merge($this->extraMetadata, $metadata);
        $this->flushResultCache();

        return $this;
    }
}
