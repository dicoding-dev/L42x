<?php namespace Illuminate\Support;

use ArrayAccess;
use Closure;
use Illuminate\Support\Traits\MacroableTrait;

class Arr {

	use MacroableTrait;

    /**
     * Determine whether the given value is array accessible.
     *
     * @param  mixed  $value
     * @return bool
     */
    public static function accessible($value): bool
    {
        return is_array($value) || $value instanceof ArrayAccess;
    }

	/**
	 * Add an element to an array using "dot" notation if it doesn't exist.
	 *
	 * @param array  $array
	 * @param string $key
	 * @param  mixed $value
	 *
	 * @return array
	 */
	public static function add(array $array, string $key, mixed $value): array
    {
		if (is_null(static::get($array, $key)))
		{
			static::set($array, $key, $value);
		}

		return $array;
	}

	/**
	 * Build a new array using a callback.
	 *
	 * @param array     $array
	 * @param  \Closure $callback
	 *
	 * @return array
	 */
	public static function build(array $array, Closure $callback): array
    {
		$results = array();

		foreach ($array as $key => $value)
		{
			[$innerKey, $innerValue] = call_user_func($callback, $key, $value);

			$results[$innerKey] = $innerValue;
		}

		return $results;
	}

    /**
     * Collapse an array of arrays into a single array.
     *
     * @param iterable $array
     *
     * @return array
     */
    public static function collapse(iterable $array): array
    {
        $results = [];

        foreach ($array as $values) {
            if ($values instanceof Collection) {
                $values = $values->all();
            } elseif (! is_array($values)) {
                continue;
            }

            $results[] = $values;
        }

        return array_merge([], ...$results);
    }

    /**
     * Cross join the given arrays, returning all possible permutations.
     *
     * @param  iterable  ...$arrays
     * @return array
     */
    public static function crossJoin(...$arrays): array
    {
        $results = [[]];

        foreach ($arrays as $index => $array) {
            $append = [];

            foreach ($results as $product) {
                foreach ($array as $item) {
                    $product[$index] = $item;

                    $append[] = $product;
                }
            }

            $results = $append;
        }

        return $results;
    }

	/**
	 * Divide an array into two arrays. One with keys and the other with values.
	 *
	 * @param array $array
	 *
	 * @return array
	 */
	public static function divide(array $array): array
    {
		return array(array_keys($array), array_values($array));
	}

	/**
	 * Flatten a multi-dimensional associative array with dots.
	 *
	 * @param array  $array
	 * @param string $prepend
	 *
	 * @return array
	 */
	public static function dot(array $array, string $prepend = ''): array
    {
        $results = [];

        foreach ($array as $key => $value) {
            if (is_array($value) && ! empty($value)) {
                $results = array_merge($results, static::dot($value, $prepend.$key.'.'));
            } else {
                $results[$prepend.$key] = $value;
            }
        }

        return $results;
	}

    /**
     * Convert a flatten "dot" notation array into an expanded array.
     *
     * @param iterable $array
     *
     * @return array
     */
    public static function undot(iterable $array): array
    {
        $results = [];

        foreach ($array as $key => $value) {
            static::set($results, $key, $value);
        }

        return $results;
    }

	/**
	 * Get all of the given array except for a specified array of items.
	 *
	 * @param array        $array
	 * @param array|string $keys
	 *
	 * @return array
	 */
	public static function except(array $array, array|string $keys): array
    {
        static::forget($array, $keys);

        return $array;
	}

    /**
     * Determine if the given key exists in the provided array.
     *
     * @param \ArrayAccess|array $array
     * @param int|string         $key
     *
     * @return bool
     */
    public static function exists(ArrayAccess|array $array, int|string $key): bool
    {
        if ($array instanceof ArrayAccess) {
            return $array->offsetExists($key);
        }

        if (is_float($key)) {
            $key = (string) $key;
        }

        return array_key_exists($key, $array);
    }


	/**
	 * Fetch a flattened array of a nested array element.
	 *
	 * @param  array   $array
	 * @param  string  $key
	 * @return array
	 */
	public static function fetch($array, $key)
	{
		foreach (explode('.', $key) as $segment)
		{
			$results = array();

			foreach ($array as $value)
			{
				if (array_key_exists($segment, $value = (array) $value))
				{
					$results[] = $value[$segment];
				}
			}

			$array = array_values($results);
		}

		return array_values($results);
	}

	/**
	 * Return the first element in an array passing a given truth test.
	 *
	 * @param array      $array
	 * @param \Closure|null   $callback
	 * @param mixed|null $default
	 *
	 * @return mixed
	 */
	public static function first(array $array, ?Closure $callback = null, mixed $default = null): mixed
    {
        if (is_null($callback)) {
            if (empty($array)) {
                return value($default);
            }

            foreach ($array as $item) {
                return $item;
            }
        }

        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return value($default);
	}

	/**
	 * Return the last element in an array passing a given truth test.
	 *
	 * @param array         $array
	 * @param \Closure|null $callback
	 * @param  mixed        $default
	 *
	 * @return mixed
	 */
	public static function last(array $array, ?Closure $callback = null, $default = null): mixed
    {
        if (is_null($callback)) {
            return empty($array) ? value($default) : end($array);
        }

        return static::first(array_reverse($array, true), $callback, $default);
	}

