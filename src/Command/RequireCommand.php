<?php
declare(strict_types = 1);

namespace Conductor\Command;

use Composer\Package\Version\VersionParser;
use Composer\Package\Version\VersionSelector;
use Composer\Repository\RepositorySet;
use Conductor\Installer;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RequireCommand extends MonorepoCommand
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
        if (!$this->monorepo->inPackage()) {
            throw new RuntimeException("`composer require` may only be used within a package directory. This command will not create a package. If you would like to create a package run `composer new <packageName>`");
        }

        $packages = $input->getArgument("packages");
        $isDev    = $input->getOption("dev");

        $phpVersion = $this->monorepo->getRepository()->findPackage("php", "*")->getPrettyVersion();
        $requirements = $this->determineRequirements($packages, $phpVersion);

        $versionParser = new VersionParser();
        $monorepoRepository = $this->monorepo->monorepoRepository;

        foreach ($requirements as $package => $constraint) {
            if ($monorepoRepository->findPackage($package, $constraint)) {
                $requirements[$package] = "@internal";
            }

            $versionParser->parseConstraints($constraint);
        }

        $installer = new Installer($this->monorepo);

        return $installer->run($requirements, $isDev, isUpdate: true);
    }

    /**
     * @param array<string> $requires
     * @param string        $phpVersion
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

            [$name, $version] = $this->findBestVersionAndNameForPackage($require["name"], $require["version"], $phpVersion);

            $require["name"] = $name;

            if (!$require["version"]) {
                $require["version"] = $version;

                $this->monorepo->io->write(sprintf(
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
     * @param string  $packageName
     * @param ?string $packageVersion
     * @param ?string $phpVersion
     *
     * @return array<string>
     */
    private function findBestVersionAndNameForPackage(string $packageName, ?string $packageVersion = null, ?string $phpVersion = null): array
    {
        $repositorySet = new RepositorySet();
        $repositorySet->addRepository($this->monorepo->getRepository());

        $versionSelector = new VersionSelector($repositorySet);
        $package = $versionSelector->findBestCandidate($packageName, $packageVersion);

        if (!$package) {
            // Check whether the PHP version was the problem
            if ($phpVersion && $versionSelector->findBestCandidate($packageName, $packageVersion, ignorePlatformReqs: true)) {
                throw new InvalidArgumentException(sprintf(
                    "Package %s at version %s has a PHP requirement incompatible with your PHP version (%s)",
                    $packageName,
                    $packageVersion,
                    $phpVersion,
                ));
            }

            // Check whether the required version was the problem
            if ($packageVersion && $versionSelector->findBestCandidate($packageName)) {
                throw new InvalidArgumentException(sprintf(
                    "Could not find package %s in a version matching %s",
                    $packageName,
                    $packageVersion,
                ));
            }

            // Check whether the PHP version was the problem
            if ($phpVersion && $versionSelector->findBestCandidate($packageName, $packageVersion)) {
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
