<?php

namespace Tests\Antlers\Runtime\Support;

use Statamic\View\Antlers\Language\Analyzers\Html\Element;
use Statamic\View\Antlers\Language\Analyzers\Html\NodeSequence;
use Statamic\View\Antlers\Language\Analyzers\Html\Region;
use Statamic\View\Antlers\Language\Analyzers\Html\TextEdge;

class WhitespaceTrimmer
{
    protected const HTML_WHITESPACE = " \t\n\r\f";

    protected static $sensitiveElements = [
        'iframe' => true,
        'listing' => true,
        'noembed' => true,
        'noframes' => true,
        'noscript' => true,
        'plaintext' => true,
        'pre' => true,
        'script' => true,
        'style' => true,
        'textarea' => true,
        'title' => true,
        'xmp' => true,
    ];

    /** @var array<string, bool> */
    protected $additionalSensitiveElements = [];

    /** @var callable|null */
    protected $preserveWhen;

    public function __construct($sensitive = [], ?callable $preserveWhen = null)
    {
        foreach ($sensitive as $name) {
            $this->additionalSensitiveElements[strtolower($name)] = true;
        }

        $this->preserveWhen = $preserveWhen;
    }

    public function trim(iterable $elements)
    {
        foreach ($elements as $element) {
            if (! $element instanceof Element) {
                throw new \InvalidArgumentException('Only HTML elements can have their content edges trimmed.');
            }

            if (! $this->preservesWhitespace($element)) {
                $this->trimContent($element->content());
            }
        }

        return $this;
    }

    public function trimContent(NodeSequence $content)
    {
        $content->rewriteTextEdges(
            fn ($text, $edge) => $edge === TextEdge::LEADING
                ? ltrim($text, self::HTML_WHITESPACE)
                : rtrim($text, self::HTML_WHITESPACE),
            fn (Element $element) => ! $this->preservesWhitespace($element)
                && ! $this->isComponent($element)
        );

        return $this;
    }

    public function trimRegion(Region $region)
    {
        $this->trim($region->ownedElements());
        $this->trimContent($region->content());

        return $this;
    }

    protected function preservesWhitespace(Element $element)
    {
        return $element->ancestorsAndSelf()->contains(function (Element $ancestor) {
            $name = $ancestor->name();

            return ! $ancestor->isHtml()
                || $name === null
                || isset(self::$sensitiveElements[strtolower($name)])
                || isset($this->additionalSensitiveElements[strtolower($name)])
                || ($this->preserveWhen !== null && ($this->preserveWhen)($ancestor));
        });
    }

    protected function isComponent(Element $element)
    {
        $name = $element->name();

        return $name === null || str_contains($name, '-') || str_contains($name, ':');
    }
}
