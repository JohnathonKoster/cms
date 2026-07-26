<?php

namespace Statamic\View\Instrumentation;

use InvalidArgumentException;
use RuntimeException;
use Statamic\View\Antlers\Language\Runtime\GlobalRuntimeState;
use Statamic\View\Antlers\Language\Runtime\RuntimeConfiguration;
use Statamic\View\Antlers\Language\Runtime\Tracing\RuntimeTracerContract;
use Statamic\View\Antlers\Language\Runtime\Tracing\TraceManager;
use Statamic\View\Instrumentation\Blade\TemplateAnalyzer as BladeTemplateAnalyzer;

/**
 * The single registration surface for template instrumentation. Pieces are
 * routed to every engine seam that can host them: tracers to the Antlers
 * TraceManager and the Blade tag host, HTML instrumenters to the Antlers
 * preparser pipeline and (when Forte is installed) the Blade precompiler.
 *
 * The engine seams remain the source of truth for when things fire; this
 * manager only handles registration, including queueing Antlers pieces until
 * the RuntimeConfiguration singleton resolves.
 */
class InstrumentationManager
{
    const ENGINE_ANTLERS = Span::ENGINE_ANTLERS;

    const ENGINE_BLADE = Span::ENGINE_BLADE;

    /**
     * Antlers registrations queued until the runtime configuration resolves.
     *
     * @var callable[]
     */
    protected $pendingAntlers = [];

    /**
     * The resolved runtime configuration, once available. Registrations made
     * afterwards apply to it immediately instead of queueing.
     *
     * @var RuntimeConfiguration|null
     */
    protected $runtimeConfiguration = null;

    /**
     * HTML instrumenters applied to Blade templates during precompilation.
     *
     * @var HtmlInstrumentation[]
     */
    protected $bladeInstrumenters = [];

    /**
     * Blade tag tracers are application-scoped with this manager instead of
     * process-scoped static state on BladeTagHost.
     *
     * @var TracerContract[]
     */
    protected $bladeTracers = [];

    /**
     * Pieces resolved on behalf of configuration, keyed so that re-applying
     * the same configuration hands back the same instance. Registration
     * de-duplicates by identity, so stable instances are what make repeat
     * application a no-op.
     *
     * @var array<string, object>
     */
    protected $configured = [];

    /**
     * Pieces this manager registered with Antlers, so flush() can withdraw
     * exactly those and leave the debugger's and profiler's own tracers alone.
     *
     * @var array<int, HtmlInstrumentation|RuntimeTracerContract|TracerContract>
     */
    protected $antlersPieces = [];

    /**
     * A fresh HtmlInstrumentation instance, ready for fluent configuration
     * and registration. Shortcut for HtmlInstrumentation::make().
     *
     * @return HtmlInstrumentation
     */
    public function html()
    {
        return HtmlInstrumentation::make();
    }

    /**
     * Decode a marker or attribute payload produced by any registered HTML
     * instrumentation. Shortcut for HtmlInstrumentation::decode().
     *
     * @param  string  $value
     * @return array<string, mixed>|null
     */
    public function decode($value)
    {
        return HtmlInstrumentation::decode($value);
    }

    /**
     * Replace all Blade tag tracers, preserving BladeTagHost's public seam.
     */
    public function traceBladeUsing(?TracerContract $tracer)
    {
        $this->bladeTracers = $tracer === null ? [] : [$tracer];

        return $this;
    }

    public function addBladeTracer(TracerContract $tracer)
    {
        foreach ($this->bladeTracers as $registered) {
            if ($registered === $tracer) {
                return $this;
            }
        }

        $this->bladeTracers[] = $tracer;

        return $this;
    }

    /**
     * @return TracerContract[]
     */
    public function bladeTracers()
    {
        return $this->bladeTracers;
    }

    /**
     * Register an instrumentation piece with every engine that can host it.
     *
     * Accepts an engine-neutral TracerContract (traced on both engines), an
     * Antlers RuntimeTracerContract (Antlers only), an HtmlInstrumentation
     * instance (Antlers preparser plus, when Forte is installed, a Blade
     * precompiler), or a class string resolved through the container.
     *
     * Registering a tracer enables tracing on the engines it targets; no
     * separate configuration flag is required. Pass an engines list to narrow
     * a piece to a subset of what it could host.
     *
     * @param  object|string  $piece
     * @param  string[]|null  $engines
     * @return $this
     */
    public function register($piece, ?array $engines = null)
    {
        if (is_string($piece)) {
            // Resolved once per abstract: configuration is applied on every
            // parser resolution, and a fresh instance each time would stack up
            // duplicate tracers.
            $piece = $this->configured[$piece] ??= app($piece);
        }

        $engines = $this->normalizeEngines($engines);

        if ($piece instanceof HtmlInstrumentation) {
            return $this->registerHtmlInstrumentation($piece, $engines);
        }

        if ($piece instanceof RuntimeTracerContract || $piece instanceof TracerContract) {
            return $this->registerTracer($piece, $engines);
        }

        throw new InvalidArgumentException(
            'Instrumentation pieces must be an HtmlInstrumentation instance, a TracerContract, or a RuntimeTracerContract.'
        );
    }

