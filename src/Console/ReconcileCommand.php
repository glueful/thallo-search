<?php

declare(strict_types=1);

namespace Thallo\Search\Console;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Search\Lifecycle\Reconciler;

/**
 * The scheduled reconcile (search block spec §3.5.7): every workspace's outstanding demand, every
 * minute; with `--full`, a rebuild of every available kind (scheduled daily), which is what repairs
 * a change lost between a commit and its after-commit event.
 */
#[AsCommand(name: 'search:reconcile', description: 'Rebuild search index kinds with outstanding demand (--full: all).')]
final class ReconcileCommand extends BaseCommand
{
    public function __construct(private readonly Reconciler $reconciler)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'full',
            null,
            InputOption::VALUE_NONE,
            'Rebuild every available kind, even ones reporting ready.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->reconciler->runAll((bool) $input->getOption('full'));
        return self::SUCCESS;
    }
}
