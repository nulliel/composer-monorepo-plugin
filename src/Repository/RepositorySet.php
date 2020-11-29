<?php
declare(strict_types = 1);

namespace Conductor\Repository;

use Composer\Package\Link;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositorySet as ComposerRepositorySet;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Conductor\Package\MonorepoPackage;

class RepositorySet extends ComposerRepositorySet
{
    private MonorepoPackage $package;

    private bool $isDev;
    private bool $isUpdate;

    public function __construct(MonorepoPackage $package, array $packages, bool $isDev, bool $isUpdate)
    {
        $this->package = $package;

        $this->isDev    = $isDev;
        $this->isUpdate = $isUpdate;

        $stabilityFlags = $this->package->monorepo->getLocker()->isLocked()
            ? $this->package->monorepo->getLocker()->getStabilityFlags()
            : [];

        parent::__construct("stable", $stabilityFlags, [], [], $this->getRequires($packages));
    }

    /**
     * @return array<ConstraintInterface>
     */
    private function getRequires(array $packages): array
    {
        $requires = [];
        $rootRequires = [];

        if ($this->isUpdate) {
            $requires = array_merge(
                $packages,
                $this->package->getRequires(),
                $this->package->getDevRequires(),
            );
        } else {
            foreach ($this->package->monorepo->getLocker()->getLockedRepository($this->isDev)->getPackages() as $package) {
                $constraint = new Constraint("=", $package->getVersion());
                $constraint->setPrettyString($package->getPrettyVersion());
                $requires[$package->getName()] = $constraint;
            }
        }

        foreach ($requires as $require => $constraint) {
            assert(is_string($require));

            if (PlatformRepository::isPlatformPackage($require)) {
                continue;
            }

            if ($constraint instanceof Link) {
                $rootRequires[$require] = $constraint->getConstraint();
            } else if (is_string($constraint)) {
                $rootRequires[$require] = new Constraint("=", $constraint);
            } else {
                $rootRequires[$require] = $constraint;
            }
        }

        return $rootRequires;
    }
}
