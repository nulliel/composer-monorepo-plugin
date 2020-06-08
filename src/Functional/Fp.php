<?php
declare(strict_types = 1);

namespace Conductor\Functional;

// phpcs:ignoreFile

final class Fp
{
    /**
     * @template T
     *
     * @param T $value
     *
     * @return (Closure(): T)
     */
    public static function always($value)
    {
        return fn() => $value;
    }

    /**
     * @template T
     *
     * @return (Closure(T): T)
     */
    public static function identity()
    {
        return fn($x) => $x;
    }
}
