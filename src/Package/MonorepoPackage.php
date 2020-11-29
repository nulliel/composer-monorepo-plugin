<?php
declare(strict_types = 1);

namespace Conductor\Package;

use Composer\Config;
use Composer\Downloader\DownloadManager;
use Composer\Downloader\FileDownloader;
use Composer\Downloader\GitDownloader;
use Composer\Downloader\GzipDownloader;
use Composer\Downloader\PathDownloader;
use Composer\Downloader\PharDownloader;
use Composer\Downloader\TarDownloader;
use Composer\Downloader\XzDownloader;
use Composer\Downloader\ZipDownloader;
use Composer\Factory;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Repository\CompositeRepository;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryFactory;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RepositoryManager;
use Composer\Semver\VersionParser;
use Composer\Util\Filesystem;
use Conductor\Autoload\AutoloadGenerator;
use Conductor\Installer\LibraryInstaller;
use Conductor\Installer\PluginInstaller;
use Conductor\Io\File;
use Conductor\Monorepo;
use Conductor\Repository\PackageRepository;

/**
 * Represents a project's composer.json or monorepo.json.
 */
class MonorepoPackage extends CompletePackage
{
    public ?File $composerFile = null;

    public ?Monorepo $monorepo = null;
    public ?IOInterface $io = null;

    protected RepositoryManager $repositoryManager;
    private InstallationManager $installationManager;
    private DownloadManager $downloadManager;

    //====================
    // Autoload Management
    //====================
    public function shouldDumpAutoload(): bool
    {
        return true;
    }

    public function dumpAutoloads(): void
    {
        $autoload = new AutoloadGenerator();
        $autoload->setDevMode(true);
        $autoload->dump(
            $this->monorepo->getConfig($this->io),
            $this->getLocalRepository(),
            $this,
            $this->getInstallationManager(),
            "composer",
            true,
            $this->getVendorDirectory()->dirname()->getRealPath(),
        );
    }

    //====================
    // Composer Management
    //====================


    //=====================
    // File Management
    //=====================
    public function inDirectory(): bool
    {
        return $this->composerFile->dirname()->getRealPath() === realpath(getcwd());
    }

    //===================
    // Package Management
    //===================
    public function hasPackage(string $name): bool
    {
        foreach (array_merge($this->getRequires(), $this->getDevRequires()) as $link) {
            assert($link instanceof Link);

            if ($link->getTarget() === $name) {
                return true;
            }
        }

        return false;
    }

    //
    //
//

    public function getConfig(IOInterface $io): Config
    {
        return $this->monorepo->getConfig($io);
    }

    //=====================
    // Installer Management
    //=====================
    public function getDownloadManager(): DownloadManager {
        if (isset($this->downloadManager)) {
            return $this->downloadManager;
        }

        $downloadManager = new DownloadManager($this->io);
        $downloadManager->setPreferDist(true);

        $filesystem = new Filesystem($this->monorepo->processExecutor);

        $downloadManager->setDownloader("git", new GitDownloader($this->io, $this->getConfig($this->io), $this->monorepo->processExecutor, $filesystem));
        $downloadManager->setDownloader("zip", new ZipDownloader($this->io, $this->getConfig($this->io), $this->monorepo->getHttpDownloader(), null, null, $filesystem, $this->monorepo->processExecutor));
        $downloadManager->setDownloader("tar", new TarDownloader($this->io, $this->getConfig($this->io), $this->monorepo->getHttpDownloader(), null, null, $filesystem, $this->monorepo->processExecutor));
        $downloadManager->setDownloader("gzip", new GzipDownloader($this->io, $this->getConfig($this->io), $this->monorepo->getHttpDownloader(), null, null, $filesystem, $this->monorepo->processExecutor));
        $downloadManager->setDownloader("xz", new XzDownloader($this->io, $this->getConfig($this->io), $this->monorepo->getHttpDownloader(), null, null, $filesystem, $this->monorepo->processExecutor));
        $downloadManager->setDownloader("phar", new PharDownloader($this->io, $this->getConfig($this->io), $this->monorepo->getHttpDownloader(), null, null));
        $downloadManager->setDownloader("file", new FileDownloader($this->io, $this->getConfig($this->io), $this->monorepo->getHttpDownloader(), null, null));
        $downloadManager->setDownloader("path", new PathDownloader($this->io, $this->getConfig($this->io), $this->monorepo->getHttpDownloader(), null, null));

        return $downloadManager;
    }

    public function getInstallationManager()
    {
        if (isset($this->installationManager)) {
            return $this->installationManager;
        }
        $this->installationManager = new InstallationManager($this->monorepo->getLoop(), $this->io);
        $this->installationManager->addInstaller(new LibraryInstaller($this));
        $this->installationManager->addInstaller(new PluginInstaller($this));

        return $this->installationManager;
    }

    //======================
    // Repository Management
    //======================
    public function getRepositoryManager()
    {
        if (isset($this->repositoryManager)) {
            return $this->repositoryManager;
        }

        $this->repositoryManager = RepositoryFactory::manager($this->io, $this->monorepo->getConfig($this->io), $this->monorepo->getHttpDownloader());
        $this->repositoryManager->setLocalRepository(new PackageRepository($this));

        return $this->repositoryManager;
    }

    public function getRepository(): RepositoryInterface
    {
        return new CompositeRepository([
            $this->monorepo->getPlatformRepository(),
            $this->monorepo->getLocalRepository(),
        ]);
    }

    public function getLocalRepository(): InstalledRepositoryInterface
    {
        return new PackageRepository($this);
    }

    //
    //
    //
    public function setComposerFile(File $file): void
    {
        $this->composerFile = $file;
    }

    public function setMonorepo(Monorepo $monorepo)
    {
        $this->monorepo = $monorepo;
    }
    public function getDirectory(): File
    {
        return $this->composerFile->dirname();
    }

    public function getVersion(): string
    {
        $packageConfig = $this->composerFile->toJsonFile()->read();

        return ($this instanceof Monorepo)
            ? $packageConfig["version"]
            : $this->monorepo->getVersion();
    }
    public function getVendorDirectory(): File
    {
        return $this->composerFile->dirname()->withPath("vendor");
    }

    private function createArchiveManager(DownloadManager $downloadManager, Loop $loop): ArchiveManager
    {
        $archiveManager = new ArchiveManager($downloadManager, $loop);
        $archiveManager->addArchiver(new ZipArchiver());
        $archiveManager->addArchiver(new PharArchiver());

        return $archiveManager;
    }
}
