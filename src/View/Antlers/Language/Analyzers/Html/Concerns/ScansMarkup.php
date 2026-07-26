<?php

namespace Statamic\View\Antlers\Language\Analyzers\Html\Concerns;

use Statamic\View\Antlers\Language\Analyzers\Html\Node;
use Statamic\View\Antlers\Language\Analyzers\Html\Region;
use Statamic\View\Antlers\Language\Analyzers\Html\Text;
use Statamic\View\Antlers\Language\Nodes\AntlersNode as LanguageAntlersNode;
use Statamic\View\Instrumentation\CharacterOffsets;

/**
 * Low-level source scanning: tag and markup extents, raw-text and
 * script-data boundaries, and Antlers region indexing.
 */
trait ScansMarkup
{
    protected function isCurrentRawTextClosingTag($position)
    {
        $container = $this->textElement;

        if ($container === null || substr($this->source, $position, 2) !== '</') {
            return false;
        }

        if ($this->arena->name[$container] === 'script' && $this->scriptEscape === 2) {
            return false;
        }

        $name = preg_quote($this->arena->name[$container], '/');

        return (bool) preg_match('/^<\/\s*'.$name.'(?=[\x00-\x20>\/])/i', substr($this->source, $position));
    }

    protected function nextRawTextBoundary($position)
    {
        $sourceLength = strlen($this->source);
        $nextAntlers = $this->nextRegionStart($position);
        $container = $this->textElement;

        if ($this->arena->name[$container] === 'script') {
            return $this->nextScriptBoundary(
                $position,
                $nextAntlers === null ? $sourceLength : $nextAntlers
            );
        }

        $pattern = '/<\/\s*'.preg_quote($this->arena->name[$container], '/').'(?=[\x00-\x20>\/])/i';
        $closing = preg_match($pattern, $this->source, $matches, PREG_OFFSET_CAPTURE, $position)
            ? $matches[0][1]
            : false;

        return min(
            $nextAntlers === null ? $sourceLength : $nextAntlers,
            $closing === false ? $sourceLength : $closing
        );
    }

    protected function nextScriptBoundary($position, $limit)
    {
        for ($index = $position; $index < $limit; $index++) {
            $char = $this->source[$index];

            if ($char === '<' && ($this->source[$index + 1] ?? null) === '/') {
                $closing = strtolower(substr($this->source, $index + 2, 6));
                $delimiter = $this->source[$index + 8] ?? null;

                if ($closing === 'script' && ($delimiter === null || $delimiter === '>' || $delimiter === '/' || $this->isHtmlSpace($delimiter))) {
                    if ($this->scriptEscape === 2) {
                        $this->scriptEscape = 1;
                        $index += 7;

                        continue;
                    }

                    return $index;
                }
            }

            if ($char === '<') {
                if ($this->scriptEscape === 0 && substr($this->source, $index + 1, 3) === '!--') {
                    $this->scriptEscape = 1;
                    $index += 3;
                } elseif ($this->scriptEscape === 1
                    && strtolower(substr($this->source, $index + 1, 6)) === 'script') {
                    $delimiter = $this->source[$index + 7] ?? null;

                    if ($delimiter === null || $delimiter === '>' || $delimiter === '/' || $this->isHtmlSpace($delimiter)) {
                        $this->scriptEscape = 2;
                        $index += 6;
                    }
                }
            } elseif ($char === '>'
                && $this->scriptEscape !== 0
                && $index >= 2
                && $this->source[$index - 1] === '-'
                && $this->source[$index - 2] === '-') {
                $this->scriptEscape = 0;
            }
        }

        return $limit;
    }

