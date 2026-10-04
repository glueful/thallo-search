<?php

declare(strict_types=1);

namespace Thallo\Search\Console;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Contracts\Schema\ContentTypeReader;
use Thallo\Contracts\Search\SearchSourceRegistry;
use Thallo\Contracts\Settings\SystemChannel;
use Thallo\Search\Index\DocumentBuilder;
use Thallo\Search\Lifecycle\DemandResolver;
use Thallo\Search\Lifecycle\StateRepository;
use Thallo\Search\Lifecycle\Workspace;
use Thallo\Search\Query\KindAvailability;
use Thallo\Search\Store\IndexStore;

/**
 * Where the search index stands (search block spec §3.8): the engine and its readiness, then each
 * kind's status, documents, progress, last success, last error and outstanding demand — the same
 * table as Settings › Search. `--all` covers every workspace and the installation-wide legacy
 * index state.
 */
#[AsCommand(name: 'search:status', description: 'Report the search engine and each kind\'s index status.')]
final class StatusCommand extends BaseCommand
{
    public function __construct(
        private readonly IndexStore $store,
        private readonly KindAvailability $availability,
        private readonly SearchSourceRegistry $sources,
        private readonly StateRepository $state,
        private readonly DemandResolver $demand,
        private readonly Workspace $workspace,
        private readonly SystemChannel $flags,
        private readonly DocumentBuilder $builder,
        private readonly ContentTypeReader $types,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'all',
            null,
            InputOption::VALUE_NONE,
            'Every workspace, and the installation-wide legacy index.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $readiness = $this->store->readiness();
        $output->writeln($readiness->available
            ? '<info>Engine ready'
                . ($readiness->version !== null ? " (version {$readiness->version})" : '') . '.</info>'
            : '<error>' . (string) $readiness->message . '</error>');

        if ((bool) $input->getOption('all')) {
            $this->workspace->each(function (?string $workspace) use ($output): void {
                $output->writeln('Workspace: ' . ($workspace ?? 'single store'));
                $this->kindTable($output);
            });
            $output->writeln('Legacy index: ' . ($this->flags->get('search.legacy_index') ?? 'present'));
        } else {
            $this->kindTable($output);
        }

        foreach ($this->configWarnings() as $warning) {
            $output->writeln('<comment>' . $warning . '</comment>');
        }
        return $readiness->available ? self::SUCCESS : self::FAILURE;
    }

    private function kindTable(OutputInterface $output): void
    {
        $rows = [];
        foreach ($this->sources->all() as $kind => $contributor) {
            $row = $this->state->row($kind);
            $available = $this->availability->isAvailable($kind);
            $rows[] = [
                $contributor->label() . " ({$kind})",
                $available ? (string) ($row['status'] ?? 'pending') : (string) $this->availability->reasonFor($kind),
                (string) ($row['documents'] ?? 0),
                (string) ($row['processed'] ?? 0),
                (string) ($row['last_success_at'] ?? '—'),
                (string) ($row['last_error'] ?? '—'),
                $available ? ($this->demand->pending($kind) ?? '—') : '—',
            ];
        }
        (new Table($output))
            ->setHeaders(['Kind', 'Status', 'Documents', 'Processed', 'Last success', 'Last error', 'Demand'])
            ->setRows($rows)
            ->render();
    }

    /** @return list<string> */
    private function configWarnings(): array
    {
        $warnings = [];
        foreach ($this->builder->configuredTypeSlugs() as $slug) {
            $uuid = $this->types->findUuidBySlug($slug);
            if ($uuid === null) {
                $warnings[] = "[{$slug}] configured type has no matching content type (skipped).";
                continue;
            }
            $schema = $this->types->schemaFor($uuid);
            if ($schema !== null) {
                $warnings = array_merge($warnings, $this->builder->validate($slug, $schema));
            }
        }
        return $warnings;
    }
}
