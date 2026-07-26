<?php

namespace Statamic\View\Instrumentation\Concerns;

use Statamic\View\Antlers\Language\Runtime\GlobalRuntimeState;
use Statamic\View\Instrumentation\HtmlContext;
use Statamic\View\Instrumentation\TemplateRegion;

/**
 * The engine-neutral emission core: safety gates, marker and attribute
 * assembly, skip reporting, and single-pass byte splicing.
 */
trait EmitsInstrumentation
{
    /**
     * The engine-neutral emission core: walks analyzed regions, applies the
     * configured strategies through the shared safety gates, reports skips,
     * and splices all edits in one pass.
     *
     * @param  string  $template
     * @param  array{regions: TemplateRegion[], elements: array<int, array{insertAt: int, hasAttribute: callable}>}  $analysis
     * @return string
     */
    protected function emitInstrumentation(
        $template,
        array $analysis,
        &$nextId = null,
        $commentsAtDocumentRoot = true
    ) {
        $edits = [];
        $elementMetadata = [];
        $deferredSkips = [];
        $nextId ??= 0;

        foreach ($analysis['regions'] as $region) {
            $metadata = $this->regionMetadata($region);

            if ($region->type !== TemplateRegion::TYPE_ANTLERS_BLOCK
                && $this->nodeFilter !== null
                && ! call_user_func($this->nodeFilter, $metadata, $region)) {
                $this->reportSkip($metadata, $region, self::SKIP_FILTERED);

                continue;
            }

            if ($region->type === TemplateRegion::TYPE_ANTLERS_BLOCK) {
                // The block's interior is Antlers, instrumented by the
                // Antlers pipeline — but only when the block itself sits in
                // surroundings where injected markup is safe.
                if (! $region->commentSafe) {
                    $this->reportSkip($metadata, $region, $this->skipReasonFor($region));

                    continue;
                }

                $inner = substr($template, $region->innerByteStart, $region->innerByteEnd - $region->innerByteStart);
                $lineOffset = substr_count(substr($template, 0, $region->innerByteStart), "\n");
                $innerCommentsAtDocumentRoot = $commentsAtDocumentRoot
                    || $region->context()->elementStack !== [];
                $instrumented = $this->instrumentAntlersWithLineOffset(
                    $inner,
                    $lineOffset,
                    $innerCommentsAtDocumentRoot,
                    $nextId
                );

                if ($instrumented !== $inner) {
                    $edits[] = [
                        'start' => $region->innerByteStart,
                        'remove' => $region->innerByteEnd - $region->innerByteStart,
                        'content' => $instrumented,
                        'type' => 'replace',
                        'nodeStart' => $region->innerByteStart,
                    ];
                }

                continue;
            }

            $emitsComments = $this->commentsEnabled && $region->commentSafe;
            $emitsAttributes = ! empty($this->attributeLayers) && $region->context()->canInstrumentElement();

            if (! $emitsComments && ! $emitsAttributes) {
                $this->reportSkip($metadata, $region, $this->skipReasonFor($region));

                continue;
            }

            // A per-template node id correlates a pair's start and end
            // markers with each other and with attribute-layer entries. Only
            // regions that reach output consume one, so ids stay contiguous.
            $metadata['id'] = ++$nextId;
            $contributed = false;

            if ($emitsComments) {
                [$start, $end] = $this->markersFor($metadata);

                if ($start !== '') {
                    $edits[] = ['start' => $region->byteStart, 'remove' => 0, 'content' => $start, 'type' => 'start', 'nodeStart' => $region->byteStart];
                }

                if ($end !== '') {
                    $edits[] = ['start' => $region->byteEnd, 'remove' => 0, 'content' => $end, 'type' => 'end', 'nodeStart' => $region->byteStart];
                }

                $contributed = $start !== '' || $end !== '';
            }

            if ($emitsAttributes) {
                $elementMetadata[$region->context()->elementStartOffset][] = $metadata;
                $deferredSkips[$region->context()->elementStartOffset][] = [$metadata, $region, $contributed];
                $contributed = true;
            }

            if (! $contributed) {
                $this->reportSkip($metadata, $region, $this->skipReasonFor($region));
            }
        }

        foreach ($elementMetadata as $elementKey => $metadataList) {
            $element = $analysis['elements'][$elementKey] ?? null;

            if ($element === null) {
                $this->reportDeferredSkips($deferredSkips[$elementKey] ?? []);

                continue;
            }

            $attributes = '';

            foreach ($this->attributeLayers as $name => $valueFactory) {
                if (($element['hasAttribute'])($name)) {
                    continue;
                }

                $value = $valueFactory($metadataList);

                if ($value !== null) {
                    $attributes .= ' '.$name.'="'.htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
                }
            }

            if ($attributes !== '') {
                $edits[] = ['start' => $element['insertAt'], 'remove' => 0, 'content' => $attributes, 'type' => 'attribute', 'nodeStart' => $element['insertAt']];
            } else {
                $this->reportDeferredSkips($deferredSkips[$elementKey] ?? []);
            }
        }

        return $this->spliceByteEdits($template, $edits);
    }

