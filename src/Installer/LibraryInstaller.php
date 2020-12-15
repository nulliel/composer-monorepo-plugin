<?php
declare(strict_types = 1);

namespace Conductor\Installer;

use Composer\Installer\BinaryPresenceInterface;
use Composer\Installer\InstallerInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\Filesystem;
use Composer\Util\Platform;
use Composer\Util\Silencer;
use Conductor\Monorepo;
use Conductor\Package\MonorepoPackage;
use InvalidArgumentException;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

class LibraryInstaller implements InstallerInterface, BinaryPresenceInterface
{
    private BinaryInstaller $binaryInstaller;
    private Filesystem $filesystem;
    private MonorepoPackage $package;

    public function __construct(MonorepoPackage $package)
    {
        $this->binaryInstaller = new BinaryInstaller($package);
        $this->filesystem      = new Filesystem();
        $this->package         = $package;

        $this->filesystem->ensureDirectoryExists(
            $this->package->getVendorDirectory()->getPath(),
        );
    }

    //====================
    // !InstallerInterface
    //====================
    public function supports($packageType): bool
    {
        return true;
    }

    public function isInstalled(InstalledRepositoryInterface $repo, PackageInterface $package): bool
    {
        if (!$repo->hasPackage($package)) {
            return false;
        }

        $installPath = $this->getInstallPath($package);

        if (is_readable($installPath)) {
            return true;
        }

        return (Platform::isWindows() && $this->filesystem->isJunction($installPath)) || is_link($installPath);
    }

    public function download(PackageInterface $package, ?PackageInterface $previousPackage = null): ?PromiseInterface
    {
        $downloadPath = $this->getInstallPath($package);

        if (!$this->package instanceof Monorepo) {
            return resolve();
        }

        return $this->package->getDownloadManager()->download($package, $downloadPath, $previousPackage);
    }

    public function prepare($type, PackageInterface $package, ?PackageInterface $previousPackage = null): ?PromiseInterface
    {
        $downloadPath = $this->getInstallPath($package);

        if (!$this->package instanceof Monorepo) {
            return resolve();
        }

        return $this->package->getDownloadManager()->prepare($type, $package, $downloadPath, $previousPackage);
    }

    public function install(InstalledRepositoryInterface $repo, PackageInterface $package): void
    {
        $downloadPath = $this->getInstallPath($package);

        // Remove binaries if package folder was manually removed
        if (!is_readable($downloadPath) && $repo->hasPackage($package)) {
            $this->removeBinaries($package);
        }

        $install = $this->installPackage($package);

        if (!$install instanceof PromiseInterface) {
            $install = resolve();
        }

        $install->then(function () use ($package, $repo) {
            $this->installBinaries($package);

            if (!$repo->hasPackage($package)) {
                $repo->addPackage(clone $package);
            }
        });
    }

    /**
     * @inheritDoc
     *
     * @throws InvalidArgumentException
     */
    public function update(
        InstalledRepositoryInterface $repository,
        PackageInterface $previousPackage,
        PackageInterface $newPackage
    ) {
        echo "update\n";
        if (!$repository->hasPackage($previousPackage)) {
            throw new InvalidArgumentException("Package is not installed: " . $previousPackage);
        }

        $repository->removePackage($previousPackage);
        $this->removeBinaries($previousPackage);

        $this->updatePackage($previousPackage, $newPackage);
        $this->installBinaries($newPackage);

        if (!$repository->hasPackage($newPackage)) {
            $repository->addPackage(clone $newPackage);
        }
    }

    /**
     * @inheritDoc
     *
     * @throws InvalidArgumentException
     */
    public function uninstall(InstalledRepositoryInterface $repository, PackageInterface $package): void
    {
        echo "uninstall\n";
        if (!$repository->hasPackage($package)) {
            throw new InvalidArgumentException("Package is not installed: " . $package);
        }

        $this->removeBinaries($package);
        $this->removePackage($package);

        $repository->removePackage($package);

        if (!strpos($package->getName(), "/")) {
            return;
        }

        $packageVendorDir = dirname($this->getInstallPath($package));

        if (is_dir($packageVendorDir) && $this->filesystem->isDirEmpty($packageVendorDir)) {
            Silencer::call("rmdir", $packageVendorDir);
        }
    }

    /**
     * @inheritDoc
     */
    public function cleanup($type, PackageInterface $package, ?PackageInterface $prevPackage = null): ?PromiseInterface
    {
        return resolve();
        return $this->package->getDownloadManager()->cleanup(
            $type,
            $package,
            $this->getInstallPath($package),
            $prevPackage,
        );
    }

    public function getInstallPath(PackageInterface $package): string
    {
        $vendorDirectory = $this->package->getVendorDirectory();

        return $vendorDirectory
            ->withPath($package->getPrettyName())
            ->getPath();
    }

    //=========================
    // !BinaryPresenceInterface
    //=========================
    public function ensureBinariesPresence(PackageInterface $package): void
    {
        $this->installBinaries($package);
    }

    //==================
    // Code Modification
    //==================
    private function installPackage(PackageInterface $package): ?PromiseInterface
    {
        $downloadPath = $this->getInstallPath($package);

        return $this->package->getDownloadManager()->install($package, $downloadPath);
    }

    private function updatePackage(PackageInterface $previousPackage, PackageInterface $newPackage): void
    {
        $previousPath = $this->getInstallPath($previousPackage);
        $newPath      = $this->getInstallPath($newPackage);

        if ($previousPath !== $newPath) {
            // If the directories intersect, remove + install to prevent the $previousPackage
            // cleanup from modifying the updated directory.
            if (substr($previousPath, 0, strlen($newPath)) === $newPath
                || substr($newPath, 0, strlen($previousPath)) === $newPath
            ) {
                $this->removePackage($previousPackage);
                $this->installPackage($newPackage);

                return;
            }

            $this->filesystem->rename($previousPath, $newPath);
        }

        $this->package->getDownloadManager()->update($previousPackage, $newPackage, $newPath);
    }

    protected function removePackage(PackageInterface $package): void
    {
        $this->package->getDownloadManager()->remove($package, $this->getInstallPath($package));
    }

    private function installBinaries(PackageInterface $package): void
    {
        $this->binaryInstaller->installBinaries($package, $this->getInstallPath($package));
    }

    private function removeBinaries(PackageInterface $package): void
    {
        $this->binaryInstaller->removeBinaries($package);
    }
}
