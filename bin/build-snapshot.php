<?php

/**
 * Rebuilds data/disposable-snapshot.json.gz from the public upstream list.
 *
 * Maintainer tool, not shipped functionality. Interim source until the
 * central email-guard data publishing pipeline exists; the output follows
 * the email-guard-disposable/1 format from the spec either way.
 *
 * Usage: php bin/build-snapshot.php
 */

declare(strict_types=1);

const UPSTREAM = 'https://raw.githubusercontent.com/disposable/disposable-email-domains/master/domains.txt';
const TARGET = __DIR__ . '/../data/disposable-snapshot.json.gz';

$raw = file_get_contents(UPSTREAM);
if ($raw === false) {
    fwrite(STDERR, "Cannot fetch upstream list\n");
    exit(1);
}

$domains = [];
foreach (explode("\n", $raw) as $line) {
    $line = strtolower(trim($line));
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    $domains[$line] = true;
}
$domains = array_keys($domains);
sort($domains, SORT_STRING);

$snapshot = [
    'format' => 'email-guard-disposable/1',
    'version' => date('Y.m.d'),
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'count' => count($domains),
    'domains' => $domains,
];

$json = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
file_put_contents(TARGET, gzencode($json, 9));

printf("Wrote %s: %d domains, version %s, %.1f KB gzipped\n", TARGET, count($domains), $snapshot['version'], filesize(TARGET) / 1024);
