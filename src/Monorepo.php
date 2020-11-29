<?php
declare(strict_types = 1);

namespace Conductor;


use Composer\Config;
use Composer\Factory;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Json\JsonManipulator;
use Composer\Package\CompletePackage;
use Composer\Package\Locker;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryFactory;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RepositoryManager;
use Composer\Semver\VersionParser;
use Composer\Util\Filesystem;
use Composer\Util\HttpDownloader;
use Composer\Util\Loop;
use Composer\Util\ProcessExecutor;
use Conductor\DependencyResolver\Solver;
use Conductor\Installer\LibraryInstaller;
use Conductor\Installer\PluginInstaller;
use Conductor\Io\File;
use Conductor\Io\NonexistentFile;
use Conductor\Package\MonorepoPackage;
use Conductor\Repository\MonorepoRepository;
use Conductor\Repository\PackageRepository;
use Illuminate\Support\Collection;

final class Monorepo extends MonorepoPackage
{
    public MonorepoRepository $monorepoRepository;
    public ProcessExecutor $processExecutor;

    public function __construct(IOInterface $io)
    {
        $this->io = $io;

        $this->processExecutor = new ProcessExecutor($io);

        $this->setMonorepo($this);
        $this->setComposerFile(self::findMonorepoRoot());

        $version = $this->getConfig($io)->get("version");

        parent::__construct("", $version, $version);

        if (!self::inMonorepo()) {
            return;
        }

        $this->monorepoRepository = new MonorepoRepository($this);
    }

    //======================
    // Repository Management
    //======================
    public function getRepository(): RepositoryInterface
    {
        return new CompositeRepository([
            $this->getPlatformRepository(),
            $this->monorepoRepository,
            ...$this->getRepositoryManager()->getRepositories()
        ]);
    }

    public function getPlatformRepository(): RepositoryInterface
    {
        $platformOverrides = []; // $this->composer->getConfig()->get("platform") ?: [];
        return new PlatformRepository([], $platformOverrides);
    }

    //==================
    // Config Management
    //==================
    public function writeLockfile(Solver $solver): void
    {
        $lockTransaction = $solver->getLockTransaction();

        if (!$lockTransaction->getOperations()) {
            $this->io->write("<info>Nothing to modify in lock file</info>");
            return;
        }

        $this->io->write("<info>Writing monorepo.lock</info>");

        $platformRequirements = $solver->extractPlatformRequirements($this->getRequires());
        $platformDevRequirements = $solver->extractPlatformRequirements($this->getDevRequires());

        $this->getLocker()->setLockData(
            $lockTransaction->getNewLockPackages(false, true),
            $lockTransaction->getNewLockPackages(true, true),
            $platformRequirements,
            $platformDevRequirements,
            [], //$lockTransaction->getAliases($package->getAliases()),
            "stable",
            [], //$package->getStabilityFlags(),
            true,
            false,
            [],
            true,
        );
    }
    public ?Config $config = null;
    public function getConfig(IOInterface $io = null): Config
    {
        if (!$io) {
            $io = $this->io;
        }
        if (isset($this->config)) {
            return $this->config;
        }

        if (!$this->composerFile->exists()) {
            $io->writeError(sprintf("<error>Failed to load configurations from `%s`: File does not exist</error>", $monorepoFile->getPath()));
            exit(1);
        }

        $config = Factory::createConfig($io, $this->composerFile->dirname()->getRealPath());
        $json   = $this->composerFile->toJsonFile()->read();

        $config->merge($json);

        // Ensure the monorepo has a global version
        if (!isset($json["version"])) {
            $io->writeError("<error>A `version` property must be provided in the root monorepo.json file</error>");
            exit(1);
        }

        $json["version"] = (new VersionParser())->normalize($json["version"]);

        return $config;
    }


