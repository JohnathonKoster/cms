<?php

namespace Tests\Antlers\Runtime;

use Illuminate\Support\Facades\Blade;
use Statamic\Facades\Cascade;
use Statamic\Statamic;
use Tests\Antlers\ParserTestCase;
use Tests\FakesViews;

/**
 * Verifies the examples shown in the include tag documentation actually work,
 * so the docs never present something broken. Antlers and Blade views use
 * distinct names so the two engines don't clobber each other's registration.
 *
 * @see https://github.com/statamic/docs content/collections/tags/include.md
 */
class IncludeDocsTest extends ParserTestCase
{
    use FakesViews;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withFakeViews();
        $this->artisan('view:clear');
    }

    private function antlers(string $template, array $data = []): string
    {
        return $this->renderString($template, $data, true);
    }

    private function renderBlade(string $template, array $data = []): string
    {
        return trim(Blade::render($template, $data));
    }

    private function squish(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value));
    }

    public function test_overview_basic_render()
    {
        $this->viewShouldReturnRaw('blog.card', 'The card.');
        $this->viewShouldReturnRaw('blog.widget', 'The widget.', 'blade.php');

        $this->assertSame('The card.', $this->antlers('{{ include:blog/card }}'));
        $this->assertSame('The widget.', $this->renderBlade('<s:include:blog/widget />'));
    }

    public function test_passing_data()
    {
        $flavors = ['Chocolate Chip Cookie Dough', 'Mint Chocolate Chip', 'Neon Mind Melter'];

        $this->viewShouldReturnRaw('list', '<h2>These are my {{ header }}</h2>{{ items | ul }}');
        $this->viewShouldReturnRaw('list_b', '<h2>These are my {{ $header }}</h2>{!! Statamic::modify($items)->ul() !!}', 'blade.php');

        $antlers = $this->antlers('{{ include:list header="favorite ice cream flavors" :items="flavors" }}', ['flavors' => $flavors]);
        $blade = $this->renderBlade('<s:include:list_b header="favorite ice cream flavors" :items="$flavors" />', ['flavors' => $flavors]);

        foreach ([$antlers, $blade] as $output) {
            $this->assertStringContainsString('<h2>These are my favorite ice cream flavors</h2>', $output);
            $this->assertStringContainsString('<li>Neon Mind Melter</li>', $output);
        }
    }

    public function test_spreading_an_array_of_data()
    {
        $author = ['name' => 'Jack', 'avatar' => '/jack.jpg'];

        $this->viewShouldReturnRaw('card', '<h2>{{ name }}</h2><img src="{{ avatar }}">');
        $this->viewShouldReturnRaw('card_b', '<h2>{{ $name }}</h2><img src="{{ $avatar }}">', 'blade.php');

        $expected = '<h2>Jack</h2><img src="/jack.jpg">';
        $this->assertSame($expected, $this->antlers('{{ include:card :params="author" }}', ['author' => $author]));
        $this->assertSame($expected, $this->renderBlade('<s:include:card_b :params="$author" />', ['author' => $author]));

        // Explicit parameters override the array.
        $this->assertSame(
            '<h2>A different name</h2><img src="/jack.jpg">',
            $this->antlers('{{ include:card :params="author" name="A different name" }}', ['author' => $author])
        );
    }

    public function test_params_must_be_an_associative_array()
    {
        $this->viewShouldReturnRaw('card', 'ok');

        $this->expectException(\RuntimeException::class);
        $this->antlers('{{ include:card :params="bad" }}', ['bad' => ['a', 'b']]);
    }

    public function test_the_params_variable()
    {
        $author = ['name' => 'Jack'];

        $this->viewShouldReturnRaw('card', '{{ params:name }}|{{ params:role }}');
        $this->viewShouldReturnRaw('card_b', "{{ \$params['name'] }}|{{ \$params['role'] }}", 'blade.php');

        $this->assertSame('Jack|Editor', $this->antlers('{{ include:card :params="author" role="Editor" }}', ['author' => $author]));
        $this->assertSame('Jack|Editor', $this->renderBlade('<s:include:card_b :params="$author" role="Editor" />', ['author' => $author]));
    }

    public function test_scope_isolation_nothing_leaks_in()
    {
        $this->viewShouldReturnRaw('widget', '<h2>{{ title }}</h2>');
        $this->viewShouldReturnRaw('widget_b', '<h2>{{ $title ?? "" }}</h2>', 'blade.php');

        // Not passed -> empty.
        $this->assertSame('<h2></h2>', $this->antlers('{{ title = "Dashboard" }}{{ include:widget }}'));
        $this->assertSame('<h2></h2>', $this->renderBlade('<s:include:widget_b />', ['title' => 'Dashboard']));

        // Passed -> available.
        $this->assertSame('<h2>Dashboard</h2>', $this->antlers('{{ include:widget title="Dashboard" }}'));
        $this->assertSame('<h2>Dashboard</h2>', $this->renderBlade('<s:include:widget_b title="Dashboard" />', ['title' => 'Outer']));
    }

    public function test_scope_isolation_nothing_leaks_out()
    {
        // Antlers idiom: a variable assigned inside the view stays inside it.
        $this->viewShouldReturnRaw('publisher', '{{ status = "published" }}Publishing as {{ status }}...');

        $output = $this->antlers('{{ status = "draft" }}{{ include:publisher }}|Status: {{ status }}');

        $this->assertSame('Publishing as published...|Status: draft', $output);
    }

    public function test_the_cascade()
    {
        Cascade::set('current_user', ['name' => 'David Hasselhoff']);

        $this->viewShouldReturnRaw('header', 'Welcome back, {{ current_user:name }}');
        $this->viewShouldReturnRaw('header_b', "Welcome back, {{ \$current_user['name'] ?? '' }}", 'blade.php');

        // Off by default.
        $this->assertSame('Welcome back,', trim($this->antlers('{{ include:header }}')));
        $this->assertSame('Welcome back,', $this->renderBlade('<s:include:header_b />'));

        // Opted in.
        $this->assertSame('Welcome back, David Hasselhoff', $this->antlers('{{ include:header cascade="true" }}'));
        $this->assertSame('Welcome back, David Hasselhoff', $this->renderBlade('<s:include:header_b cascade="true" />'));
    }

    public function test_front_matter_defaults()
    {
        $expected = '<img src="https://example.com/placeholder.png"><p>Written by David Hasselhoff</p>';

        $this->viewShouldReturnRaw('card', "---\nauthor: Jack McDade\nimage: https://example.com/placeholder.png\n---\n<img src=\"{{ view:image }}\"><p>Written by {{ view:author }}</p>");
        $this->assertSame($expected, $this->squish($this->antlers('{{ include:card author="David Hasselhoff" }}')));

        $this->viewShouldReturnRaw('card_b', "@frontmatter(['author' => 'Jack McDade', 'image' => 'https://example.com/placeholder.png'])\n<img src=\"{{ \$view['image'] }}\"><p>Written by {{ \$view['author'] }}</p>", 'blade.php');
        $this->assertSame($expected, $this->squish($this->renderBlade('<s:include:card_b author="David Hasselhoff" />')));
    }

    public function test_variable_prefixing()
    {
        $entry = ['hero_title' => 'Big Title', 'hero_subtitle' => 'Smaller'];

        $this->viewShouldReturnRaw('hero', '<h1>{{ title }}</h1><p>{{ subtitle }}</p>');
        $this->viewShouldReturnRaw('hero_b', '<h1>{{ $title }}</h1><p>{{ $subtitle }}</p>', 'blade.php');

        $expected = '<h1>Big Title</h1><p>Smaller</p>';
        $this->assertSame($expected, $this->antlers('{{ include:hero :params="entry" handle_prefix="hero_" }}', ['entry' => $entry]));
        $this->assertSame($expected, $this->renderBlade('<s:include:hero_b :params="$entry" handle_prefix="hero_" />', ['entry' => $entry]));
    }

    public function test_variable_prefixing_precedence()
    {
        $entry = ['hero_title' => 'Prefixed', 'title' => 'Plain'];

        $this->viewShouldReturnRaw('hero', '{{ title }}');

        $this->assertSame('Prefixed', $this->antlers('{{ include:hero :params="entry" handle_prefix="hero_" }}', ['entry' => $entry]));
        $this->assertSame('Win', $this->antlers('{{ include:hero :params="entry" handle_prefix="hero_" title="Win" }}', ['entry' => $entry]));
    }

    public function test_slots()
    {
        $this->viewShouldReturnRaw('modal', '<h2>{{ title }}</h2>{{ slot }}');
        $this->viewShouldReturnRaw('modal_b', '<h2>{{ $title }}</h2>{!! $slot !!}', 'blade.php');

        $this->assertSame(
            '<h2>Confirmation</h2><p>Body</p>',
            $this->squish($this->antlers('{{ include:modal title="Confirmation" }}<p>Body</p>{{ /include:modal }}'))
        );
        $this->assertSame(
            '<h2>Confirmation</h2><p>Body</p>',
            $this->squish($this->renderBlade('<s:include:modal_b title="Confirmation"><p>Body</p></s:include:modal_b>'))
        );
    }

    public function test_named_slots()
    {
        $this->viewShouldReturnRaw('card', '<header>{{ slot:header }}</header><main>{{ slot }}</main>');
        $this->viewShouldReturnRaw('card_b', '<header>{{ $header }}</header><main>{{ $slot }}</main>', 'blade.php');

        $this->assertSame(
            '<header><h1>Welcome</h1></header><main>Body</main>',
            $this->squish($this->antlers('{{ include:card }}{{ slot:header }}<h1>Welcome</h1>{{ /slot:header }}Body{{ /include:card }}'))
        );
        $this->assertSame(
            '<header><h1>Welcome</h1></header><main>Body</main>',
            $this->squish($this->renderBlade('<s:include:card_b><s:slot:header><h1>Welcome</h1></s:slot:header>Body</s:include:card_b>'))
        );
    }

    public function test_checking_for_slots()
    {
        $this->viewShouldReturnRaw('card', '{{ if slot:header }}<header>{{ slot:header }}</header>{{ else }}<header>Default heading</header>{{ /if }}');
        $this->viewShouldReturnRaw('card_b', '@if(isset($header))<header>{{ $header }}</header>@else<header>Default heading</header>@endif', 'blade.php');

        // Provided.
        $this->assertSame('<header>Hi</header>', $this->squish($this->antlers('{{ include:card }}{{ slot:header }}Hi{{ /slot:header }}{{ /include:card }}')));
        $this->assertSame('<header>Hi</header>', $this->squish($this->renderBlade('<s:include:card_b><s:slot:header>Hi</s:slot:header></s:include:card_b>')));

        // Absent -> fallback.
        $this->assertSame('<header>Default heading</header>', $this->squish($this->antlers('{{ include:card }}body{{ /include:card }}')));
        $this->assertSame('<header>Default heading</header>', $this->squish($this->renderBlade('<s:include:card_b>body</s:include:card_b>')));
    }

    public function test_scoped_slots()
    {
        $people = [['name' => 'Alice'], ['name' => 'Bob'], ['name' => 'Carol']];

        $this->viewShouldReturnRaw('list', '{{ rows }}{{ slot:row :name="name" :index="count" }}{{ /rows }}');
        $this->viewShouldReturnRaw('list_b', "@foreach(\$rows as \$person)<s:slot:row :name=\"\$person['name']\" :index=\"\$loop->iteration\" />@endforeach", 'blade.php');

        $antlers = $this->antlers('{{ include:list :rows="people" }}{{ slot:row }}{{ name }} - #{{ index }}{{ /slot:row }}{{ /include:list }}', ['people' => $people]);
        $blade = $this->renderBlade('<s:include:list_b :rows="$people"><s:slot:row>{{ $name }} - #{{ $index }}</s:slot:row></s:include:list_b>', ['people' => $people]);

        foreach ([$antlers, $blade] as $output) {
            $this->assertStringContainsString('Alice - #1', $output);
            $this->assertStringContainsString('Bob - #2', $output);
            $this->assertStringContainsString('Carol - #3', $output);
        }
    }

    public function test_conditional_rendering()
    {
        $this->viewShouldReturnRaw('subtitle', '<h2>{{ subtitle }}</h2>');
        $this->viewShouldReturnRaw('subtitle_b', '<h2>{{ $subtitle }}</h2>', 'blade.php');

        $this->assertSame('<h2>The subtitle</h2>', $this->squish($this->antlers('{{ include:subtitle :when="subtitle" subtitle="The subtitle" }}body{{ /include:subtitle }}', ['subtitle' => 'The subtitle'])));
        $this->assertSame('', $this->antlers('{{ include:subtitle :when="subtitle" }}x{{ /include:subtitle }}', ['subtitle' => false]));

        $this->assertSame('<h2>The subtitle</h2>', $this->squish($this->renderBlade('<s:include:subtitle_b :when="isset($subtitle)" subtitle="The subtitle" />', ['subtitle' => 'yes'])));
        $this->assertSame('', $this->renderBlade('<s:include:subtitle_b :when="isset($subtitle)" />'));
    }

    public function test_using_with_modifiers()
    {
        $this->viewShouldReturnRaw('component', '<p>one</p>   <p>two</p>');

        // Antlers: sub-expression modifiers.
        $this->assertSame(
            '<p>one</p><p>two</p>',
            $this->antlers('{{ { include:component } | spaceless }}')
        );

        // Blade: render the view and pass it through Statamic::modify().
        $this->assertSame(
            '<p>one</p><p>two</p>',
            (string) Statamic::modify(Statamic::tag('include:component')->fetch())->spaceless()
        );
    }

    public function test_include_exists()
    {
        $this->viewShouldReturnRaw('cards.author', 'content');

        $this->assertSame('It exists.', $this->squish($this->antlers('{{ if {include:exists src="cards/author"} }}It exists.{{ else }}It does not.{{ /if }}')));
        $this->assertSame('It does not.', $this->squish($this->antlers('{{ if {include:exists src="nope"} }}It exists.{{ else }}It does not.{{ /if }}')));

        $this->assertTrue(Statamic::tag('include:exists')->src('cards/author')->fetch());
        $this->assertFalse(Statamic::tag('include:exists')->src('nope')->fetch());
    }

    public function test_include_if_exists()
    {
        $this->viewShouldReturnRaw('cards.author', 'The author card.');

        $this->assertSame('The author card.', $this->antlers('{{ include:if_exists src="cards/author" }}'));
        $this->assertSame('', $this->antlers('{{ include:if_exists src="nope" }}'));

        $this->assertSame('The author card.', $this->renderBlade('<s:include:if_exists src="cards/author" />'));
        $this->assertSame('', $this->renderBlade('<s:include:if_exists src="nope" />'));
    }
}