    /**
     * The HtmlInstrumentation instance for a configuration array, built once
     * per distinct configuration so that re-applying it registers the same
     * object instead of a duplicate.
     *
     * @internal Called by the ViewServiceProvider; not part of the public API.
     *
     * @param  array<string, mixed>  $config
     * @return HtmlInstrumentation
     */
    public function configuredHtmlInstrumentation(array $config)
    {
        // serialize() rather than json_encode(): configuration is required to
        // be cacheable, so it holds no closures, and unlike JSON it keeps
        // distinct binary values distinct.
        $key = 'html:'.md5(serialize($config));

        return $this->configured[$key] ??= HtmlInstrumentation::fromConfig($config);
    }

    /**
     * Remove a previously registered piece from the engines it targets.
     *
     * Mirrors register(): pass an engines list to narrow the removal, or omit
     * it to remove the piece everywhere it could have been registered. Tracing
     * stays enabled after the last tracer is removed — the flag only decides
     * whether the runtime consults the trace manager at all.
     *
     * @param  object|string  $piece
     * @param  string[]|null  $engines
     * @return $this
     */
    public function forget($piece, ?array $engines = null)
    {
        if (is_string($piece)) {
            $resolved = $this->configured[$piece] ?? null;

            if ($resolved === null) {
                return $this;
            }

            unset($this->configured[$piece]);
            $piece = $resolved;
        }

        $engines = $this->normalizeEngines($engines);
        $wantsAntlers = $engines === null || in_array(static::ENGINE_ANTLERS, $engines, true);
        $wantsBlade = $engines === null || in_array(static::ENGINE_BLADE, $engines, true);

        if ($wantsAntlers) {
            $this->antlersPieces = $this->without($this->antlersPieces, $piece);

            $this->applyToAntlers(function (RuntimeConfiguration $config) use ($piece) {
                if ($piece instanceof HtmlInstrumentation) {
                    $config->removePreparser($piece);

                    return;
                }

                $config->traceManager?->removeTracer($piece);
            });
        }

        if ($wantsBlade) {
            if ($piece instanceof HtmlInstrumentation) {
                $this->bladeInstrumenters = $this->without($this->bladeInstrumenters, $piece);
            } else {
                $this->bladeTracers = $this->without($this->bladeTracers, $piece);
            }
        }

        return $this;
    }

    /**
     * Drop every registration this manager owns, including any still queued
     * for a runtime configuration that has not resolved yet.
     *
     * Tracers the debugger or profiler attached to the trace manager directly
     * are left in place; this only withdraws what was registered here.
     *
     * @return $this
     */
    public function flush()
    {
        $antlers = $this->antlersPieces;

        $this->pendingAntlers = [];
        $this->bladeInstrumenters = [];
        $this->bladeTracers = [];
        $this->configured = [];
        $this->antlersPieces = [];

        if ($this->runtimeConfiguration === null) {
            return $this;
        }

        foreach ($antlers as $piece) {
            if ($piece instanceof HtmlInstrumentation) {
                $this->runtimeConfiguration->removePreparser($piece);

                continue;
            }

            $this->runtimeConfiguration->traceManager?->removeTracer($piece);
        }

        return $this;
    }

    /**
     * @return HtmlInstrumentation[]
     */
    public function bladeInstrumenters()
    {
        return $this->bladeInstrumenters;
    }

    /**
     * @return void
     */
    protected function rememberAntlersPiece($piece)
    {
        if (! in_array($piece, $this->antlersPieces, true)) {
            $this->antlersPieces[] = $piece;
        }
    }

    /**
     * @template T
     *
     * @param  T[]  $items
     * @return T[]
     */
    protected function without(array $items, $needle)
    {
        return array_values(array_filter($items, fn ($item) => $item !== $needle));
    }

    /**
     * Apply queued Antlers registrations to the resolved runtime
     * configuration, and route later registrations to it directly.
     *
     * @internal Called by the ViewServiceProvider; not part of the public API.
     *
     * @return void
     */
    public function applyTo(RuntimeConfiguration $runtimeConfiguration)
    {
        $this->runtimeConfiguration = $runtimeConfiguration;

        foreach ($this->pendingAntlers as $apply) {
            $apply($runtimeConfiguration);
        }

        $this->pendingAntlers = [];
    }

