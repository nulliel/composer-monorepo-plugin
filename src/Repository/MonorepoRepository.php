<?php
declare(strict_types = 1);

namespace Conductor\Repository;

use Composer\Package\Loader\ArrayLoader;
use Composer\Repository\ArrayRepository;
use Conductor\Io\File;
use Conductor\Monorepo;
use Conductor\MonorepoPackage;
use Illuminate\Support\Collection;

final class MonorepoRepository extends ArrayRepository
{
    public function __construct(Monorepo $monorepo)
    {
        parent::__construct();

        $packageDirs = $monorepo->getPackageDirs();
        $rootDir     = $monorepo->getDirectory();

        Collection::wrap($packageDirs)
            ->map($this->_getGlobPaths($rootDir))
            ->map($this->_getGlobMatches())
            ->flatten()
            ->filter($this->_hasComposerJson())
            ->each($this->_loadPackage($monorepo));
    }

    public function getActivePackage(): ?MonorepoPackage
    {
        foreach ($this->packages as $package) {
            assert($package instanceof MonorepoPackage);

            if (realpath(getcwd()) === $package->getComposerFile()->dirname()->getRealPath()) {
                return $package;
            }
        }

        return null;
    }

    private function _getGlobPaths(File $rootDir): callable
    {
        return static fn (string $path) => $rootDir->withPath($path)->getPath();
    }

    private function _getGlobMatches(): callable
    {
        return static function (string $path): array {
            $flags = GLOB_MARK | GLOB_ONLYDIR;

            if (defined("GLOB_BRACE")) {
                $flags |= GLOB_BRACE;
            }

            $x = glob($path, $flags);

            return Collection::wrap(glob($path, $flags))
                ->map(static fn($result) => rtrim(str_replace(DIRECTORY_SEPARATOR, "/", $result), "/"))
                ->map(static fn($path) => new File($path))
                ->toArray();
        };
    }

    private function _hasComposerJson(): callable
    {
        return static fn(File $path) => $path->withPath("composer.json")->exists();
    }

    private function _loadPackage(Monorepo $monorepo): callable
    {
        $loader = new ArrayLoader(null, false);

        return function (File $packageDir) use ($loader, $monorepo): void {
            $file = $packageDir->withPath("composer.json");
            $data = $file->toJsonFile()->read();

            $data["version"] = "1.0.0";

            $data["dist"] = [
                "type"      => "path",
                "url"       => $packageDir->getRealPath(),
                "reference" => sha1($file->read()),
            ];

            $package = $loader->load($data, MonorepoPackage::class);

            $package->setComposerFile($file);
            $package->setMonorepo($monorepo);

            $this->addPackage($package);
        };
    }
}
