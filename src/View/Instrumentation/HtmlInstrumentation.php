<?php

namespace Statamic\View\Instrumentation;

use Statamic\View\Antlers\Language\Runtime\GlobalRuntimeState;
use Statamic\View\Instrumentation\Antlers\TemplateAnalyzer as AntlersTemplateAnalyzer;
use Statamic\View\Instrumentation\Blade\TemplateAnalyzer as BladeTemplateAnalyzer;

/**
 * Rewrites Antlers and Blade templates with diagnostic markup while
 * preserving HTML syntax. The instance is invokable as an Antlers preparser.
 *
 * Comment markers are emitted only where HtmlContext permits them. Attribute
 * layers collect every eligible Antlers region on its static owning or
 * containing element and add one attribute per configured layer.
 */
class HtmlInstrumentation
{
    use Concerns\ConfiguresInstrumentation;
    use Concerns\EmitsInstrumentation;
    use Concerns\MemoizesResults;

    const SKIP_FILTERED = 'filtered';

    const SKIP_INSIDE_HTML_COMMENT = 'inside-html-comment';

    const SKIP_INSIDE_DOCTYPE = 'inside-doctype';

    const SKIP_DYNAMIC_MARKUP = 'dynamic-markup';

    const SKIP_CLOSING_TAG_MARKUP = 'closing-tag-markup';

    const SKIP_CROSS_CONTAINER_PAIR = 'cross-container-pair';

    const SKIP_NO_STRATEGY = 'no-available-strategy';

    /** @var bool */
    protected $commentsEnabled = true;

    /** @var string */
    protected $commentPrefix = 'antlers';

    /** @var callable|null */
    protected $commentFactory = null;

    /** @var array<string, callable> */
    protected $attributeLayers = [];

    /** @var array<string, mixed> */
    protected $extraMetadata = [];

    /** @var callable|null */
    protected $nodeFilter = null;

    /** @var callable|null */
    protected $skipCallback = null;

    /**
     * Element name prefixes treated as dynamic component boundaries.
     *
     * @var string[]
     */
    protected $componentPrefixes = ComponentPrefixes::DEFAULTS;

    /**
     * Memoized results, keyed by view + template content. Registered as a
     * preparser, the same instance instruments every render of a template;
     * the parse and context scan only need to happen once per distinct
     * template. Any configuration change flushes the cache.
     *
     * @var array<string, string>
     */
    protected $resultCache = [];

    /**
     * Process-wide memoized results, bucketed by configuration fingerprint.
     * Mirrors the runtime's static node cache: under PHP-FPM the container
     * (and therefore the configured instrumenter instance) is rebuilt every
     * request, but the worker process persists — a static cache lets later
     * requests on the same worker skip re-instrumenting unchanged templates.
     *
     * @var array<string, array<string, string>>
     */
    protected static $sharedResultCache = [];

    /**
     * When set, results are memoized process-wide under this bucket instead
     * of per-instance. fromConfig() derives it from the configuration array;
     * mutating the instance afterwards drops back to the instance cache.
     *
     * @var string|null
     */
    protected $cacheScope = null;

    /** @var int */
    protected $resultCacheLimit = 256;

    /**
     * Final so that make() can safely construct a subclass; instances are
     * configured fluently rather than through constructor arguments.
     */
    final protected function __construct()
    {
    }

    /**
     * @return static
     */
    public static function make()
    {
        return new static;
    }

    /**
     * Build an instrumenter from the statamic.antlers.instrumentation config
     * shape. Custom marker factories and attribute value factories remain a
     * programmatic concern and can be configured with make().
     *
     * @param  array<string, mixed>  $config
     * @return self
     */
    public static function fromConfig(array $config)
    {
        $instrumentation = static::make();

        if (($config['comments'] ?? true) === false) {
            $instrumentation->withoutComments();
        } else {
            $instrumentation->comments($config['prefix'] ?? 'antlers');
        }

        foreach ((array) ($config['attributes'] ?? []) as $attributeName) {
            if (is_string($attributeName) && $attributeName !== '') {
                $instrumentation->attributes($attributeName);
            }
        }

        if (is_array($config['metadata'] ?? null)) {
            $instrumentation->withMetadata($config['metadata']);
        }

        if (is_array($config['componentPrefixes'] ?? null)) {
            $instrumentation->componentPrefixes($config['componentPrefixes']);
        }

        // Config-built instrumenters are deterministic functions of the
        // config array, so their results can be shared process-wide. A config
        // that cannot be encoded has no distinguishable fingerprint, so that
        // instance keeps its private cache rather than joining a bucket it
        // would share with every other unencodable configuration.
        $fingerprint = json_encode($config);

        if ($fingerprint !== false) {
            $instrumentation->cacheScope = md5($fingerprint);
        }

        return $instrumentation;
    }

