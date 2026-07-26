<?php

namespace Statamic\View\Antlers\Language\Runtime;

use Statamic\View\Antlers\Language\Runtime\Tracing\NodeVisitorContract;
use Statamic\View\Antlers\Language\Runtime\Tracing\TraceManager;

class RuntimeConfiguration
{
    /**
     * A list of callbacks that transform authored template source before
     * Statamic and Blade components are compiled.
     *
     * @var callable[]
     */
    protected $beforeComponentCompilationCallbacks = [];

    /**
     * A list of all Antlers preparser callbacks.
     *
     * @var callable[]
     */
    protected $preparsers = [];

    /**
     * A list of all document node visitors.
     *
     * @var NodeVisitorContract[]
     */
    protected $visitors = [];

    /**
     * Controls whether unpaired loops throw an exception or not.
     *
     * @var bool
     */
    public $fatalErrorOnUnpairedLoop = false;

    /**
     * Controls whether attempting to render an object as a string throws an exception or not.
     *
     * @var bool
     */
    public $fatalErrorOnStringObject = false;

    /**
     * Controls whether runtime tracing is enabled.
     *
     * @var bool
     */
    public $isTracingEnabled = false;

    /**
     * An optional runtime TraceManager instance.
     *
     * @var TraceManager|null
     */
    public $traceManager = null;

    /**
     * Controls whether runtime access violations fail silently or not.
     *
     * @var bool
     */
    public $throwErrorOnAccessViolation = false;

    /**
     * A list of all invalid variable patterns.
     *
     * @var string[]
     */
    public $guardedVariablePatterns = [];

    /**
     * A list of all invalid content variable patterns.
     *
     * @var string[]
     */
    public $guardedContentVariablePatterns = [];

    /**
     * A list of all invalid tag patterns.
     *
     * @var string[]
     */
    public $guardedTagPatterns = [];

    /**
     * A list of all invalid content tag patterns.
     *
     * @var string[]
     */
    public $guardedContentTagPatterns = [];

    /**
     * A list of all allowed content tag patterns.
     *
     * @var string[]
     */
    public $allowedContentTagPatterns = [];

    /**
     * A list of all invalid modifier patterns.
     *
     * @var string[]
     */
    public $guardedModifiers = [];

    /**
     * A list of all invalid content modifier patterns.
     *
     * @var string[]
     */
    public $guardedContentModifiers = [];

    /**
     * A list of all allowed content modifier patterns.
     *
     * @var string[]
     */
    public $allowedContentModifiers = [];

    /**
     * Indicates if PHP Code should be evaluated in user content.
     *
     * When disabled, *antlers.php templates will be allowed,
     * but PHP code when evaluating fields with antlers:true
     * will revert to the behavior of PHP being disabled.
     *
     * @var bool
     */
    public $allowPhpInUserContent = false;

    /**
     * Indicates if method invocations should be evaluated in user content.
     *
     * When disabled, method calls like object:method() within
     * fields with antlers:true will be blocked.
     *
     * @var bool
     */
    public $allowMethodsInUserContent = false;

    /**
     * Whether parsed nodes are eagerly annotated with their surrounding HTML
     * context. Nodes can always resolve their context lazily; this makes the
     * annotation happen at parse time instead.
     *
     * @var bool
     */
    public $annotateHtmlContext = false;

    /**
     * Register a source transformer that runs before component compilation.
     *
     * @return $this
     */
    public function beforeComponentCompilation(callable $callable)
    {
        $this->beforeComponentCompilationCallbacks[] = $callable;

        return $this;
    }

    /**
     * Get the source transformers that run before component compilation.
     *
     * @return callable[]
     */
    public function getBeforeComponentCompilationCallbacks()
    {
        return $this->beforeComponentCompilationCallbacks;
    }

    /**
     * Registers a new Antlers preparser callback.
     *
     * @param  callable  $callable  The preparser callback.
     */
    public function preparse(callable $callable)
    {
        if (in_array($callable, $this->preparsers, true)) {
            return;
        }

        $this->preparsers[] = $callable;
    }

    /**
     * Removes a previously registered Antlers preparser callback.
     *
     * @return void
     */
    public function removePreparser(callable $callable)
    {
        $this->preparsers = array_values(array_filter(
            $this->preparsers,
            fn ($preparser) => $preparser !== $callable
        ));
    }

    /**
     * Removes a previously registered source transformer.
     *
     * @return void
     */
    public function removeBeforeComponentCompilation(callable $callable)
    {
        $this->beforeComponentCompilationCallbacks = array_values(array_filter(
            $this->beforeComponentCompilationCallbacks,
            fn ($callback) => $callback !== $callable
        ));
    }

    /**
     * Gets all registered Antlers preparsers.
     *
     * @return callable[]
     */
    public function getPreparsers()
    {
        return $this->preparsers;
    }

    /**
     * Registers a new NodeVisitorContract instance.
     *
     * @param  NodeVisitorContract  $visitor  The visitor.
     */
    public function addVisitor(NodeVisitorContract $visitor)
    {
        if (in_array($visitor, $this->visitors, true)) {
            return;
        }

        $this->visitors[] = $visitor;
        RuntimeParser::clearRenderNodeCache();
    }

    /**
     * Removes a previously registered node visitor.
     *
     * @return void
     */
    public function removeVisitor(NodeVisitorContract $visitor)
    {
        $this->visitors = array_values(array_filter(
            $this->visitors,
            fn ($registered) => $registered !== $visitor
        ));

        RuntimeParser::clearRenderNodeCache();
    }

    /**
     * Returns all registered node visitors.
     *
     * @return NodeVisitorContract[]
     */
    public function getVisitors()
    {
        return $this->visitors;
    }
}
