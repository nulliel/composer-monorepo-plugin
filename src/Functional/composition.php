<?php
declare(strict_types = 1);

namespace Conductor\Functional;

use Closure;

// phpcs:ignoreFile

/**
 * Performs right-to-left function composition.
 *
 * All functions must be unary.
 *
 * @example
 *
 *     $add = fn(int $x) => fn(int $y) => $x + $y;
 *     $mul = fn(int $x) => fn(int $y) => $x * $y;
 *
 *     compose($mul(2), $add(5))(2) //=> 14
 *
 * @psalm-pure
 *
 * @template A
 * @template B
 * @templace C
 *
 * @psalm-param (Closure(B)) $f
 * @psalm-param (Closure(A)) $g
 *
 * @psalm-return (Closure(A) : C)
 *
 * @param Closure $f
 * @param Closure $g
 *
 * @return Closure
 */
function compose(Closure $f, Closure $g)
{
    return fn($x) => $f($g($x));
}



/**
 * @psalm-pure
 *
 * @template A
 * @template B
 *
 * @psalm-param (Closure(A $x) : B) $f
 *
 * @psalm-return (Closure(array $x) : array<B>)
 *
 * @param Closure $f
 *
 * @return Closure
 */
function map(Closure $f) : Closure
{
    return fn(array $x) => array_map($f, $x);
}

/**
 * @psalm-pure
 *
 * @template T
 *
 * @psalm-param (Closure(T $x) : void) $f
 *
 * @psalm-return (Closure(T $x) : T)
 *
 * @param Closure $f
 *
 * @return Closure
 */
function run(Closure $f) : Closure
{
    return function($x) use ($f) {
        $f($x);
        return $x;
    };
}

/**
 * @psalm-pure
 *
 * @template A
 *
 * @psalm-param (Closure(A $x) : bool) $f
 *
 * @psalm-return (Closure(array<A> $x) : array<A>)
 *
 * @param callable $f
 *
 * @return Closure
 */
function filter(Closure $f) : Closure
{
    return fn($x) => array_filter($x, $f);
}

function when(callable $predicate, callable $whenTrue)
{
    return fn($x) => $predicate($x) ? $whenTrue : $x;
}

function both(callable $x, callable $y)
{
    return fn($z) => $x($z) && $y($z);
}

function is_array() {
    return fn($x) => \is_array($x);
}

function is_object() {
    return fn($x) => \is_object($x);
}

/**
 * @template T
 *
 * @psalm-return T
 *
 * @param mixed    $identity
 * @param callable $operation
 * @param array    $list
 *
 * @return mixed
 */
function fold($identity, callable $operation, array $list)
{
    $accumulator = $identity;

    foreach ($list as $item) {
        $accumulator = $operation($accumulator, $item);
    }

    return $accumulator;
}

/**
 * @template T
 *
 * @param callable $predicate
 * @param callable $ifTrue
 * @param callable $ifFalse
 *
 * @return callable
 */
function _if(callable $predicate, callable $ifTrue, callable $ifFalse)
{
    return fn($x) => $predicate($x) ? $ifTrue($x) : $ifFalse($x);
}

/**
 * @psalm-param array<array{}> $args
 *
 * @param array ...$args
 */
function cond(...$args)
{
    return function($x) use ($args) {
        foreach ($args as $arg) {
            if ($arg[0]($x)) {
                return $arg[1]($x);
            }
        }

        return null;
    };
}



/**
 * @template T
 *
 * @psalm-return (Closure(T): T)
 *
 * @return Closure
 */
function _throw()
{
    return function($x) {
        throw $x;
    };
}

function array_put($array, $key, $value)
{
    $array[$key] = $value;
    return $array;
}
