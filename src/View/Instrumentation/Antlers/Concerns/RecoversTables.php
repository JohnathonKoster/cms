<?php

namespace Statamic\View\Instrumentation\Antlers\Concerns;

/**
 * HTML5 table recovery: insertion modes, stack clearing, and the
 * foster-parenting positions that make comments unsafe.
 */
trait RecoversTables
{
    protected function prepareTableStart($name)
    {
        $mode = $this->tableMode();
        $recoveredThroughContent = false;

        if ($mode === 'normal'
            && (isset(self::$tableStructuralElements[$name])
                || in_array($name, ['table', 'col', 'tr', 'td', 'th'], true))) {
            $mode = $this->tableStructureMode();
            $recoveredThroughContent = $mode !== 'normal';
        }

        if ($mode === null || $mode === 'normal') {
            return;
        }

        // A template start tag uses the "in head" rules in every table
        // insertion mode. In particular, it does not close a colgroup.
        if ($name === 'template') {
            return;
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
                return;
            }

            if ($mode === 'cell') {
                $this->closeCurrentTableCell();
            } else {
                $this->clearStackBackToTable();
            }

            $this->prepareTableStart($name);

            return;
        }

        if ($mode === 'table') {
            if ($name === 'table') {
                $this->sliceStacks($this->nearestTableIndex());

                return;
            }

            return;
        }

        if ($mode === 'body') {
            if (isset(self::$tableStructuralElements[$name]) || $name === 'table' || $name === 'col') {
                $this->clearStackBackToTable();
                $this->prepareTableStart($name);
            }

            return;
        }

        if ($mode === 'row') {
            if (isset(self::$tableStructuralElements[$name]) || $name === 'table' || $name === 'col') {
                $this->clearStackBackToTable();
                $this->prepareTableStart($name);
            }

            return;
        }

        if ($mode === 'colgroup' && $name !== 'col') {
            $this->sliceStacks(count($this->elementStack) - 1);
            $this->prepareTableStart($name);
        }
    }

    protected function tableMode()
    {
        $tableIndex = $this->nearestTableIndex();

        if ($tableIndex === null) {
            return null;
        }

        for ($index = count($this->elementStack) - 1; $index > $tableIndex; $index--) {
            if ($this->namespaceStack[$index] !== 'html') {
                return 'normal';
            }

            $name = $this->elementStack[$index];

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

            return 'normal';
        }

        return 'table';
    }

    protected function tableStructureMode()
    {
        $tableIndex = $this->nearestTableIndex();

        if ($tableIndex === null) {
            return null;
        }

        for ($index = count($this->elementStack) - 1; $index > $tableIndex; $index--) {
            if ($this->namespaceStack[$index] !== 'html') {
                continue;
            }

            $name = $this->elementStack[$index];

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
        for ($index = count($this->elementStack) - 1; $index >= 0; $index--) {
            if ($this->namespaceStack[$index] === 'html' && $this->elementStack[$index] === 'table') {
                return $index;
            }
        }

        return null;
    }

    protected function clearStackBackToTable()
    {
        $tableIndex = $this->nearestTableIndex();

        if ($tableIndex !== null) {
            $this->sliceStacks($tableIndex + 1);
        }
    }

    protected function clearStackBackToTableSection()
    {
        for ($index = count($this->elementStack) - 1; $index >= 0; $index--) {
            if ($this->namespaceStack[$index] === 'html'
                && isset(self::$tableSections[$this->elementStack[$index]])) {
                $this->sliceStacks($index + 1);

                return;
            }
        }
    }

    protected function clearStackBackToTableRow()
    {
        for ($index = count($this->elementStack) - 1; $index >= 0; $index--) {
            if ($this->namespaceStack[$index] === 'html' && $this->elementStack[$index] === 'tr') {
                $this->sliceStacks($index + 1);

                return;
            }
        }
    }

    protected function closeCurrentTableCell()
    {
        for ($index = count($this->elementStack) - 1; $index >= 0; $index--) {
            if ($this->namespaceStack[$index] === 'html'
                && ($this->elementStack[$index] === 'td' || $this->elementStack[$index] === 'th')) {
                $this->sliceStacks($index);

                return;
            }
        }
    }
}
