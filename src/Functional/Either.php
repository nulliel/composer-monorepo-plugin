<?php
declare(strict_types = 1);

namespace Conductor\Functional;

use Throwable;

// phpcs:ignoreFile

/**
 * @template L
 * @template R
 */
abstract class Either
{
    /**
     * @psalm-var L|R
     *
     * @var mixed
     */
    protected $value;

    /**
     * @psalm-param L|R $value
     *
     * @param mixed $value
     */
    public function __construct($value)
    {
        $this->value = $value;
    }

    abstract public function ap(Either $f) : Either;

    /**
     * @template L2
     * @template R2
     *
     * @psalm-param L2 $x
     *
     * @psalm-return Either<L2, R2>
     *
     * @param mixed $x
     *
     * @return Either
     */
    final public static function left($x) : Either
    {
        return new Left($x);
    }

    /**
     * @template L2
     * @template R2
     *
     * @psalm-param R2 $x
     *
     * @psalm-return Either<L2, R2>
     *
     * @param mixed $x
     *
     * @return Either
     */
    final public static function right($x) : Either
    {
        return new Right($x);
    }

    /**
     * @template L2
     * @template R2
     *
     * @psalm-param R2 $x
     *
     * @psalm-return Either<L2, R2>
     *
     * @param mixed $x
     *
     * @return Either
     */
    final public static function of($x) : Either
    {
        return self::right($x);
    }

    /**
     * @template LR
     * @template RR
     *
     * @psalm-param (callable(L $l) : LR) $left
     * @psalm-param (callable(R $r) : RR) $right
     *
     * @psalm-return LR|RR
     *
     * @param callable $left
     * @param callable $right
     *
     * @return mixed
     */
    abstract public function fold(callable $left, callable $right);


    /**
     * @template R2
     *
     * @psalm-param (callable() : R2) $f
     *
     * @psalm-return Either<Throwable, R2>
     *
     * @param callable      $f
     * @param null|callable $onCatch
     *
     * @return Either
     */
    final public static function tryCatch(callable $f, callable $onCatch = null) : Either
    {
        try {
            return self::of($f());
        } catch (Throwable $e) {
            return self::left($onCatch ? $onCatch($e) : $e);
        }
    }

    /**
     * @template T
     *
     * @psalm-param (callable(R $x) : Either<L, T>) $f
     *
     * @psalm-return Either<L, T>
     *
     * @param callable $f
     *
     * @return Either
     */
    abstract public function chain(callable $f) : Either;

    /**
     * @template T
     *
     * @psalm-param (callable(R $x) : Either<L, T>) $f
     *
     * @psalm-return Either<L, T>
     *
     * @param callable $f
     *
     * @return Either
     */
    abstract public function flatMap(callable $f) : Either;

    /**
     * @template TL
     * @template TR
     *
     * @psalm-param (callable(L $x) : Either<TL, R>) $l
     * @psalm-param (callable(R $x) : Either<L, TR>) $r
     *
     * @psalm-return Either<TL, TR>
     *
     * @param callable $l
     * @param callable $r
     *
     * @return Either
     */
    abstract public function biflatMap(callable $l ,callable $r) : Either;

    /**
     * @template T
     *
     * @psalm-param (callable(R $x) : T) $f
     *
     * @psalm-return Either<L, T>
     *
     * @param callable $f
     *
     * @return Either
     */
    abstract public function map(callable $f) : Either;

    /**
     * @template T
     *
     * @psalm-param (callable(L $x) : T) $f
     *
     * @psalm-return Either<T, R>
     *
     * @param callable $f
     *
     * @return Either
     */
    abstract public function mapLeft(callable $f) : Either;

    /**
     * @template TL
     * @template TR
     *
     * @psalm-param (callable(L $x) : TL) $l
     * @psalm-param (callable(R $x) : TR) $r
     *
     * @psalm-return Either<T, R>
     *
     * @param callable $l
     * @param callable $r
     *
     * @return Either
     */
    abstract public function bimap(callable $l, callable $r) : Either;

    //
    //
    //
    abstract public function tap(callable $f) : Either;

    abstract public function tapCatching(callable $f) : Either;

    abstract public function tapLeft(callable $f) : Either;

    abstract public function handleError(callable $f) : Either;

    // public function ap(Either $ff) : Either
    // {
    //     $ff->chain(fn ($f) => $this->map($f));
    // }
}

/**
 * @template L2
 * @template-extends Either<L2, empty>
 *
 * @internal
 * @psalm-internal Quinn\Functional\Core
 */
class Left extends Either
{
    /**
     * @psalm-var L2
     *
     * @var mixed
     */
    protected $value;

    public function ap(Either $either) : Either
    {
        return $either;
    }

    public function fold(callable $left, callable $right)
    {
        return $left($this->value);
    }

    public function chain(callable $f) : Either
    {
        return $this;
    }

    public function biflatMap(callable $l, callable $r) : Either
    {
        return Either::left($l($this->value)->value);
    }

    public function handleError(callable $f) : Either
    {
        return $f($this->value);
    }

    public function flatMap(callable $f) : Either
    {
        return $this;
    }

    public function map(callable $f) : Either
    {
        return $this;
    }

    public function bimap(callable $l, callable $r) : Either
    {
        return Either::left($l($this->value));
    }

    public function mapLeft(callable $f) : Either
    {
        return Either::left($f($this->value));
    }

    public function tap(callable $f) : Either
    {
        return $this;
    }

    public function tapCatching(callable $f) : Either
    {
        return $this;
    }

    public function tapLeft(callable $f) : Either
    {
        return $this->mapLeft(run($f));
    }
}

/**
 * @template R2
 * @template-extends Either<empty, R2>
 *
 * @internal
 * @psalm-internal Quinn\Functional\Core
 */
class Right extends Either
{
    /**
     * @psalm-var R2
     *
     * @var mixed
     */
    protected $value;

    public function ap(Either $either) : Either
    {
        return $this->chain(fn($a) => $either->map(fn(callable $f) => $f($a)));
    }

    public function fold(callable $left, callable $right)
    {
        return $right($this->value);
    }

    /**
     * {@inheritDoc}
     */
    public function chain(callable $f) : Either
    {
        return $f($this->value);
    }

    public function flatMap(callable $f) : Either
    {
        return $f($this->value);
    }

    public function handleError(callable $f) : Either
    {
        return $this;
    }

    public function biflatMap(callable $l, callable $r) : Either
    {
        return $this->flatMap($r);
    }

    public function map(callable $f) : Either
    {
        return Either::of($f($this->value));
    }

    public function bimap(callable $l, callable $r) : Either
    {
        return Either::of($r($this->value));
    }

    public function mapLeft(callable $f) : Either
    {
        return $this;
    }

    public function tap(callable $f) : Either
    {
        return $this->map(run($f));
    }

    public function tapCatching(callable $f) : Either
    {
        return $this->flatMap(function ($x) use ($f) {
            try {
                $f($x);
                return Either::right($x);
            } catch (Throwable $e) {
                return Either::left($e);
            }
        });
    }

    public function tapLeft(callable $f) : Either
    {
        return $this;
    }
}
