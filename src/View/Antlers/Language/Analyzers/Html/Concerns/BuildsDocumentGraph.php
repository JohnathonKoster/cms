<?php

namespace Statamic\View\Antlers\Language\Analyzers\Html\Concerns;

use Statamic\View\Antlers\Language\Analyzers\Html\Arena;
use Statamic\View\Antlers\Language\Analyzers\Html\Region;
use Statamic\View\Antlers\Language\Nodes\AntlersNode as LanguageAntlersNode;

/**
 * Drives graph construction: tokenizes the parsed literal/Antlers
 * sequence and appends recovered nodes to the arena.
 */
trait BuildsDocumentGraph
{
    protected function build(array $nodes)
    {
        $this->indexAntlersByteRegions($nodes);
        $this->regionCursor = 0;
        $sourceLength = strlen($this->source);
        $position = 0;

        while ($position < $sourceLength) {
            if (isset($this->regionEnds[$position])) {
                $position = $this->appendAntlers($position, $this->currentContainer());

                continue;
            }

            if ($this->textElement !== null && $this->arena->name[$this->textElement] === 'plaintext') {
                $this->appendTextWithAntlers($position, $sourceLength, $this->currentContainer());
                $position = $sourceLength;

                continue;
            }

            if ($this->textElement !== null && ! $this->isCurrentRawTextClosingTag($position)) {
                $next = $this->nextRawTextBoundary($position);
                $this->appendTextWithAntlers($position, $next, $this->currentContainer());
                $position = $next;

                continue;
            }

            if ($this->source[$position] !== '<') {
                $nextMarkup = strpos($this->source, '<', $position);
                $nextAntlers = $this->regionStarts ? $this->nextRegionStart($position) : null;
                $next = min(
                    $nextMarkup === false ? $sourceLength : $nextMarkup,
                    $nextAntlers === null ? $sourceLength : $nextAntlers
                );
                $this->appendParsedText(substr($this->source, $position, $next - $position));
                $position = $next;

                continue;
            }

            $position = $this->consumeMarkup($position);
        }

        // Tokenization is complete; release build-only state. Region nodes
        // and starts remain for regions(), but the ends column, open stacks,
        // and foster bookkeeping are never consulted again.
        $this->regionEnds = [];
        $this->stack = [];
        $this->activeFormatting = [];
        $this->fosterCharacterTails = [];
    }

