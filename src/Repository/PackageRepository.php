<?php
declare(strict_types = 1);

namespace Conductor\Repository;

use Composer\Installer\InstallationManager;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\Loader\ArrayLoader;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Repository\InvalidRepositoryException;
use Composer\Repository\WritableArrayRepository;
use Composer\Util\Filesystem;
use Conductor\Io\File;
use Conductor\MonorepoPackage;
use Throwable;
use UnexpectedValueException;

/**
 * A repository containing a package's installed dependencies
 */
class PackageRepository extends WritableArrayRepository implements InstalledRepositoryInterface
{
    private const INSTALL_FILE_PATH = "composer/installed.json";

    private File $installFile;

    public function __construct(MonorepoPackage $package)
    {
        parent::__construct();

        $this->installFile = $package->getVendorDirectory()->withPath(self::INSTALL_FILE_PATH);
    }

    protected function initialize(): void
    {
        parent::initialize();

        $file = $this->installFile->toJsonFile();

        if (!$file->exists()) {
            return;
        }

        try {
            $data     = $file->read();
            $packages = $data["packages"] ?? $data;

            if (isset($packages["packages"])) {
                $packages = $packages["packages"];
            }

            if (!is_array($packages)) {
                throw new UnexpectedValueException("Could not parse package list from the repository");
            }
        } catch (Throwable $e) {
            throw new InvalidRepositoryException("Invalid repository data in " . $file->getPath() . ", packages could not be loaded: [" . get_class($e) . "] " . $e->getMessage());
        }

        $loader = new ArrayLoader(null, true);

        foreach ($packages as $packageData) {
            $package = $loader->load($packageData);

            // if (!$this->package->getVendorDirectory()->withPath($package->getName())->getRealPath()) {
            //     throw new LogicException("Could not find path");
            // }

            $this->addPackage($package);
        }
    }

    public function reload(): void
    {
        $this->packages = null;
        $this->initialize();
    }

    /**
     * @inheritDoc
     */
    public function write($devMode, InstallationManager $installationManager): void
    {
        $data = [
            "packages" => [],
            "dev"      => $devMode,
        ];

        $dumper = new ArrayDumper();
        $fs     = new Filesystem();

        $repositoryDirectory = $fs->normalizePath($this->installFile->getPath());

        foreach ($this->getCanonicalPackages() as $package) {
            $packageArray = $dumper->dump($package);
            $path         = $installationManager->getInstallPath($package);

            $packageArray["install-path"] = $path !== "" && $path !== null
                ? $fs->findShortestPath($repositoryDirectory, $fs->isAbsolutePath($path) ? $path : getcwd() . "/" . $path, true)
                : null;
            $data["packages"][] = $packageArray;
        }

        usort($data["packages"], static fn($a, $b) => $a["name"] <=> $b["name"]);

        $this->installFile->toJsonFile()->write($data);
    }

    public function isFresh(): bool
    {
        return $this->installFile->exists();
    }
}
