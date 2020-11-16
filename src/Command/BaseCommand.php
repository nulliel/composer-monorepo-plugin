<?php
declare(strict_types = 1);

namespace Conductor\Command;

use Conductor\Monorepo;
use Symfony\Component\Console\Command\Command;

abstract class BaseCommand extends Command
{
    private Monorepo $monorepo;

    public function getMonorepo(): Monorepo
    {
        return $this->monorepo ??= Monorepo::create($this->getApplication()->getIO());
    }
}