    protected function consumeMarkup($start)
    {
        $next = $this->source[$start + 1] ?? null;

        if ($next !== null
            && ! ctype_alpha($next)
            && ! in_array($next, ['!', '?', '/'], true)
            && ! isset($this->regionEnds[$start + 1])) {
            $this->appendParsedText('<');

            return $start + 1;
        }

        $end = $this->markupEnd($start);
        $markup = substr($this->source, $start, $end - $start);

        if ($next === '!' || $next === '?') {
            $this->appendOpaqueMarkup($start, $end, $markup);

            return $end;
        }

        $closing = $next === '/';
        $name = $this->tagName($start, $end, $closing);

        if ($closing && is_string($name)) {
            if ($this->openSelect !== null
                && $this->selectInTable
                && $this->isInHtmlSelectMode()
                && isset(self::$selectTableElements[$name])) {
                if (! $this->hasOpenElementInTableScope($name)) {
                    $this->appendOpaqueMarkup($start, $end, $markup);

                    return $end;
                }

                $this->closeOpenElement('select');
            }

            if ($this->openSelect !== null
                && $this->isInHtmlSelectMode()
                && ! in_array($name, ['option', 'optgroup', 'select', 'template'], true)) {
                $this->appendOpaqueMarkup($start, $end, $markup);

                return $end;
            }

            if ($name === 'form' && ! $this->hasOpenHtmlTemplate()) {
                if ($this->formElement !== null) {
                    $formIndex = array_search($this->formElement, $this->stack, true);

                    if ($formIndex !== false) {
                        $tableIndex = $this->nearestTableIndex();

                        if ($tableIndex === null || $formIndex > $tableIndex) {
                            $this->generateImpliedEndTags();
                            $formIndex = array_search($this->formElement, $this->stack, true);
                        }

                        if ($formIndex !== false) {
                            array_splice($this->stack, $formIndex, 1);
                        }
                    }

                    $this->formElement = null;
                }

                $this->appendOpaqueMarkup($start, $end, $markup);

                return $end;
            }

            if (isset(self::$formattingElements[$name])
                && $this->closeFormattingElement($name, $markup, $start, $end)) {
                return $end;
            }

            if (! $this->currentContainerIsForeign() && $name === 'p') {
                $match = $this->htmlEndTagMatchIndex($name);

                if ($match === null) {
                    $this->appendSyntheticElement('p');
                    $match = count($this->stack) - 1;
                }
            } elseif (! $this->currentContainerIsForeign()) {
                $match = $this->htmlEndTagMatchIndex($name);
            } else {
                $match = count($this->stack) - 1;

                if ($match < 0 || strcasecmp($this->arena->name[$this->stack[$match]], $name) !== 0) {
                    $match = $this->stackIndex($name);
                }
            }

            if ($match === null) {
                $this->appendOpaqueMarkup($start, $end, $markup);
            } else {
                if ($this->regionStarts) {
                    $this->attachEmbeddedRegions($this->stack[$match], $start, $end);
                }

                $this->setElementClosingMarkup($this->stack[$match], $markup);

                if ($this->stack[$match] === $this->textElement) {
                    $this->textElement = null;
                }

                $this->sliceOpenStack($match);

                if ($name === 'select') {
                    $this->openSelect = null;
                    $this->selectInTable = false;
                }
            }

            return $end;
        }

        if ($closing && $name === null) {
            for ($index = count($this->stack) - 1; $index >= 0; $index--) {
                if ($this->arena->name[$this->stack[$index]] === null) {
                    $this->attachEmbeddedRegions($this->stack[$index], $start, $end);
                    $this->setElementClosingMarkup($this->stack[$index], $markup);
                    $this->sliceOpenStack($index);

                    return $end;
                }
            }
        }

        if ($name === false || $closing) {
            $this->appendOpaqueMarkup($start, $end, $markup);

            return $end;
        }

        $sourcePositionContainer = $this->currentContainer();
        $sourcePositionAnchor = $this->arena->sourceAnchor[$sourcePositionContainer] ?? null;

        if ($this->openSelect !== null && $this->isInHtmlSelectMode()) {
            if ($this->selectInTable && isset(self::$selectTableElements[$name])) {
                $this->closeOpenElement('select');
            } elseif ($name === 'select') {
                $this->closeOpenElement('select');
                $this->appendOpaqueMarkup($start, $end, $markup);

                return $end;
            } elseif (in_array($name, ['input', 'keygen', 'textarea'], true)) {
                $this->closeOpenElement('select');
            } elseif (! in_array($name, ['option', 'optgroup', 'hr', 'script', 'template'], true)) {
                $this->appendOpaqueMarkup($start, $end, $markup);

                return $end;
            }
        }

        $sourceContainer = $this->currentContainer();
        [$sourceTable, $sourceTableTail] = $this->sourceTablePosition();

        if ($sourceContainer !== 0
            && ($this->arena->kind($sourceContainer) & (Arena::SVG | Arena::MATHML))
            && $this->shouldExitForeignContent($name, $markup)) {
            $this->exitForeignContent();
            $sourceContainer = $this->currentContainer();
        }

        $namespace = $sourceContainer === 0
            || ! ($this->arena->kind($sourceContainer) & (Arena::SVG | Arena::MATHML))
            ? ($name === 'svg' ? 'svg' : ($name === 'math' ? 'mathml' : 'html'))
            : $this->namespaceForForeignChild($name, $sourceContainer);

        if ($namespace === 'html'
            && $name === 'form'
            && $this->formElement !== null
            && ! $this->hasOpenHtmlTemplate()) {
            $this->appendOpaqueMarkup($start, $end, $markup);

            return $end;
        }

        if ($namespace === 'html'
            && isset(self::$tableStructureStarts[$name])
            && $this->tableStructureMode() === null
            && ! $this->hasOpenHtmlTemplate()) {
            $this->appendOpaqueMarkup($start, $end, $markup);

            return $end;
        }

        $selectInTable = $namespace === 'html'
            && $name === 'select'
            && in_array($this->tableStructureMode(), ['table', 'body', 'row', 'cell', 'caption', 'colgroup'], true);
        $tableForm = $namespace === 'html'
            && $name === 'form'
            && in_array($this->tableStructureMode(), ['table', 'body', 'row'], true);

        if ($this->hasSeenTable && $namespace === 'html' && $name !== null) {
            $foster = $this->prepareTableStart($name);
        } else {
            $sourceIsHtml = $sourceContainer === 0
                || ! ($this->arena->kind($sourceContainer) & (Arena::SVG | Arena::MATHML));
            $foster = $sourceIsHtml && $namespace !== 'html' && $this->shouldFosterContent();
        }

        if ($namespace === 'html' && $name === 'form') {
            $tableForm = in_array($this->tableStructureMode(), ['table', 'body', 'row'], true);
        }

        if ($name !== null && $namespace === 'html') {
            if (($name === 'a' || $name === 'nobr') && $this->activeFormattingIndex($name) !== null) {
                $formatting = $this->activeFormatting[$this->activeFormattingIndex($name)];
                $stackDepthBeforeFormattingClose = count($this->stack);
                $this->closeFormattingElement($name);
                $activeIndex = array_search($formatting, $this->activeFormatting, true);

                if ($activeIndex !== false) {
                    array_splice($this->activeFormatting, $activeIndex, 1);
                }

                $stackIndex = array_search($formatting, $this->stack, true);

                if ($stackIndex !== false) {
                    array_splice($this->stack, $stackIndex, 1);
                }

                if (! $foster
                    && count($this->stack) !== $stackDepthBeforeFormattingClose
                    && $this->hasSeenTable
                    && $this->shouldFosterContent()) {
                    $foster = $this->prepareTableStart($name);
                }
            }

            $stackDepthBeforeImpliedClose = count($this->stack);

            if (! $tableForm && $this->stack && (isset(self::$pClosingElements[$name])
                || isset(self::$impliedCloseTargets[$name])
                || isset(self::$headingElements[$name])
                || $name === 'button')) {
                $this->closeImpliedElements($name);
            }

            if (! $foster
                && count($this->stack) !== $stackDepthBeforeImpliedClose
                && $this->hasSeenTable
                && $this->shouldFosterContent()) {
                $foster = $this->prepareTableStart($name);
            }

            if ($this->activeFormatting
                && (! isset(self::$formattingBreakStarts[$name]) || in_array($name, ['button', 'select'], true))) {
                $this->reconstructActiveFormatting();

                if ($foster && $this->tableMode() === 'normal') {
                    $foster = false;
                }
            }
        }

        $selfClosing = $this->isSelfClosingTag($start, $end);
        $elementName = $namespace === 'svg' ? (self::$svgTagNames[$name] ?? $name) : $name;
        $element = $this->arena->element($elementName, $markup, $selfClosing, $namespace);
        if ($namespace === 'html' && $name === 'form' && ! $this->hasOpenHtmlTemplate()) {
            $this->formElement = $element;
        }

        if ($foster && $name === 'input' && strtolower((string) $this->handle($element)->attr('type')) === 'hidden') {
            $foster = false;
        }

        if ($tableForm) {
            $foster = false;
        }

        if ($foster) {
            $this->fosterNode($element, $sourceContainer);
        } else {
            $insertionContainer = $this->currentContainer();
            $this->arena->append($insertionContainer, $element);

            // If the preceding graph sibling was moved away from its literal
            // source position, this token followed it in the source stream as
            // well. Continue that anchored sequence beside the sibling's
            // source anchor instead of serializing this token at the recovered
            // tree position before (or after) an enclosing table.
            $previous = $this->arena->previous($element);

            $this->anchorRecoveredSourceNode(
                $element,
                $insertionContainer,
                $sourceTable,
                $sourceTableTail,
                $previous,
                $sourcePositionAnchor
            );
        }

        if ($this->regionStarts) {
            $this->attachEmbeddedRegions($element, $start, $end);
        }

        // `image` is not a void element, but the in-body insertion rules
        // rewrite the start tag to `img`, so in the HTML namespace it never
        // holds children; SVG's image element is a normal foreign element.
        $push = $namespace === 'html'
            ? ($name === null || (! isset(self::$voidElements[$name]) && $name !== 'image'))
            : ! $selfClosing;

        if ($tableForm) {
            $push = false;
        }

        if ($push) {
            $this->stack[] = $element;

            if ($namespace === 'html' && $name !== null && isset(self::$formattingElements[$name])) {
                $this->pushActiveFormattingElement($element);
            }

            if ($namespace === 'html' && $name === 'table') {
                $this->hasSeenTable = true;
            }

            if ($namespace === 'html' && $name === 'select') {
                $this->openSelect = $element;
                $this->selectInTable = $selectInTable;
            }

            if ($namespace === 'html' && isset(self::$formattingMarkerElements[$name])) {
                $this->activeFormatting[] = null;
            }

            if ($namespace === 'html' && $name === 'script') {
                $this->scriptEscape = 0;
            }

            if ($namespace === 'html'
                && $name !== null
                && (isset(self::$rawTextElements[$name]) || $name === 'plaintext')) {
                $this->textElement = $element;
            }
        }

        return $end;
    }

