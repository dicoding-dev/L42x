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
}