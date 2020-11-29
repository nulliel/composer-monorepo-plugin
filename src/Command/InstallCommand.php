<?php
declare(strict_types = 1);

namespace Conductor\Command;

use Conductor\Installer;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class InstallCommand extends MonorepoCommand
{
    protected function configure(): void
    {
        $this
            ->setName("install")
            ->setDescription("Installs project dependencies defined by composer.json")
            ->setDefinition([
                new InputOption("no-dev", null, InputOption::VALUE_NONE, "Disables installation of require-dev packages."),
            ]);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $installer = new Installer($this->monorepo);

        return $installer
            ->setDev(!$input->getOption("no-dev"))
            ->run();
    }
}
