<?php

namespace Tests\Antlers\Runtime;

use Tests\Antlers\ParserTestCase;
use Tests\FakesViews;

/**
 * Adversarial tests that attempt to break the include's scope sandbox.
 */
class IncludeSandboxTest extends ParserTestCase
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

    public function test_a_partial_inside_an_include_cannot_see_the_outer_caller_scope()
    {
        // The partial captures the include's scope (params only), not the outer
        // caller, so "p" from the outer caller must not reach it.
        $this->viewShouldReturnRaw('shell', 'S[{{ p }}]{{ partial:inner }}');
        $this->viewShouldReturnRaw('inner', 'P[{{ p }}]');

        $this->assertSame(
            'S[param]P[param]',
            $this->render('{{ include:shell p="param" }}', ['p' => 'caller-p'])
        );
    }

    public function test_a_partials_assignment_cannot_escape_the_include_boundary()
    {
        // Partials normally leak assignments to their caller; the include's
        // isolation must contain that so it never reaches the outer caller.
        $this->viewShouldReturnRaw('shell', '{{ partial:setter }}IN[{{ v }}]');
        $this->viewShouldReturnRaw('setter', '{{ v = "from-partial" }}');

        $this->assertSame(
            'IN[]|OUT[caller]',
            $this->render('{{ v = "caller" }}{{ include:shell }}|OUT[{{ v }}]')
        );
    }

    public function test_the_internal_slot_carrier_key_is_not_exposed_to_the_view()
    {
        $this->viewShouldReturnRaw('v', 'C[{{ __antlers_include_slots }}]');

        $this->assertSame('C[]', $this->render('{{ include:v }}body{{ /include:v }}'));
    }

    public function test_mutating_a_passed_array_does_not_affect_the_caller()
    {
        $this->viewShouldReturnRaw('mut', '{{ data:key = "mutated" }}IN[{{ data:key }}]');

        $this->assertSame(
            'IN[mutated]|OUT[original]',
            $this->render('{{ include:mut :data="data" }}|OUT[{{ data:key }}]', ['data' => ['key' => 'original']])
        );
    }

    public function test_mutating_a_passed_object_does_not_affect_the_caller()
    {
        $obj = new \stdClass();
        $obj->prop = 'original';

        $this->viewShouldReturnRaw('omut', '{{ o:prop = "mutated" }}IN[{{ o:prop }}]');

        $result = $this->render('{{ include:omut :o="o" }}|OUT[{{ o:prop }}]', ['o' => $obj]);

        $this->assertStringContainsString('OUT[original]', $result);
        $this->assertSame('original', $obj->prop, 'The underlying PHP object must not be mutated.');
    }

    public function test_self_closing_slot_output_avoids_same_name_pairing()
    {
        // A self-closing {{ slot:title /}} lets a view both output its own slot
        // and define a same-named slot for a nested include without the parser
        // mis-pairing them.
        $this->viewShouldReturnRaw('outer', '<t>{{ slot:title /}}</t>{{ include:inner }}{{ slot:title }}INNER{{ /slot:title }}{{ /include:inner }}');
        $this->viewShouldReturnRaw('inner', '<it>{{ slot:title /}}</it>');

        $template = '{{ include:outer }}{{ slot:title }}OUTER{{ /slot:title }}{{ /include:outer }}';

        $this->assertSame('<t>OUTER</t><it>INNER</it>', $this->render($template));
    }

    public function test_slot_content_cannot_see_include_internal_variables()
    {
        $this->viewShouldReturnRaw('w', '{{ internal = "secret" }}<w>{{ slot }}</w>');

        $this->assertSame('<w>[]</w>', $this->render('{{ include:w }}[{{ internal }}]{{ /include:w }}'));
    }

    // The {{ scope }} tag and {{ push }}/{{ stack }} write to global state, so
    // they intentionally cross the include boundary - just as they do for
    // partials and components. This is outbound only: an include can surface its
    // own data, but still cannot read the caller's scope.

    public function test_scope_tag_writes_are_visible_outside_the_include()
    {
        $this->viewShouldReturnRaw('writer', '{{ scope:smuggled }}{{ secret }}{{ /scope:smuggled }}W');

        $this->assertSame(
            'SW|CALLER[S]',
            $this->render('{{ include:writer secret="S" }}|CALLER[{{ smuggled:secret }}]')
        );
    }

    public function test_stacks_pushed_inside_an_include_surface_in_the_caller()
    {
        $this->viewShouldReturnRaw('pusher', '{{ push:mystack }}PUSHED{{ /push:mystack }}P');

        $this->assertSame('PUSHEDP', $this->render('{{ stack:mystack }}{{ include:pusher }}'));
    }
}