    protected function appendOpaqueMarkup($start, $end, $markup)
    {
        [$sourceTable, $sourceTableTail] = $this->sourceTablePosition();
        $type = 'markup';

        if (strncmp($markup, '<!--', 4) === 0) {
            $type = 'comment';
        } elseif (stripos($markup, '<!doctype') === 0) {
            $type = 'doctype';
        } elseif (strncmp($markup, '<!', 2) === 0) {
            $type = 'declaration';
        } elseif (strncmp($markup, '<?', 2) === 0) {
            $type = 'processing-instruction';
        }

        $opaque = $this->arena->opaque($markup, $type);
        $container = $this->currentContainer();
        $this->arena->append($container, $opaque);
        $this->anchorRecoveredSourceNode($opaque, $container, $sourceTable, $sourceTableTail);

        foreach ($this->regionsBetween($start, $end) as $region) {
            $graphNode = $this->makeAntlers($region['node'], $region['start'], $region['end']);
            $this->arena->embedded[$opaque][] = $graphNode;
            $this->arena->setParent($graphNode, $opaque);
            $this->arena->embeddedOwner[$graphNode] = $opaque;
        }
    }

    protected function appendTextWithAntlers($start, $end, $container)
    {
        $position = $start;

        while ($position < $end) {
            if (isset($this->regionEnds[$position])) {
                $position = $this->appendAntlers($position, $container);

                continue;
            }

            $next = $this->nextRegionStart($position);
            $next = $next === null || $next > $end ? $end : $next;
            $this->arena->append($container, $this->arena->text(substr($this->source, $position, $next - $position)));
            $position = $next;
        }
    }

