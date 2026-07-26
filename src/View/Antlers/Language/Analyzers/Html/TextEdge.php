<?php

namespace Statamic\View\Antlers\Language\Analyzers\Html;

/**
 * Identifies which edge of an HTML node sequence is being rewritten.
 */
class TextEdge
{
    public const LEADING = 'leading';

    public const TRAILING = 'trailing';
}
