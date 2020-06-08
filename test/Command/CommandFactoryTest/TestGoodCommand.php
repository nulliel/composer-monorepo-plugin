<?php
declare(strict_types = 1);

namespace Command\CommandFactoryTest;

use Conductor\Command\Command;
use Conductor\Command\CommandConfig;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TestGoodCommand extends Command
{
    /**
     * @var CommandConfig $testConfig
     */
    public $testConfig;

    /**
     * Test Dependency Injection
     *
     * @param CommandConfig $config
     */
    public function __construct(CommandConfig $config)
    {
        $this->testConfig = $config;
    }

    /**
     * Test
     *
     * @return string
     */
    public static function getCommandName() : string
    {
        return "command:name";
    }

    /**
     * Test
     *
     * @return void
     */
    protected function configureCommand()
    {
    }

    /**
     * Test
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return void
     */
    protected function executeCommand(InputInterface $input, OutputInterface $output)
    {
    }

}
