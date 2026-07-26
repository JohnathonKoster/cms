<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Debugbar
    |--------------------------------------------------------------------------
    |
    | Here you may specify whether the Antlers profiler should be added
    | to the Laravel Debugbar. This is incredibly useful for finding
    | performance impacts within any of your Antlers templates.
    |
    */

    'debugbar' => env('STATAMIC_ANTLERS_DEBUGBAR', true),

    /*
    |--------------------------------------------------------------------------
    | Runtime Tracing
    |--------------------------------------------------------------------------
    |
    | When tracing is enabled, each tracer class listed here is resolved from
    | the container and receives onEnter/onExit callbacks for every node the
    | Antlers runtime processes. Tracers may implement RuntimeTracerContract
    | or the engine-neutral TracerContract. Antlers-specific tracers that also
    | implement ProcessorAwareTracerContract receive the active NodeProcessor.
    |
    */

    'tracing' => env('STATAMIC_ANTLERS_TRACING', false),

    'tracers' => [
        // \App\Antlers\MyTracer::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | HTML Context Analysis
    |--------------------------------------------------------------------------
    |
    | When enabled, parsed Antlers nodes are annotated with their surrounding
    | HTML context (element content, attribute value, tag open, script/style
    | raw text) on the node's htmlContext property. Instrumentation tooling
    | uses this to decide where output markers may safely be injected.
    |
    */

    'htmlContext' => env('STATAMIC_ANTLERS_HTML_CONTEXT', false),

    /*
    |--------------------------------------------------------------------------
    | HTML Instrumentation
    |--------------------------------------------------------------------------
    |
    | Rewrites templates before rendering to add diagnostic markers. Comments
    | are added only where they are safe (never in attributes, title, pre,
    | script, etc.). Attribute layers collect metadata on static owning or
    | containing elements, providing a safe alternative in those contexts.
    | For custom markers, filters, or attribute-value callbacks, register a
    | configured HtmlInstrumentation instance through the Instrument facade.
    |
    | Blade's and Statamic's own component prefixes (x-, s-, statamic-) are
    | always treated as dynamic boundaries. List additional prefixes below if
    | your project uses another component library. Everything inside a
    | component call is left unmarked, so only add prefixes that really do
    | render arbitrary HTML.
    |
    */

    'instrumentation' => [
        'enabled' => env('STATAMIC_ANTLERS_INSTRUMENTATION', false),
        'comments' => true,
        'prefix' => 'antlers',
        'attributes' => [
            // 'data-antlers',
        ],
        'metadata' => [],
        'componentPrefixes' => [
            // 'flux-', 'flux:',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Guarded Variables
    |--------------------------------------------------------------------------
    |
    | Any variable pattern that appears in this list will not be allowed
    | in any Antlers template, including any user-supplied values.
    |
    */

    'guardedVariables' => [
        'config.app.key',
    ],

    /*
    |--------------------------------------------------------------------------
    | Guarded Tags
    |--------------------------------------------------------------------------
    |
    | Any tag pattern that appears in this list will not be allowed
    | in any Antlers template, including any user-supplied values.
    |
    */

    'guardedTags' => [

    ],

    /*
    |--------------------------------------------------------------------------
    | Guarded Modifiers
    |--------------------------------------------------------------------------
    |
    | Any modifier pattern that appears in this list will not be allowed
    | in any Antlers template, including any user-supplied values.
    |
    */

    'guardedModifiers' => [

    ],

    /*
    |--------------------------------------------------------------------------
    | User content allowlists
    |--------------------------------------------------------------------------
    |
    | These control which tags and modifiers will be permitted in user-supplied
    | Antlers (e.g. fields with `antlers: true`). Include the literal string
    | `@default` in the array to merge Statamic's defaults with your own.
    |
    */

    // 'allowedContentTags' => [
    //     '@default',
    //     'foo:*',
    // ],

    // 'allowedContentModifiers' => [
    //     '@default',
    //     'foo'
    // ],

];