	/**
	 * Flatten a multi-dimensional array into a single level.
	 *
	 * @param  array  $array
	 * @return array
	 */
	public static function flatten($array)
	{
		$return = array();

		array_walk_recursive($array, function($x) use (&$return) { $return[] = $x; });

		return $return;
	}

	/**
	 * Remove one or many array items from a given array using "dot" notation.
	 *
	 * @param  array  $array
	 * @param  array|string  $keys
	 * @return void
	 */
	public static function forget(&$array, $keys)
	{
		$original =& $array;

		foreach ((array) $keys as $key)
		{
			$parts = explode('.', (string) $key);

			while (count($parts) > 1)
			{
				$part = array_shift($parts);

				if (isset($array[$part]) && is_array($array[$part]))
				{
					$array =& $array[$part];
				}
			}

			unset($array[array_shift($parts)]);

			// clean up after each pass
			$array =& $original;
		}
	}

	/**
	 * Get an item from an array using "dot" notation.
	 *
	 * @param  array   $array
	 * @param  string  $key
	 * @param  mixed   $default
	 * @return mixed
	 */
	public static function get($array, $key, $default = null)
	{
		if (is_null($key)) return $array;

		if (isset($array[$key])) return $array[$key];

		foreach (explode('.', $key) as $segment)
		{
			if ( ! is_array($array) || ! array_key_exists($segment, $array))
			{
				return value($default);
			}

			$array = $array[$segment];
		}

		return $array;
	}

	/**
	 * Check if an item exists in an array using "dot" notation.
	 *
	 * @param  array   $array
	 * @param  string  $key
	 * @return bool
	 */
	public static function has($array, $key)
	{
		if (empty($array) || is_null($key)) return false;

		if (array_key_exists($key, $array)) return true;

		foreach (explode('.', $key) as $segment)
		{
			if ( ! is_array($array) || ! array_key_exists($segment, $array))
			{
				return false;
			}

			$array = $array[$segment];
		}

		return true;
	}

	/**
	 * Get a subset of the items from the given array.
	 *
	 * @param  array  $array
	 * @param  array|string  $keys
	 * @return array
	 */
	public static function only($array, $keys)
	{
		return array_intersect_key($array, array_flip((array) $keys));
	}

	/**
	 * Pluck an array of values from an array.
	 *
	 * @param  array   $array
	 * @param  string  $value
	 * @param  string  $key
	 * @return array
	 */
	public static function pluck($array, $value, $key = null)
	{
		$results = array();

		foreach ($array as $item)
		{
			$itemValue = is_object($item) ? $item->{$value} : $item[$value];

			// If the key is "null", we will just append the value to the array and keep
			// looping. Otherwise we will key the array using the value of the key we
			// received from the developer. Then we'll return the final array form.
			if (is_null($key))
			{
				$results[] = $itemValue;
			}
			else
			{
				$itemKey = is_object($item) ? $item->{$key} : $item[$key];

				$results[$itemKey] = $itemValue;
			}
		}

		return $results;
	}

	/**
	 * Get a value from the array, and remove it.
	 *
	 * @param  array   $array
	 * @param  string  $key
	 * @param  mixed   $default
	 * @return mixed
	 */
	public static function pull(&$array, $key, $default = null)
	{
		$value = static::get($array, $key, $default);

		static::forget($array, $key);

		return $value;
	}

	/**
	 * Set an array item to a given value using "dot" notation.
	 *
	 * If no key is given to the method, the entire array will be replaced.
	 *
	 * @param  array   $array
	 * @param  string  $key
	 * @param  mixed   $value
	 * @return array
	 */
	public static function set(&$array, $key, $value)
	{
		if (is_null($key)) return $array = $value;

		$keys = explode('.', $key);

		while (count($keys) > 1)
		{
			$key = array_shift($keys);

			// If the key doesn't exist at this depth, we will just create an empty array
			// to hold the next value, allowing us to create the arrays to hold final
			// values at the correct depth. Then we'll keep digging into the array.
			if ( ! isset($array[$key]) || ! is_array($array[$key]))
			{
				$array[$key] = array();
			}

			$array =& $array[$key];
		}

		$array[array_shift($keys)] = $value;

		return $array;
	}

	/**
	 * Sort the array using the given Closure.
	 *
	 * @param  array     $array
	 * @param  \Closure  $callback
	 * @return array
	 */
	public static function sort($array, Closure $callback)
	{
		return Collection::make($array)->sortBy($callback)->all();
	}

	/**
	 * Filter the array using the given Closure.
	 *
	 * @param  array     $array
	 * @param  \Closure  $callback
	 * @return array
	 */
	public static function where($array, Closure $callback)
	{
		$filtered = array();

		foreach ($array as $key => $value)
		{
			if (call_user_func($callback, $key, $value)) $filtered[$key] = $value;
		}

		return $filtered;
	}

    /**
     * If the given value is not an array and not null, wrap it in one.
     *
     * @param  mixed  $value
     * @return array
     */
    public static function wrap($value)
    {
        if (is_null($value)) {
            return [];
        }

        return is_array($value) ? $value : [$value];
    }
}