    public function writePackages(Solver $solver, bool $isDev): void
    {
        $lockTransaction = $solver->getLockTransaction();

        Collection::wrap($this)
            ->merge($this->monorepoRepository->getPackages())
            ->each(function (MonorepoPackage $package) use ($lockTransaction, $solver, $isDev): void {
                $addNode    = $isDev ? "require-dev" : "require";
                $removeNode = $isDev ? "require" : "require-dev";

                $manipulator = new JsonManipulator($package->composerFile->read());

                foreach ($lockTransaction->getNewLockPackages(false) as $lockPackage) {
                    if ($package->hasPackage($lockPackage->getName())) {
                        $version = $this->monorepoRepository->findPackage($lockPackage->getName(), "*") ? "@dev" : $lockPackage->getPrettyVersion();
                        $manipulator->addLink("require", $lockPackage->getName(), $version, true);
                        $manipulator->removeSubNode("require-dev", $lockPackage->getName());
                    }
                }

                foreach ($lockTransaction->getNewLockPackages(true) as $lockPackage) {
                    assert($lockPackage instanceof CompletePackage);

                    // phpcs:ignore
                    if ($package->hasPackage($lockPackage->getName())) {
                        $version = $this->monorepoRepository->findPackage($lockPackage->getName(), "*") ? "@dev" : $lockPackage->getPrettyVersion();
                        $manipulator->addLink("require-dev", $lockPackage->getName(), $version, true);
                        $manipulator->removeSubNode("require", $lockPackage->getName());
                    }
                }

                foreach ($solver->getNewPackages() as $packageName => $constraint) {
                    if ($package->hasPackage($packageName) || $package->inDirectory()) {
                        $manipulator->addLink($addNode, $packageName, $constraint, true);
                        $manipulator->removeSubNode($removeNode, $packageName);
                    }
                }

                $package->composerFile->write($manipulator->getContents());
            });
    }

    public function getApplicationDirectories(): array
    {
        $config = $this->getConfig($this->io)->get("monorepo");

        if (!$config) {
            $this->io->writeError("<error>Could not find `monorepo` config in root monorepo.json file</error>");
            exit(1);
        }

        if (!isset($config["app-dirs"]) || !is_array($config["app-dirs"])) {
            $this->io->writeError("<error>Could not find `packages` in monorepo config</error>");
            exit(1);
        }

        return $config["app-dirs"];
    }

    public function getLibraryDirectories(): array
    {
        $config = $this->getConfig($this->io)->get("monorepo");

        if (!$config) {
            $this->io->writeError("<error>Could not find `monorepo` config in root monorepo.json file</error>");
            exit(1);
        }

        if (!isset($config["lib-dirs"]) || !is_array($config["lib-dirs"])) {
            $this->io->writeError("<error>Could not find `packages` in monorepo config</error>");
            exit(1);
        }

        return $config["lib-dirs"];
    }

    /**
     * Checks whether composer was called from within a monorepo.
     *
     * Composer is in a monorepo when a monorepo.json file can be
     * found in or above the current directory. The search is done
     * recursively until the root directory is reached.
     */
    public static function inMonorepo(): bool
    {
        return self::findMonorepoRoot()->exists();
    }

    /**
     * Checks whether composer was called from within a monorepo
     * package.
     *
     * The monorepo root does not count as a package as running
     * many commands in this namespace is pointless.
     */
    public function inPackage(): bool
    {
        $currentDirectory = realpath(getcwd());

        return Collection::wrap($this->monorepoRepository->getPackages())
            ->filter(static fn(MonorepoPackage $package) => $currentDirectory === $package->getDirectory()->getRealPath())
            ->isNotEmpty();
    }

    /**
     * Checks whether composer was called from within a monorepo
     * package.
     *
     * The monorepo root does not count as a package as running
     * many commands in this namespace is pointless.
     */
    public function getCurrentPackage()
    {
        $currentDirectory = realpath(getcwd());

        return Collection::wrap($this->monorepoRepository->getPackages())
            ->first(static fn(MonorepoPackage $package) => $currentDirectory === $package->getDirectory()->getRealPath());
    }

    /**
     * Searches for a monorepo.json file in or above the current
     * directory (or directory supplied via $searchDirectory).
     */
    private static function findMonorepoRoot(?File $searchDirectory = null): File
    {
        $currentDirectory = $searchDirectory ?: new File(realpath(getcwd()));
        $monorepoFile     = $currentDirectory->withPath("monorepo.json");

        if ($monorepoFile->exists()) {
            return new File($monorepoFile->getRealPath());
        }

        $parentDirectory = $currentDirectory->withPath("..");

        if ($currentDirectory->getRealPath() === $parentDirectory->getRealPath()) {
            return new NonexistentFile();
        }

        return self::findMonorepoRoot($parentDirectory);
    }

    //
    // Test
    //
    private HttpDownloader $httpDownloader;

    public function getHttpDownloader(): HttpDownloader
    {
        return $this->httpDownloader ??= Factory::createHttpDownloader($this->io, $this->getConfig($this->io));
    }