    protected function markupEnd($start)
    {
        $length = strlen($this->source);
        $declaration = ($this->source[$start + 1] ?? null) === '!';
        $processingInstruction = ($this->source[$start + 1] ?? null) === '?';
        $comment = $declaration && substr($this->source, $start, 4) === '<!--';
        $cdata = $declaration
            && ! $comment
            && $this->currentContainerIsForeign()
            && substr($this->source, $start, 9) === '<![CDATA[';

        if (! $declaration && ! $processingInstruction) {
            return $this->tagMarkupEnd($start, $length);
        }

        if (empty($this->regionStarts)) {
            if ($comment) {
                if (($this->source[$start + 4] ?? null) === '>') {
                    return $start + 5;
                }

                if (substr($this->source, $start + 4, 2) === '->') {
                    return $start + 6;
                }

                $normalEnd = strpos($this->source, '-->', $start + 4);
                $bangEnd = strpos($this->source, '--!>', $start + 4);

                if ($normalEnd === false) {
                    $end = $bangEnd;
                    $terminatorLength = 4;
                } elseif ($bangEnd === false || $normalEnd < $bangEnd) {
                    $end = $normalEnd;
                    $terminatorLength = 3;
                } else {
                    $end = $bangEnd;
                    $terminatorLength = 4;
                }

                return $end === false ? $length : $end + $terminatorLength;
            }

            if ($cdata) {
                $end = strpos($this->source, ']]>', $start + 9);

                return $end === false ? $length : $end + 3;
            }

        }

        // A comment terminator cannot reuse the two dashes from its opener.
        // Starting at `<!--` + 4 prevents malformed forms such as `<!--!>`
        // and `<!---!>` from being closed by an overlapping `--!>` match.
        $scanStart = $comment ? $start + 4 : $start + 1;

        for ($index = $scanStart; $index < $length; $index++) {
            if (isset($this->regionEnds[$index])) {
                $index = $this->regionEnds[$index] - 1;

                continue;
            }

            if ($comment) {
                if (substr($this->source, $index, 3) === '-->') {
                    return $index + 3;
                }

                if (substr($this->source, $index, 4) === '--!>') {
                    return $index + 4;
                }

                if ($index === $start + 4 && $this->source[$index] === '>') {
                    return $index + 1;
                }

                if ($index === $start + 4 && substr($this->source, $index, 2) === '->') {
                    return $index + 2;
                }

                continue;
            }

            if ($cdata) {
                if (substr($this->source, $index, 3) === ']]>') {
                    return $index + 3;
                }

                continue;
            }

            // In HTML, doctypes, bogus declarations (including CDATA-like
            // markup in the HTML namespace), and processing instructions all
            // end at the first `>`. Quotes and XML internal subsets do not
            // extend those tokens. Actual CDATA sections are handled above
            // only while the adjusted current node is foreign content.
            if ($this->source[$index] === '>') {
                return $index + 1;
            }
        }

        return $length;
    }

    protected function tagMarkupEnd($start, $length)
    {
        $state = 'tag-name';
        $quote = null;
        $index = $start + 1;

        if (($this->source[$index] ?? null) === '/') {
            $index++;
        }

        for (; $index < $length; $index++) {
            if (isset($this->regionEnds[$index])) {
                if ($state === 'before-value') {
                    $state = 'unquoted-value';
                } elseif ($state === 'before-attribute' || $state === 'after-attribute') {
                    $state = 'attribute-name';
                }

                $index = $this->regionEnds[$index] - 1;

                continue;
            }

            $char = $this->source[$index];

            if ($state === 'quoted-value') {
                if ($char === $quote) {
                    $state = 'before-attribute';
                    $quote = null;
                }

                continue;
            }

            if ($state === 'unquoted-value') {
                if ($char === '>') {
                    return $index + 1;
                }

                if ($this->isHtmlSpace($char)) {
                    $state = 'before-attribute';
                }

                continue;
            }

            if ($state === 'before-value') {
                if ($this->isHtmlSpace($char)) {
                    continue;
                }

                if ($char === '"' || $char === "'") {
                    $state = 'quoted-value';
                    $quote = $char;
                } elseif ($char === '>') {
                    return $index + 1;
                } else {
                    $state = 'unquoted-value';
                }

                continue;
            }

            if ($state === 'tag-name') {
                if ($char === '>') {
                    return $index + 1;
                }

                if ($this->isHtmlSpace($char)) {
                    $state = 'before-attribute';
                } elseif ($char === '/') {
                    $state = 'self-closing';
                }

                continue;
            }

            if ($state === 'attribute-name') {
                if ($char === '=') {
                    $state = 'before-value';
                } elseif ($char === '>') {
                    return $index + 1;
                } elseif ($char === '/') {
                    $state = 'self-closing';
                } elseif ($this->isHtmlSpace($char)) {
                    $state = 'after-attribute';
                }

                continue;
            }

            if ($state === 'after-attribute') {
                if ($char === '=') {
                    $state = 'before-value';
                } elseif ($char === '>') {
                    return $index + 1;
                } elseif ($char === '/') {
                    $state = 'self-closing';
                } elseif (! $this->isHtmlSpace($char)) {
                    $state = 'attribute-name';
                }

                continue;
            }

            if ($state === 'self-closing') {
                if ($char === '>') {
                    return $index + 1;
                }

                $state = $this->isHtmlSpace($char) ? 'before-attribute' : 'attribute-name';

                continue;
            }

            if ($char === '>') {
                return $index + 1;
            }

            if (! $this->isHtmlSpace($char) && $char !== '/') {
                $state = 'attribute-name';
            }
        }

        return $length;
    }

    protected function nextRegionStart($position)
    {
        $count = count($this->regionStarts);

        while ($this->regionCursor < $count
            && $this->regionStarts[$this->regionCursor] < $position) {
            $this->regionCursor++;
        }

        return $this->regionStarts[$this->regionCursor] ?? null;
    }

