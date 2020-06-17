<?php
declare(strict_types = 1);

namespace Conductor;

use Composer\Cache;
use Composer\Composer;
use Composer\Config;
use Composer\Downloader\DownloadManager;
use Composer\Downloader\FileDownloader;
use Composer\Downloader\FossilDownloader;
use Composer\Downloader\GitDownloader;
use Composer\Downloader\GzipDownloader;
use Composer\Downloader\HgDownloader;
use Composer\Downloader\PathDownloader;
use Composer\Downloader\PerforceDownloader;
use Composer\Downloader\PharDownloader;
use Composer\Downloader\RarDownloader;
use Composer\Downloader\SvnDownloader;
use Composer\Downloader\TarDownloader;
use Composer\Downloader\XzDownloader;
use Composer\Downloader\ZipDownloader;
use Composer\EventDispatcher\Event;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Factory;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\Archiver\ArchiveManager;
use Composer\Package\Archiver\PharArchiver;
use Composer\Package\Archiver\ZipArchiver;
use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Package\Loader\RootPackageLoader;
use Composer\Package\RootAliasPackage;
use Composer\Package\Version\VersionGuesser;
use Composer\Package\Version\VersionParser;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginManager;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryFactory;
use Composer\Repository\RepositoryInterface;
use Composer\Util\Filesystem;
use Composer\Util\HttpDownloader;
use Composer\Util\Loop;
use Composer\Util\ProcessExecutor;
use Conductor\Autoload\AutoloadGenerator;
use Conductor\Composer\Plugin;
use Conductor\Installer\LibraryInstaller;
use Conductor\Installer\PluginInstaller;
use Conductor\Io\File;
use Conductor\Repository\PackageRepository;

class MonorepoPackage extends CompletePackage
{
    private Composer $composer;
    private File $composerFile;
    private Monorepo $monorepo;

    private function initialize(): void
    {
        if (isset($this->composer) || !isset($this->monorepo) || !isset($this->composerFile) || !$this->monorepo || !$this->composerFile) {
            return;
        }

        $this->composer = $this->createComposer($this->monorepo->getIO());
        $this->composer->getConfig()->merge([
            "config" => [
                "vendor-dir" => $this->getVendorDirectory()->getPath(),
            ],
        ]);
    }

    //====================
    // Autoload Management
    //====================
    /**
     * TODO: Create a config that packages can configure to prevent dumping of autoloads.
     *       Speeds up installs / updates when a repository contains many libraries that
     *       do not need autoloads to function.
     */
    public function shouldDumpAutoloads(): bool
    {
        return true;
    }

    public function reload()
    {
        $this->composer = $this->createComposer($this->monorepo->getIO());
    }

    public function dumpAutoloads(): void
    {
        $composer = $this->createComposer($this->monorepo->getIO());

        $composer->getAutoloadGenerator()->setDevMode(true);
        $composer->getAutoloadGenerator()->dump(
            $composer->getConfig(),
            $composer->getRepositoryManager()->getLocalRepository(),
            $composer->getPackage(),
            $composer->getInstallationManager(),
            "composer",
            true,
            $this->getVendorDirectory()->dirname()->getRealPath(),
        );
    }

    //===================
    // Package Management
    //===================
    public function hasPackage(string $name): bool
    {
        $package = $this->composer->getPackage();

        foreach (array_merge($package->getRequires(), $package->getDevRequires()) as $link) {
            assert($link instanceof Link);

            if ($link->getTarget() === $name) {
                return true;
            }
        }

        return false;
    }

    //=====================
    // Directory Management
    //=====================
    public function inDirectory(): bool
    {
        return $this->composerFile->dirname()->getRealPath() === realpath(getcwd());
    }

    public function getVendorDirectory(): File
    {
        return $this->composerFile->dirname()->withPath("vendor");
    }

    //======================
    // Repository Management
    //======================
    public function getRepository(): RepositoryInterface
    {
        return new CompositeRepository([
            $this->getPlatformRepository(),
            $this->monorepo->getMonorepoRepository(),
            ...$this->composer->getRepositoryManager()->getRepositories(),
        ]);
    }

    public function getPlatformRepository(): RepositoryInterface
    {
        $platformOverrides = $this->composer->getConfig()->get("platform") ?: [];

        return new PlatformRepository([], $platformOverrides);
    }

