<?php

declare(strict_types=1);

namespace Thallo\Search\Lifecycle;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Queue\Job;

/**
 * The wake-up queued after a rebuild request commits. It carries the workspace it was requested in
 * and reconciles inside it; the demand itself is in the database, so this job is only ever an
 * early start for what the schedule would do anyway.
 */
final class SearchWakeJob extends Job
{
    public function handle(): void
    {
        $context = $this->context;
        if (!$context instanceof ApplicationContext) {
            throw new \RuntimeException('SearchWakeJob requires an ApplicationContext.');
        }
        $data = $this->getData();
        $workspace = $data['workspace'] ?? null;
        $drain = $data['drain'] ?? null;
        $container = $context->getContainer();
        $container->get(Workspace::class)->forget(); // a long-lived worker reads the flags afresh per job
        $container->get(Workspace::class)->run(
            is_string($workspace) ? $workspace : null,
            // A live change asks for its kind's backlog to be drained; a rebuild request for a reconcile.
            static fn () => is_string($drain)
                ? $container->get(Reconciler::class)->drainBacklog($drain)
                : $container->get(Reconciler::class)->runWorkspace(false),
        );
    }
}
