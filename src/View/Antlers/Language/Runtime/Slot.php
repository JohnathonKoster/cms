<?php

namespace Statamic\View\Antlers\Language\Runtime;

use Statamic\Tags\TagNotFoundException;
use Statamic\View\Antlers\Language\Exceptions\RuntimeException;
use Statamic\View\Antlers\Language\Exceptions\SyntaxErrorException;
use Statamic\View\Antlers\Language\Nodes\AbstractNode;
use Throwable;

class Slot
{
    protected string $name;

    /**
     * @var AbstractNode[]
     */
    protected array $nodes;

    protected array $context;

    protected array $params;

    protected NodeProcessor $processor;

    /**
     * @param  AbstractNode[]  $nodes
     */
    public function __construct(
        string $name,
        array $nodes,
        array $context,
        array $params,
        NodeProcessor $processor
    ) {
        $this->name = $name;
        $this->nodes = $nodes;
        $this->context = $context;
        $this->params = $params;
        $this->processor = $processor;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @throws TagNotFoundException
     * @throws RuntimeException
     * @throws SyntaxErrorException
     * @throws Throwable
     */
    public function render(array $props = []): string
    {
        if (empty($this->nodes)) {
            return '';
        }

        $data = array_merge($this->context, ['params' => $this->params], $props);

        $processor = $this->processor->cloneProcessor();
        $processor->setData($data);

        return trim((string) $processor->reduce($this->nodes));
    }

    /**
     * @throws Throwable
     * @throws RuntimeException
     * @throws TagNotFoundException
     * @throws SyntaxErrorException
     */
    public function __toString(): string
    {
        return $this->render();
    }
}
