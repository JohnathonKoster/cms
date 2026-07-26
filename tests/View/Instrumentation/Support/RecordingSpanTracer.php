<?php

namespace Tests\View\Instrumentation\Support;

use Statamic\View\Instrumentation\Span;
use Statamic\View\Instrumentation\TracerContract;

class RecordingSpanTracer implements TracerContract
{
    public array $events = [];

    /** @var callable|null */
    public $log = null;

    protected int $handles = 0;

    public function __construct(public string $name = 'tracer')
    {
    }

    public function onEnter(Span $span): mixed
    {
        $this->events[] = ['enter', $span];

        if ($this->log) {
            call_user_func($this->log, 'enter');
        }

        return 'handle-'.(++$this->handles);
    }

    public function onExit(Span $span, mixed $handle, mixed $output): void
    {
        $this->events[] = ['exit', $span, $handle, $output];

        if ($this->log) {
            call_user_func($this->log, 'exit');
        }
    }

    public function onRenderComplete(): void
    {
        $this->events[] = ['complete'];
    }
}
