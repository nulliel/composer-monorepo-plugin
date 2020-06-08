<?php
declare(strict_types = 1);

namespace Conductor\Command;

use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Conductor\Monorepo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class BaseCommand extends Command
{
    private Monorepo $monorepo;

    public function __construct(Monorepo $monorepo)
    {
        $this->monorepo = $monorepo;

        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $commandEvent = new CommandEvent(PluginEvents::COMMAND, $this->getName(), $input, $output);
        $dispatcher   = $this->getMonorepo()->getComposer()->getEventDispatcher();

        $dispatcher->dispatch($commandEvent->getName(), $commandEvent);

        return 0;
    }

    public function getMonorepo(): Monorepo
    {
        return $this->monorepo;
    }
}
