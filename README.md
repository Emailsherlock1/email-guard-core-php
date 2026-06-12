# email-guard-core (PHP)

Reference implementation of [email-guard-spec](https://github.com/Emailsherlock1/email-guard-spec):
a guard for the email field of your signup form, checkout, or any form where
a fake address costs real money.

Without an API key it blocks what it can prove locally: broken syntax,
reserved TLDs (`deleted+user274@deleted.invalid` is syntactically fine and
still junk), and 73k+ known disposable domains from the bundled snapshot.
Zero network calls, zero latency. An [EmailSherlock API key](https://emailsherlock.com/api/docs)
adds the data unlock: live MX, SMTP probe, a fresh disposable list, role and
catch-all detection.

No framework dependency. The Symfony bundle and the WordPress plugin build
on this library; use it directly anywhere PHP 8.1+ runs.

## Install

```bash
composer require emailsherlock/email-guard-core
```

## Use

```php
use Emailsherlock\EmailGuard\EmailGuard;

$guard = new EmailGuard();              // local checks only
$result = $guard->check($email);

if ($result->isDenied()) {
    // reject the form field
}
```

With an API key and a policy:

```php
$guard = new EmailGuard([
    'api_key'   => getenv('EMAILSHERLOCK_API_KEY'),
    'block_on'  => ['invalid', 'disposable'],   // default
    'review_on' => ['catch_all'],               // hold for confirmation
]);

$result = $guard->check($email);

match ($result->action) {
    Action::Allow  => $this->accept(),
    Action::Deny   => $this->reject($result->reasons),
    Action::Review => $this->requireEmailConfirmation(),
};
```

## What you get back

Every check returns a `Result` with four spec-defined fields:

| Field | Type | Meaning |
|---|---|---|
| `verdict` | `Verdict` | `valid`, `invalid`, `disposable`, `role`, `catch_all`, `unknown` |
| `action` | `Action` | `allow`, `deny`, `review`, resolved from your policy |
| `reasons` | `string[]` | machine-readable codes, e.g. `reserved_tld`, `mailbox_not_found` |
| `degraded` | `bool` | true when the API was wanted but unreachable |

Plus `apiCalled` and `apiResponse` (the raw Verify API payload, informational).

## Configuration

| Key | Default | Notes |
|---|---|---|
| `api_key` | `null` | null disables the remote check entirely |
| `block_on` | `['invalid', 'disposable']` | verdicts that deny |
| `review_on` | `[]` | verdicts that flag for a second gate |
| `fail_open` | `true` | an API outage lets addresses through, never blocks them |
| `timeout_ms` | `800` | total budget for the API call |
| `base_url` | `https://api.emailsherlock.com` | override for testing |

Policy is yours: the guard reports verdicts, your `block_on` decides what a
deny is. The defaults block provable junk and let everything debatable
(role addresses, catch-all domains, unknowns) through.

**Fail-open is the default on purpose.** A blocked legitimate customer costs
more than a leaked junk signup. If the API is unreachable, the local checks
keep working and the rest passes with `degraded: true`. Set
`'fail_open' => false` if your form prefers to reject on outage.

## Custom HTTP client

The default transport uses ext-curl. To route the API call through your own
PSR-18 client:

```php
use Emailsherlock\EmailGuard\Http\Psr18Transport;

$guard = new EmailGuard(
    ['api_key' => $key],
    new Psr18Transport($psr18Client, $requestFactory, $streamFactory),
);
```

PSR-18 carries no per-request timeout, so configure the budget on the client
itself.

## Conformance

The test suite runs every vector from
[email-guard-spec](https://github.com/Emailsherlock1/email-guard-spec)
(vendored under `tests/vectors/`, synced via `tests/update-vectors.sh`).
The same vectors run against every Email-Guard core library, so `.invalid`
gets blocked bit-identically in PHP and in every other language.

```bash
composer test
```

## Data

`data/disposable-snapshot.json.gz` is a point-in-time export of known
disposable-mail domains, refreshed with each release
(`php bin/build-snapshot.php`). Matching is exact, same as the API's live
list: the local check never blocks an address the API would let pass.

## License

MIT, see [LICENSE](LICENSE).
