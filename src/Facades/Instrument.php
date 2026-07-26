<?php

namespace Statamic\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Statamic\View\Instrumentation\InstrumentationManager register(object|string $piece, ?array $engines = null)
 * @method static \Statamic\View\Instrumentation\InstrumentationManager forget(object|string $piece, ?array $engines = null)
 * @method static \Statamic\View\Instrumentation\InstrumentationManager flush()
 * @method static \Statamic\View\Instrumentation\HtmlInstrumentation html()
 * @method static array|null decode(string $value)
 * @method static \Statamic\View\Instrumentation\TracerContract[] bladeTracers()
 * @method static \Statamic\View\Instrumentation\HtmlInstrumentation[] bladeInstrumenters()
 *
 * @see \Statamic\View\Instrumentation\InstrumentationManager
 */
class Instrument extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Statamic\View\Instrumentation\InstrumentationManager::class;
    }
}
