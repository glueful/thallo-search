<?php

declare(strict_types=1);

use Glueful\Routing\Router;
use Thallo\Search\Http\SearchAdminController;

/** @var Router $router */

// Settings › Search (search block spec §3.8): the index's status and Rebuild, for those who manage
// settings — permission content.manage, as Settings › General.
$router->group(
    [
        'prefix' => '/v1/admin/search',
        'middleware' => ['auth', 'tenant_profile:admin', 'tenant_bootstrap', 'admin_tenant_binding'],
    ],
    function (Router $router): void {
        $router->get('/status', [SearchAdminController::class, 'status'])
            ->middleware('content_permission:content.manage')
            ->name('thallo.search.admin.status');
        $router->post('/rebuild', [SearchAdminController::class, 'rebuild'])
            ->middleware('content_permission:content.manage')
            ->name('thallo.search.admin.rebuild');
    },
);
