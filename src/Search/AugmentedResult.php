<?php

namespace Statamic\Search;

use Statamic\Data\AbstractAugmented;
use Statamic\Fields\Value;

class AugmentedResult extends AbstractAugmented
{
    private $extraAugmentedData = [];
    private $augmented;

    protected function getExtraAugmentedResultData()
    {
        if ($this->extraAugmentedData) {
            return $this->extraAugmentedData;
        }

        return $this->extraAugmentedData = $this->data->getIndex()->extraAugmentedResultData($this->data);
    }

    protected function getAugmented()
    {
        if ($this->augmented) {
            return $this->augmented;
        }

        return $this->augmented = $this->data->getSearchable()->augmented();
    }

    public function keys()
    {
        return array_merge(
            $this->data->getSearchable()->keys()->all(),
            [
                'result_type',
                'search_score',
            ],
            array_keys($this->getExtraAugmentedResultData())
        );
    }

    public function get($handle): Value
    {
        if (array_key_exists($handle, $this->getExtraAugmentedResultData())) {
            return new Value($this->extraAugmentedData[$handle], $handle);
        }

        if ($handle === 'result_type') {
            return new Value($this->data->getType(), $handle);
        }

        if ($handle === 'search_score') {
            return new Value($this->data->getScore(), $handle);
        }

        return $this->getAugmented()->get($handle);
    }
}
