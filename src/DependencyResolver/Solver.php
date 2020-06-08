<?php
declare(strict_types = 1);

namespace Conductor\DependencyResolver;

use Composer\DependencyResolver\DefaultPolicy;
use Composer\DependencyResolver\LockTransaction;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\DependencyResolver\Request;
use Composer\DependencyResolver\Solver as ComposerSolver;
use Composer\DependencyResolver\SolverProblemsException;
use Composer\IO\IOInterface;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\RootAliasPackage;
use Composer\Repository\PlatformRepository;
use Composer\Semver\Constraint\Constraint;
use Conductor\Monorepo;
use Conductor\MonorepoPackage;
use Conductor\Repository\RepositorySet;

abstract class Solver
{
    private IOInterface $io;
    private Monorepo $monorepo;
    private MonorepoPackage $package;

    private bool $isDev;
    private bool $isUpdate;

    /** @var array<string> */
    private array $packages = [];

    private ?LockTransaction $lockTransaction = null;

    public function __construct(MonorepoPackage $package, bool $isDev, bool $isUpdate)
    {
        $this->io       = $package->getMonorepo()->getIO();
        $this->monorepo = $package->getMonorepo();
        $this->package  = $package;

        $this->isDev    = $isDev;
        $this->isUpdate = $isUpdate;
    }

    /**
     * @param array<string> $packages
     */
    abstract public function solve(array $packages): LockTransaction;

    protected function createRepositorySet(): RepositorySet
    {
        $this->isUpdate
            ? $this->io->write("<info>Loading composer repositories with package information</info>")
            : $this->io->write("<info>Verifying lockfile contents can be installed on current platform</info>");

        $repositorySet = new RepositorySet($this->package, $this->getNewPackages(), $this->isDev, $this->isUpdate);

        if ($this->isUpdate) {
            $repositorySet->addRepository($this->monorepo->getRepository());
        } else {
            $repository = $this->monorepo->getComposer()->getLocker()->getLockedRepository($this->isDev);

            // if ($isPackage) {
            //     foreach ($repository->getPackages() as $lockPackage) {
            //         if ($this->monorepo->getMonorepoRepository()->findPackage($lockPackage->getName(), "*")) {
            //             $lockPackage->setDistType("path");
            //             $lockPackage->setDistUrl(
            //                 $this->monorepo->getVendorDirectory()->withPath($lockPackage->getName())->getPath(),
            //             );
            //         }
            //     }
            // }

            $repositorySet->addRepository($repository);
        }

        return $repositorySet;
    }

    protected function createRequest(): Request
    {
        $this->isUpdate
            ? $this->io->write("<info>Updating dependencies</info>")
            : $this->io->write("<info>Verifying lockfile contents can be installed on current platform</info>");

        $locker = $this->package->getComposer()->getLocker();
        $lockedRepository = $locker && $locker->isLocked()
            ? $locker->getLockedRepository($this->isDev)
            : null;

        if ($lockedRepository && !$locker->isFresh()) {
            $this->io->write(
                // phpcs:ignore
                "<warning>The lockfile is not up to date with the latest changes in monorepo.json. It is recommended you run `composer update`.</warning>",
            );
        }

        $request = new Request($lockedRepository);
        $package = $this->package->getComposer()->getPackage();

        // $request->setUpdateAllowList($this->packages, Request::UPDATE_LISTED_WITH_TRANSITIVE_DEPS);

        if ($package instanceof RootAliasPackage) {
            $request->fixPackage($package->getAliasOf(), false);
        } else {
            $request->fixPackage($package, false);
        }

        // Mark platform packages as installed so the solver does
        // not complain when trying to upgrade these
        foreach ($this->monorepo->getPlatformRepository()->getPackages() as $fixedPackage) {
            $request->fixPackage($fixedPackage, false);
        }

        if (!$this->isUpdate && $lockedRepository) {
            foreach ($lockedRepository->getPackages() as $lockedPackage) {
                $request->requireName($lockedPackage->getName(), new Constraint("==", $lockedPackage->getVersion()));
            }
        } else {
            $links = array_merge($package->getRequires(), $package->getDevRequires());

            foreach ($links as $link) {
                $request->requireName($link->getTarget(), $link->getConstraint());
            }
        }

        // if (!$isUpdate) {
        //     if ($lockedRepository) {
        //         foreach ($lockedRepository->getPackages() as $lockedPackage) {
        //             $request->fixPackage($lockedPackage);
        //         }
        //     }
        //
        //     foreach ($this->composer->getLocker()->getPlatformRequirements($isDev) as $link) {
        //         $request->requireName($link->getTarget(), $link->getConstraint());
        //     }
        // }

        return $request;
    }

