<?php

namespace Tests\View\Blade\AntlersComponents;

use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\FakesViews;
use Tests\TestCase;

#[Group('blade-compiler')]
class IncludeCompilerTest extends TestCase
{
    use FakesViews;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withFakeViews();
        $this->artisan('view:clear');
    }

    #[Test]
    public function it_compiles_include_tags()
    {
        $this->viewShouldReturnRaw('alert', '<div>{{ $title }}</div>', 'blade.php');

        $expected = '<div>The Title</div>';

        $this->assertSame($expected, Blade::render('<s:include:alert title="The Title" />'));
        $this->assertSame($expected, Blade::render('<s:include:alert title="The Title"></s:include:alert>'));
    }

    #[Test]
    public function it_does_not_capture_the_caller_scope()
    {
        $this->viewShouldReturnRaw('alert', '[{{ $secret ?? "none" }}][{{ $passed ?? "none" }}]');

        $this->assertSame(
            '[none][yes]',
            Blade::render('<s:include:alert passed="yes" />', ['secret' => 'LEAK'])
        );
    }

    #[Test]
    public function it_compiles_slots()
    {
        $this->viewShouldReturnRaw('alert', '<div>{{ $slot }}</div>');

        $template = <<<'BLADE'
<s:include:alert>
  I am the slot content.
</s:include:alert>
BLADE;

        $this->assertSame('<div>I am the slot content.</div>', Blade::render($template));
    }

    #[Test]
    public function slot_content_sees_the_caller_scope()
    {
        $this->viewShouldReturnRaw('alert', '<div>{{ $slot }}</div>');

        // The slot reads the caller scope even though the view cannot.
        $this->assertSame(
            '<div>LEAK</div>',
            Blade::render('<s:include:alert>{{ $secret }}</s:include:alert>', ['secret' => 'LEAK'])
        );
    }

    #[Test]
    public function it_compiles_named_slots()
    {
        $alert = <<<'ALERT'
<div id="header">{{ $header }}</div>
<div>{{ $slot }}</div>
<div id="footer">{{ $footer }}</div>
ALERT;
        $this->viewShouldReturnRaw('alert', $alert);

        $template = <<<'BLADE'
<s:include:alert>
  <s:slot:header>The header</s:slot:header>
  <s:slot.footer>The footer</s:slot.footer>
  I am the slot content.
</s:include:alert>
BLADE;

        $expected = <<<'EXPECTED'
<div id="header">The header</div>
<div>I am the slot content.</div>
<div id="footer">The footer</div>
EXPECTED;

        $this->assertSame($expected, Blade::render($template));
    }

    #[Test]
    public function it_compiles_scoped_slots()
    {
        // The view loops and hands each row to the slot via props.
        $this->viewShouldReturnRaw('list', "@foreach(\$rows as \$person)<s:slot:row :name=\"\$person['name']\" :index=\"\$loop->iteration\" />@endforeach", 'blade.php');

        $template = <<<'BLADE'
<s:include:list :rows="$people">
  <s:slot:row>[{{ $name }}#{{ $index }}]</s:slot:row>
</s:include:list>
BLADE;

        $this->assertSame(
            '[Alice#1][Bob#2]',
            Blade::render($template, ['people' => [['name' => 'Alice'], ['name' => 'Bob']]])
        );
    }

    #[Test]
    public function a_named_slot_can_be_output_with_the_slot_tag()
    {
        $this->viewShouldReturnRaw('card', '<header><s:slot:header /></header>', 'blade.php');

        $this->assertSame(
            '<header>Hi</header>',
            Blade::render('<s:include:card><s:slot:header>Hi</s:slot:header></s:include:card>')
        );
    }

    #[Test]
    public function it_forwards_exists_method_calls()
    {
        $template = '<s:include:exists src="alert">Yes</s:include:exists>';

        $this->assertSame('', Blade::render($template));

        $this->viewShouldReturnRaw('alert', 'some content');

        $this->assertSame('Yes', Blade::render($template));
    }

    #[Test]
    public function it_forwards_if_exists_method_calls()
    {
        $template = '<s:include:if_exists src="alert" />';

        $this->assertSame('', Blade::render($template));

        $this->viewShouldReturnRaw('alert', 'some content');

        $this->assertSame('some content', Blade::render($template));
    }

    #[Test]
    public function it_compiles_when_parameter()
    {
        $this->viewShouldReturnRaw('the_partial', 'The content');

        $template = '<s:include:the_partial :when="$theValue" />';

        $this->assertSame('', Blade::render($template, ['theValue' => false]));
        $this->assertSame('The content', Blade::render($template, ['theValue' => true]));
    }

    #[Test]
    public function it_compiles_unless_parameter()
    {
        $this->viewShouldReturnRaw('the_partial', 'The content');

        $template = '<s:include:the_partial :unless="$theValue" />';

        $this->assertSame('', Blade::render($template, ['theValue' => true]));
        $this->assertSame('The content', Blade::render($template, ['theValue' => false]));
    }

    #[Test]
    public function it_isolates_the_caller_scope_through_nesting()
    {
        $this->viewShouldReturnRaw('outer', 'O[{{ $a ?? "none" }}]<s:include:inner />', 'blade.php');
        $this->viewShouldReturnRaw('inner', 'I[{{ $a ?? "none" }}]', 'blade.php');

        $this->assertSame('O[none]I[none]', Blade::render('<s:include:outer />', ['a' => 'CALLER']));
    }

    #[Test]
    public function a_param_does_not_leak_into_the_next_include()
    {
        $this->viewShouldReturnRaw('card', 'C[{{ $class ?? "none" }}]', 'blade.php');

        $this->assertSame(
            'C[cool]C[none]',
            Blade::render('<s:include:card class="cool" /><s:include:card />')
        );
    }

    #[Test]
    public function an_assignment_inside_an_include_does_not_leak_to_a_sibling()
    {
        $this->viewShouldReturnRaw('setter', '{{ v = "set" }}S');
        $this->viewShouldReturnRaw('getter', 'G[{{ v ?? "none" }}]');

        $this->assertSame('SG[none]', Blade::render('<s:include:setter /><s:include:getter />'));
    }

    #[Test]
    public function it_compiles_nested_includes()
    {
        $this->viewShouldReturnRaw('one', 'Just Some Text');
        $this->viewShouldReturnRaw('two', '{{ $slot }}');

        $template = <<<'BLADE'
<s:include:two>
  <s:include:one />
</s:include:two>
BLADE;

        $this->assertSame('Just Some Text', trim(Blade::render($template)));
    }
}
