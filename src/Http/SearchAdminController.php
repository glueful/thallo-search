<?php

declare(strict_types=1);

namespace Thallo\Search\Http;

use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Search\SearchSourceRegistry;
use Thallo\Search\Lifecycle\DemandResolver;
use Thallo\Search\Lifecycle\ErrorText;
use Thallo\Search\Lifecycle\SearchDemand;
use Thallo\Search\Lifecycle\StateRepository;
use Thallo\Search\Query\KindAvailability;
use Thallo\Search\Store\IndexStore;

/**
 * Settings › Search's API (search block spec §3.8), behind `content.manage`. `status` is the
 * engine's readiness and each kind's index state for this workspace; `rebuild` records demand in
 * the request's transaction and queues the wake-up after it commits, answering 202 either way —
 * the demand is recorded, and the schedule picks it up if the queue could not.
 */
final class SearchAdminController
{
    public function __construct(
        private readonly SearchDemand $requests,
        private readonly StateRepository $state,
        private readonly DemandResolver $demand,
        private readonly KindAvailability $availability,
        private readonly SearchSourceRegistry $sources,
        private readonly IndexStore $store,
        private readonly int $stallAfterSeconds,
    ) {
    }

    public function status(): Response
    {
        $readiness = $this->store->readiness();
        $kinds = [];
        foreach ($this->sources->all() as $kind => $contributor) {
            $row = $this->state->row($kind);
            $available = $this->availability->isAvailable($kind);
            $pending = $available ? $this->demand->pending($kind) : null;
            $error = isset($row['last_error']) ? ErrorText::sanitize((string) $row['last_error']) : null;
            $kinds[] = [
                'kind' => $kind,
                'label' => $contributor->label(),
                'available' => $available,
                'reason' => $available ? null : $this->availability->reasonFor($kind),
                'status' => (string) ($row['status'] ?? 'pending'),
                'documents' => (int) ($row['documents'] ?? 0),
                'processed' => (int) ($row['processed'] ?? 0),
                'last_success_at' => $row['last_success_at'] ?? null,
                'last_error' => $error,
                'demand_pending' => $pending !== null,
                'stalled' => $pending !== null && $this->stalled($kind, $row, (string) $error),
            ];
        }
        return Response::success([
            'engine' => [
                'ready' => $readiness->available,
                'message' => $readiness->message,
                'version' => $readiness->version,
            ],
            'kinds' => $kinds,
        ]);
    }

    public function rebuild(Request $request): Response
    {
        $body = json_decode((string) $request->getContent(), true);
        $kind = is_array($body) && is_string($body['kind'] ?? null) && $body['kind'] !== '' ? $body['kind'] : null;
        if ($kind !== null && !$this->availability->isAvailable($kind)) {
            return Response::error(
                isset($this->sources->all()[$kind])
                    ? 'That search kind is not available: ' . $this->availability->reasonFor($kind) . '.'
                    : 'No search kind is called that.',
                422,
            );
        }
        $result = $this->requests->request($kind, 'manual');
        $response = Response::success(
            ['recorded' => true, 'kinds' => $result['kinds'], 'queued' => $result['queued']],
            $result['queued'] === false
                ? 'Rebuild requested; it will start when background processing runs.'
                : 'Rebuild requested.',
        );
        $response->setStatusCode(202);
        return $response;
    }

    /**
     * Background processing has not picked a request up: the wake-up could not be queued, or the
     * oldest outstanding demand is older than the threshold and no build has claimed the kind.
     *
     * @param array<string, mixed>|null $row
     */
    private function stalled(string $kind, ?array $row, string $error): bool
    {
        if (str_starts_with($error, 'queue:')) {
            return true;
        }
        if ($row === null || $row['owner_token'] !== null) {
            return false;
        }
        $oldest = $this->state->oldestUnsatisfiedDemandAt($kind);
        return $oldest !== null
            && strtotime($this->state->now() . ' UTC') - strtotime($oldest . ' UTC') > $this->stallAfterSeconds;
    }
}