    /**
     * @param  array<int, array{0: array<string, mixed>, 1: TemplateRegion, 2: bool}>  $entries
     * @return void
     */
    protected function reportDeferredSkips(array $entries)
    {
        foreach ($entries as [$metadata, $region, $alreadyContributed]) {
            if (! $alreadyContributed) {
                $this->reportSkip($metadata, $region, $this->skipReasonFor($region));
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function regionMetadata(TemplateRegion $region)
    {
        $metadata = [
            'type' => $region->type === TemplateRegion::TYPE_SINGLE ? 'single' : 'pair',
            'engine' => $region->engine,
            'expression' => $region->expression,
            'line' => $region->line,
            'start' => $region->start,
            'end' => $region->end,
        ];

        return $this->appendContextualMetadata($metadata, $region->context());
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return void
     */
    protected function reportSkip(array $metadata, TemplateRegion $region, $reason)
    {
        if ($this->skipCallback !== null) {
            call_user_func($this->skipCallback, $metadata, $region, $reason);
        }
    }

    /**
     * Classifies why a region produced no output, most specific reason first.
     *
     * @return string One of the SKIP_* constants.
     */
    protected function skipReasonFor(TemplateRegion $region)
    {
        $context = $region->context();

        if ($context->kind === HtmlContext::KIND_COMMENT) {
            return self::SKIP_INSIDE_HTML_COMMENT;
        }

        if ($context->kind === HtmlContext::KIND_DOCTYPE) {
            return self::SKIP_INSIDE_DOCTYPE;
        }

        if ($context->kind === HtmlContext::KIND_ELEMENT_NAME
            || in_array(HtmlContext::DYNAMIC_ELEMENT, $context->elementStack, true)) {
            return self::SKIP_DYNAMIC_MARKUP;
        }

        if ($context->isClosingTag) {
            return self::SKIP_CLOSING_TAG_MARKUP;
        }

        // The opening boundary itself was comment-safe, so a pair can only
        // have failed because its boundaries cannot share a container.
        if ($this->commentsEnabled && $region->isPair() && $context->isSafeForHtmlComments()) {
            return self::SKIP_CROSS_CONTAINER_PAIR;
        }

        return self::SKIP_NO_STRATEGY;
    }

    /**
     * Appends the context-derived and configured metadata shared by every
     * engine's payload entries.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    protected function appendContextualMetadata(array $metadata, HtmlContext $context)
    {
        $metadata['context'] = $context->kind;

        if ($context->elementName !== null) {
            $metadata['element'] = $context->elementName;
        }

        if ($context->attributeName !== null) {
            $metadata['attribute'] = $context->attributeName;
        }

        // When invoked as a preparser during a view render, the runtime has
        // already recorded which template file is being processed.
        $view = GlobalRuntimeState::$currentExecutionFile;

        if (is_string($view) && $view !== '') {
            $metadata['view'] = $this->publicViewIdentifier($view);
        }

        return array_merge($metadata, $this->extraMetadata);
    }

    /**
     * Avoid publishing an application's absolute filesystem root in HTML.
     * Relative identifiers remain unchanged; files beneath the application
     * root are reported relative to it, and external files use their basename.
     */
    protected function publicViewIdentifier($view)
    {
        $normalized = str_replace('\\', '/', $view);

        if (! preg_match('#^(?:[A-Za-z]:/|/)#', $normalized)) {
            return $normalized;
        }

        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        $comparisonView = DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
        $comparisonBase = DIRECTORY_SEPARATOR === '\\' ? strtolower($base) : $base;

        if (str_starts_with($comparisonView, $comparisonBase)) {
            return substr($normalized, strlen($base));
        }

        return basename($normalized);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{0: string, 1: string}
     */
    protected function markersFor(array $metadata)
    {
        if ($this->commentFactory !== null) {
            $markers = call_user_func($this->commentFactory, $metadata);

            return [$markers[0] ?? '', $markers[1] ?? ''];
        }

        $json = json_encode($metadata, JSON_INVALID_UTF8_SUBSTITUTE);
        $encoded = base64_encode($json === false ? '{}' : $json);
        $id = isset($metadata['id']) ? ' '.$metadata['id'] : '';

        return [
            '<!-- '.$this->commentPrefix.':start '.$encoded.' -->',
            '<!-- '.$this->commentPrefix.':end'.$id.' -->',
        ];
    }

    /**
     * Stitches edits — insertions and replacements addressed by byte
     * offset — into the template in one linear pass.
     *
     * @param  string  $template
     * @param  array<int, array{start: int, remove: int, content: string, type: string, nodeStart: int}>  $edits
     * @return string
     */
    protected function spliceByteEdits($template, array $edits)
    {
        if (empty($edits)) {
            return $template;
        }

        $order = ['end' => 0, 'attribute' => 1, 'start' => 2, 'replace' => 3];

        usort($edits, function ($left, $right) use ($order) {
            if ($left['start'] !== $right['start']) {
                return $left['start'] <=> $right['start'];
            }

            $leftType = $order[$left['type']];
            $rightType = $order[$right['type']];

            if ($leftType !== $rightType) {
                return $leftType <=> $rightType;
            }

            if ($left['type'] === 'end') {
                return $right['nodeStart'] <=> $left['nodeStart'];
            }

            return $left['nodeStart'] <=> $right['nodeStart'];
        });

        $result = '';
        $previous = 0;

        foreach ($edits as $edit) {
            // Edits are non-overlapping by construction; clamping keeps a
            // future overlap from silently copying the wrong slice.
            $result .= substr($template, $previous, max(0, $edit['start'] - $previous));
            $result .= $edit['content'];
            $previous = max($previous, $edit['start'] + $edit['remove']);
        }

        return $result.substr($template, $previous);
    }
}
