<?php
declare(strict_types = 1);

namespace Conductor\Installer;

use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Conductor\MonorepoPackage;
use React\Promise\PromiseInterface;
use Throwable;
use UnexpectedValueException;

final class PluginInstaller extends LibraryInstaller
{
    private MonorepoPackage $package;

    public function __construct(MonorepoPackage $package)
    {
        parent::__construct($package);

        $this->package = $package;
    }

    /**
     * @inheritDoc
     */
    public function supports($packageType): bool
    {
        return $packageType === "composer-plugin" || $packageType === "composer-installer";
    }

    /**
     * @inheritDoc
     *
     * @throws UnexpectedValueException
     */
    public function download(PackageInterface $package, ?PackageInterface $previousPackage = null): ?PromiseInterface
    {
        if (!isset($package->getExtra()["class"])) {
            throw new UnexpectedValueException(sprintf(
                "Error while installing %s. Could not find a plugin class in its package.json",
                $package->getPrettyName(),
            ));
        }

        return parent::download($package, $previousPackage);
    }

    /**
     * @inheritDoc
     *
     * @throws Throwable
     */
    public function install(InstalledRepositoryInterface $repository, PackageInterface $package): void
    {
        parent::install($repository, $package);

        try {
            $this->package->getComposer()->getPluginManager()->registerPackage($package, true);
        } catch (Throwable $e) {
            $this->package->getIO()->writeError(sprintf(
                "Plugin initialization failed (%s), uninstalling plugin",
                $e->getMessage(),
            ));

            parent::uninstall($repository, $package);

            throw $e;
        }
    }

    /**
     * @inheritDoc
     *
     * @throws Throwable
     */
    public function update(
        InstalledRepositoryInterface $repository,
        PackageInterface $previousPackage,
        PackageInterface $newPackage
    ) {
        parent::update($repository, $previousPackage, $newPackage);

        try {
            $this->package->getComposer()->getPluginManager()->deactivatePackage($previousPackage);
            $this->package->getComposer()->getPluginManager()->registerPackage($newPackage, true);
        } catch (Throwable $e) {
            $this->package->getIO()->writeError(sprintf(
                "Plugin initialization failed (%s), uninstalling plugin",
                $e->getMessage(),
            ));

            parent::uninstall($repository, $newPackage);

            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public function uninstall(InstalledRepositoryInterface $repository, PackageInterface $package): void
    {
        $this->package->getComposer()->getPluginManager()->uninstallPackage($package);

        parent::uninstall($repository, $package);
    }
}
