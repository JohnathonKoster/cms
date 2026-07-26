<?php

namespace Statamic\View\Antlers\Language\Facades;

use Illuminate\Support\Facades\Facade;
use Statamic\View\Antlers\Language\Runtime\RuntimeConfiguration;

/**
 * @method static RuntimeConfiguration beforeComponentCompilation(callable $callable)
 * @method static void preparse(callable $callable)
 * @method static void addVisitor(\Statamic\View\Antlers\Language\Runtime\Tracing\NodeVisitorContract $visitor)
 *
 * @see RuntimeConfiguration
 */
class Runtime extends Facade
{
    protected static function getFacadeAccessor()
    {
        return RuntimeConfiguration::class;
    }
}