    protected function doSolve(): LockTransaction
    {
        $repositorySet = $this->createRepositorySet();
        $request       = $this->createRequest();

        $pool   = $repositorySet->createPool($request, $this->io);
        $solver = new ComposerSolver($this->getPolicy(), $pool, $this->io);

        try {
            $lockTransaction = $solver->solve($request);
            $ruleSetSize     = $solver->getRuleSetSize();

            if (!$this->isUpdate && count($lockTransaction->getOperations()) === 0) {
                $this->io->write(
                    // phpcs:ignore
                    "<error>Your lockfile cannot be installed on this system without changes. Please run `composer update`</error>",
                );
            }
        } catch (SolverProblemsException $e) {
            $this->io->writeError(
                "<error>Your requirements could not be resolved to an installable set of packages.</error>",
            );
            $this->io->writeError($e->getPrettyString($repositorySet, $request, $pool, $this->io->isVerbose()));

            return $e->getCode();
        }

        $this->io->writeError("Analyzed " . count($pool) . " packages to resolve dependencies");
        $this->io->writeError("Analyzed " . $ruleSetSize . " rules to resolve dependencies");

        $this->extractDevPackages();

        $this->lockTransaction = $lockTransaction;

        return $lockTransaction;
    }

    // phpcs:ignore
    protected function displayOperations(): void
    {
        // Transaction $transaction, string $operationType = "Lockfile"
        $transaction = $this->lockTransaction;
        $operationType = "Lockfile";

        $installs = $updates = $removes = [];

        foreach ($transaction->getOperations() as $operation) {
            if ($operation instanceof InstallOperation) {
                $installs[] = sprintf(
                    "%s:%s",
                    $operation->getPackage()->getPrettyName(),
                    $operation->getPackage()->getFullPrettyVersion(),
                );
            } elseif ($operation instanceof UpdateOperation) {
                $updates[] = sprintf(
                    "%s:%s",
                    $operation->getTargetPackage()->getPrettyName(),
                    $operation->getTargetPackage()->getFullPrettyVersion(),
                );
            } elseif ($operation instanceof UninstallOperation) {
                $removes[] = $operation->getPackage()->getPrettyName();
            }
        }

        $this->io->write(sprintf(
            "<info>%s operations: %d installs, %d updates, %d removals</info>",
            $operationType,
            count($installs),
            count($updates),
            count($removes),
        ));

        if (count($installs) >= 0) {
            $this->io->write("Installs: " . implode(", ", $installs), true, IOInterface::VERBOSE);
        }

        if (count($updates) >= 0) {
            $this->io->write("Updates: " . implode(", ", $updates), true, IOInterface::VERBOSE);
        }

        if (count($removes) >= 0) {
            $this->io->write("Removals: " . implode(", ", $removes), true, IOInterface::VERBOSE);
        }
    }

    private function extractDevPackages(): void
    {
        if (!$this->package->getComposer()->getPackage()->getDevRequires()) {
            return;
        }

        /*
        $resultRepo = new ArrayRepository([]);
        $loader = new ArrayLoader(null, true);
        $dumper = new ArrayDumper();

        foreach ($lockTransaction->getNewLockPackages(false) as $package) {
            $resultRepo->addPackage($loader->load($dumper->dump($package)));
        }

        $repositorySet = $this->createRepositorySet(false, true);
        $repositorySet->addRepository($resultRepo);

        $request = $this->createRequest($package);

        $links = $this->composer->getPackage()->getRequires();

        foreach ($links as $link) {
            $request->requireName($link->getTarget(), $link->getConstraint());
        }

        $pool = $repositorySet->createPoolWithAllPackages();

        $solver = new Solver($policy, $pool, $this->io);

        try {
            $nonDevLockTransaction = $solver->solve($request);
            $solver = null;
        } catch (SolverProblemsException $e) {
            $this->io->writeError("<error>Unable to find a compatible set of packages based on y
        our non-dev requirements alone</error>");
            $this->io->writeError("Your requirements can be resolved successfully when
         require-dev packages are present");
            $this->io->writeError("You may need to move packages from require-dev or s
        ome of their dependencies to require");
            $this->io->writeError($e->getPrettyString($repositorySet, $request, $pool
        , $this->io->isVerbose(), true));

            return $e->getCode();
        }

        $lockTransaction->setNonDevPackages($nonDevLockTransaction);
        */
    }

    /**
     * @param array<string> $links
     *
     * @return array<string>
     */
    public function extractPlatformRequirements(array $links): array
    {
        $platformRequirements = [];

        foreach ($links as $link) {
            if (preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $link->getTarget())) {
                $platformRequirements[$link->getTarget()] = $link->getPrettyConstraint();
            }
        }

        return $platformRequirements;
    }

    /**
     * @param array<MonorepoPackage> $packages
     */
    public function addNewPackages(array $packages): void
    {
        $this->packages = array_merge($packages, $this->packages);
    }

    /**
     * @return array<string>
     */
    public function getNewPackages(): array
    {
        return $this->packages;
    }

    public function getPolicy(): DefaultPolicy
    {
        return new DefaultPolicy(true, false);
    }

    public function getIO(): IOInterface
    {
        return $this->io;
    }

    public function getPackage(): MonorepoPackage
    {
        return $this->package;
    }

    public function isDev(): bool
    {
        return $this->isDev;
    }

    public function getLockTransaction(): LockTransaction
    {
        return $this->lockTransaction;
    }
}
