<?php
declare(strict_types = 1);

namespace Conductor;

use Composer\DependencyResolver\LocalRepoTransaction;
use Composer\Repository\ArrayRepository;
use Conductor\DependencyResolver\MonorepoSolver;
use Conductor\DependencyResolver\PackageSolver;
use Conductor\DependencyResolver\Solver;
use Illuminate\Support\Collection;

final class Installer
{
    private bool $isDev    = false;
    private bool $isUpdate = false;

    /** @var array<string> */
    private array $packages = [];

    public function __construct(private Monorepo $monorepo) {}

    public function run(): int
    {
        $composer = $this->monorepo->getComposer();

        if (!$this->isUpdate && !$composer->getLocker()->isLocked()) {
            $this->monorepo->getIO()->write("<warning>No lock file found. Updating dependencies instead of installing from lock file</warning>");
            $this->isUpdate = true;
        }

        /*
        if ($this->dryRun) {
            $this->verbose = true;
            $this->runScripts = false;
            $this->executeOperations = false;
            $this->writeLock = false;
            $this->dumpAutoloader = false;
            $this->mockLocalRepositories($this->repositoryManager);
        }
        */

        $solver = new MonorepoSolver($this->monorepo, $this->isDev, $this->isUpdate);
        $solver->solve($this->packages);

        return $this->isUpdate ? $this->update($solver) : $this->install($solver);
    }

    private function update(Solver $solver): int
    {
        $this->monorepo->writeLockfile($solver);
        $this->monorepo->writePackages($solver, $this->isDev);

        // When updating, the installer must always install dev-mode dependencies.
        $this->isDev = true;

        return $this->install($solver);
    }

    private function install(Solver $solver): int
    {
        $this->monorepo->getIO()->write("<info>Installing dependencies from lockfile" . ($this->isDev ? " (including require-dev)" : "") . "</info>");

        $composer = $this->monorepo->getComposer();

        $lockedRepository = $composer->getLocker()->getLockedRepository($this->isDev);
        $localTransaction = new LocalRepoTransaction($lockedRepository, $composer->getRepositoryManager()->getLocalRepository());

        if (!$localTransaction->getOperations()) {
            $this->monorepo->getIO()->write("Nothing to install, update, or remove");
        }

        $composer->getInstallationManager()->execute($composer->getRepositoryManager()->getLocalRepository(), $localTransaction->getOperations(), $this->isDev);

        Collection::wrap($this->monorepo->getMonorepoRepository()->getPackages())->each(function (MonorepoPackage $package) use ($solver): void {
            $package->reload();

            $packageSolver = new PackageSolver($package, $this->isDev, false);
            $solve         = $packageSolver->solve($solver->getNewPackages());

            $repository = new ArrayRepository();

            foreach ($solve->getNewLockPackages(false, true) as $lockPackage) {
                $repository->addPackage(clone $lockPackage);
            }

            if ($this->isDev) {
                foreach ($solve->getNewLockPackages(true, true) as $lockPackage) {
                    $repository->addPackage(clone $lockPackage);
                }
            }

            $solve = new LocalRepoTransaction($repository, $package->getComposer()->getRepositoryManager()->getLocalRepository());

            $this->monorepo->getIO()->write("<info>Installing dependencies from lockfile" . ($this->isDev ? " (including require-dev)" : "") . "</info>");

            $package->getComposer()->getInstallationManager()->execute($package->getComposer()->getRepositoryManager()->getLocalRepository(), $solve->getOperations(), $this->isDev);

            if ($package->shouldDumpAutoloads()) {
                $package->dumpAutoloads();
            }
        });

        return 0;
    }

    //==================
    // Getters & Setters
    //==================
    public function setDev(bool $isDev): self
    {
        $this->isDev = $isDev;

        return $this;
    }

    /**
     * @param array<string> $packages
     */
    public function setPackages(array $packages): self
    {
        $this->packages = $packages;
        $this->isUpdate = true;

        return $this;
    }
}

/*
 * $lockedRepository = $this->locker->getLockedRepository(true);
        foreach ($lockedRepository->getPackages() as $package) {
            if (!$package instanceof CompletePackage || !$package->isAbandoned()) {
                continue;
            }

            $replacement = is_string($package->getReplacementPackage())
                ? 'Use ' . $package->getReplacementPackage() . ' instead'
                : 'No replacement was suggested';

            $this->io->writeError(
                sprintf(
                    "<warning>Package %s is abandoned, you should avoid using it. %s.</warning>",
                    $package->getPrettyName(),
                    $replacement
                )
            );
        }

        if ($this->dumpAutoloader) {
            // write autoloader
            if ($this->optimizeAutoloader) {
                $this->io->writeError('<info>Generating optimized autoload files</info>');
            } else {
                $this->io->writeError('<info>Generating autoload files</info>');
            }

            $this->autoloadGenerator->setDevMode($this->devMode);
            $this->autoloadGenerator->setClassMapAuthoritative($this->classMapAuthoritative);
            $this->autoloadGenerator->setApcu($this->apcuAutoloader, $this->apcuAutoloaderPrefix);
            $this->autoloadGenerator->setRunScripts($this->runScripts);
            $this->autoloadGenerator->setIgnorePlatformRequirements($this->ignorePlatformReqs);
            $this->autoloadGenerator->dump($this->config, $localRepo, $this->package, $this->installationManager, 'composer', $this->optimizeAutoloader);
        }

        if ($this->install && $this->executeOperations) {
            // force binaries re-generation in case they are missing
            foreach ($localRepo->getPackages() as $package) {
                $this->installationManager->ensureBinariesPresence($package);
            }
        }
 */
