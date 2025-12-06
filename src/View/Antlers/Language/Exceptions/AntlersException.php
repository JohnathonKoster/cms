<?php

namespace Statamic\View\Antlers\Language\Exceptions;

use ErrorException;
use Statamic\View\Antlers\Language\Nodes\AbstractNode;

class AntlersException extends ErrorException
{
    /**
     * @var AbstractNode|null
     */
    public $node = null;

    public $type = '';
}
