<?php

namespace Tests\Antlers\Runtime;

use Tests\Antlers\ParserTestCase;
use Tests\FakesViews;

/**
 * Regression coverage for the partial-scope leakage issues the include tag is
 * intended to resolve:
 *
 * - https://github.com/statamic/cms/issues/8175
 * - https://github.com/statamic/cms/issues/10703
 * - https://github.com/statamic/cms/issues/11486
 * - https://github.com/statamic/cms/issues/12709
 */
class IncludeIssuesTest extends ParserTestCase
{
    use FakesViews;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withFakeViews();
    }

    private function render($template, $data = [])
    {
        return $this->renderString($template, $data, true);
    }

    /**
     * #8175 - "Inconsistent behaviour for variables set in a partial."
     *
     * A variable assigned inside the included view must never leak to the parent,
     * and the result must not depend on whatever other tags the view contains.
     */
    public function test_issue_8175_assigned_variables_never_leak_consistently()
    {
        $this->viewShouldReturnRaw('noop', '');
        $this->viewShouldReturnRaw('setter', '{{ $var = "SET" }}');
        $this->viewShouldReturnRaw('setter_extra', '{{ $var = "SET" }}{{ partial:noop }}');

        $this->assertSame('|[]', $this->render('{{ include:setter }}|[{{ $var }}]'));
        $this->assertSame('|[]', $this->render('{{ include:setter_extra }}|[{{ $var }}]'));
    }

    /**
     * #10703 - "Cascade method getViewData should only return the data from the
     * current view." A param given to one include must not bleed into the next.
     */
    public function test_issue_10703_params_do_not_leak_into_the_next_include()
    {
        $this->viewShouldReturnRaw('cardA', '[{{ class }}|{{ view:class }}]');
        $this->viewShouldReturnRaw('cardB', '[{{ class }}|{{ view:class }}]');

        // cardB receives nothing - checked via both the top-level variable and
        // the {{ view: }} (front matter) accessor.
        $this->assertSame(
            '[cool|cool][|]',
            $this->render('{{ include:cardA class="cool" }}{{ include:cardB }}')
        );
    }

    /**
     * #11486 - "Leakage of view variables in antlers partials with frontmatter."
     * Front matter declared in an included view stays isolated across nested and
     * repeated inclusions, and never reaches the parent.
     */
    public function test_issue_11486_frontmatter_does_not_leak_across_inclusions()
    {
        $this->viewShouldReturnRaw('inc_a', "---\nvar_a: A\n---\nA[{{ view:var_a }}]");
        $this->viewShouldReturnRaw('inc_b', "---\nvar_b: B\n---\nB[{{ view:var_b }}]{{ include:inc_a }}");

        $template = '{{ include:inc_b }}{{ include:inc_b }}|HOME[{{ view:var_a }}|{{ view:var_b }}]';

        $this->assertSame('B[B]A[A]B[B]A[A]|HOME[|]', $this->render($template));
    }

    /**
     * #12709 - "Shorthand version not working as intended." The include must be
     * isolated identically whether used inside {{ if }} or the shorthand `?=`,
     * removing the inconsistency the original report describes.
     */
    public function test_issue_12709_isolation_is_consistent_across_conditional_forms()
    {
        $this->viewShouldReturnRaw('mod', '{{ foo = "changed" }}M');

        $this->assertSame(
            'M|orig',
            $this->render('{{ foo = "orig" }}{{ if bar }}{{ include:mod }}{{ /if }}|{{ foo }}', ['bar' => true])
        );

        $this->assertSame(
            'M|orig',
            $this->render('{{ foo = "orig" }}{{ bar ?= { include:mod } }}|{{ foo }}', ['bar' => true])
        );
    }
}
