<?php

namespace Statamic\View\Instrumentation\Antlers;

/**
 * The single implementation of Antlers region-extent scanning used by the
 * HTML analyzers. It mirrors the runtime document parser exactly: a region
 * ends at the first `}}` whose braces are not `@`-escaped, `{...}`
 * interpolations are skipped by balancing braces, and quotes are not
 * significant to region extents. Keeping one copy guarantees every HTML-side
 * consumer agrees with the runtime about where a region ends.
 *
 * @internal
 */
class RegionExtents
{
    /**
     * Returns the byte offset immediately after the region's closing `}}`,
     * or false when the region never terminates.
     *
     * @param  string  $source
     * @param  int  $start  Byte offset of the region's `{{`.
     * @param  int  $length
     * @return int|false
     */
    public static function endInBytes($source, $start, $length)
    {
        $index = $start + 2;

        while ($index < $length) {
            $char = $source[$index];
            $previous = $source[$index - 1];

            if ($char === '{' && $previous !== '@') {
                $depth = 0;

                while ($index < $length) {
                    $char = $source[$index];

                    if ($source[$index - 1] !== '@') {
                        if ($char === '{') {
                            $depth++;
                        } elseif ($char === '}') {
                            $depth--;

                            if ($depth === 0) {
                                break;
                            }
                        }
                    }

                    $index++;
                }

                $index++;

                continue;
            }

            if ($char === '}' && $previous !== '@' && ($source[$index + 1] ?? null) === '}') {
                return $index + 2;
            }

            $index++;
        }

        return false;
    }
}
