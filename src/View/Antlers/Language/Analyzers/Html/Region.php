<?php

namespace Statamic\View\Antlers\Language\Analyzers\Html;

use Illuminate\Support\LazyCollection;
use Statamic\View\Antlers\Language\Nodes\AntlersNode as LanguageAntlersNode;
use Statamic\View\Antlers\Language\Nodes\Parameters\ParameterNode;

/**
 * A lazy source region delimited by paired Antlers graph nodes.
 */
class Region
{
    /** @var AntlersNode */
    protected $opening;

    /** @var AntlersNode */
    protected $closing;

    public function __construct(AntlersNode $opening, AntlersNode $closing)
    {
        $this->opening = $opening;
        $this->closing = $closing;
    }

    public function opening()
    {
        return $this->opening;
    }

    public function closing()
    {
        return $this->closing;
    }

    public function document()
    {
        return $this->opening->document();
    }

    public function name()
    {
        $name = $this->opening->antlersNode()->name;

        return $name === null ? null : $name->compound;
    }

    /**
     * @return ParameterNode|null
     */
    public function parameter($name)
    {
        return $this->opening->antlersNode()->parameter($name);
    }

    public function staticParameterValue($name, $default = null)
    {
        try {
            return $this->opening->antlersNode()->staticParameterValue($name, $default);
        } catch (\InvalidArgumentException $exception) {
            throw new \InvalidArgumentException(sprintf(
                'The [%s] parameter on the [%s] HTML region must be static.',
                $name,
                $this->name()
            ), 0, $exception);
        }
    }

    /**
     * Return the lazy sequence between this region's boundaries, or replace it.
     *
     * @return NodeSequence|$this
     */
    public function content($content = null)
    {
        $this->assertOrderedSiblings();

        if (func_num_args() !== 0) {
            if ($content === $this->opening || $content === $this->closing) {
                throw new \LogicException('HTML region boundaries cannot become region content.');
            }

            $existing = NodeSequence::between($this->opening, $this->closing)
                ->nodes()
                ->all();

            $this->closing->before($content);

            foreach ($existing as $node) {
                if ($node !== $content) {
                    $node->remove();
                }
            }

            return $this;
        }

        return NodeSequence::between($this->opening, $this->closing);
    }

    /**
     * @param  callable(string, string, Text): (string|null)  $rewrite
     * @param  (callable(Element): bool)|null  $descend
     */
    public function rewriteTextEdges(callable $rewrite, ?callable $descend = null)
    {
        $this->content()->rewriteTextEdges($rewrite, $descend);

        return $this;
    }

    /**
     * Returns deep elements owned by this region, excluding elements claimed
     * by nested regions with the same Antlers name.
     *
     * @return LazyCollection<int, Element>
     */
    public function ownedElements()
    {
        return LazyCollection::make(fn () => $this->ownedElementsWithin($this->content()))->values();
    }

    public function unwrap()
    {
        $this->assertOrderedSiblings();
        $this->closing->remove();
        $this->opening->remove();

        return $this;
    }

    /**
     * Wraps the entire region — boundaries and content — with the given
     * element, which takes over the region's position in the graph. Throws
     * when the boundaries are not ordered siblings of one container, so a
     * region can never be wrapped in a way that would corrupt the tree.
     *
     * @return $this
     */
    public function wrapWith(Element $element)
    {
        if ($element->document() !== $this->document()) {
            throw new \InvalidArgumentException('HTML graph nodes cannot be moved between documents.');
        }

        $this->assertOrderedSiblings();

        $members = [
            $this->opening,
            ...$this->content()->nodes()->all(),
            $this->closing,
        ];

        if (in_array($element, $members, true)) {
            throw new \LogicException('An HTML region cannot be wrapped with one of its own members.');
        }

        $this->opening->before($element);

        foreach ($members as $member) {
            $element->append($member);
        }

        return $this;
    }

    protected function ownedElementsWithin(NodeSequence $sequence)
    {
        $nestedClosing = null;

        foreach ($sequence->nodes() as $node) {
            if ($nestedClosing !== null) {
                if ($node === $nestedClosing) {
                    $nestedClosing = null;
                }

                continue;
            }

            if ($node instanceof AntlersNode) {
                $candidate = $node->antlersNode();

                if ($this->isNestedRegionOpening($candidate)) {
                    $closing = $candidate->isClosedBy->htmlNode();

                    if ($closing instanceof AntlersNode
                        && $closing->parent() === $node->parent()) {
                        $nestedClosing = $closing;

                        continue;
                    }
                }
            }

            if (! $node instanceof Element) {
                continue;
            }

            yield $node;
            yield from $this->ownedElementsWithin($node->content());
        }
    }

    protected function isNestedRegionOpening($node)
    {
        return $node instanceof LanguageAntlersNode
            && ! $node->isClosingTag
            && $node->isClosedBy instanceof LanguageAntlersNode
            && $node->name !== null
            && strcasecmp($node->name->compound, (string) $this->name()) === 0;
    }

    protected function assertOrderedSiblings()
    {
        $parent = $this->opening->parent();

        if ($parent === null
            || $parent !== $this->closing->parent()
            || ! $parent->hasChild($this->opening)
            || ! $parent->hasChild($this->closing)) {
            throw new \LogicException('HTML regions must begin and end as children of the same container.');
        }
    }
}
