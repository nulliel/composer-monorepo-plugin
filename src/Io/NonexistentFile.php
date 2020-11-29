<?php
declare(strict_types = 1);

namespace Conductor\Io;

// phpcs:ignoreFile

use JetBrains\PhpStorm\Pure;

final class NonexistentFile extends File
{
    public function __construct()
    {
        parent::__construct("");
    }

    public function getPath()
    {
        return "";
    }

    #[Pure] public function withPath(string $path)
    {
        return $this;
    }

    public function exists()
    {
        return false;
    }

    public function isDirectory()
    {
        return false;
    }

    public function dirname()
    {
        return new File("");
    }
}