    /**
     * Instrument Blade source ahead of compilation. Wired as the first Blade
     * precompiler so instrumenters see the authored template; a passthrough
     * when nothing is registered.
     *
     * @internal Called by the Blade precompiler seam; not part of the public API.
     *
     * @param  string  $template
     * @return string
     */
    public function preprocessBlade($template)
    {
        if ($this->bladeInstrumenters === []) {
            return $template;
        }

        $previousView = GlobalRuntimeState::$currentExecutionFile;
        GlobalRuntimeState::$currentExecutionFile = $this->compilingViewPath();

        try {
            foreach ($this->bladeInstrumenters as $instrumentation) {
                $template = InstrumentationState::whileInstrumentingView(
                    fn () => $instrumentation->instrumentBlade($template)
                );
            }
        } finally {
            GlobalRuntimeState::$currentExecutionFile = $previousView;
        }

        return $template;
    }

    /**
     * @param  RuntimeTracerContract|TracerContract  $tracer
     * @param  string[]|null  $engines
     * @return $this
     */
    protected function registerTracer($tracer, ?array $engines)
    {
        $isUnified = $tracer instanceof TracerContract;

        if ($engines === null) {
            $engines = $isUnified
                ? [static::ENGINE_ANTLERS, static::ENGINE_BLADE]
                : [static::ENGINE_ANTLERS];
        }

        if (in_array(static::ENGINE_BLADE, $engines, true) && ! $isUnified) {
            throw new InvalidArgumentException(
                'Antlers-specific tracers cannot trace Blade; implement the engine-neutral TracerContract.'
            );
        }

        if (in_array(static::ENGINE_ANTLERS, $engines, true)) {
            $this->rememberAntlersPiece($tracer);

            $this->applyToAntlers(function (RuntimeConfiguration $config) use ($tracer) {
                if ($config->traceManager === null) {
                    $config->traceManager = new TraceManager();
                }

                $config->isTracingEnabled = true;
                $config->traceManager->registerTracer($tracer);
            });
        }

        if (in_array(static::ENGINE_BLADE, $engines, true)) {
            $this->addBladeTracer($tracer);
        }

        return $this;
    }

    /**
     * @param  string[]|null  $engines
     * @return $this
     */
    protected function registerHtmlInstrumentation(HtmlInstrumentation $instrumentation, ?array $engines)
    {
        if ($engines !== null && in_array(static::ENGINE_BLADE, $engines, true) && ! $this->bladeAvailable()) {
            throw new RuntimeException(
                'Instrumenting Blade templates requires the Forte parser. Install it with: composer require fortephp/forte'
            );
        }

        $wantsAntlers = $engines === null || in_array(static::ENGINE_ANTLERS, $engines, true);
        $wantsBlade = $engines === null ? $this->bladeAvailable() : in_array(static::ENGINE_BLADE, $engines, true);

        if ($wantsAntlers) {
            $this->rememberAntlersPiece($instrumentation);
            $this->applyToAntlers(fn (RuntimeConfiguration $config) => $config->preparse($instrumentation));
        }

        if ($wantsBlade) {
            if (! in_array($instrumentation, $this->bladeInstrumenters, true)) {
                $this->bladeInstrumenters[] = $instrumentation;
            }
        }

        return $this;
    }

    /**
     * @return void
     */
    protected function applyToAntlers(callable $apply)
    {
        if ($this->runtimeConfiguration !== null) {
            $apply($this->runtimeConfiguration);

            return;
        }

        $this->pendingAntlers[] = $apply;
    }

    /**
     * @param  string[]|null  $engines
     * @return string[]|null
     */
    protected function normalizeEngines(?array $engines)
    {
        if ($engines === null) {
            return null;
        }

        $engines = array_values(array_unique(array_map('strtolower', $engines)));

        if ($engines === []) {
            throw new InvalidArgumentException('At least one engine must be provided.');
        }

        foreach ($engines as $engine) {
            if ($engine !== static::ENGINE_ANTLERS && $engine !== static::ENGINE_BLADE) {
                throw new InvalidArgumentException("Unknown instrumentation engine [{$engine}].");
            }
        }

        return $engines;
    }

    /**
     * @return bool
     */
    protected function bladeAvailable()
    {
        return BladeTemplateAnalyzer::isAvailable();
    }

    /**
     * The view file the Blade compiler is currently processing, giving Blade
     * markers the same view identity Antlers markers receive at runtime.
     *
     * @return string|null
     */
    protected function compilingViewPath()
    {
        $compiler = app('blade.compiler');

        $path = method_exists($compiler, 'getPath') ? $compiler->getPath() : null;

        return is_string($path) && $path !== '' ? $path : null;
    }
}
