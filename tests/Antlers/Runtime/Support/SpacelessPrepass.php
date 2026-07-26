<?php

namespace Tests\Antlers\Runtime\Support;

use Statamic\View\Antlers\Language\Analyzers\Html\Document;
use Statamic\View\Antlers\Language\Analyzers\Html\Region;

class SpacelessPrepass
{
    public function __invoke($source)
    {
        $html = Document::parse($source);

        foreach ($html->regions('spaceless') as $region) {
            (new WhitespaceTrimmer($this->sensitiveElements($region)))
                ->trimRegion($region);
            $region->unwrap();
        }

        return $html->toHtml();
    }

    /**
     * @return string[]
     */
    protected function sensitiveElements(Region $region)
    {
        return array_values(array_filter(preg_split(
            '/[\s,]+/',
            strtolower((string) $region->staticParameterValue('sensitive', ''))
        )));
    }
}
