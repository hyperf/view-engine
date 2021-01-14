<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace Hyperf\ViewEngine\Component;

use ArrayAccess;
use ArrayIterator;
use Hyperf\Utils\Arr;
use Hyperf\Utils\Str;
use Hyperf\Utils\Traits\Macroable;
use Hyperf\ViewEngine\Contract\Htmlable;
use Hyperf\ViewEngine\HtmlString;
use IteratorAggregate;

class ComponentAttributeBag implements ArrayAccess, Htmlable, IteratorAggregate
{
    use Macroable;

    /**
     * The raw array of attributes.
     *
     * @var array
     */
    protected $attributes = [];

    /**
     * Create a new component attribute bag instance.
     */
    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
    }

    /**
     * Merge additional attributes / values into the attribute bag.
     *
     * @return HtmlString
     */
    public function __invoke(array $attributeDefaults = [])
    {
        return new HtmlString((string) $this->merge($attributeDefaults));
    }

    /**
     * Implode the attributes into a single HTML ready string.
     *
     * @return string
     */
    public function __toString()
    {
        $string = '';

        foreach ($this->attributes as $key => $value) {
            if ($value === false || is_null($value)) {
                continue;
            }

            if ($value === true) {
                $value = $key;
            }

            $string .= ' ' . $key . '="' . str_replace('"', '\\"', trim($value)) . '"';
        }

        return trim($string);
    }

    /**
     * Get the first attribute's value.
     *
     * @param mixed $default
     * @return mixed
     */
    public function first($default = null)
    {
        return $this->getIterator()->current() ?? value($default);
    }

    /**
     * Get a given attribute from the attribute array.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get($key, $default = null)
    {
        return $this->attributes[$key] ?? value($default);
    }

    /**
     * Only include the given attribute from the attribute array.
     *
     * @param array|mixed $keys
     * @return static
     */
    public function only($keys)
    {
        if (is_null($keys)) {
            $values = $this->attributes;
        } else {
            $keys = Arr::wrap($keys);

            $values = Arr::only($this->attributes, $keys);
        }

        $this->setAttributes($values);

        return $this;
    }

    /**
     * Exclude the given attribute from the attribute array.
     *
     * @param array|mixed $keys
     * @return static
     */
    public function except($keys)
    {
        if (is_null($keys)) {
            $values = $this->attributes;
        } else {
            $keys = Arr::wrap($keys);

            $values = Arr::except($this->attributes, $keys);
        }

        $this->setAttributes($values);

        return $this;
    }

    /**
     * Filter the attributes, returning a bag of attributes that pass the filter.
     *
     * @param callable $callback
     * @return static
     */
    public function filter($callback)
    {
        $this->setAttributes(collect($this->attributes)->filter($callback)->all());

        return $this;
    }

    public function reject($callback)
    {
        $this->setAttributes(collect($this->attributes)->reject($callback)->all());

        return $this;
    }

    /**
     * Return a bag of attributes that have keys starting with the given value / pattern.
     *
     * @param string $string
     * @return static
     */
    public function whereStartsWith($string)
    {
        return $this->filter(function ($value, $key) use ($string) {
            return Str::startsWith($key, $string);
        });
    }

    /**
     * Return a bag of attributes with keys that do not start with the given value / pattern.
     *
     * @param string $string
     * @return static
     */
    public function whereDoesntStartWith($string)
    {
        return $this->reject(function ($value, $key) use ($string) {
            return Str::startsWith($key, $string);
        });
    }

    /**
     * Return a bag of attributes that have keys starting with the given value / pattern.
     *
     * @param string $string
     * @return static
     */
    public function thatStartWith($string)
    {
        return $this->whereStartsWith($string);
    }

    /**
     * Exclude the given attribute from the attribute array.
     *
     * @param array|mixed $keys
     * @return static
     */
    public function exceptProps($keys)
    {
        $props = [];

        foreach ($keys as $key => $defaultValue) {
            $key = is_numeric($key) ? $defaultValue : $key;

            $props[] = $key;
            $props[] = Str::kebab($key);
        }

        return $this->except($props);
    }

    /**
     * Merge additional attributes / values into the attribute bag.
     *
     * @return static
     */
    public function merge(array $attributeDefaults = [], bool $forceMerge = false)
    {
        $attributes = [];

        $attributeDefaults = array_map(function ($value) {
            if (is_object($value) || is_null($value) || is_bool($value)) {
                return $value;
            }

            return \Hyperf\ViewEngine\T::e($value);
        }, $attributeDefaults);

        foreach ($this->attributes as $key => $value) {
            if (! $forceMerge && $key !== 'class') {
                $attributes[$key] = $value;
                continue;
            }

            $attributes[$key] = implode(' ', array_unique(
                array_filter([$attributeDefaults[$key] ?? '', $value])
            ));
        }
        $this->setAttributes(array_merge($attributeDefaults, $attributes));

        return $this;
    }

    /**
     * Get all of the raw attributes.
     *
     * @return array
     */
    public function getAttributes()
    {
        return $this->attributes;
    }

    /**
     * Set the underlying attributes.
     */
    public function setAttributes(array $attributes)
    {
        if (isset($attributes['attributes'])
            && $attributes['attributes'] instanceof self) {
            $parentBag = $attributes['attributes'];

            unset($attributes['attributes']);

            $attributes = $parentBag->merge($attributes);
        }

        $this->attributes = $attributes;
    }

    /**
     * Determine if a given attribute exists in the attribute array.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    /**
     * Get content as a string of HTML.
     *
     * @return string
     */
    public function toHtml()
    {
        return (string) $this;
    }

    /**
     * Determine if the given offset exists.
     *
     * @param string $offset
     * @return bool
     */
    public function offsetExists($offset)
    {
        return isset($this->attributes[$offset]);
    }

    /**
     * Get the value at the given offset.
     *
     * @param string $offset
     * @return mixed
     */
    public function offsetGet($offset)
    {
        return $this->get($offset);
    }

    /**
     * Set the value at a given offset.
     *
     * @param string $offset
     * @param mixed $value
     */
    public function offsetSet($offset, $value)
    {
        $this->attributes[$offset] = $value;
    }

    /**
     * Remove the value at the given offset.
     *
     * @param string $offset
     */
    public function offsetUnset($offset)
    {
        unset($this->attributes[$offset]);
    }

    /**
     * Get an iterator for the items.
     *
     * @return ArrayIterator
     */
    public function getIterator()
    {
        return new ArrayIterator($this->attributes);
    }
}
