<?php

declare(strict_types=1);

namespace Thallo\Search\Assets;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves `search.js` and `search.css`: a logical name redirects to its fingerprinted one, a
 * fingerprinted name is served immutable for a year (the URL is the cache-buster), anything else is
 * a plain 404.
 */
final class SearchAssetController
{
    public function __construct(private readonly SearchAssetMap $assets)
    {
    }

    public function serve(string $file): Response
    {
        $alias = $this->assets->fingerprintedName($file);
        if ($alias !== null) {
            return new RedirectResponse('/_thallo/search/' . rawurlencode($alias), 302);
        }
        $path = $this->assets->resolve($file);
        $cache = 'public, max-age=31536000, immutable';
        if ($path === null && preg_match('/\A([a-z][a-z0-9-]*)-[a-f0-9]+\.(js|css)\z/', $file, $m) === 1) {
            // An earlier deploy's fingerprint, named by a page cached before it: today's file, briefly
            // cached, so the block keeps its script until the page itself is re-rendered.
            $current = $this->assets->fingerprintedName($m[1] . '.' . $m[2]);
            $path = $current !== null ? $this->assets->resolve($current) : null;
            $cache = 'public, max-age=300';
        }
        if ($path === null || !is_file($path)) {
            return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => str_ends_with(
                $file,
                '.css',
            ) ? 'text/css; charset=UTF-8' : 'application/javascript; charset=UTF-8',
            'Cache-Control' => $cache,
        ]);
    }
}
