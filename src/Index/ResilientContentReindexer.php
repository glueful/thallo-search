<?php

declare(strict_types=1);

namespace Thallo\Search\Index;

use Thallo\Contracts\Search\ContentReindexer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Wraps the real reindexer so a search-backend failure is caught + logged and NEVER breaks
 * publishing (the seam runs in the pipeline's afterCommit). `search:reindex` recovers later.
 */
final class ResilientContentReindexer implements ContentReindexer
{
    public function __construct(
        private readonly ContentReindexer $inner,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function reindexEntry(string $entryUuid, ?string $locale): void
    {
        try {
            $this->inner->reindexEntry($entryUuid, $locale);
        } catch (Throwable $e) {
            $this->logger->warning('thallo-search reindex failed; skipping (recover via search:reindex).', [
                'entry' => $entryUuid,
                'locale' => $locale,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
