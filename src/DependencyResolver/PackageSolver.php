<?php
declare(strict_types = 1);

namespace Conductor\DependencyResolver;

use Composer\DependencyResolver\LockTransaction;

class PackageSolver extends Solver
{
    /**
     * @inheritDoc
     */
    public function solve(array $packages): LockTransaction
    {
        $this->getIO()->write("<info>Solving " . $this->getPackage()->getName() . "</info>");

        $this->packages = $packages;
        $this->doSolve();

        return $this->getLockTransaction();
    }
}
