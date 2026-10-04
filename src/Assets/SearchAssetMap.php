<?php

declare(strict_types=1);

namespace Thallo\Search\Assets;

/**
 * The fingerprinted allowlist for `GET /_thallo/search/{file}` — the shop's asset map, for the
 * search pack's own `assets/` directory. Built once from that directory, never from request input:
 * a name it did not compute (any traversal attempt included) simply misses.
 */
final class SearchAssetMap
{
    /** @var array<string,string> fingerprinted filename => absolute path */
    private readonly array $filesByName;

    /** @var array<string,string> logical filename (e.g. 'shop.js') => fingerprinted filename */
    private readonly array $fingerprintsByLogicalName;

    public function __construct(string $assetsDir)
    {
        $filesByName = [];
        $fingerprints = [];

        $dir = rtrim($assetsDir, '/');
        $entries = is_dir($dir) ? (scandir($dir) ?: []) : [];
        sort($entries); // deterministic across platforms/filesystems

        foreach ($entries as $entry) {
            if (!is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }
            if (!str_ends_with($entry, '.js') && !str_ends_with($entry, '.css')) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (!is_file($path)) {
                continue;
            }
            $contents = (string) file_get_contents($path);
            $hash = substr(hash('sha256', $contents), 0, 12);
            $ext = pathinfo($entry, PATHINFO_EXTENSION);
            $stem = pathinfo($entry, PATHINFO_FILENAME);
            $fingerprintedName = $stem . '-' . $hash . '.' . $ext;

            $filesByName[$fingerprintedName] = $path;
            $fingerprints[$entry] = $fingerprintedName;
        }

        $this->filesByName = $filesByName;
        $this->fingerprintsByLogicalName = $fingerprints;
    }

    /** Exact allowlist lookup — an unknown name (or any traversal attempt) always misses. */
    public function resolve(string $filename): ?string
    {
        return $this->filesByName[$filename] ?? null;
    }

    /** e.g. `fingerprintedName('shop.js')` => `'shop-<hash>.js'`, or null if the file is missing. */
    public function fingerprintedName(string $logicalName): ?string
    {
        return $this->fingerprintsByLogicalName[$logicalName] ?? null;
    }
}
