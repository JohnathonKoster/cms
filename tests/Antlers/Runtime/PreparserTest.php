<?php

namespace Tests\Antlers\Runtime;

use Statamic\Tags\Loader;
use Statamic\Tags\Tags;
use Statamic\View\Antlers\Language\Analyzers\Html\Document;
use Statamic\View\Antlers\Language\Analyzers\Html\Element;
use Statamic\View\Antlers\Language\Facades\Runtime;
use Statamic\View\Antlers\Language\Lexer\AntlersLexer;
use Statamic\View\Antlers\Language\Nodes\AbstractNode;
use Statamic\View\Antlers\Language\Parser\DocumentParser;
use Statamic\View\Antlers\Language\Parser\LanguageParser;
use Statamic\View\Antlers\Language\Runtime\EnvironmentDetails;
use Statamic\View\Antlers\Language\Runtime\NodeProcessor;
use Statamic\View\Antlers\Language\Runtime\RuntimeParser;
use Statamic\View\Antlers\Language\Runtime\Tracing\NodeVisitorContract;
use Statamic\View\Antlers\Language\Utilities\StringUtilities;
use Tests\Antlers\ParserTestCase;
use Tests\Antlers\Runtime\Support\SpacelessPrepass;
use Tests\Antlers\Runtime\Support\WhitespaceTrimmer;
use Tests\FakesViews;

class PreparserTest extends ParserTestCase
{
    use FakesViews;

    public function test_source_transformers_run_before_component_compilation_and_preparsers_remain_after_it()
    {
        $authoredSource = null;
        $compiledSource = null;
        $parser = $this->parser([], true);

        $parser->beforeComponentCompilation(function ($source) use (&$authoredSource) {
            $authoredSource = $source;
            $html = Document::parse($source);
            $html->first('x-alert')->setAttribute('title', 'Transformed');

            return $html->toHtml();
        });

        $parser->preparse(function ($source) use (&$compiledSource) {
            $compiledSource = $source;

            return $source;
        });

        $result = (string) $parser->parse('<x-alert title="Original" />');

        $this->assertSame('<x-alert title="Original" />', $authoredSource);
        $this->assertStringNotContainsString('<x-alert', $compiledSource);
        $this->assertStringContainsString('%component_proxy:index', $compiledSource);
        $this->assertStringContainsString('title="Transformed"', $compiledSource);
        $this->assertSame('<p>The Alert: Transformed</p>', $result);
    }

    public function test_source_transformers_registered_through_runtime_are_used_by_the_normal_view_render_path()
    {
        // Resolve the view engine and its parser before registering the hook.
        $this->withFakeViews();
        $this->viewShouldReturnRaw('source-transform', '<p>Hello, {{ world }}</p>');

        Runtime::beforeComponentCompilation(function ($source) {
            $html = Document::parse($source);
            $html->first('p')->setAttribute('data-greeting', 'true');

            return $html->toHtml();
        });

        $result = view('source-transform', ['world' => 'World'])->render();

        $this->assertSame('<p data-greeting="true">Hello, World</p>', $result);
    }

    public function test_visitors_registered_after_the_parser_resolves_are_used_immediately()
    {
        $parser = app(\Statamic\Contracts\View\Antlers\Parser::class);
        $visitor = new RecordingNodeVisitor;

        Runtime::addVisitor($visitor);
        $parser->parse('{{ title }}', ['title' => 'Hello']);

        $this->assertNotEmpty($visitor->nodes);
    }

    public function test_source_transformers_can_modify_statamic_component_attributes()
    {
        (new class extends Tags
        {
            protected static $handle = 'source_transform_test';

            public function index()
            {
                return $this->params->get('value');
            }
        })::register();

        $parser = $this->parser([], true);

        $parser->beforeComponentCompilation(function ($source) {
            $html = Document::parse($source);
            $html->first('s:source_transform_test')->setAttribute('value', 'Transformed');

            return $html->toHtml();
        });

        $result = (string) $parser->parse('<s:source_transform_test value="Original" />');

        $this->assertSame('Transformed', $result);
    }

    public function test_source_transformers_preserve_supported_directives()
    {
        $compiledSource = null;
        $parser = $this->parser([], true);
        $parser->beforeComponentCompilation(function ($source) {
            $html = Document::parse($source);
            $html->first('p')->setAttribute('data-transformed', 'true');

            return $html->toHtml();
        });
        $parser->preparse(function ($source) use (&$compiledSource) {
            $compiledSource = $source;

            return '';
        });

        $parser->parse(<<<'ANTLERS'
@props(['title' => 'Default'])
@aware(['subtitle'])
@cascade(['heading'])
<p>{{ title }}</p>
ANTLERS);

        $this->assertStringContainsString("@props(['title' => 'Default'])", $compiledSource);
        $this->assertStringContainsString("@aware(['subtitle'])", $compiledSource);
        $this->assertStringContainsString("@cascade(['heading'])", $compiledSource);
        $this->assertStringContainsString('<p data-transformed="true">{{ title }}</p>', $compiledSource);
    }

    public function test_source_transformers_are_carried_into_isolated_runtimes()
    {
        $parser = $this->parser()->isolateRuntimes(true);
        $parser->beforeComponentCompilation(function ($source) {
            return str_replace('{{ title }}', '{{ subtitle }}', $source);
        });

        $result = (string) $parser->parse('{{ title }}', [
            'title' => 'Title',
            'subtitle' => 'Subtitle',
        ]);

        $this->assertSame('Subtitle', $result);
    }

