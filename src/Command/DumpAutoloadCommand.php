<?php
declare(strict_types=1);

namespace Conductor\Command;

use Conductor\Autoload\AutoloadGenerator;
use Conductor\Package\MonorepoPackage;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class DumpAutoloadCommand extends MonorepoCommand
{
    protected function configure()
    {
        $this
            ->setName("dump-autoload")
            ->setAliases(["dump", "dumpautoload"])
            ->setDescription("")
            ->setDefinition([
                new InputOption("no-dev", null, InputOption::VALUE_NONE, "Disables autoload-dev rules."),
            ])
            ->setHelp("");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->monorepo->monorepoRepository->getPackages() as $package) {
            if (!$package instanceof MonorepoPackage) {
                continue;
            }

            $autoloadGenerator = new AutoloadGenerator($this->monorepo->io, !$input->getOption("no-dev"));

            $numberOfClasses = $autoloadGenerator->dump($package);

            $this->monorepo->io->write("<info>Generated autoload files for package " . $package->getPrettyName() . " containing " . $numberOfClasses . " classes</info>");
        }

        return 0;
    }
}
