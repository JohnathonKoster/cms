<?php

namespace Statamic\Facades;

use Illuminate\Support\Facades\Facade;
use Statamic\Contracts\View\Antlers\Parser;
use Statamic\View\Antlers\AntlersString;

/**
 * @method static Parser parser()
 * @method static mixed usingParser(Parser $parser, \Closure $callback)
 * @method static AntlersString parse(string $str, array $variables = [], bool $trusted = false)
 * @method static string parseLoop(string $content, array $data, bool $supplement = true, array $context = [], bool $trusted = false)
 * @method static array identifiers(string $content)
 * @method static string instrument(string $template, ?\Statamic\View\Instrumentation\HtmlInstrumentation $instrumentation = null)
 * @method static \Statamic\View\Antlers\Language\Analyzers\Html\Document html(string $template)
 *
 * @see \Statamic\View\Antlers\Antlers
 */
class Antlers extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Statamic\View\Antlers\Antlers::class;
    }
}
