<?php

namespace Tests\Antlers\Parser\Html;

use Illuminate\Support\LazyCollection;
use Statamic\Facades\Antlers;
use Statamic\View\Antlers\Language\Analyzers\Html\Element;
use Statamic\View\Antlers\Language\Analyzers\Html\TextEdge;
use Tests\Antlers\ParserTestCase;

class RegionStressTest extends ParserTestCase
{
    public function test_many_adjacent_regions_can_be_rewritten_and_unwrapped_during_lazy_iteration()
    {
        $source = '';
        $expected = '';

        for ($index = 0; $index < 1000; $index++) {
            $source .= '{{ scope }} <p> value-'.$index.' </p> {{ /scope }}';
            $expected .= '<p>value-'.$index.'</p>';
        }

        $html = Antlers::html($source);
        $regions = $html->regions('scope');

        $this->assertInstanceOf(LazyCollection::class, $regions);

        foreach ($regions as $region) {
            $region->rewriteTextEdges(
                fn ($text, $edge) => $edge === TextEdge::LEADING
                    ? ltrim($text)
                    : rtrim($text)
            )->unwrap();
        }

        $this->assertSame($expected, $html->toHtml());
    }

    public function test_deeply_nested_regions_keep_element_ownership_partitioned()
    {
        $depth = 256;
        $source = '<strong>core</strong>';
        $expected = $source;

        for ($index = $depth - 1; $index >= 0; $index--) {
            $source = '{{ scope depth="'.$index.'" }}'
                .'<section data-depth="'.$index.'">'.$source.'</section>'
                .'{{ /scope }}';
            $expected = '<section data-depth="'.$index.'">'.$expected.'</section>';
        }

        $html = Antlers::html($source);
        $regions = $html->regions('scope')->all();

        $this->assertCount($depth, $regions);

        foreach ($regions as $index => $region) {
            $owned = $region->ownedElements();

            $this->assertSame((string) $index, $region->staticParameterValue('depth'));
            $this->assertSame(
                [$index],
                $owned
                    ->filter(fn (Element $element) => $element->hasAttribute('data-depth'))
                    ->map(fn (Element $element) => (int) $element->attr('data-depth'))
                    ->all()
            );
            $this->assertSame(
                $index === $depth - 1 ? ['section', 'strong'] : ['section'],
                $owned->map(fn (Element $element) => $element->name())->all()
            );
        }

        foreach (array_reverse($regions) as $region) {
            $region->unwrap();
        }

        $this->assertSame($expected, $html->toHtml());
    }

    public function test_region_queries_remain_repeatable_after_non_structural_mutations()
    {
        $html = Antlers::html(
            '{{ scope }}<article><p>first</p><p>last</p></article>{{ /scope }}'
        );
        $region = $html->regions('scope')->first();

        $firstPass = $region->ownedElements()
            ->map(fn (Element $element) => $element->name())
            ->all();

        $region->ownedElements()->each(
            fn (Element $element) => $element->setAttribute('data-seen')
        );

        $secondPass = $region->ownedElements()
            ->map(fn (Element $element) => $element->name())
            ->all();

        $this->assertSame(['article', 'p', 'p'], $firstPass);
        $this->assertSame($firstPass, $secondPass);
        $this->assertSame(
            '{{ scope }}<article data-seen><p data-seen>first</p><p data-seen>last</p></article>{{ /scope }}',
            $html->toHtml()
        );
    }

    public function test_empty_opaque_and_antlers_only_boundaries_do_not_confuse_edge_walks()
    {
        $html = Antlers::html(
            '{{ scope }}  <!-- before --> {{ value }} <!-- after -->  {{ /scope }}'
        );
        $region = $html->regions('scope')->first();

        $region->rewriteTextEdges(
            fn ($text, $edge) => $edge === TextEdge::LEADING
                ? ltrim($text)
                : rtrim($text)
        )->unwrap();

        $this->assertSame(
            '<!-- before -->{{ value }}<!-- after -->',
            $html->toHtml()
        );
    }
}
