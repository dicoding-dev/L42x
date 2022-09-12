<?php

namespace Illuminate\Support;

use Closure;

class Util
{
    /**
     * Return the default value of the given value.
     *
     * @param  mixed  $value
     * @param  mixed  ...$args
     * @return mixed
     */
    public static function unwrapIfClosure($value, ...$args)
    {
        return $value instanceof Closure ? $value(...$args) : $value;
    }

    public static function isEmpty(mixed $value): bool
    {
        if (!isset($value)) {
            return true;
        }

        if ($value instanceof \Countable) {
            return $value->count() === 0;
        }

        return empty($value);
    }
}