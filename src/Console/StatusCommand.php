<?php

declare(strict_types=1);

namespace Thallo\Search\Console;

use Glueful\Console\BaseCommand;
use Thallo\Contracts\Schema\ContentTypeReader;
use Thallo\Search\Engine\SearchBackend;
use Thallo\Search\Index\DocumentBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'search:status', description: 'Report search backend health and configuration warnings.')]
final class StatusCommand extends BaseCommand
{
    public function __construct(
        private readonly SearchBackend $backend,
        private readonly DocumentBuilder $builder,
        private readonly ContentTypeReader $types,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $healthy = $this->backend->health();
        $output->writeln($healthy
            ? '<info>Backend: reachable, index present.</info>'
            : '<error>Backend: UNREACHABLE (GET /v1/search will return 503).</error>');

        // Per-type config-field validation. The injected DocumentBuilder is the single
        // source of the configured types — never re-read the config tree it was built from.
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

        foreach ($warnings as $w) {
            $output->writeln('<comment>' . $w . '</comment>');
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
