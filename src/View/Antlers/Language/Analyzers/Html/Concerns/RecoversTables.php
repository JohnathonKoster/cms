<?php

namespace Statamic\View\Antlers\Language\Analyzers\Html\Concerns;

use Statamic\View\Antlers\Language\Analyzers\Html\Arena;

/**
 * HTML5 table recovery: insertion modes, stack clearing, synthetic
 * sections, and foster parenting of misplaced content.
 */
trait RecoversTables
{
    protected function sourceTablePosition()
    {
        if (! $this->hasSeenTable) {
            return [null, null];
        }

        $index = $this->nearestTableIndex();
        $table = $index === null ? null : $this->stack[$index];

        return [$table, $table === null ? null : $this->arena->lastChild($table)];
    }

    protected function prepareTableStart($name)
    {
        if (++$this->tableStartDepth > 32) {
            $this->tableStartDepth--;

            return false;
        }

        try {
            if (! $this->hasSeenTable) {
                return false;
            }

            $mode = $this->tableMode();
            $recoveredThroughContent = false;

            if ($mode === 'normal'
                && (isset(self::$tableStructuralElements[$name])
                    || in_array($name, ['table', 'col', 'tr', 'td', 'th'], true))) {
                $mode = $this->tableStructureMode();
                $recoveredThroughContent = $mode !== 'normal';
            }

            if ($mode === null || $mode === 'normal') {
                return false;
            }

            // Template start tags are handled using the "in head" rules from
            // every table insertion mode, so they never close a colgroup or get
            // foster-parented out of a row/section.
            if ($name === 'template') {
                return false;
            }

            if ($recoveredThroughContent) {
                if ($mode === 'table') {
                    $this->clearStackBackToTable();
                } elseif ($mode === 'body' && in_array($name, ['tr', 'td', 'th'], true)) {
                    $this->clearStackBackToTableSection();
                } elseif ($mode === 'row' && ($name === 'td' || $name === 'th')) {
                    $this->clearStackBackToTableRow();
                }
            }

            if ($mode === 'cell' || $mode === 'caption') {
                if (! isset(self::$tableStructuralElements[$name])
                    && ! in_array($name, ['col', 'tr', 'td', 'th'], true)) {
                    return false;
                }

                if ($mode === 'cell') {
                    $this->closeCurrentTableCell();
                } else {
                    $this->clearStackBackToTable();
                }

                return $this->prepareTableStart($name);
            }

            if ($mode === 'table') {
                if ($name === 'table') {
                    $tableIndex = $this->nearestTableIndex();
                    $this->sliceOpenStack($tableIndex);

                    return false;
                }

                if (isset(self::$allowedInTable[$name])) {
                    return false;
                }

                if ($name === 'col') {
                    $this->appendSyntheticElement('colgroup');

                    return false;
                }

                if ($name === 'tr') {
                    $this->appendSyntheticElement('tbody');

                    return false;
                }

                if ($name === 'td' || $name === 'th') {
                    $this->appendSyntheticElement('tbody');
                    $this->appendSyntheticElement('tr');

                    return false;
                }

                return true;
            }

            if ($mode === 'body') {
                if ($name === 'tr' || $name === 'style' || $name === 'script') {
                    return false;
                }

                if ($name === 'td' || $name === 'th') {
                    $this->appendSyntheticElement('tr');

                    return false;
                }

                if (isset(self::$tableStructuralElements[$name]) || $name === 'table' || $name === 'col') {
                    $this->clearStackBackToTable();

                    return $this->prepareTableStart($name);
                }

                return true;
            }

            if ($mode === 'row') {
                if ($name === 'td' || $name === 'th' || $name === 'tr'
                    || $name === 'style' || $name === 'script') {
                    return false;
                }

                if (isset(self::$tableStructuralElements[$name]) || $name === 'table' || $name === 'col') {
                    $this->clearStackBackToTable();

                    return $this->prepareTableStart($name);
                }

                return true;
            }

            if ($mode === 'colgroup') {
                if ($name === 'col') {
                    return false;
                }

                array_pop($this->stack);

                return $this->prepareTableStart($name);
            }

            return false;
        } finally {
            $this->tableStartDepth--;
        }
    }

    protected function shouldFosterContent()
    {
        if (! $this->hasSeenTable) {
            return false;
        }

        $mode = $this->tableMode();

        return $mode === 'table' || $mode === 'body' || $mode === 'row';
    }