    //====================
    // Composer Management
    //====================
    private function createComposer(IOInterface $io): Composer
    {
        $composer = $this->composer = new Composer();
        $config   = $this->createConfig($io);

        $composer->setConfig($config);

        $packageConfig = $this->getComposerFile()->toJsonFile()->read();
        $packageConfig["version"] = "1.0.0";

        $packageConfig["dist"] = [
            "type" => "path",
            "url"  => $this->composerFile->dirname()->getPath(),
        ];

        $httpDownloader = Factory::createHttpDownloader($io, $config);
        $loop           = new Loop($httpDownloader);

        // The event dispatcher must use the new composer instance as it
        // requires its' `Package` instance.
        $composer->setEventDispatcher(new EventDispatcher($composer, $io));

        $repositoryManager = RepositoryFactory::manager($io, $config, $httpDownloader, $composer->getEventDispatcher());
        $repositoryManager->setLocalRepository(new PackageRepository($this));

        $composer->setRepositoryManager($repositoryManager);

        // Loads the root monorepo.json configurations into a structure usable
        // by composer
        $parser  = new VersionParser();
        $guesser = new VersionGuesser($config, new ProcessExecutor($io), $parser);
        $loader  = new RootPackageLoader($repositoryManager, $config, $parser, $guesser, $io);

        $composer->setPackage($loader->load($packageConfig));

        if ($composer->getPackage() instanceof RootAliasPackage) {
            $composer->setPackage($composer->getPackage()->getAliasOf());
        }

        // DownloadManager
        $composer->setDownloadManager(
            $this->createDownloadManager($config, $httpDownloader, $io, $composer->getEventDispatcher()),
        );

        // Create a new installation
        $installationManager = new InstallationManager($loop, $io);
        $installationManager->addInstaller(new LibraryInstaller($this));
        $installationManager->addInstaller(new PluginInstaller($this));
        
        $composer->setInstallationManager($installationManager);

        $composer->setAutoloadGenerator(new AutoloadGenerator($composer->getEventDispatcher(), $io));
        $composer->setArchiveManager($this->createArchiveManager($composer->getDownloadManager(), $loop));

        $composer->setPluginManager(new PluginManager($io, $composer));
        $composer->getPluginManager()->loadInstalledPlugins();

        // Remove this plugin from the PluginManager to get rid of any infinite
        // loops that may occur.
        foreach ($composer->getPluginManager()->getPlugins() as $plugin) {
            if ($plugin instanceof Plugin) {
                $composer->getPluginManager()->removePlugin($plugin);
            }
        }

        // Notify listeners to initialize plugins
        $initEvent = new Event(PluginEvents::INIT);
        $composer->getEventDispatcher()->dispatch($initEvent->getName(), $initEvent);

        return $composer;
    }

    private function createConfig(IOInterface $io): Config
    {
        $monorepoFile = $this->monorepo->getComposerFile();

        if (!$monorepoFile->exists()) {
            $io->writeError(sprintf("<error>Failed to load configurations from `%s`: File does not exist</error>", $monorepoFile->getPath()));
            exit(1);
        }

        $config = Factory::createConfig($io, $monorepoFile->dirname()->getRealPath());
        $json   = $monorepoFile->toJsonFile()->read();

        $config->merge($json);

        // Ensure the monorepo has a global version
        if (!isset($json["version"])) {
            $io->writeError("<error>A `version` property must be provided in the root monorepo.json file</error>");
            exit(1);
        }

        $json["version"] = (new VersionParser())->normalize($json["version"]);

        return $config;
    }

    private function createDownloadManager(
        Config $config,
        HttpDownloader $httpDownloader,
        IOInterface $io,
        EventDispatcher $eventDispatcher
    ): DownloadManager {
        $cache = $config->get("cache-files-ttl") > 0
            ? new Cache($io, $config->get("cache-files-dir"), "a-z0-9_./")
            : null;

        $downloadManager = new DownloadManager($io);
        $downloadManager->setPreferDist(true);

        $executor   = new ProcessExecutor($io);
        $filesystem = new Filesystem($executor);

        $downloadManager->setDownloader("git", new GitDownloader($io, $config, $executor, $filesystem));
        $downloadManager->setDownloader("svn", new SvnDownloader($io, $config, $executor, $filesystem));
        $downloadManager->setDownloader("fossil", new FossilDownloader($io, $config, $executor, $filesystem));
        $downloadManager->setDownloader("hg", new HgDownloader($io, $config, $executor, $filesystem));
        $downloadManager->setDownloader("perforce", new PerforceDownloader($io, $config));
        $downloadManager->setDownloader("zip", new ZipDownloader($io, $config, $httpDownloader, $eventDispatcher, $cache));
        $downloadManager->setDownloader("rar", new RarDownloader($io, $config, $httpDownloader, $eventDispatcher, $cache));
        $downloadManager->setDownloader("tar", new TarDownloader($io, $config, $httpDownloader, $eventDispatcher, $cache));
        $downloadManager->setDownloader("gzip", new GzipDownloader($io, $config, $httpDownloader, $eventDispatcher, $cache));
        $downloadManager->setDownloader("xz", new XzDownloader($io, $config, $httpDownloader, $eventDispatcher, $cache));
        $downloadManager->setDownloader("phar", new PharDownloader($io, $config, $httpDownloader, $eventDispatcher, $cache));
        $downloadManager->setDownloader("file", new FileDownloader($io, $config, $httpDownloader, $eventDispatcher, $cache));
        $downloadManager->setDownloader("path", new PathDownloader($io, $config, $httpDownloader, $eventDispatcher, $cache));

        return $downloadManager;
    }

    private function createArchiveManager(DownloadManager $downloadManager, Loop $loop): ArchiveManager
    {
        $archiveManager = new ArchiveManager($downloadManager, $loop);
        $archiveManager->addArchiver(new ZipArchiver());
        $archiveManager->addArchiver(new PharArchiver());

        return $archiveManager;
    }

    //==================
    // Getters & Setters
    //==================
    public function getComposer(): Composer
    {
        return $this->composer;
    }

    public function getComposerFile(): File
    {
        return $this->composerFile;
    }

    public function setComposerFile(File $file): void
    {
        $this->composerFile = $file;
        $this->initialize();
    }

    public function getDirectory(): File
    {
        return $this->composerFile->dirname();
    }

    public function getMonorepo(): Monorepo
    {
        return $this->monorepo;
    }

    public function setMonorepo(Monorepo $monorepo): void
    {
        $this->monorepo = $monorepo;
        $this->initialize();
    }
}
