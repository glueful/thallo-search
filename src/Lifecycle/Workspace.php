<?php

declare(strict_types=1);

namespace Thallo\Search\Lifecycle;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Thallo\Contracts\Settings\SystemChannel;

/**
 * The workspace boundary for search (search block spec §3.3). With tenancy enforced, the current
 * workspace comes from the server's tenant context, never the request; queued work names its
 * workspace and runs inside it. Without enforcement there is one store and no workspace segment.
 * Tenancy's flags are read afresh through the system channel, as another process may change them.
 */
final class Workspace
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function enforcementActive(): bool
    {
        $container = $this->context->getContainer();
        if (!$container->has(SystemChannel::class)) {
            return false;
        }
        $flags = $container->get(SystemChannel::class);
        if (method_exists($flags, 'clearCache')) {
            $flags->clearCache();
        }
        return $flags->get('tenancy.enabled') === '1'
            && $flags->get('tenancy.enable_step') === 'on'
            && $flags->get('tenancy.retrofit_active') !== '1';
    }

    /** The current workspace's uuid, or null on a single-store site. */
    public function current(): ?string
    {
        if (!$this->enforcementActive()) {
            return null;
        }
        $container = $this->context->getContainer();
        if (!$container->has(CurrentTenantResolver::class)) {
            return null;
        }
        $uuid = $container->get(CurrentTenantResolver::class)->tenantUuid($this->context);
        return $uuid === '' ? null : $uuid;
    }

    /** Run `$fn` inside `$workspace` when tenancy is enforced; otherwise just run it. */
    public function run(?string $workspace, callable $fn): mixed
    {
        $container = $this->context->getContainer();
        $scoped = $workspace !== null && $workspace !== '' && $this->enforcementActive();
        if ($scoped && $container->has(TenantContextRunner::class)) {
            return $container->get(TenantContextRunner::class)->runAsTenant($workspace, $fn);
        }
        return $fn();
    }

    /** Run `$fn` once per active workspace, inside it, or once on a single-store site. */
    public function each(callable $fn): void
    {
        $container = $this->context->getContainer();
        if ($this->enforcementActive() && $container->has(TenantContextRunner::class)) {
            $container->get(TenantContextRunner::class)->forEachTenant(fn () => $fn($this->current()));
            return;
        }
        $fn(null);
    }

    /** Run `$fn` as the system, across workspaces, when tenancy is enforced. */
    public function asSystem(callable $fn): mixed
    {
        $container = $this->context->getContainer();
        if ($this->enforcementActive() && $container->has(TenantContextRunner::class)) {
            return $container->get(TenantContextRunner::class)->runAsSystem($fn);
        }
        return $fn();
    }
}
