<?php
declare(strict_types = 1);

namespace Conductor\Composer;

use Composer\Composer;
use Composer\Console\Application;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Conductor\Command\InstallCommand;
use Conductor\Command\RequireCommand;
use Conductor\Monorepo;
use Symfony\Component\Console\Input\ArgvInput;

// phpcs:ignoreFile

/**
 * The entry point of the composer plugin.
 *
 *   https://getcomposer.org/doc/articles/plugins.md
 *
 */
final class Plugin implements PluginInterface
{
    public function activate(Composer $composer, IOInterface $io): void
    {
        $application = $this->getApplication($io);
        $monorepo    = Monorepo::create($io);

        if (!$monorepo->isMonorepo()) {
            return;
        }

        $application->add(new InstallCommand($monorepo));
        $application->add(new RequireCommand($monorepo));
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    private function getApplication(IOInterface $io): Application
    {
        $backtrace = debug_backtrace();

        foreach ($backtrace as $trace) {
            if (!isset($trace["object"]) || !isset($trace["args"][0])) {
                continue;
            }

            if (!$trace["object"] instanceof Application || !$trace["args"][0] instanceof ArgvInput) {
                continue;
            }

            return $trace["object"];
        }

        $io->writeError("<error>Could not obtain the composer application.</error>");

        exit(1);
    }
}
