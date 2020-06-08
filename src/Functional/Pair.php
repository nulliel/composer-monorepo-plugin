<?php
declare(strict_types = 1);

namespace Conductor\Functional;

// phpcs:ignoreFile

/**
 * @template L
 * @template R
 */
class Pair
{
    /** @var L */
    private $left;

    /** @var R */
    private $right;

    /**
     * @param L $left
     * @param R $right
     */
    public function __construct($left, $right)
    {
        $this->left  = $left;
        $this->right = $right;
    }

    /**
     * @return L
     */
    public function getLeft()
    {
        return $this->left;
    }

    /**
     * @return R
     */
    public function getRight()
    {
        return $this->right;
    }
}
