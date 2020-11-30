<?php
declare(strict_types = 1);

namespace Conductor;

use Composer\DependencyResolver\LocalRepoTransaction;
use Composer\Repository\ArrayRepository;
use Conductor\DependencyResolver\MonorepoSolver;
use Conductor\DependencyResolver\PackageSolver;
use Conductor\DependencyResolver\Solver;
use Conductor\Package\MonorepoPackage;
use Illuminate\Support\Collection;

final class Installer
{
    public function __construct(private Monorepo $monorepo) {}

    /**
     * @param array<string> $packages
     */
    public function run(array $packages = [], bool $isDev = false, bool $isUpdate = false): int
    {
        if (!$isUpdate && !$this->monorepo->getLocker()->isLocked()) {
            $this->monorepo->io->write("<warning>No lock file found. Updating dependencies instead of installing from lock file</warning>");
            $isUpdate = true;
        }

        $solver = new MonorepoSolver($this->monorepo, $isDev, $isUpdate);
        $solver->solve($packages);

        return $isUpdate ? $this->update($solver, $isDev, $isUpdate) : $this->install($solver, $isDev, $isUpdate);
    }

    private function update(Solver $solver, bool $isDev, bool $isUpdate): int
    {
        $this->monorepo->writeLockfile($solver);
        $this->monorepo->writePackages($solver, $isDev);

        return $this->install($solver, $isDev, $isUpdate);
    }

    private function install(Solver $solver, bool $isDev, bool $isUpdate): int
    {
        $this->monorepo->io?->write("<info>Installing dependencies from lockfile" . ($isDev ? " (including require-dev)" : "") . "</info>");

        $lockedRepository = $this->monorepo->getLocker()->getLockedRepository($isUpdate ?: $isDev);
        $localTransaction = new LocalRepoTransaction($lockedRepository, $this->monorepo->getRepositoryManager()->getLocalRepository());

        if (count($localTransaction->getOperations()) === 0) {
            $this->monorepo->io?->write("Nothing to install, update, or remove");
        } else {
            $this->monorepo->getInstallationManager()->execute($this->monorepo->getRepositoryManager()->getLocalRepository(), $localTransaction->getOperations(), $isDev);
        }

        Collection::wrap($this->monorepo->monorepoRepository->getPackages())->each(function (MonorepoPackage $package) use ($solver, $isDev, $isUpdate): void {
            $packageSolver = new PackageSolver($package, $isDev, $isUpdate);
            $solve         = $packageSolver->solve($solver->getNewPackages());

            $repository = new ArrayRepository();

            foreach ($solve->getNewLockPackages(false, true) as $lockPackage) {
                $repository->addPackage(clone $lockPackage);
            }

            if ($isDev) {
                foreach ($solve->getNewLockPackages(true, true) as $lockPackage) {
                    $repository->addPackage(clone $lockPackage);
                }
            }

            $solve = new LocalRepoTransaction($repository, $package->getRepositoryManager()->getLocalRepository());

            $this->monorepo->io->write("<info>Installing dependencies from lockfile" . ($isDev ? " (including require-dev)" : "") . "</info>");

            $package->getInstallationManager()->execute($package->getLocalRepository(), $solve->getOperations(), $isDev);

            // if ($package->shouldDumpAutoloads()) {
                 $package->dumpAutoloads($isDev);
            // }
        });

        return 0;
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