    public function test_source_transformers_can_trim_selected_element_boundaries_without_changing_sensitive_content()
    {
        $parser = $this->parser([], true);
        $parser->beforeComponentCompilation(function ($source) {
            $html = Document::parse($source);
            (new WhitespaceTrimmer(
                [],
                fn (Element $element) => $element->hasAttribute('data-preserve-whitespace')
            ))->trim(
                $html->elements()->filter(
                    fn (Element $element) => $element->hasAttribute('data-trim')
                )
            );

            return $html->toHtml();
        });

        $result = (string) $parser->parse(
            '<div>'
            .'<p data-trim>  Hello, <strong>{{ world }}</strong>!  </p>'
            .'<p>  Not selected.  </p>'
            .'<pre data-trim>  Whitespace is meaningful.  </pre>'
            .'<p data-trim data-preserve-whitespace>  Project-specific preservation.  </p>'
            .'</div>',
            ['world' => 'World']
        );

        $this->assertSame(
            '<div>'
            .'<p data-trim>Hello, <strong>World</strong>!</p>'
            .'<p>  Not selected.  </p>'
            .'<pre data-trim>  Whitespace is meaningful.  </pre>'
            .'<p data-trim data-preserve-whitespace>  Project-specific preservation.  </p>'
            .'</div>',
            $result
        );
    }

    public function test_a_source_transformer_can_implement_a_compile_time_spaceless_pair()
    {
        $parser = $this->parser([], true);
        $parser->beforeComponentCompilation(new SpacelessPrepass);

        $result = (string) $parser->parse(<<<'ANTLERS'
Before
{{ spaceless sensitive="code" }}
    <section>
        <p>  Hello, <strong>  {{ world }}  </strong>!  </p>
        <code>  Project-sensitive whitespace.  </code>
        <pre>  Native whitespace.  </pre>
    </section>
{{ /spaceless }}
After
ANTLERS, ['world' => 'World']);

        $this->assertSame(<<<'HTML'
Before
<section><p>Hello, <strong>World</strong>!</p>
        <code>  Project-sensitive whitespace.  </code>
        <pre>  Native whitespace.  </pre></section>
After
HTML, $result);
        $this->assertStringNotContainsString('spaceless', $result);
    }

    public function test_compile_time_spaceless_parameters_must_be_static()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The [sensitive] parameter on the [spaceless] HTML region must be static.'
        );

        (new SpacelessPrepass)(
            '{{ spaceless :sensitive="preserved_elements" }}<p>  Hello.  </p>{{ /spaceless }}'
        );
    }

    public function test_compile_time_spaceless_parameters_cannot_contain_interpolations()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The [sensitive] parameter on the [spaceless] HTML region must be static.'
        );

        (new SpacelessPrepass)(
            '{{ spaceless sensitive="{{ preserved_elements }}" }}<p>  Hello.  </p>{{ /spaceless }}'
        );
    }

    public function test_compile_time_spaceless_pairs_rewrite_component_slot_content_before_compilation()
    {
        $parser = $this->parser([], true);
        $parser->beforeComponentCompilation(new SpacelessPrepass);

        $result = (string) $parser->parse(
            '{{ spaceless }} <x-basic> <p>  Hello, {{ world }}!  </p> </x-basic> {{ /spaceless }}',
            ['world' => 'World']
        );

        $this->assertSame('<p>Hello, World!</p>', $result);
        $this->assertStringNotContainsString('spaceless', $result);
    }

    public function test_compile_time_spaceless_pairs_rewrite_statamic_component_content_before_compilation()
    {
        (new class extends Tags
        {
            protected static $handle = 'spaceless_component_test';

            public function index()
            {
                return $this->parse();
            }
        })::register();

        $parser = $this->parser([], true);
        $parser->beforeComponentCompilation(new SpacelessPrepass);

        $result = (string) $parser->parse(
            '{{ spaceless }} '
            .'<s:spaceless_component_test> <p>  Hello, {{ world }}!  </p> </s:spaceless_component_test>'
            .' {{ /spaceless }}',
            ['world' => 'World']
        );

        $this->assertSame('<p>Hello, World!</p>', $result);
        $this->assertStringNotContainsString('spaceless', $result);
    }

    public function test_pre_parser_can_modify_text()
    {
        $data = [
            'title' => 'hello',
            'subtitle' => 'world',
        ];

        $text = <<<'EOT'
{{ title }}
EOT;

        $documentParser = new DocumentParser();
        $loader = new Loader();
        $envDetails = new EnvironmentDetails();

        $processor = new NodeProcessor($loader, $envDetails);
        $processor->setData($data);

        $runtimeParser = new RuntimeParser($documentParser, $processor, new AntlersLexer(), new LanguageParser());
        $runtimeParser->preparse(function ($text) {
            return str_replace('{{ title }}', '{{ subtitle | upper }}', $text);
        });

        $result = StringUtilities::normalizeLineEndings((string) $runtimeParser->parse($text, $data));
        $this->assertSame('WORLD', $result);
    }
}

class RecordingNodeVisitor implements NodeVisitorContract
{
    public array $nodes = [];

    public function visit(AbstractNode $node)
    {
        $this->nodes[] = $node;
    }
}
