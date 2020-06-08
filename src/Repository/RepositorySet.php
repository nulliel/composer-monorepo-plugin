<?php
declare(strict_types = 1);

namespace Conductor\Repository;

use Composer\Package\Link;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositorySet as ComposerRepositorySet;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Conductor\MonorepoPackage;

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

        $stabilityFlags = $this->getStabilityFlags();
        $rootAliases    = $this->getRootAliases();
        $rootReferences = $package->getComposer()->getPackage()->getReferences();

        /**
         * @psalm-suppress MixedArgumentTypeCoercion
         */
        parent::__construct("stable", $stabilityFlags, $rootAliases, $rootReferences, $this->getRequires($packages));
    }

    /**
     * @return array<ConstraintInterface|null>
     */
    private function getRequires(array $packages): array
    {
        $composer = $this->package->getComposer();

        $requires = [];
        $rootRequires = [];

        if ($this->isUpdate) {
            $requires = array_merge(
                $composer->getPackage()->getRequires(),
                $composer->getPackage()->getDevRequires(),
            );
        } else {
            foreach ($this->package->getMonorepo()->getComposer()->getLocker()->getLockedRepository($this->isDev)->getPackages() as $package) {
                $constraint = new Constraint("=", $package->getVersion());
                $constraint->setPrettyString($package->getPrettyVersion());
                $requires[$package->getName()] = $constraint;
            }
        }

        foreach ($requires as $require => $constraint) {
            assert(is_string($require));

            if (preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $require)) {
                continue;
            }

            if (in_array($require, array_keys($packages))) {
                $rootRequires[$require] = $constraint = $packages[$require];
            }

            if ($constraint instanceof Link) {
                $rootRequires[$require] = $constraint->getConstraint();
            } else {
                $rootRequires[$require] = $constraint;
            }
        }

        return $rootRequires;
    }

    /**
     * @return list<array{package: string, version: string, alias: string, alias_normalized: string}>
     *
     * @psalm-suppress MixedInferredReturnType
     * @psalm-suppress MixedReturnStatement
     */
    private function getRootAliases(): array
    {
        return $this->isUpdate
            ? $this->package->getComposer()->getPackage()->getAliases()
            : $this->package->getMonorepo()->getComposer()->getLocker()->getAliases();
    }

    /**
     * @return array<string, int>
     *
     * @psalm-suppress MixedInferredReturnType
     * @psalm-suppress MixedReturnStatement
     */
    private function getStabilityFlags(): array
    {
        return $this->isUpdate
            ? $this->package->getComposer()->getPackage()->getStabilityFlags()
            : $this->package->getMonorepo()->getComposer()->getLocker()->getStabilityFlags();
    }
}