    public function getRepositoryManager()
    {
        if (isset($this->repositoryManager)) {
            return $this->repositoryManager;
        }

        $this->repositoryManager = RepositoryFactory::manager($this->io, $this->getConfig($this->io), $this->getHttpDownloader());
        $this->repositoryManager->setLocalRepository(new PackageRepository($this));

        $repos = RepositoryFactory::defaultRepos($this->io, $this->getConfig($this->io), $this->repositoryManager);

        foreach ($repos as $repo) {
            $this->repositoryManager->addRepository($repo);
        }

        return $this->repositoryManager;
    }

    private Locker $locker;

    public function getLocker(): Locker
    {
        if (isset($this->locker)) {
            return $this->locker;
        }

        $lockfile     = new File(substr_replace($this->composerFile->getRealPath(), "lock", -4));
        $this->locker = new Locker($this->io, $lockfile->toJsonFile(), $this->getInstallationManager(), $this->composerFile->read());

        return $this->locker;
    }


    private Loop $loop;

    public function getLoop(): Loop
    {
        return $this->loop ??= new Loop($this->getHttpDownloader(), $this->processExecutor);
    }


}

/*
 * //====================
    // Composer Management
    //====================
    private function createComposer(IOInterface $io): Composer
    {
        $filesystem = new Filesystem();

        $composer = $this->composer = new Composer();
        $config   = $this->createConfig($io);

        $composer->setConfig($config);

        $packageConfig = $this->getComposerFile()->toJsonFile()->read();
        $packageConfig["version"] = ($this instanceof Monorepo)
            ? $packageConfig["version"]
            : $this->monorepo->getComposer()->getPackage()->getVersion();

        $packageConfig["dist"] = [
            "type" => "path",
            "url"  => $this->composerFile->dirname()->getPath(),
        ];



        // The event dispatcher must use the new composer instance as it
        // requires its' `Package` instance.
        $composer->setEventDispatcher(new EventDispatcher($composer, $io));



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
            $this->createDownloadManager($config, $httpDownloader, $io, $composer->getEventDispatcher(), $processExecutor),
        );

        // Create a new installation


        $composer->setInstallationManager($installationManager);

        $composer->setArchiveManager($this->createArchiveManager($composer->getDownloadManager(), $loop));

        $composer->setAutoloadGenerator(new \Composer\Autoload\AutoloadGenerator($composer->getEventDispatcher()));

        // $composer->setPluginManager(new PluginManager($io, $composer));
        // $composer->getPluginManager()->loadInstalledPlugins();

        // Remove this plugin from the PluginManager to get rid of any infinite
        // loops that may occur.
        // foreach ($composer->getPluginManager()->getPlugins() as $plugin) {
        //     if ($plugin instanceof Plugin) {
        //          $composer->getPluginManager()->removePlugin($plugin);
        //     }
        // }

        // Notify listeners to initialize plugins
        $initEvent = new Event(PluginEvents::INIT);
        $composer->getEventDispatcher()->dispatch($initEvent->getName(), $initEvent);

        return $composer;
    }
 */

/*
final class Monorepo extends MonorepoPackage
{
    private IOInterface $io;
    private MonorepoRepository $monorepoRepository;

    public static function create(IOInterface $io): Monorepo
    {
        BasePackage::$stabilities["internal"] = 30;

        $monorepo     = new self("MonorepoRoot", "999999-dev", "999999-dev");
        $monorepoRoot = self::findMonorepoRoot();

        $monorepo->setIO($io);

        if (!$monorepoRoot->exists()) {
            return $monorepo;
        }

        $monorepo->setComposerFile($monorepoRoot);
        $monorepo->setMonorepo($monorepo);

        $monorepo->monorepoRepository = new MonorepoRepository($monorepo);

        $monorepo->configureLockfile();

        return $monorepo;
    }



    /**
     * @return array<string>
     *
     * @throws Exception
     *
    public function getPackageDirs(): array
    {
        $config = $this->getComposer()->getConfig()->get("monorepo");

        if (!$config) {
            $this->io->writeError("<error>Could not find `monorepo` config in root monorepo.json file</error>");
            exit(1);
        }

        if (!isset($config["packages"]) || !is_array($config["packages"])) {
            $this->io->writeError("<error>Could not find `packages` in monorepo config</error>");
            exit(1);
        }

        return $config["packages"];
    }





    //==================
    // Getters & Setters
    //==================
    public function getIO(): IOInterface
    {
        return $this->io;
    }

    public function setIO(IOInterface $io): void
    {
        $this->io = $io;
    }

    public function getMonorepoRepository(): MonorepoRepository
    {
        return $this->monorepoRepository;
    }



}
*/
