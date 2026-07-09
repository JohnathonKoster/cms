<?php

namespace Statamic\Tags\Concerns;

trait GetsViewName
{
    protected function viewName($partial)
    {
        $partial = str_replace('/', '.', $partial);

        if (view()->exists($underscored = $this->underscoredViewName($partial))) {
            return $underscored;
        }

        if (view()->exists($subdirectoried = 'partials.'.$partial)) {
            return $subdirectoried;
        }

        if (view()->exists($underscored_subdirectoried = 'partials.'.$this->underscoredViewName($partial))) {
            return $underscored_subdirectoried;
        }

        return $partial;
    }

    protected function underscoredViewName($partial)
    {
        $bits = collect(explode('.', $partial));

        $last = $bits->pull($bits->count() - 1);

        return $bits->implode('.').'._'.$last;
    }
}
