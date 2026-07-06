<?php

declare(strict_types=1);

namespace Thallo\Search\Query;

use Glueful\Auth\ApiKey\ApiKeyService;
use Thallo\Contracts\Schema\ContentTypeReader;

/**
 * Mirrors DeliveryAccessMiddleware for search: for each live content type, the request may
 * see it when the type opts into public delivery or a granted scope satisfies
 * `read:content:{slug}` (fnmatch semantics — wildcards like `read:content:*` behave exactly
 * as delivery treats them). Resolved from the live schema store per request, never from
 * values denormalized into the index, so a type flipped private drops out of search
 * immediately.
 */
final class VisibilityResolver
{
    public function __construct(private readonly ContentTypeReader $types)
    {
    }

    /** @param list<string>|null $grantedScopes null = anonymous (no api_key_scopes attribute). */
    public function resolve(?array $grantedScopes): VisibilityContext
    {
        // An authenticated key with an empty scope list has full access (framework convention).
        if ($grantedScopes !== null && ApiKeyService::scopeSatisfies($grantedScopes, 'read:content')) {
            return new VisibilityContext(true, []);
        }

        $visible = [];
        foreach ($this->types->deliveryTypes() as $uuid => $type) {
            $accessible = $type['public_delivery']
                || ($grantedScopes !== null
                    && ApiKeyService::scopeSatisfies($grantedScopes, 'read:content:' . $type['slug']));
            if ($accessible) {
                $visible[] = $uuid;
            }
        }

        return new VisibilityContext(false, $visible);
    }

    /** Delivery-parity accessibility for a PROVIDED type: all-access or in the visible set. */
    public function isTypeAccessible(VisibilityContext $ctx, string $typeUuid): bool
    {
        return $ctx->allAccess || in_array($typeUuid, $ctx->visibleTypeUuids, true);
    }
}
