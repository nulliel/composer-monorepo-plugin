<?php
declare(strict_types = 1);

namespace Conductor;

use Composer\IO\IOInterface;
use Composer\Json\JsonManipulator;
use Composer\Package\CompletePackage;
use Composer\Package\Locker;
use Conductor\DependencyResolver\Solver;
use Conductor\Io\File;
use Conductor\Io\NonexistentFile;
use Conductor\Repository\MonorepoRepository;
use Exception;
use Tightenco\Collect\Support\Collection;

final class Monorepo extends MonorepoPackage
{
    private IOInterface $io;
    private MonorepoRepository $monorepoRepository;

    public static function create(IOInterface $io): Monorepo
    {
        $monorepo     = new Monorepo("__root__", "999999-dev", "999999-dev");
        $monorepoRoot = $monorepo->findMonorepoRoot();

        $monorepo->setIO($io);

        if (!$monorepoRoot->exists()) {
            return $monorepo;
        }

        $monorepo->setComposerFile($monorepoRoot);
        $monorepo->setMonorepo($monorepo);

        $monorepo->monorepoRepository = new MonorepoRepository($monorepo);

        $monorepo->configureLockfile();

        // if (function_exists('pcntl_async_signals')) {
        //     pcntl_async_signals(true);
        //     pcntl_signal(SIGINT, array($this, 'revertComposerFile'));
        //     pcntl_signal(SIGTERM, array($this, 'revertComposerFile'));
        //     pcntl_signal(SIGHUP, array($this, 'revertComposerFile'));
        // }

        return $monorepo;
    }

    //==================
    // Config Management
    //==================
    public function writeLockfile(Solver $solver): void
    {
        $lockTransaction = $solver->getLockTransaction();
        $package         = $this->getComposer()->getPackage();

        if (!$lockTransaction->getOperations()) {
            $this->io->write("<info>Nothing to modify in lock file</info>");

            return;
        }

        $this->io->write("<info>Writing monorepo.lock</info>");

        $platformRequirements = $solver->extractPlatformRequirements($package->getRequires());
        $platformDevRequirements = $solver->extractPlatformRequirements($package->getDevRequires());

        $this->getComposer()->getLocker()->setLockData(
            $lockTransaction->getNewLockPackages(false, true),
            $lockTransaction->getNewLockPackages(true, true),
            $platformRequirements,
            $platformDevRequirements,
            $lockTransaction->getAliases($package->getAliases()),
            "stable",
            $package->getStabilityFlags(),
            true,
            false,
            [],
            true,
        );
    }

    public function writePackages(Solver $solver, bool $isDev): void
    {
        $lockTransaction    = $solver->getLockTransaction();
        $monorepoRepository = $this->getMonorepoRepository();

        Collection::wrap($this)
            ->merge($this->getMonorepoRepository()->getPackages())
            ->each(function (MonorepoPackage $package) use ($lockTransaction, $monorepoRepository, $solver, $isDev): void {
                $addNode    = $isDev ? "require-dev" : "require";
                $removeNode = $isDev ? "require" : "require-dev";

                $manipulator = new JsonManipulator($package->getComposerFile()->read());

                foreach ($lockTransaction->getNewLockPackages(true) as $lockPackage) {
                    assert($lockPackage instanceof CompletePackage);

                    // phpcs:ignore
                    if ($package->hasPackage($lockPackage->getName())) {
                        $version = $monorepoRepository->findPackage($lockPackage->getName(), "*")
                            ? "*"
                            : $lockPackage->getPrettyVersion();
                        $manipulator->addLink("require-dev", $lockPackage->getName(), $version, true);
                        $manipulator->removeSubNode("require", $lockPackage->getName());
                    }
                }

                foreach ($lockTransaction->getNewLockPackages(false) as $lockPackage) {
                    if ($package->hasPackage($lockPackage->getName())) {
                        $version = $monorepoRepository->findPackage($lockPackage->getName(), "*")
                            ? "*"
                            : $lockPackage->getPrettyVersion();
                        $manipulator->addLink("require", $lockPackage->getName(), $version, true);
                        $manipulator->removeSubNode("require-dev", $lockPackage->getName());
                    }
                }

                foreach ($solver->getNewPackages() as $packageName => $constraint) {
                    if ($package->hasPackage($packageName) || $package->inDirectory()) {
                        $manipulator->addLink($addNode, $packageName, $constraint, true);
                        $manipulator->removeSubNode($removeNode, $packageName);
                    }
                }

                $package->getComposerFile()->write($manipulator->getContents());
            });
    }

    /**
     * @return array<string>
     *
     * @throws Exception
     */
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

    private function configureLockfile(): void
    {
        $composerFile        = $this->getComposerFile();
        $installationManager = $this->getComposer()->getInstallationManager();
        $lockfile            = new File(substr_replace($this->getComposerFile()->getRealPath(), "lock", -4));

        $locker = new Locker($this->io, $lockfile->toJsonFile(), $installationManager, $composerFile->read());

        $this->getComposer()->setLocker($locker);
    }

    /**
     * Searches up from the current directory to find the root
     * monorepo.json file for a given project.
     */
    private function findMonorepoRoot(?File $searchDirectory = null): File
    {
        $searchDirectory = $searchDirectory ?: new File(realpath("."));
        $monorepoFile    = $searchDirectory->withPath("monorepo.json");

        if ($monorepoFile->exists()) {
            return new File($monorepoFile->getRealPath());
        }

        $parentDirectory = $searchDirectory->withPath("..");

        if ($searchDirectory->getRealPath() === $parentDirectory->getRealPath()) {
            return new NonexistentFile();
        }

        return $this->findMonorepoRoot($parentDirectory);
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

    public function isMonorepo(): bool
    {
        return $this->findMonorepoRoot()->exists();
    }

    public function inPackage(): bool
    {
        $currentDirectory = realpath(getcwd());

        if ($currentDirectory === $this->getDirectory()->getRealPath()) {
            return true;
        }

        return Collection::wrap($this->monorepoRepository->getPackages())
            ->filter(static fn(MonorepoPackage $package) => $currentDirectory === $package->getDirectory()->getRealPath())
            ->isNotEmpty();
    }
}
