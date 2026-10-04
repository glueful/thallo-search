<?php

declare(strict_types=1);

use Thallo\Search\Http\SearchController;
use Thallo\Search\Http\SuggestController;
use Glueful\Routing\Router;

/** @var Router $router */

// Public content search. Optional API key narrows visibility; anonymous sees public content.
$router->get('/v1/search', [SearchController::class, 'search'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap'])
    ->middleware('optional_api_key')
    ->middleware('rate_limit')
    ->rateLimit(120, 1, by: 'user');

// The Search block's live suggestions: always public, never cached (search block spec §3.4).
$router->get('/_search/suggest', [SuggestController::class, 'suggest'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap'])
    ->middleware('rate_limit')
    ->rateLimit(120, 1, by: 'ip');
