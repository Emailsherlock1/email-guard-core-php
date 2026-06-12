<?php

/**
 * Refreshes the bundled snapshot from a pinned email-guard-data release.
 *
 * Maintainer tool, not shipped functionality. Bump DATA_TAG to the release
 * you want to embed, run the script, commit, release. Pinning a tag (not a
 * branch) keeps the offline protection identical per core-lib release
 * across languages, as the spec requires (section 9.1).
 *
 * Usage: php bin/build-snapshot.php
 */

declare(strict_types=1);

const DATA_TAG = 'v2026.06.13';
const SOURCE = 'https://raw.githubusercontent.com/Emailsherlock1/email-guard-data/' . DATA_TAG . '/disposable-snapshot.json.gz';
const TARGET = __DIR__ . '/../data/disposable-snapshot.json.gz';

$raw = file_get_contents(SOURCE);
if ($raw === false) {
    fwrite(STDERR, 'Cannot fetch ' . SOURCE . "\n");
    exit(1);
}

// Validate before embedding: a malformed artifact must fail the build,
// not ship inside the package.
$decoded = json_decode((string) gzdecode($raw), true);
if (
    !is_array($decoded)
    || ($decoded['format'] ?? null) !== 'email-guard-disposable/1'
    || !is_array($decoded['domains'] ?? null)
    || ($decoded['count'] ?? -1) !== count($decoded['domains'])
) {
    fwrite(STDERR, "Artifact failed validation against email-guard-disposable/1\n");
    exit(1);
}

file_put_contents(TARGET, $raw);

printf(
    "Embedded %s: %d domains, version %s, %.1f KB gzipped\n",
    DATA_TAG,
    $decoded['count'],
    $decoded['version'],
    filesize(TARGET) / 1024,
);
