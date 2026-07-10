<?php

declare(strict_types=1);

use Thallo\Search\Http\SearchController;
use Glueful\Routing\Router;

/** @var Router $router */

// Public content search. Optional API key narrows visibility; anonymous sees public content.
$router->get('/v1/search', [SearchController::class, 'search'])
    ->middleware('tenant_bootstrap')
    ->middleware('optional_api_key')
    ->middleware('rate_limit')
    ->rateLimit(120, 1, by: 'user');
