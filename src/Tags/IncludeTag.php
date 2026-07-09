<?php

namespace Statamic\Tags;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\HtmlString;
use RuntimeException;
use Statamic\Facades\Cascade;
use Statamic\Fields\Value;
use Statamic\Support\Arr;
use Statamic\Tags\Concerns\GetsViewName;
use Statamic\View\Antlers\Language\Runtime\GlobalRuntimeState;
use Statamic\View\Antlers\Language\Runtime\NodeProcessor;

class IncludeTag extends Tags
{
    use GetsViewName;

    protected static $handle = 'include';

    protected static $reservedParams = ['__frontmatter'];

    public static $isolated = true;

    public function wildcard($tag)
    {
        return $this->render(
            $this->params->get('src', $tag)
        );
    }

    public function index()
    {
        if (! $view = $this->params->get('src')) {
            return $this->wildcard('index');
        }

        return $this->render($view);
    }

    protected function render($view)
    {
        foreach (static::$reservedParams as $reserved) {
            if ($this->params->has($reserved)) {
                throw new RuntimeException("Cannot pass reserved parameter [{$reserved}] to the include tag.");
            }
        }

        if (! $this->shouldRender()) {
            return '';
        }

        // The :params array is spread into the view scope. handle_prefix rewrites
        // its prefixed keys (hero_title -> title) so they arrive unprefixed; the
        // prefixed key is dropped to avoid polluting the scope. Explicit params on
        // the tag then override everything. The merged result is the data the view
        // sees and what {{ params }} returns.
        $data = array_merge($this->prefixed($this->spreadParams()), $this->namedParams());

        $scope = $this->params->bool('cascade') ? Cascade::toArray() : [];

        $scope = array_merge($scope, $data, [
            'params' => $data,
            '__frontmatter' => $data,
        ], $this->slots());

        // The included view's front matter is registered in the shared "views"
        // cascade and exposed through {{ view:* }} via Cascade::getViewData(),
        // which merges every rendered view together. Snapshotting and restoring
        // that state around the render keeps the include's front matter available
        // inside the view while preventing it from leaking to other things.
        $viewsState = Cascade::get('views') ?? [];

        try {
            return view($this->viewName($view), $scope)
                ->withoutExtractions()
                ->render();
        } finally {
            Cascade::set('views', $viewsState);
        }
    }

    /**
     * The params named directly on the tag (the include's explicit interface),
     * excluding the include's own control params.
     */
    protected function namedParams(): array
    {
        return Arr::except(
            $this->params->all(),
            NodeProcessor::INCLUDE_CONTROL_PARAMS
        );
    }

    /**
     * The entries of the :params array, spread into the view scope. The value
     * must be an associative array; the include's own control params are removed
     * so meta-params (src, when, handle_prefix, ...) cannot slip into the scope.
     */
    protected function spreadParams(): array
    {
        $spread = $this->params->get('params');

        if ($spread === null) {
            return [];
        }

        if ($spread instanceof Value) {
            $spread = $spread->value();
        }

        if ($spread instanceof Arrayable) {
            $spread = $spread->toArray();
        }

        if (! is_array($spread) || (! empty($spread) && ! Arr::isAssoc($spread))) {
            throw new RuntimeException('The [params] parameter on the include tag must be an associative array.');
        }

        return Arr::except($spread, NodeProcessor::INCLUDE_CONTROL_PARAMS);
    }

    /**
     * Rewrites keys matching handle_prefix to their unprefixed form, dropping the
     * prefixed key. A prefixed value wins over a non-prefixed one of the same
     * name, since it is the more specific match.
     */
    protected function prefixed(array $data): array
    {
        if (! $prefix = $this->params->get('handle_prefix')) {
            return $data;
        }

        foreach ($data as $key => $value) {
            if (is_string($key) && str_starts_with($key, $prefix) && strlen($key) > strlen($prefix)) {
                $data[substr($key, strlen($prefix))] = $value;
                unset($data[$key]);
            }
        }

        return $data;
    }

    /**
     * The slot variables exposed to the included view.
     *
     * In the Antlers ({{ include }}) path these are deferred Slot objects
     * captured by the runtime. In the Blade (<s:include>) path the runtime is
     * not involved: named slots arrive as params and the default slot arrives
     * as the tag's content, which is rendered here.
     */
    protected function slots(): array
    {
        $slots = $this->deferredSlots();

        // Blade path: no runtime slot carrier. Expose the tag content as the
        // default slot, mirroring the partial tag's behaviour.
        if ($this->isolatedContext === null && $this->isPair && ! isset($slots['slot'])) {
            $slots['slot'] = $this->getSlotContent();
        }

        return $slots;
    }

    /**
     * The deferred slot objects captured by the runtime, forwarded into the
     * view scope. Only this include's own slots are forwarded - they are read
     * from a dedicated carrier key so an enclosing include's slots (which may
     * be present in the surrounding scope) cannot leak in.
     */
    protected function deferredSlots(): array
    {
        $all = $this->isolatedContext?->all() ?? [];

        $slots = $all[NodeProcessor::INCLUDE_SLOTS_KEY] ?? [];

        if (empty($slots)) {
            return [];
        }

        foreach (array_keys($slots) as $key) {
            if (str_starts_with($key, 'slot:')) {
                $slots[GlobalRuntimeState::createIndicatorVariable(
                    GlobalRuntimeState::INDICATOR_NAMED_SLOTS_AVAILABLE
                )] = true;

                break;
            }
        }

        return $slots;
    }

    private function getSlotContent(): HtmlString|string
    {
        $content = trim($this->parse());

        if ($this->isAntlersBladeComponent()) {
            return new HtmlString($content);
        }

        return $content;
    }

    protected function shouldRender(): bool
    {
        if ($this->params->has('when')) {
            return $this->params->bool('when');
        }

        if ($this->params->has('unless')) {
            return ! $this->params->bool('unless');
        }

        return true;
    }

    /**
     * The {{ include:exists }} tag.
     */
    public function exists()
    {
        if (! $view = $this->params->get('src')) {
            return $this->wildcard('exists');
        }

        return view()->exists($this->viewName($view));
    }

    /**
     * The {{ include:if_exists }} tag.
     */
    public function ifExists()
    {
        if (! $view = $this->params->get('src')) {
            return $this->wildcard('if_exists');
        }

        if (view()->exists($this->viewName($view))) {
            return $this->render($view);
        }
    }
}
