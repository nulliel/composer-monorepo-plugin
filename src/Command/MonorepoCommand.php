<?php
declare(strict_types = 1);

namespace Conductor\Command;

use Conductor\Monorepo;
use Symfony\Component\Console\Command\Command;

abstract class MonorepoCommand extends Command
{
    public function __construct(protected Monorepo $monorepo)
    {
        parent::__construct();
    }
}
