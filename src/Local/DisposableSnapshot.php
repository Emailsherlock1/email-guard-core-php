<?php

declare(strict_types=1);

namespace Emailsherlock\EmailGuard\Local;

use Emailsherlock\EmailGuard\Exception\SnapshotException;

/**
 * The bundled disposable-domain snapshot (email-guard-spec, sections 4.3
 * and 9.1). Matching is exact by design: the Verify API matches its live
 * list the same way, and the local check must never block an address the
 * API would let pass.
 */
final class DisposableSnapshot
{
    private const FORMAT = 'email-guard-disposable/1';

    /** @var array<string, true>|null */
    private ?array $domains = null;

    private ?string $version = null;

    public function __construct(
        private readonly string $path = __DIR__ . '/../../data/disposable-snapshot.json.gz',
    ) {
    }

    /**
     * @param string $domain lowercased domain part
     */
    public function isDisposable(string $domain): bool
    {
        $this->load();

        return isset($this->domains[$domain]);
    }

    public function version(): string
    {
        $this->load();

        return $this->version ?? '';
    }

    private function load(): void
    {
        if ($this->domains !== null) {
            return;
        }

        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            throw new SnapshotException("Cannot read disposable snapshot at {$this->path}");
        }
        if (str_ends_with($this->path, '.gz')) {
            $raw = @gzdecode($raw);
            if ($raw === false) {
                throw new SnapshotException("Cannot gunzip disposable snapshot at {$this->path}");
            }
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT || !is_array($data['domains'] ?? null)) {
            throw new SnapshotException("Disposable snapshot at {$this->path} is not a valid " . self::FORMAT . ' document');
        }
        if (($data['count'] ?? -1) !== count($data['domains'])) {
            throw new SnapshotException("Disposable snapshot at {$this->path} fails its count check");
        }

        $this->domains = array_fill_keys($data['domains'], true);
        $this->version = is_string($data['version'] ?? null) ? $data['version'] : '';
    }
}
