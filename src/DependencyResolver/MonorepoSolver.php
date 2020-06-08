<?php
declare(strict_types = 1);

namespace Conductor\DependencyResolver;

use Composer\DependencyResolver\LockTransaction;
use Composer\Package\Link;
use Composer\Package\Loader\ArrayLoader;
use Conductor\MonorepoPackage;
use Tightenco\Collect\Support\Collection;

final class MonorepoSolver extends Solver
{
    /**
     * @inheritDoc
     */
    public function solve(array $packages): LockTransaction
    {
        // Disable GC to save CPU cycles. The dependency solver can create
        // hundreds of thousands of PHP objects which can cause the GC to
        // spend a significant amount of time walking the tree of references
        // searching for things to collect while there is nothing to collect.
        // This slows things down dramatically and turning it off results in
        // a significant performance increase. Do not try this at home.
        gc_collect_cycles();
        gc_disable();

        $this->addNewPackages($packages);
        $this->preparePackages();

        $this->doSolve();
        $this->displayOperations();

        gc_enable();

        return $this->getLockTransaction();
    }

    public function preparePackages(): void
    {
        $activePackage = $this->getPackage()->getMonorepo()->getMonorepoRepository()->getActivePackage();

        $activePackage = $activePackage ? $activePackage->getComposer()->getPackage() : null;
        $loader        = new ArrayLoader();

        $getCommand = $this->isDev() ? "getDevRequires" : "getRequires";
        $setCommand = $this->isDev() ? "setDevRequires" : "setRequires";

        $links = $loader->parseLinks($activePackage, "", "", $this->getNewPackages());

        if ($activePackage) {
            $activePackage->$setCommand(
                Collection::wrap($activePackage->$getCommand())
                    ->filter(static fn($package) => !in_array($package->getTarget(), array_keys($links)))
                    ->merge($links)
                    ->toArray(),
            );
        }

        Collection::wrap($this->getPackage()->getMonorepo()->getMonorepoRepository()->getPackages())
            ->map(function (MonorepoPackage $package) use ($getCommand, $setCommand, $links) {
                $package->$setCommand(
                    Collection::wrap($package->$getCommand())
                        ->map(function (Link $link) use ($links) {
                            foreach ($links as $newLink) {
                                if ($link->getTarget() === $newLink->getTarget()) {
                                    return $newLink;
                                }
                            }

                            return $link;
                        })->toArray()
                );
            });

        $this->getPackage()->getMonorepo()->getComposer()->getPackage()->$setCommand(
            Collection::wrap($this->getPackage()->getMonorepo()->getComposer()->getPackage()->$getCommand())
                ->filter(static fn($package) => !in_array($package->getTarget(), array_keys($links)))
                ->merge($links)
                ->toArray(),
        );
    }
}
