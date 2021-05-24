<?php
declare(strict_types = 1);

namespace Conductor\Installer;

use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Composer\Util\Silencer;
use Conductor\Io\File;
use Conductor\Package\MonorepoPackage;
use Illuminate\Support\Collection;
use Throwable;

final class BinaryInstaller
{
    private File $binDir;
    private Filesystem $filesystem;
    private MonorepoPackage $package;

    public function __construct(MonorepoPackage $package)
    {
        $this->filesystem = new Filesystem();
        $this->package    = $package;

        $binDir = $package->getVendorDirectory()->withPath("bin");

        $this->filesystem->ensureDirectoryExists(
            $binDir->getPath(),
        );

        $this->binDir = new File($binDir->getRealPath());
    }

    public function installBinaries(PackageInterface $package, string $installPath): void
    {
        $this->filesystem->ensureDirectoryExists($this->binDir->getPath());

        Collection::wrap($package->getBinaries())->each(function (string $binary) use ($package, $installPath): void {
            $file    = new File($installPath);
            $binFile = $file->withPath($binary);

            if (!$binFile->exists()) {
                $this->package->monorepo->io->write(sprintf(
                    "<warning>Skipping installation of bin %s for package %s: file not found</warning>",
                    $binary,
                    $package->getName(),
                ));
            }

            $link = $this->binDir->withPath(basename($binary));

            if ($link->exists()) {
                if ($link->isLink()) {
                    // Likely leftover from a previous install. Ensure the target is executable.
                    Silencer::call("chmod", $link->getPath(), 0777 & ~umask());
                }

                $this->package->monorepo->io->write(sprintf(
                    "    <warning>Skipped installation of bin %s for package %s: file already exists</warning>",
                    $binary,
                    $package->getName(),
                ));

                return;
            }

            $this->installBinary($binFile, $link, $binary, $package);

            Silencer::call("chmod", $link->getRealPath(), 0777 & ~umask());
        });
    }

    public function removeBinaries(PackageInterface $package): void
    {
        Collection::wrap($package->getBinaries())->each(function (string $binary): void {
            $link = $this->binDir->withPath(basename($binary))->getPath();

            if (is_link($link) || file_exists($link)) {
                $this->filesystem->unlink($link);
            }

            if (file_exists($link . ".bat")) {
                $this->filesystem->unlink($link . ".bat");
            }
        });

        // Attempt to remove the bin directory if it is empty
        if (is_dir($this->binDir->getPath()) && $this->filesystem->isDirEmpty($this->binDir->getPath())) {
            Silencer::call("rmdir", $this->binDir->getPath());
        }
    }

    private function installBinary(File $packageFile, File $binFile, string $binary, PackageInterface $package): void
    {
        $this->filesystem->ensureDirectoryExists($packageFile->dirname()->getPath());

        if (!$packageFile->getRealPath()) {
            $packageFile->write("");
        }

        if (substr($packageFile->getRealPath(), -4) !== ".bat") {
            $this->installUnixBinary($packageFile, $binFile);
            Silencer::call("chmod", $binFile->getRealPath(), 0777 & ~umask());

            $binFile = new File($binFile->getRealPath() . ".bat");

            if ($binFile->exists()) {
                $this->package->monorepo->io->write(sprintf(
                    "    Skipped installation of bin %s.bat proxy for package %s: a .bat proxy was already installed",
                    $binary,
                    $package->getName(),
                ));
            }
        }

        if (!$binFile->exists()) {
            $this->installWindowsBinary($packageFile, $binFile);
        }
    }

    private function installUnixBinary(File $packageFile, File $binFile): void
    {
        $binFile->write($this->generateUnixProxy($packageFile, $binFile));
    }

    private function installWindowsBinary(File $packageFile, File $binFile): void
    {
        $binFile->write($this->generateWindowsProxy($packageFile, $binFile));
    }

    private function generateUnixProxy(File $packageFile, File $binFile): string
    {
        $binPathToPackage = ProcessExecutor::escape(dirname(
            $this->filesystem->findShortestPath(
                $binFile->getPath(),
                $packageFile->getRealPath(),
            ),
        ));

        $binFile = basename($binFile->basename());

        // phpcs:ignore
        return <<<PROXY
#!/usr/bin/env sh

dir=\$(cd "\${0%[/\\\\]*}" > /dev/null; cd $binPathToPackage && pwd)

if [ -d /proc/cygdrive ]; then
    case \$(which php) in
        \$(readlink -n /proc/cygdrive)/*)
            # We are in Cygwin using Windows php, so the path must be translated
            dir=\$(cygpath -m "\$dir");
            ;;
    esac
fi

"\${dir}/$binFile" "\$@"

PROXY;
    }

    private function generateWindowsProxy(File $packageFile, File $binFile): string
    {
        $binPathToPackage = ProcessExecutor::escape(dirname(
            $this->filesystem->findShortestPath(
                $binFile->getPath(),
                $packageFile->getRealPath(),
            ),
        ));

        $packagePath = $packageFile->getRealPath();

        $caller = "php";

        if (substr($packagePath, -4) === ".bin" || substr($packagePath, -4) === ".exe") {
            $caller = "call";
        }

        $content = $packageFile->read();

        if (preg_match("{^#!/(?:usr/bin/env )?(?:[^/]+/)*(.+)$}m", $content, $match)) {
            $caller = trim($match[1]);
        }

        // phpcs:ignore
        return <<<PROXY
@ECHO OFF\r\n
setlocal DISABLEDELAYEDEXPANSION\r\n
SET BIN_TARGET=%~dp0/{$binPathToPackage}\r\n
{$caller} "%BIN_TARGET%" %*\r\n
PROXY;
    }
}
