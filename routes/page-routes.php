<?php

declare(strict_types=1);

use Glueful\Routing\Router;
use Thallo\Search\Http\SearchPageController;

/** @var Router $router */

// The results page, registered whether or not search is on: while it is off the path answers the
// theme's 404 rather than the framework's JSON one (search block spec §3.7).
$router->get('/search', [SearchPageController::class, 'page'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap']);
