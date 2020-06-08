<?php
declare(strict_types = 1);

namespace Conductor\Command;

use Composer\Package\Version\VersionParser;
use Composer\Package\Version\VersionSelector;
use Composer\Repository\RepositorySet;
use Conductor\Installer;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tightenco\Collect\Support\Collection;

class RequireCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName("require")
            ->setDescription("Adds packages to your monorepo.json and installs them.")
            ->setDefinition([
                new InputArgument("packages", InputArgument::IS_ARRAY | InputArgument::REQUIRED, "The package name and an optional version constraint. foo/bar or foo/bar:1.0.0 or foo/bar=1.0.0"),
                new InputOption("dev", null, InputOption::VALUE_NONE, "Add the packages to require-dev."),
            ])
            ->setHelp("");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        if (!$this->getMonorepo()->inPackage()) {
            throw new RuntimeException(
                "`composer require` may only be used within a package directory. This command will not create a package. If you would like to create a package run `composer new <packageName>`",
            );
        }

        $packages = $input->getArgument("packages");

        $phpVersion   = $this->getMonorepo()->getRepository()->findPackage("php", "*")->getPrettyVersion();
        $requirements = $this->determineRequirements($packages, $phpVersion);

        $versionParser = new VersionParser();
        $monorepoRepository = $this->getMonorepo()->getMonorepoRepository();

        foreach ($requirements as $package => $constraint) {
            if ($monorepoRepository->findPackage($package, $constraint)) {
                $requirements[$package] = "@dev";
            }

            $versionParser->parseConstraints($constraint);
        }

        $installer = new Installer($this->getMonorepo());

        return $installer
            ->setDev($input->getOption("dev"))
            ->setPackages($requirements)
            ->run();
    }

    /**
     * @param array<string> $requires
     *
     * @return array<string>
     */
    private function determineRequirements(array $requires, string $phpVersion): array
    {
        $parser   = new VersionParser();
        $requires = $parser->parseNameVersionPairs($requires);

        return Collection::wrap($requires)->reduce(function ($result, $require) use ($phpVersion): array {
            if (!isset($require["version"])) {
                $require["version"] = null;
            }

            [
                $name,
                $version,
            ] = $this->findBestVersionAndNameForPackage($require["name"], $require["version"], $phpVersion);

            $require["name"] = $name;

            if (!$require["version"]) {
                $require["version"] = $version;

                $this->getMonorepo()->getIO()->write(sprintf(
                    "Using version <info>%s</info> for <info>%s</info>",
                    $require["version"],
                    $require["name"],
                ));
            }

            $result[$require["name"]] = $require["version"];

            return $result;
        }, []);
    }

    /**
     * @return array<string>
     *
     * @throws InvalidArgumentException
     */
    private function findBestVersionAndNameForPackage(
        string $packageName,
        ?string $packageVersion,
        ?string $phpVersion
    ): array {
        $repositorySet = new RepositorySet();
        $repositorySet->addRepository($this->getMonorepo()->getRepository());

        $versionSelector = new VersionSelector($repositorySet);
        $package = $versionSelector->findBestCandidate($packageName, $packageVersion, "stable");

        if (!$package) {
            // Check whether the PHP version was the problem
            if ($phpVersion && $versionSelector->findBestCandidate($packageName, $packageVersion, null)) {
                throw new InvalidArgumentException(sprintf(
                    "Package %s at version %s has a PHP requirement incompatible with your PHP version (%s)",
                    $packageName,
                    $packageVersion,
                    $phpVersion,
                ));
            }

            // Check whether the required version was the problem
            if ($packageVersion && $versionSelector->findBestCandidate($packageName, null, $phpVersion)) {
                throw new InvalidArgumentException(sprintf(
                    "Could not find package %s in a version matching %s",
                    $packageName,
                    $packageVersion,
                ));
            }

            // Check whether the PHP version was the problem
            if ($phpVersion && $versionSelector->findBestCandidate($packageName)) {
                throw new InvalidArgumentException(sprintf(
                    "Could not find package %s in any version matching your PHP version (%s)",
                    $packageName,
                    $phpVersion,
                ));
            }

            throw new InvalidArgumentException(sprintf(
                "Could not find a matching version of package %s. Check the package spelling, your version constraint and that the package is available in a stability which matches your minimum-stability (stable).",
                $packageName,
            ));
        }

        return [
            $package->getPrettyName(),
            $package->getPrettyVersion(),
        ];
    }
}