    /**
     * Instrument a template source string.
     *
     * @param  string  $template
     * @return string
     */
    public function instrument($template)
    {
        return $this->instrumentAntlers($template);
    }

    /**
     * @param  string  $template
     * @return string
     */
    public function instrumentAntlers($template)
    {
        return $this->instrumentAntlersWithLineOffset($template, 0);
    }

    /**
     * @param  string  $template
     * @param  int  $lineOffset
     * @return string
     */
    protected function instrumentAntlersWithLineOffset(
        $template,
        $lineOffset,
        $commentsAtDocumentRoot = true,
        &$nextId = null
    ) {
        $sharedIdCounter = $nextId !== null;

        if (InstrumentationState::parsingComponentContent()) {
            return $template;
        }

        // With no output strategies configured, or no Antlers regions in the
        // template, there is nothing to do; skip parsing and scanning.
        if ((! $this->commentsEnabled && empty($this->attributeLayers))
            || strpos($template, '{{') === false) {
            return $template;
        }

        // The view file participates in marker metadata, so it is part of
        // the cache identity.
        $view = GlobalRuntimeState::$currentExecutionFile;
        $commentsAtDocumentRoot = $commentsAtDocumentRoot
            && ! InstrumentationState::renderingComponentView()
            && ! InstrumentationState::instrumentingView();
        $cacheKey = md5('antlers|'.$lineOffset.'|'.($commentsAtDocumentRoot ? 'root' : 'nested').'|'.(is_string($view) ? $view : '').'|'.$template);
        $cached = $sharedIdCounter ? null : $this->cachedResult($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $analysis = (new AntlersTemplateAnalyzer($this->componentPrefixes))->analyze(
            $template,
            ! empty($this->attributeLayers),
            $lineOffset,
            $commentsAtDocumentRoot
        );

        $instrumented = $this->emitInstrumentation(
            $template,
            $analysis,
            $nextId,
            $commentsAtDocumentRoot
        );

        if ($sharedIdCounter) {
            return $instrumented;
        }

        return $this->storeResult($cacheKey, $instrumented);
    }

    /**
     * Make the instrumenter directly usable as an Antlers preparser.
     *
     * @param  string  $template
     * @return string
     */
    public function __invoke($template)
    {
        return InstrumentationState::whileInstrumentingView(
            fn () => $this->instrumentAntlers($template)
        );
    }

    /**
     * Instrument a Blade template with the same configuration, markers, and
     * safety analysis. Requires the optional Forte parser; Blade echoes are
     * normalized to the shared TemplateRegion shape (engine: blade), and
     * `@antlers ... @endantlers` blocks are handed to the Antlers pipeline.
     *
     * @param  string  $template
     * @return string
     *
     * @throws \RuntimeException When Forte is not installed.
     */
    public function instrumentBlade($template)
    {
        if (! $this->commentsEnabled && empty($this->attributeLayers)) {
            return $template;
        }

        if (strpos($template, '{{') === false
            && strpos($template, '{!!') === false
            && stripos($template, '@antlers') === false) {
            return $template;
        }

        if (! BladeTemplateAnalyzer::isAvailable()) {
            throw new \RuntimeException(
                'Instrumenting Blade templates requires the Forte parser. Install it with: composer require fortephp/forte'
            );
        }

        $view = GlobalRuntimeState::$currentExecutionFile;
        $commentsAtDocumentRoot = ! InstrumentationState::renderingComponentView()
            && ! InstrumentationState::instrumentingView();
        $cacheKey = md5('blade|'.($commentsAtDocumentRoot ? 'root' : 'nested').'|'.(is_string($view) ? $view : '').'|'.$template);
        $cached = $this->cachedResult($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $analysis = (new BladeTemplateAnalyzer($this->componentPrefixes))->analyze($template, $commentsAtDocumentRoot);

        $nextId = null;

        return $this->storeResult(
            $cacheKey,
            $this->emitInstrumentation($template, $analysis, $nextId, $commentsAtDocumentRoot)
        );
    }

    /**
     * Decode the metadata from a default start comment or base64 payload.
     *
     * @param  string  $marker
     * @return array<string, mixed>|null
     */
    public static function decode($marker)
    {
        $commentStart = strpos($marker, '<!-- ');

        if ($commentStart !== false) {
            $payloadStart = strpos($marker, ':start ', $commentStart);

            if ($payloadStart === false) {
                return null;
            }

            $payloadStart += strlen(':start ');
            $payloadEnd = strpos($marker, ' -->', $payloadStart);

            if ($payloadEnd === false) {
                return null;
            }

            $marker = substr($marker, $payloadStart, $payloadEnd - $payloadStart);
        }

        $decoded = base64_decode($marker, true);

        if ($decoded === false) {
            return null;
        }

        $metadata = json_decode($decoded, true);

        return is_array($metadata) ? $metadata : null;
    }
}