    /**
     * Read a tag name once, skipping Antlers regions as opaque input.
     *
     * A false return is invalid markup; null is a dynamic Antlers name.
     *
     * @return string|null|false
     */
    protected function tagName($start, $end, $closing)
    {
        $index = $start + 1;

        if ($closing) {
            $index++;

            while ($index < $end && $this->isHtmlSpace($this->source[$index])) {
                $index++;
            }
        }

        $name = '';
        $dynamic = false;

        for (; $index < $end; $index++) {
            if (isset($this->regionEnds[$index])) {
                $dynamic = true;
                $index = $this->regionEnds[$index] - 1;

                continue;
            }

            $char = $this->source[$index];

            if ($char === '>' || $char === '/' || $this->isHtmlSpace($char)) {
                break;
            }

            $name .= $char === "\0" ? "\xEF\xBF\xBD" : $char;
        }

        if ($name === '' && ! $dynamic) {
            return false;
        }

        return $dynamic ? null : strtolower($name);
    }

    protected function isSelfClosingTag($start, $end)
    {
        // The flag is only set when `>` immediately follows the solidus; with
        // anything between them (even whitespace) the slash is a stray and
        // the element stays open.
        $slash = $end - 2;

        if ($slash <= $start || $this->source[$slash] !== '/') {
            return false;
        }

        $index = $start + 1;

        while ($index < $slash) {
            if (isset($this->regionEnds[$index])) {
                $index = $this->regionEnds[$index];

                continue;
            }

            $char = $this->source[$index];

            if ($this->isHtmlSpace($char) || $char === '/' || $char === '>') {
                break;
            }

            $index++;
        }

        $state = 'before-attribute';
        $quote = null;

        for (; $index < $slash; $index++) {
            if (isset($this->regionEnds[$index])) {
                if ($state === 'before-value') {
                    $state = 'unquoted-value';
                }

                $index = $this->regionEnds[$index] - 1;

                continue;
            }

            $char = $this->source[$index];

            if ($state === 'quoted-value') {
                if ($char === $quote) {
                    $state = 'before-attribute';
                    $quote = null;
                }

                continue;
            }

            if ($state === 'unquoted-value') {
                if ($this->isHtmlSpace($char)) {
                    $state = 'before-attribute';
                }

                continue;
            }

            if ($state === 'before-value') {
                if ($this->isHtmlSpace($char)) {
                    continue;
                }

                if ($char === '"' || $char === "'") {
                    $state = 'quoted-value';
                    $quote = $char;
                } else {
                    $state = 'unquoted-value';
                }

                continue;
            }

            if ($state === 'attribute-name') {
                if ($char === '=') {
                    $state = 'before-value';
                } elseif ($this->isHtmlSpace($char)) {
                    $state = 'after-attribute-name';
                }

                continue;
            }

            if ($state === 'after-attribute-name') {
                if ($char === '=') {
                    $state = 'before-value';
                } elseif (! $this->isHtmlSpace($char)) {
                    $state = 'attribute-name';
                }

                continue;
            }

            if (! $this->isHtmlSpace($char)) {
                $state = 'attribute-name';
            }
        }

        return ! in_array($state, ['before-value', 'quoted-value', 'unquoted-value'], true);
    }

    protected function isHtmlSpace($char)
    {
        return $char === ' ' || $char === "\t" || $char === "\n" || $char === "\r" || $char === "\f";
    }

    protected function regionIndexAtOrAfter($position)
    {
        $low = 0;
        $high = count($this->regionStarts);

        while ($low < $high) {
            $middle = ($low + $high) >> 1;

            if ($this->regionStarts[$middle] < $position) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }

    protected function indexAntlersByteRegions(array $nodes)
    {
        $characterRegions = [];
        $needed = [0 => true];

        foreach ($nodes as $node) {
            if (! $node instanceof LanguageAntlersNode || $node->startPosition === null || $node->endPosition === null) {
                continue;
            }

            $start = $node->startPosition->offset;
            $end = $node->endPosition->offset + 1;
            $characterRegions[] = [$start, $end, $node];
            $needed[$start] = true;
            $needed[$end] = true;
        }

        $this->regionEnds = [];
        $this->regionNodes = [];
        $this->regionStarts = [];

        if (empty($characterRegions)) {
            return;
        }

        $byteOffsets = $this->characterToByteOffsets($needed);

        foreach ($characterRegions as [$start, $end, $node]) {
            if (isset($byteOffsets[$start], $byteOffsets[$end])) {
                $this->regionEnds[$byteOffsets[$start]] = $byteOffsets[$end];
                $this->regionNodes[$byteOffsets[$start]] = $node;
            }
        }

        ksort($this->regionEnds, SORT_NUMERIC);
        $this->regionStarts = array_keys($this->regionEnds);
    }

    protected function characterToByteOffsets(array $needed)
    {
        return CharacterOffsets::toBytes($this->source, array_keys($needed));
    }
}
