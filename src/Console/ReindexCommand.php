<?php

declare(strict_types=1);

namespace Thallo\Search\Console;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Search\Lifecycle\RebuildOutcome;
use Thallo\Search\Lifecycle\Reconciler;
use Thallo\Search\Lifecycle\SearchDemand;
use Thallo\Search\Lifecycle\StateRepository;
use Thallo\Search\Lifecycle\Workspace;
use Thallo\Search\Query\KindAvailability;

/**
 * The operator's way to ask for a rebuild (search block spec §3.5.10). It never writes to the
 * index itself: it records a rebuild request, and with `--wait` runs the rebuild in the foreground
 * under the same claim and fences as every other build — waiting for one already under way rather
 * than building alongside it. Filtered rebuilds are gone: a filtered build cannot safely sweep.
 */
#[AsCommand(name: 'search:reindex', description: 'Request a rebuild of the search index (add --wait to run it now).')]
final class ReindexCommand extends BaseCommand
{
    private const MAX_WAITS = 600;

    private readonly \Closure $sleep;

    public function __construct(
        private readonly SearchDemand $requests,
        private readonly Reconciler $reconciler,
        private readonly KindAvailability $availability,
        private readonly StateRepository $state,
        ?\Closure $sleep = null,
        private readonly ?Workspace $workspace = null,
    ) {
        $this->sleep = $sleep ?? static function (): void {
            sleep(1);
        };
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('kind', null, InputOption::VALUE_REQUIRED, 'Rebuild one kind (entries, products, …).')
            ->addOption('wait', null, InputOption::VALUE_NONE, 'Run the rebuild now and report.')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Removed: search rebuilds whole kinds.')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Removed: search rebuilds whole kinds.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('type') !== null || $input->getOption('locale') !== null) {
            $output->writeln(
                '<error>`--type`/`--locale` are no longer supported: search rebuilds whole kinds. '
                . 'Use `search:reindex --kind=entries`.</error>',
            );
            return self::FAILURE;
        }
        $kind = $input->getOption('kind');
        if (is_string($kind) && !$this->availability->isAvailable($kind)) {
            $output->writeln("<error>No search kind '{$kind}' is available.</error>");
            return self::FAILURE;
        }
        $kinds = is_string($kind) ? [$kind] : array_keys($this->availability->available());
        $wait = (bool) $input->getOption('wait');

        $failed = false;
        $each = function () use ($kinds, $kind, $wait, $output, &$failed): void {
            $this->requests->request(is_string($kind) ? $kind : null, 'manual');
            if (!$wait) {
                return;
            }
            foreach ($kinds as $one) {
                $outcome = $this->runWaiting($one);
                $failed = $failed || $outcome === RebuildOutcome::FAILED;
                $output->writeln($one . ': ' . match ($outcome) {
                    RebuildOutcome::PROMOTED => 'rebuilt',
                    RebuildOutcome::FAILED => 'failed — see `php glueful search:status`',
                    RebuildOutcome::LOST => 'taken over by another builder',
                    RebuildOutcome::BUSY => 'still being rebuilt by another process',
                    null => 'up to date',
                });
            }
        };
        if ($this->workspace !== null) {
            $this->workspace->each($each);
        } else {
            $each();
        }
        if (!$wait) {
            $output->writeln('Rebuild requested for ' . implode(', ', $kinds) . '.');
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** Run one kind, waiting while another process holds its build claim. */
    private function runWaiting(string $kind): ?RebuildOutcome
    {
        for ($waits = 0;; $waits++) {
            $outcome = $this->reconciler->runKind($kind);
            if ($outcome !== RebuildOutcome::BUSY || $waits >= self::MAX_WAITS) {
                return $outcome;
            }
            ($this->sleep)();
        }
    }
}
