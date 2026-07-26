<?php

namespace Tests\Antlers\Runtime;

use Statamic\View\Antlers\Language\Exceptions\AntlersException;
use Statamic\View\Antlers\Language\Runtime\RuntimeConfiguration;
use Tests\Antlers\ParserTestCase;

class RuntimeConfigurationTest extends ParserTestCase
{
    public function test_unpaired_loops_will_throw_fatal_error_when_configured()
    {
        $config = new RuntimeConfiguration();
        $config->fatalErrorOnUnpairedLoop = true;

        $vars = ['test' => ['one', 'two', 'three']];

        $this->expectException(AntlersException::class);
        $this->renderStringWithConfiguration('{{ test }}', $config, $vars);
    }

    public function test_a_preparser_registered_on_both_the_parser_and_the_configuration_runs_once()
    {
        $calls = 0;
        $preparser = function ($template) use (&$calls) {
            $calls++;

            return $template;
        };

        $config = new RuntimeConfiguration();
        $config->preparse($preparser);

        $parser = $this->parser();
        $parser->setRuntimeConfiguration($config);

        $parser->preparse($preparser);
        $parser->parse('{{ title }}', ['title' => 'Hello']);

        $this->assertSame(1, $calls);
    }

    public function test_preparsers_are_read_live_from_the_configuration()
    {
        $config = new RuntimeConfiguration();

        $parser = $this->parser();
        $parser->setRuntimeConfiguration($config);

        // Registered after the parser already had the configuration.
        $config->preparse(fn ($template) => str_replace('{{ title }}', 'replaced', $template));

        $this->assertSame('replaced', (string) $parser->parse('{{ title }}', ['title' => 'Hello']));
    }

    public function test_removing_a_preparser_stops_it_running()
    {
        $preparser = fn ($template) => str_replace('{{ title }}', 'replaced', $template);

        $config = new RuntimeConfiguration();
        $config->preparse($preparser);

        $parser = $this->parser();
        $parser->setRuntimeConfiguration($config);

        $config->removePreparser($preparser);

        $this->assertSame('Hello', (string) $parser->parse('{{ title }}', ['title' => 'Hello']));
    }
}
