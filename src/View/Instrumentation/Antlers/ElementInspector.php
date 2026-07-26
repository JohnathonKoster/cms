<?php

namespace Statamic\View\Instrumentation\Antlers;

/**
 * Inspects static HTML opening-tag syntax while treating Antlers regions as
 * opaque. Shared by instrumentation consumers so attribute collision checks
 * follow the same quote and expression rules as HTML context analysis.
 */
class ElementInspector
{
    /**
     * Reads the opening tag beginning at a character offset. Supply the
     * matching byte offset when it is already known to avoid an O(n)
     * character walk per element.
     */
    public function openingTagAt($template, $startOffset, $startByteOffset = null)
    {
        $offset = $startByteOffset ?? strlen(mb_substr($template, 0, $startOffset));
        $length = strlen($template);
        $state = 'tag-name';

        for ($index = $offset + 1; $index < $length; $index++) {
            $char = $template[$index];

            if ($char === '{' && substr($template, $index, 2) === '{{') {
                $end = $this->antlersEndInBytes($template, $index, $length);

                if ($end === false) {
                    return substr($template, $offset);
                }

                $index = $end - 1;

                continue;
            }

            if ($state === 'double-quoted' || $state === 'single-quoted') {
                if (($state === 'double-quoted' && $char === '"')
                    || ($state === 'single-quoted' && $char === "'")) {
                    $state = 'before-attribute';
                }

                continue;
            }

            if ($char === '>' && $state !== 'tag-name') {
                return substr($template, $offset, $index + 1 - $offset);
            }

            if ($state === 'tag-name') {
                if ($this->isHtmlSpace($char)) {
                    $state = 'before-attribute';
                } elseif ($char === '/') {
                    $state = 'self-closing';
                } elseif ($char === '>') {
                    return substr($template, $offset, $index + 1 - $offset);
                }
            } elseif ($state === 'before-attribute') {
                if ($char === '/') {
                    $state = 'self-closing';
                } elseif (! $this->isHtmlSpace($char)) {
                    $state = 'attribute-name';
                }
            } elseif ($state === 'attribute-name') {
                if ($this->isHtmlSpace($char)) {
                    $state = 'after-attribute';
                } elseif ($char === '/') {
                    $state = 'self-closing';
                } elseif ($char === '=') {
                    $state = 'before-value';
                }
            } elseif ($state === 'after-attribute') {
                if ($char === '/') {
                    $state = 'self-closing';
                } elseif ($char === '=') {
                    $state = 'before-value';
                } elseif (! $this->isHtmlSpace($char)) {
                    $state = 'attribute-name';
                }
            } elseif ($state === 'before-value') {
                if ($char === '"') {
                    $state = 'double-quoted';
                } elseif ($char === "'") {
                    $state = 'single-quoted';
                } elseif (! $this->isHtmlSpace($char)) {
                    $state = 'unquoted-value';
                }
            } elseif ($state === 'unquoted-value') {
                if ($this->isHtmlSpace($char)) {
                    $state = 'before-attribute';
                }
            } elseif ($state === 'self-closing') {
                $state = 'before-attribute';
                $index--;
            }
        }

        return substr($template, $offset);
    }

    public function hasAttribute($openingTag, $attributeName)
    {
        if (stripos($openingTag, $attributeName) === false) {
            return false;
        }

        $length = strlen($openingTag);
        $index = 1;

        while ($index < $length
            && ! $this->isHtmlSpace($openingTag[$index])
            && $openingTag[$index] !== '/'
            && $openingTag[$index] !== '>') {
            if (substr($openingTag, $index, 2) === '{{') {
                $end = $this->antlersEndInBytes($openingTag, $index, $length);

                if ($end === false) {
                    return false;
                }

                $index = $end;
            } else {
                $index++;
            }
        }

        while ($index < $length) {
            while ($index < $length && $this->isHtmlSpace($openingTag[$index])) {
                $index++;
            }

            if ($index >= $length || $openingTag[$index] === '>') {
                return false;
            }

            if ($openingTag[$index] === '/') {
                $index++;

                continue;
            }

            if (substr($openingTag, $index, 2) === '{{') {
                $end = $this->antlersEndInBytes($openingTag, $index, $length);

                if ($end === false) {
                    return false;
                }

                $index = $end;

                continue;
            }

            $nameStart = $index;
            $dynamicName = false;

            if ($openingTag[$index] === '=') {
                $index++;
            }

            while ($index < $length
                && ! $this->isHtmlSpace($openingTag[$index])
                && $openingTag[$index] !== '/'
                && $openingTag[$index] !== '>'
                && $openingTag[$index] !== '=') {
                if (substr($openingTag, $index, 2) === '{{') {
                    $dynamicName = true;
                    $end = $this->antlersEndInBytes($openingTag, $index, $length);

                    if ($end === false) {
                        return false;
                    }

                    $index = $end;
                } else {
                    $index++;
                }
            }

            $name = substr($openingTag, $nameStart, $index - $nameStart);

            if (! $dynamicName && strcasecmp($name, $attributeName) === 0) {
                return true;
            }

            while ($index < $length && $this->isHtmlSpace($openingTag[$index])) {
                $index++;
            }

            if ($index >= $length || $openingTag[$index] !== '=') {
                continue;
            }

            $index++;

            while ($index < $length && $this->isHtmlSpace($openingTag[$index])) {
                $index++;
            }

            if ($index < $length && ($openingTag[$index] === '"' || $openingTag[$index] === "'")) {
                $quote = $openingTag[$index++];

                while ($index < $length && $openingTag[$index] !== $quote) {
                    if (substr($openingTag, $index, 2) === '{{') {
                        $end = $this->antlersEndInBytes($openingTag, $index, $length);

                        if ($end === false) {
                            return false;
                        }

                        $index = $end;
                    } else {
                        $index++;
                    }
                }

                $index += $index < $length ? 1 : 0;
            } else {
                while ($index < $length
                    && ! $this->isHtmlSpace($openingTag[$index])
                    && $openingTag[$index] !== '>') {
                    if (substr($openingTag, $index, 2) === '{{') {
                        $end = $this->antlersEndInBytes($openingTag, $index, $length);

                        if ($end === false) {
                            return false;
                        }

                        $index = $end;
                    } else {
                        $index++;
                    }
                }
            }
        }

        return false;
    }

    protected function isHtmlSpace($char)
    {
        return $char === ' ' || $char === "\t" || $char === "\n" || $char === "\r" || $char === "\f";
    }

    /**
     * @return int|false Offset immediately after the closing delimiter.
     */
    protected function antlersEndInBytes($content, $start, $length)
    {
        return RegionExtents::endInBytes($content, $start, $length);
    }
}