    protected function tableMode()
    {
        $tableIndex = $this->nearestTableIndex();

        if ($tableIndex === null) {
            return null;
        }

        for ($index = count($this->stack) - 1; $index > $tableIndex; $index--) {
            $element = $this->stack[$index];

            if ($this->arena->kind($element) & (Arena::SVG | Arena::MATHML)) {
                return 'normal';
            }

            $name = strtolower((string) $this->arena->name[$element]);

            if ($name === 'td' || $name === 'th') {
                return 'cell';
            }

            if ($name === 'caption') {
                return 'caption';
            }

            if ($name === 'tr') {
                return 'row';
            }

            if ($name === 'colgroup') {
                return 'colgroup';
            }

            if (isset(self::$tableSections[$name])) {
                return 'body';
            }

            if ($this->arena->kind($this->stack[$index]) & Arena::SYNTHETIC) {
                if ($this->arena->parent($this->stack[$index]) !== $this->stack[$tableIndex]) {
                    return 'normal';
                }
            } else {
                return 'normal';
            }
        }

        return 'table';
    }

    protected function tableStructureMode()
    {
        $tableIndex = $this->nearestTableIndex();

        if ($tableIndex === null) {
            return null;
        }

        for ($index = count($this->stack) - 1; $index > $tableIndex; $index--) {
            $element = $this->stack[$index];

            if ($this->arena->kind($element) & (Arena::SVG | Arena::MATHML)) {
                continue;
            }

            $name = strtolower((string) $this->arena->name[$element]);

            if ($name === 'template') {
                return 'normal';
            }

            if ($name === 'td' || $name === 'th') {
                return 'cell';
            }

            if ($name === 'caption') {
                return 'caption';
            }

            if ($name === 'tr') {
                return 'row';
            }

            if ($name === 'colgroup') {
                return 'colgroup';
            }

            if (isset(self::$tableSections[$name])) {
                return 'body';
            }
        }

        return 'table';
    }

    protected function nearestTableIndex()
    {
        for ($index = count($this->stack) - 1; $index >= 0; $index--) {
            $id = $this->stack[$index];

            if (! ($this->arena->kind($id) & (Arena::SVG | Arena::MATHML))
                && $this->arena->name[$id] === 'table') {
                return $index;
            }
        }

        return null;
    }

    protected function clearStackBackToTable()
    {
        $tableIndex = $this->nearestTableIndex();

        if ($tableIndex !== null) {
            $this->sliceOpenStack($tableIndex + 1);
        }
    }

    protected function clearStackBackToTableSection()
    {
        for ($index = count($this->stack) - 1; $index >= 0; $index--) {
            $id = $this->stack[$index];

            if (! ($this->arena->kind($id) & (Arena::SVG | Arena::MATHML))
                && isset(self::$tableSections[$this->arena->name[$id]])) {
                $this->sliceOpenStack($index + 1);

                return;
            }
        }
    }

    protected function clearStackBackToTableRow()
    {
        for ($index = count($this->stack) - 1; $index >= 0; $index--) {
            $id = $this->stack[$index];

            if (! ($this->arena->kind($id) & (Arena::SVG | Arena::MATHML))
                && $this->arena->name[$id] === 'tr') {
                $this->sliceOpenStack($index + 1);

                return;
            }
        }
    }

    protected function closeCurrentTableCell()
    {
        for ($index = count($this->stack) - 1; $index >= 0; $index--) {
            $id = $this->stack[$index];

            if (! ($this->arena->kind($id) & (Arena::SVG | Arena::MATHML))
                && ($this->arena->name[$id] === 'td' || $this->arena->name[$id] === 'th')) {
                $this->sliceOpenStack($index);

                return;
            }
        }
    }

    protected function appendSyntheticElement($name)
    {
        $element = $this->arena->element($name, '', false, 'html', true);
        $this->arena->append($this->currentContainer(), $element);
        $this->stack[] = $element;

        return $element;
    }

    protected function fosterNode($node, $sourceContainer, $character = false)
    {
        $tableIndex = $this->nearestTableIndex();

        if ($tableIndex === null) {
            $this->arena->append($sourceContainer, $node);

            return $node;
        }

        $table = $this->stack[$tableIndex];
        $parent = $this->arena->parent($table);

        if ($parent === null || $this->arena->parent($table) !== $parent) {
            $this->arena->append($sourceContainer, $node);

            return $node;
        }

        if ($character) {
            $tableId = $table;
            $reference = $this->fosterCharacterTails[$tableId] ?? $table;

            if ($this->arena->parent($reference) !== $parent) {
                $reference = $table;
            }

            $this->arena->insertAfter($reference, $node);
            $this->fosterCharacterTails[$tableId] = $node;
        } else {
            $this->arena->insertBefore($table, $node);
        }

        $this->arena->append($sourceContainer, $this->arena->anchor($node));

        return $node;
    }

    protected function isTableFosterParent($element)
    {
        if ($this->arena->kind($element) & (Arena::SVG | Arena::MATHML)) {
            return false;
        }

        return in_array(strtolower((string) $this->arena->name[$element]), [
            'table', 'tbody', 'tfoot', 'thead', 'tr',
        ], true);
    }
}
