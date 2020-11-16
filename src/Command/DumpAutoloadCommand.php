<?php
declare(strict_types=1);

namespace Conductor\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class DumpAutoloadCommand extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName("dump-autoload")
            ->setAliases(["dump", "dumpautoload"])
            ->setDescription("")
            ->setDefinition([

            ])
            ->setHelp("");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {

    }
}
