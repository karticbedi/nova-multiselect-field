<?php

namespace OptimistDigital\MultiselectField;

use RuntimeException;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Http\Requests\NovaRequest;

class Multiselect extends Field
{
    public $component = 'multiselect-field';

    protected $pageResponseResolveCallback;
    protected $saveAsJSON = false;

    protected static $belongsToManyCache = [];

    /**
     * Sets the options available for select.
     *
     * @param  array|callable
     * @return \OptimistDigital\MultiselectField\Multiselect
     **/
    public function options($options = [])
    {
        if (is_callable($options)) $options = call_user_func($options);
        $options = collect($options ?? []);

        $isOptionGroup = $options->contains(function ($label, $value) {
            return is_array($label);
        });

        if ($isOptionGroup) {
            $_options = $options
                ->map(function ($value, $key) {
                    return collect($value + ['value' => $key]);
                })
                ->groupBy('group')
                ->map(function ($value, $key) {
                    return ['label' => $key, 'values' => $value->map->only(['label', 'value'])->toArray()];
                })
                ->values()
                ->toArray();

            return $this->withMeta(['options' => $_options]);
        }


        return $this->withMeta([
            'options' => $options->map(function ($label, $value) {
                return ['label' => $label, 'value' => $value];
            })->values()->all(),
        ]);
    }

    protected function resolveAttribute($resource, $attribute)
    {
        $singleSelect = $this->meta['singleSelect'] ?? false;
        $value = data_get($resource, str_replace('->', '.', $attribute));

        if ($this->saveAsJSON || $singleSelect) return $value;
        return is_array($value) || is_object($value) ? (array) $value : json_decode($value);
    }

    protected function fillAttributeFromRequest(NovaRequest $request, $requestAttribute, $model, $attribute)
    {
        $singleSelect = $this->meta['singleSelect'] ?? false;
        $value = $request->input($requestAttribute) ?? null;

        if ($singleSelect) {
            $model->{$attribute} = $value;
        } else {
            $model->{$attribute} = $this->saveAsJSON || is_null($value) ? $value : json_encode($value);
        }
    }

    /**
     * Allows the field to save an actual JSON array to a SQL JSON column.
     *
     * @param bool $saveAsJSON
     * @return \OptimistDigital\MultiselectField\Multiselect
     **/
    public function saveAsJSON($saveAsJSON = true)
    {
        $this->saveAsJSON = $saveAsJSON;
        return $this;
    }

    /**
     * Sets the max number of options the user can select.
     *
     * @param int $max
     * @return \OptimistDigital\MultiselectField\Multiselect
     **/
    public function max($max)
    {
        return $this->withMeta(['max' => $max]);
    }

    /**
     * Sets the placeholder value displayed on the field.
     *
     * @param string $placeholder
     * @return \OptimistDigital\MultiselectField\Multiselect
     **/
    public function placeholder($placeholder)
    {
        return $this->withMeta(['placeholder' => $placeholder]);
    }

    /**
     * Sets the maximum number of options displayed at once.
     *
     * @param int $optionsLimit
     * @return \OptimistDigital\MultiselectField\Multiselect
     **/
    public function optionsLimit($optionsLimit)
    {
        return $this->withMeta(['optionsLimit' => $optionsLimit]);
    }

    /**
     * Enables or disables reordering of the field values.
     *
     * @param bool $reorderable
     * @return \OptimistDigital\MultiselectField\Multiselect
     **/
    public function reorderable($reorderable = true)
    {
        return $this->withMeta(['reorderable' => $reorderable]);
    }

    /**
     * Enables the field to be used as a single select.
     *
     * This forces the value saved to be a single value and not an array.
     *
     * @param bool $singleSelect
     * @return \OptimistDigital\MultiselectField\Multiselect
     **/
    public function singleSelect($singleSelect = true)
    {
        return $this->withMeta(['singleSelect' => $singleSelect]);
    }

    public function taggable($taggable = true)
    {
        return $this->withMeta(['taggable' => $taggable]);
    }

    /**
     * Enables vue-multiselect's group-select feature which allows the
     * user to select the whole group at once.
     *
     * @param bool $groupSelect
     * @return \OptimistDigital\MultiselectField\Multiselect
     **/
    public function groupSelect($groupSelect = true)
    {
        return $this->withMeta(['groupSelect' => $groupSelect]);
    }

    /**
     * Enable other-field dependency.
     *
     * @param string $otherFieldName
     * @return \OptimistDigital\MultiselectField\Multiselect
     **/
    public function dependsOn($otherFieldName)
    {
        return $this->withMeta(['dependsOn' => $otherFieldName]);
    }

    /**
     * Set dependency options map as a keyed array of options.
     *
     * @param array $options
     * @return \OptimistDigital\MultiselectField\Multiselect
     **/
    public function dependsOnOptions(array $options)
    {
        return $this->withMeta(['dependsOnOptions' => $options]);
    }

    /**
     * Set max selectable value count as a keyed array of numbers.
     *
     * @param array $maxOptions
     * @return \OptimistDigital\MultiselectField\Multiselect
     **/
    public function dependsOnMax(array $maxOptions)
    {
        return $this->withMeta(['dependsOnMax' => $maxOptions]);
    }

    public function resolveResponseValue($value, $templateModel)
    {
        $parsedValue = isset($value) ? ($this->saveAsJSON ? $value : json_decode($value)) : null;
        return is_callable($this->pageResponseResolveCallback)
            ? call_user_func($this->pageResponseResolveCallback, $parsedValue, $templateModel)
            : $parsedValue;
    }

    public function resolveForPageResponseUsing(callable $resolveCallback)
    {
        $this->pageResponseResolveCallback = $resolveCallback;
        return $this;
    }


    /**
     * Makes the field to manage a BelongsToMany relationship.
     *
     * @param string $resourceClass The Nova Resource class for the other model.
     * @param string $label The column to be displayed as the label in the select field.
     * @return \OptimistDigital\MultiselectField\Multiselect
     **/
    public function belongsToMany($resourceClass, $label = null)
    {
        if (!$label && !empty($resourceClass::$title)) $label = $resourceClass::$title;
        $model = $resourceClass::$model;
        $primaryKey = (new $model)->getKeyName();

        $this->resolveUsing(function ($value) use ($primaryKey, $model, $label) {
            if (key_exists($model, static::$belongsToManyCache)) {
                $options = static::$belongsToManyCache[$model];
            } else {
                $options = $model::all()->pluck($label, $primaryKey);
                static::$belongsToManyCache[$model] = $options;
            }

            $this->options($options);

            return collect(array_values($value ?? []))->flatten(1)->pluck($primaryKey);
        });

        $this->fillUsing(function ($request, $model, $requestAttribute, $attribute) {
            $model::saved(function ($model) use ($attribute, $request) {
                // Validate
                if (!is_callable([$model, $attribute])) {
                    throw new RuntimeException("{$model}::{$attribute} must be a relation method.");
                }

                $relation = $model->{$attribute}();

                if (!method_exists($relation, 'sync')) {
                    throw new RuntimeException("{$model}::{$attribute} does not appear to model a BelongsToMany or MorphsToMany.");
                }

                // Sync
                $relation->sync($request->get($attribute) ?? []);
            });
        });

        return $this;
    }
}
