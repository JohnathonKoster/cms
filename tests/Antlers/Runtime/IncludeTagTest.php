<?php

namespace Tests\Antlers\Runtime;

use Statamic\Facades\Cascade;
use Statamic\Tags\Tags;
use Tests\Antlers\ParserTestCase;
use Tests\FakesViews;

class IncludeTagTest extends ParserTestCase
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

    private $spy;

    private function registerSpyTag(): void
    {
        $this->spy = new class extends Tags
        {
            public static $handle = 'spy';

            public static $count = 0;

            public function index()
            {
                self::$count++;

                return '';
            }
        };

        $this->spy::$count = 0;
        $this->spy::register();
    }

    public function test_it_renders_a_view()
    {
        $this->viewShouldReturnRaw('greeting', 'Hello');

        $this->assertSame('Hello', $this->render('{{ include:greeting }}'));
    }

    public function test_it_renders_a_view_using_the_src_form()
    {
        $this->viewShouldReturnRaw('greeting', 'Hi');

        $this->assertSame('Hi', $this->render('{{ include src="greeting" }}'));
    }

    public function test_params_are_available_as_variables()
    {
        $this->viewShouldReturnRaw('greeting', 'Hello {{ name }}');

        $this->assertSame('Hello World', $this->render('{{ include:greeting name="World" }}'));
    }

    public function test_unconsumed_params_are_just_variables()
    {
        $this->viewShouldReturnRaw('greeting', '<div class="{{ class }}">hi</div>');

        $this->assertSame('<div class="featured">hi</div>', $this->render('{{ include:greeting class="featured" }}'));
    }

    public function test_caller_scope_is_not_captured()
    {
        $this->viewShouldReturnRaw('greeting', '[{{ secret }}]');

        $this->assertSame('[]', $this->render('{{ include:greeting }}', ['secret' => 'leak']));
    }

    public function test_loop_variables_are_not_captured()
    {
        $this->viewShouldReturnRaw('item', '[{{ value }}]');

        $template = '{{ items }}{{ include:item }}{{ /items }}';

        $this->assertSame('[][]', $this->render($template, ['items' => [['value' => 'a'], ['value' => 'b']]]));
    }

    public function test_loop_variables_can_be_passed_explicitly()
    {
        $this->viewShouldReturnRaw('item', '[{{ value }}]');

        $template = '{{ items }}{{ include:item :value="value" }}{{ /items }}';

        $this->assertSame('[a][b]', $this->render($template, ['items' => [['value' => 'a'], ['value' => 'b']]]));
    }

    public function test_assignments_inside_an_include_do_not_leak_out()
    {
        $this->viewShouldReturnRaw('assigner', '{{ leaked = "in-include" }}{{ leaked }}');

        $template = '{{ include:assigner }}|{{ leaked }}';

        $this->assertSame('in-include|', $this->render($template));
    }

    public function test_reassigning_an_existing_caller_variable_does_not_change_it()
    {
        $this->viewShouldReturnRaw('reassign', '{{ foo = "changed" }}{{ foo }}');

        $template = '{{ foo = "original" }}{{ foo }}|{{ include:reassign }}|{{ foo }}';

        $this->assertSame('original|changed|original', $this->render($template));
    }

    public function test_reassigning_a_passed_variable_does_not_change_the_caller()
    {
        $this->viewShouldReturnRaw('reassign', '{{ foo = "changed" }}{{ foo }}');

        $template = '{{ foo = "original" }}{{ foo }}|{{ include:reassign :foo="foo" }}|{{ foo }}';

        $this->assertSame('original|changed|original', $this->render($template));
    }

    public function test_cascade_is_not_available_by_default()
    {
        Cascade::set('cascade_only', 'from-cascade');
        $this->viewShouldReturnRaw('greeting', '[{{ cascade_only }}]');

        $this->assertSame('[]', $this->render('{{ include:greeting }}'));
    }

    public function test_cascade_is_available_when_requested()
    {
        Cascade::set('cascade_only', 'from-cascade');
        $this->viewShouldReturnRaw('greeting', '[{{ cascade_only }}]');

        $this->assertSame('[from-cascade]', $this->render('{{ include:greeting cascade="true" }}'));
    }

    public function test_params_accessor_returns_passed_params()
    {
        $this->viewShouldReturnRaw('greeting', '{{ params:name }}');

        $this->assertSame('Bob', $this->render('{{ include:greeting name="Bob" }}', ['name' => 'CallerName']));
    }

    public function test_params_array_is_spread_into_the_scope_and_overridden_by_explicit_params()
    {
        $this->viewShouldReturnRaw('card', '<{{ title }}><{{ subtitle }}>');

        $data = ['title' => 'T', 'subtitle' => 'S'];

        $this->assertSame('<T><S>', $this->render('{{ include:card :params="data" }}', ['data' => $data]));
        $this->assertSame('<Override><S>', $this->render('{{ include:card :params="data" title="Override" }}', ['data' => $data]));
    }

    public function test_params_accessor_returns_the_merged_params()
    {
        $this->viewShouldReturnRaw('card', '[{{ params:title }}][{{ params:subtitle }}]');

        // The spread appears in {{ params }}; an explicit param overrides it.
        $this->assertSame(
            '[Named][S]',
            $this->render('{{ include:card :params="data" title="Named" }}', ['data' => ['title' => 'T', 'subtitle' => 'S']])
        );
    }

    public function test_meta_params_never_appear_as_data()
    {
        $this->viewShouldReturnRaw('card', '[{{ params:src }}][{{ params:when }}][{{ params:handle_prefix }}][{{ params:params }}]');

        // Control params are stripped even when smuggled in through the spread.
        $this->assertSame(
            '[][][][]',
            $this->render('{{ include:card :params="data" handle_prefix="x_" }}', ['data' => ['src' => 'sneaky', 'when' => 'sneaky']])
        );
    }

    public function test_params_must_be_an_associative_array()
    {
        $this->viewShouldReturnRaw('card', 'C');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be an associative array');

        $this->render('{{ include:card :params="bad" }}', ['bad' => ['a', 'b', 'c']]);
    }

    public function test_handle_prefix_unprefixes_spread_values_and_drops_the_prefixed_key()
    {
        $this->viewShouldReturnRaw('hero', '<{{ title }}><{{ body }}>[{{ hero_title }}][{{ params:hero_title }}]');

        $template = '{{ include:hero :params="data" handle_prefix="hero_" }}';

        // hero_title -> title, hero_body -> body, and the prefixed keys are gone.
        $this->assertSame(
            '<HT><HB>[][]',
            $this->render($template, ['data' => ['hero_title' => 'HT', 'hero_body' => 'HB', 'other' => 'O']])
        );
    }

    public function test_a_prefixed_spread_value_wins_over_a_non_prefixed_one()
    {
        $this->viewShouldReturnRaw('hero', '<{{ title }}>');

        $template = '{{ include:hero :params="data" handle_prefix="hero_" }}';

        $this->assertSame(
            '<PREFIXED>',
            $this->render($template, ['data' => ['hero_title' => 'PREFIXED', 'title' => 'PLAIN']])
        );
    }

    public function test_an_explicit_param_wins_over_a_prefixed_spread_value()
    {
        $this->viewShouldReturnRaw('hero', '<{{ title }}>');

        $template = '{{ include:hero :params="data" handle_prefix="hero_" title="EXPLICIT" }}';

        $this->assertSame(
            '<EXPLICIT>',
            $this->render($template, ['data' => ['hero_title' => 'PREFIXED']])
        );
    }

    public function test_a_default_slot_may_be_passed_inline_as_a_param()
    {
        $this->viewShouldReturnRaw('greeting', '<div>{{ slot }}</div>');

        $this->assertSame('<div>Hello</div>', $this->render('{{ include:greeting slot="Hello" }}'));
    }

    public function test_when_param_controls_rendering()
    {
        $this->viewShouldReturnRaw('greeting', 'Hello');

        $this->assertSame('', $this->render('{{ include:greeting when="false" }}'));
        $this->assertSame('Hello', $this->render('{{ include:greeting when="true" }}'));
    }

    public function test_unless_param_controls_rendering()
    {
        $this->viewShouldReturnRaw('greeting', 'Hello');

        $this->assertSame('', $this->render('{{ include:greeting unless="true" }}'));
        $this->assertSame('Hello', $this->render('{{ include:greeting unless="false" }}'));
    }

    public function test_default_slot()
    {
        $this->viewShouldReturnRaw('wrapper', '<div>{{ slot }}</div>');

        $this->assertSame('<div>Body</div>', $this->render('{{ include:wrapper }}Body{{ /include:wrapper }}'));
    }

    public function test_if_slot_is_false_when_no_body_is_given()
    {
        $this->viewShouldReturnRaw('wrapper', '{{ if slot }}HAS{{ else }}NONE{{ /if }}');

        $this->assertSame('NONE', $this->render('{{ include:wrapper }}{{ /include:wrapper }}'));
        $this->assertSame('NONE', $this->render('{{ include:wrapper }}'));
        $this->assertSame('NONE', $this->render('{{ include:wrapper }}   {{ /include:wrapper }}'));
    }

    public function test_if_slot_is_true_when_a_body_is_given()
    {
        $this->viewShouldReturnRaw('wrapper', '{{ if slot }}HAS{{ else }}NONE{{ /if }}');

        $this->assertSame('HAS', $this->render('{{ include:wrapper }}body{{ /include:wrapper }}'));
    }

    public function test_default_slot_presence_does_not_bleed_between_includes()
    {
        $this->viewShouldReturnRaw('wrapper', '{{ if slot }}HAS{{ else }}NONE{{ /if }}');

        $this->assertSame(
            'HAS|NONE',
            $this->render('{{ include:wrapper }}body{{ /include:wrapper }}|{{ include:wrapper }}{{ /include:wrapper }}')
        );
    }

    public function test_slot_sees_outer_scope_but_the_view_does_not()
    {
        $this->viewShouldReturnRaw('wrapper', '<view>{{ title }}</view><slot>{{ slot }}</slot>');

        $template = '{{ include:wrapper }}{{ title }}{{ /include:wrapper }}';

        $this->assertSame('<view></view><slot>Caller</slot>', $this->render($template, ['title' => 'Caller']));
    }

    public function test_slot_content_may_contain_a_conditional_pair()
    {
        $this->viewShouldReturnRaw('wrapper', '<w>{{ slot }}</w>');

        $tpl = '{{ include:wrapper }}{{ if show }}A{{ else }}B{{ /if }}{{ /include:wrapper }}';

        $this->assertSame('<w>A</w>', $this->render($tpl, ['show' => true]));
        $this->assertSame('<w>B</w>', $this->render($tpl, ['show' => false]));
    }

    public function test_slot_content_may_contain_a_loop_pair()
    {
        $this->viewShouldReturnRaw('wrapper', '<w>{{ slot }}</w>');

        $tpl = '{{ include:wrapper }}{{ items }}<{{ value }}>{{ /items }}{{ /include:wrapper }}';

        $this->assertSame(
            '<w><a><b></w>',
            $this->render($tpl, ['items' => [['value' => 'a'], ['value' => 'b']]])
        );
    }

    public function test_slot_content_with_text_around_a_pair_is_preserved()
    {
        $this->viewShouldReturnRaw('wrapper', '<w>{{ slot }}</w>');

        $tpl = '{{ include:wrapper }}before{{ if show }}mid{{ /if }}after{{ /include:wrapper }}';

        $this->assertSame('<w>beforemidafter</w>', $this->render($tpl, ['show' => true]));
    }

    public function test_a_slot_containing_only_a_pair_is_considered_present()
    {
        // A pair that renders nothing still counts as "present"; laziness can't
        // know a slot's output without rendering it.
        $this->viewShouldReturnRaw('wrapper', '{{ if slot }}HAS[{{ slot }}]{{ else }}NONE{{ /if }}');

        $tpl = '{{ include:wrapper }}{{ if show }}X{{ /if }}{{ /include:wrapper }}';

        $this->assertSame('HAS[X]', $this->render($tpl, ['show' => true]));
        $this->assertSame('HAS[]', $this->render($tpl, ['show' => false]));
    }

    public function test_slot_content_can_access_include_params()
    {
        $this->viewShouldReturnRaw('wrapper', '<div>{{ slot }}</div>');

        $template = '{{ include:wrapper foo="bar" }}{{ params:foo }}{{ /include:wrapper }}';

        $this->assertSame('<div>bar</div>', $this->render($template));
    }

    public function test_named_slots()
    {
        $this->viewShouldReturnRaw('card', '<h>{{ slot:header }}</h><b>{{ slot }}</b>');

        $template = '{{ include:card }}{{ slot:header }}Title{{ /slot:header }}Body{{ /include:card }}';

        $this->assertSame('<h>Title</h><b>Body</b>', $this->render($template));
    }

    public function test_named_slot_falls_back_when_not_provided()
    {
        $this->viewShouldReturnRaw('card', '{{ if slot:header }}{{ slot:header }}{{ else }}Default{{ /if }}');

        $this->assertSame('Default', $this->render('{{ include:card }}Body{{ /include:card }}'));
    }

    public function test_named_slot_is_used_when_provided()
    {
        $this->viewShouldReturnRaw('card', '{{ if slot:header }}{{ slot:header }}{{ else }}Default{{ /if }}');

        $template = '{{ include:card }}{{ slot:header }}Provided{{ /slot:header }}{{ /include:card }}';

        $this->assertSame('Provided', $this->render($template));
    }

    public function test_named_slot_presence_is_false_when_empty()
    {
        $this->viewShouldReturnRaw('card', '{{ if slot:header }}YES{{ else }}NO{{ /if }}');

        // An empty/whitespace named slot counts as absent, like the default slot.
        $this->assertSame('NO', $this->render('{{ include:card }}{{ slot:header }}   {{ /slot:header }}{{ /include:card }}'));
    }

    public function test_scoped_slot_props_are_exposed_to_slot_content()
    {
        $this->viewShouldReturnRaw('list', '{{ slot:item :label="heading" }}');

        $template = '{{ include:list heading="From View" }}{{ slot:item }}[{{ label }}]{{ /slot:item }}{{ /include:list }}';

        $this->assertSame('[From View]', $this->render($template));
    }

    public function test_scoped_slot_exposes_multiple_props()
    {
        $this->viewShouldReturnRaw('row', '{{ slot:row :label="title" :n="num" }}');

        $template = '{{ include:row title="T" num="3" }}{{ slot:row }}[{{ label }}|{{ n }}]{{ /slot:row }}{{ /include:row }}';

        $this->assertSame('[T|3]', $this->render($template));
    }

    public function test_a_scoped_slot_is_rendered_for_each_iteration_of_a_loop()
    {
        // The view loops and exposes each item to the slot; the caller's slot
        // content is rendered once per row with that row's data.
        $this->viewShouldReturnRaw('list', '{{ rows }}<{{ slot:row :label="value" :i="count" }}>{{ /rows }}');

        $template = '{{ include:list :rows="data" }}{{ slot:row }}{{ label }}#{{ i }}{{ /slot:row }}{{ /include:list }}';

        $this->assertSame('<a#1><b#2>', $this->render($template, ['data' => [['value' => 'a'], ['value' => 'b']]]));
    }

    public function test_scoped_slot_props_combine_with_caller_scope()
    {
        $this->viewShouldReturnRaw('combo', '{{ slot:item :label="heading" }}');

        $template = '{{ include:combo heading="VIEW" }}{{ slot:item }}[{{ label }}|{{ outer }}]{{ /slot:item }}{{ /include:combo }}';

        $this->assertSame('[VIEW|OUT]', $this->render($template, ['outer' => 'OUT']));
    }

    public function test_scoped_slot_props_override_caller_variables()
    {
        $this->viewShouldReturnRaw('clash', '{{ slot:item :name="inner" }}');

        $template = '{{ include:clash inner="FROM-VIEW" }}{{ slot:item }}[{{ name }}]{{ /slot:item }}{{ /include:clash }}';

        $this->assertSame('[FROM-VIEW]', $this->render($template, ['name' => 'FROM-CALLER']));
    }

    public function test_unused_slots_are_not_rendered()
    {
        $this->registerSpyTag();
        $this->viewShouldReturnRaw('wrapper', 'no slot output');

        $template = '{{ include:wrapper }}{{ spy }}{{ /include:wrapper }}';

        $this->assertSame('no slot output', $this->render($template));
        $this->assertSame(0, $this->spy::$count);
    }

    public function test_a_slot_is_rendered_each_time_it_is_output()
    {
        $this->registerSpyTag();
        $this->viewShouldReturnRaw('wrapper', '{{ slot }}{{ slot }}');

        $template = '{{ include:wrapper }}{{ spy }}{{ /include:wrapper }}';

        $this->render($template);

        $this->assertSame(2, $this->spy::$count);
    }

    public function test_a_condition_checks_slot_presence_without_rendering_it()
    {
        $this->registerSpyTag();
        $this->viewShouldReturnRaw('wrapper', '{{ if slot }}HAS{{ else }}NONE{{ /if }}');

        // {{ if slot }} must not render the slot - no double render, no side effects.
        $this->assertSame('HAS', $this->render('{{ include:wrapper }}{{ spy }}{{ /include:wrapper }}'));
        $this->assertSame(0, $this->spy::$count);
    }

    public function test_a_scoped_slot_guarded_by_a_condition_renders_only_once_with_its_props()
    {
        $this->registerSpyTag();
        $this->viewShouldReturnRaw('list', '{{ if slot:row }}{{ slot:row :label="heading" }}{{ /if }}');

        $template = '{{ include:list heading="H" }}{{ slot:row }}{{ spy }}[{{ label }}]{{ /slot:row }}{{ /include:list }}';

        // The condition does not render the slot (which would lack the props); only
        // the output site renders it, exactly once, with the exposed prop.
        $this->assertSame('[H]', $this->render($template));
        $this->assertSame(1, $this->spy::$count);
    }
}
