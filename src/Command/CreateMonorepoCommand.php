<?php
declare(strict_types = 1);

namespace Conductor\Command;

use Conductor\Io\File;
use Conductor\Monorepo;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class CreateMonorepoCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName("create-monorepo")
            ->setDescription("Creates a basic monorepo.json file in the current directory");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (Monorepo::inMonorepo()) {
            $output->writeln("<error>The `create-monorepo` command may not be run inside of an existing monorepo. To create a library or application, see the respective `create-library` and `create-application` commands.</error>");
            return 0;
        }

        $file = new File(getcwd() . "/monorepo.json");
        $json = json_encode([
            "version" => "1.0.0",
            "require" => new stdClass(),
            "require-dev" => new stdClass(),
            "config" => [
                "monorepo" => [
                    "app-dirs" => [
                        "src/*",
                    ],
                    "lib-dirs" => [
                        "lib/*",
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        if (!$file->write($json)) {
            $output->writeln("<error>Failed to write monorepo.json</error>");
            return 1;
        }

        $output->writeln("<info>Created monorepo.json</info>");
        return 0;
    }
}
