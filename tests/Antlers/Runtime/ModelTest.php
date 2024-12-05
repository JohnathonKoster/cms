<?php

namespace Tests\Antlers\Runtime;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Tags\Tags;
use Tests\Antlers\ParserTestCase;

class ModelTest extends ParserTestCase
{
    protected function model()
    {
        $model = new FakeModel;
        $model->title = 'foo';

        return $model;
    }

    #[Test, DataProvider('modelProvider')]
    public function attributes_are_returned($attribute, $expected)
    {
        $model = $this->model();

        $this->assertSame($expected, $this->renderString("{{ model:$attribute }}", ['model' => $model]));

        $this->assertSame($expected, $this->renderString("{{ nested:model:$attribute }}", [
            'nested' => ['model' => $model],
        ]));
    }

    #[Test]
    public function models_can_be_used_in_tag_pairs()
    {
        $model = $this->model();

        $template = <<<'ANTLERS'
{{ model }}
{{ title }} {{ alfa_bravo }} {{ delta_echo }}
{{ /model }}
ANTLERS;

        $this->assertSame(
            'foo charlie foxtrot',
            Str::squish($this->renderString($template, ['model' => $model]))
        );

        $template = <<<'ANTLERS'
{{ nested:model }}
{{ title }} {{ alfa_bravo }} {{ delta_echo }}
{{ /nested:model }}
ANTLERS;

        $this->assertSame(
            'foo charlie foxtrot',
            Str::squish($this->renderString($template, ['nested' => ['model' => $model]]))
        );
    }

    #[Test]
    public function variable_references_receive_models()
    {
        (new class extends Tags
        {
            public static $handle = 'tag';

            public function index()
            {
                $src = $this->params->get('value');

                return $src instanceof Model ? 'Yes' : 'No';
            }
        })::register();

        $model = $this->model();

        $this->assertSame(
            'Yes',
            $this->renderString('{{ %tag :value="model" }}', ['model' => $model])
        );
    }

    public static function modelProvider()
    {
        return [
            'column' => ['title', 'foo'],
            'accessor' => ['alfa_bravo', 'charlie'],
            'old accessor' => ['delta_echo', 'foxtrot'],
        ];
    }
}

class FakeModel extends \Illuminate\Database\Eloquent\Model
{
    public function alfaBravo(): Attribute
    {
        return Attribute::make(
            get: fn () => 'charlie',
        );
    }

    public function getDeltaEchoAttribute()
    {
        return 'foxtrot';
    }
}