    protected function appendAntlers($start, $container)
    {
        $end = $this->regionEnds[$start];
        $node = $this->makeAntlers($this->regionNodes[$start], $start, $end);
        [$sourceTable, $sourceTableTail] = $this->sourceTablePosition();

        if ($this->hasSeenTable && $this->tableMode() === 'colgroup') {
            array_pop($this->stack);
        }

        if ($this->textElement === null && $this->activeFormatting) {
            $this->reconstructActiveFormatting();
            $container = $this->currentContainer();
        }

        if ($this->hasSeenTable && $this->shouldFosterContent()) {
            $this->fosterNode($node, $container, true);
        } else {
            $this->arena->append($container, $node);
            $this->anchorRecoveredSourceNode($node, $container, $sourceTable, $sourceTableTail);
        }

        return $end;
    }

    protected function makeAntlers(LanguageAntlersNode $node, $start, $end)
    {
        $identity = spl_object_id($node);

        if (! isset($this->antlersNodes[$identity])) {
            $this->antlersNodes[$identity] = $this->arena->antlers(
                $node,
                substr($this->source, $start, $end - $start)
            );
        }

        return $this->antlersNodes[$identity];
    }

    protected function attachEmbeddedRegions($element, $start, $end)
    {
        if (empty($this->regionStarts)) {
            return;
        }

        foreach ($this->regionsBetween($start, $end) as $region) {
            $embedded = $this->makeAntlers($region['node'], $region['start'], $region['end']);
            $this->arena->embedded[$element][] = $embedded;
            $this->arena->setParent($embedded, $element);
            $this->arena->embeddedOwner[$embedded] = $element;
        }
    }

    protected function regionsBetween($start, $end)
    {
        if (empty($this->regionStarts)) {
            return [];
        }

        $regions = [];

        $index = $this->regionIndexAtOrAfter($start);

        for ($count = count($this->regionStarts); $index < $count; $index++) {
            $regionStart = $this->regionStarts[$index];

            if ($regionStart >= $end) {
                break;
            }

            $regions[] = [
                'start' => $regionStart,
                'end' => $this->regionEnds[$regionStart],
                'node' => $this->regionNodes[$regionStart],
            ];
        }

        return $regions;
    }

    protected function appendParsedText($text)
    {
        if ($text !== '') {
            [$sourceTable, $sourceTableTail] = $this->sourceTablePosition();

            if ($this->activeFormatting) {
                $this->reconstructActiveFormatting();
            }
            $container = $this->currentContainer();

            if ($this->hasSeenTable && $this->tableMode() === 'colgroup') {
                preg_match('/\A([\x09\x0A\x0C\x0D\x20]*)(.*)\z/s', $text, $matches);

                if ($matches[1] !== '') {
                    $this->arena->append($container, $this->arena->text($matches[1]));
                }

                if ($matches[2] === '') {
                    return;
                }

                array_pop($this->stack);
                $text = $matches[2];
            }

            $node = $this->arena->text($text);

            if ($this->hasSeenTable
                && $this->shouldFosterContent()
                && preg_match('/[^\x09\x0A\x0C\x0D\x20]/', $text)) {
                $this->fosterNode($node, $container, true);
            } else {
                $this->arena->append($container, $node);
                $this->anchorRecoveredSourceNode($node, $container, $sourceTable, $sourceTableTail);
            }
        }
    }

    protected function currentContainer()
    {
        return empty($this->stack) ? 0 : $this->stack[count($this->stack) - 1];
    }
}
