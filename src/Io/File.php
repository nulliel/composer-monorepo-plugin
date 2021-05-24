<?php
declare(strict_types = 1);

namespace Conductor\Io;

use Composer\Json\JsonFile;
use Conductor\Functional\Either;
use Exception;
use Throwable;

// phpcs:ignoreFile

class File
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function toJsonFile(): JsonFile
    {
        return new JsonFile($this->path);
    }

    public function dirname(): File
    {
        return new File(dirname($this->path));
    }

    /**
     *
     *
     * @param string $text
     * @return bool
     */
    public function maybeCreate(string $text = ""): bool
    {
        return $this->exists() || file_put_contents($this->path, $text) !== false;
    }

    public function read(): string
    {
        return file_get_contents($this->path);
    }

    public function write(string $content): bool
    {
        return file_put_contents($this->getPath(), $content) !== false;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function basename()
    {
        return basename($this->path);
    }

    public function getRealPath()
    {
        return realpath($this->path);
    }

    public function withPath(string $path): File
    {
        return new File($this->path . DIRECTORY_SEPARATOR . "$path");
    }

    public function exists()
    {
        return file_exists($this->path) === true;
    }

    public function isLink(): bool
    {
        return is_link($this->path);
    }

    public function isDirectory()
    {

    }

    public static function getFile($path): Either
    {
        return Either::of(new File($path));
    }

    /**
     * @return Either<Throwable, File>
     */
    public static function forCurrentDirectory(): Either
    {
        $currentDirectory = getcwd();

        if (!$currentDirectory) {
            return Either::left(
                new Exception("Could not get current directory. Is this folder and its parents readable?")
            );
        }

        return File::getFile($currentDirectory);
    }
}
