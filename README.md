# SendRepute for Laravel

## Control panel preview

![SendRepute Laravel console](https://raw.githubusercontent.com/sendrepute/sendrepute-laravel/main/docs/screenshots/dashboard.webp)

The shipped integration console in a local framework host with sample data, not a live customer account. See `docs/screenshots/dashboard.provenance.json` for capture details.

An explicit, server-side Laravel integration for SendRepute's paid email
classification API. Classification is a content safety signal, not a guarantee
of inbox placement or deliverability.

## Support

- PHP 8.2+
- Laravel 12 and 13 (the package constraints are the authoritative range)
- Symfony Mime messages sent through Laravel's mailer
- In-memory string subject plus text and/or HTML displayed alternatives

Attachments, recipients, envelopes, and raw MIME are never submitted. The API
receives only the sender **display name**, subject, all displayed in-memory
`text/plain` and `text/html` alternatives, and optional model.
Messages without a named sender or in-memory body follow the configured failure
policy.

## Install from GitHub (not Packagist)

This repository does **not** claim that `sendrepute/laravel` is published on
Packagist. Add this VCS repository to the consuming application's `composer.json`:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/sendrepute/sendrepute-laravel"
    }
  ]
}
```

Then run `composer require sendrepute/laravel:dev-main`. Commit the consuming
application's lockfile to pin the reviewed source revision. For a locally built ZIP,
use a Composer `artifact` repository pointed at the directory containing that
ZIP; do not use a normal Packagist install command unless a future release is
actually published.

Laravel package discovery registers the provider. Publish configuration with:

```sh
php artisan vendor:publish --tag=sendrepute-config
```

## Secure configuration and consent

Both switches default to false:

```dotenv
SENDREPUTE_PAID_ANALYSIS_CONSENT=true
SENDREPUTE_MAIL_ENABLED=true
SENDREPUTE_API_KEY=
SENDREPUTE_API_BASE_URL=https://www.sendrepute.com/api
SENDREPUTE_TRUSTED_HOSTS=www.sendrepute.com
SENDREPUTE_PRICE_AUTHORIZATION_ENABLED=true
SENDREPUTE_EXPECTED_CLASSIFICATION_BASE_MILLICENTS=
SENDREPUTE_EXPECTED_INCLUDED_UNIQUE_TERMS=
SENDREPUTE_EXPECTED_ADDITIONAL_TERM_MILLICENTS=
SENDREPUTE_EXPECTED_MAXIMUM_CLASSIFICATION_MILLICENTS=
SENDREPUTE_MAXIMUM_CHARGE_MILLICENTS=
```

The base URL must use verified HTTPS, contain no credentials/query/fragment,
and match an exact trusted hostname. Put the server-side key after
`SENDREPUTE_API_KEY=` in the deployment's secret environment, not in a checked
in file. Redirects are refused. Never put these values in browser-side
configuration or logs.

Price authorization also defaults off for compatibility. Before enabling it,
call authenticated `GET /api/v1/pricing`, deliberately copy its complete
four-field effective rate schedule into the four `EXPECTED_` values, and choose
an explicit per-request `MAXIMUM_CHARGE_MILLICENTS`. The ceiling may be lower
than the schedule maximum; it is not a fixed final quote. Every paid operation
sends the approved schedule plus that unchanged ceiling in
`priceAuthorization`. Settlement atomically rechecks them and rejects rate or
membership drift and charges above the ceiling without a debit. A
`PRICE_CHANGED` response never updates consent automatically and always cancels
the opted-in message, even when `mail.failure_policy` is `allow`. Exact
completed replays remain receipts rather than new charges, while API-key
cumulative caps remain independent account safeguards.

Even after those switches are enabled, each outgoing message must explicitly
opt in:

```php
use Illuminate\Mail\Mailables\Headers;

public function headers(): Headers
{
    return new Headers(text: [
        'X-SendRepute-Classify' => 'yes',
    ]);
}
```

Only the text header matters; generated Mailable structure may vary with the
Laravel version. The package removes the header before sending.
Password resets, notifications, and transactional mail are therefore untouched
unless their individual message adds the exact opt-in header. Do not add this
header in a global mail callback.

`mail.mode` is `advisory` (record no delivery decision) or `blocking` (cancel
when the finite `spamProbability` is at or above the configured 0..1
threshold). `mail.failure_policy` is independently `allow` or `block`.
`block` fails closed for malformed messages, transport errors, authentication,
balance, rate-limit, unavailable, and malformed API responses. Those failures
are never interpreted as spam.

Every opted-in attempt emits
`SendRepute\Laravel\Events\ClassificationOutcome`. This diagnostic contains
only action, score, sanitized error category/status/code, and request ID. It
never contains the message, sender, subject, body, recipients, attachments,
credentials, or an exception object. Exceptions thrown by diagnostic observers
cannot change delivery.

Laravel's `Illuminate\Mail\Events\MessageSending` is the pre-send cancellation
hook: Laravel's mailer checks the event dispatch result and skips transport when
a listener returns `false`. Throwing from this listener is intentionally
avoided; this package returns `false` for an explicit blocking decision,
fail-closed policy, or a `PRICE_CHANGED` billing-consent refusal. A skipped send
is not Laravel's post-send `MessageSent`
event and is not an SMTP failure.

## Manual classification

Resolve `SendRepute\Laravel\Contracts\Classifier` and call:

```php
$result = $classifier->classify(
    sender: 'Example Company',
    subject: 'Your receipt',
    body: 'Thanks for your purchase.',
    paidAnalysisConsent: true,
);
```

Per-call consent or global paid-analysis consent is required. Errors are
`SendReputeException` with a non-secret category:
`consent_required`, `configuration`, `malformed_request`, `transport`,
`authentication`, `balance`, `rate_limit`, `unavailable`, `price_changed`, `api`, or
`malformed_response`. There are no automatic retries, avoiding duplicate paid
attempts; callers should not retry paid requests arbitrarily.

## Offline tests and local packaging

```sh
composer install
composer test
composer lint
php scripts/package.php dist/sendrepute-laravel.zip
```

Tests use Guzzle's mock handler and Testbench; they send no email and make no
network or paid API calls. This source was tested against Laravel 12.69.2 with
Testbench 10.11.0 and Laravel 13.33.0 with Testbench 11.2.0 on PHP 8.4.10.
The lock file records the final Laravel 13 development environment; consumers
resolve their own compatible production dependencies. No live deployment
compatibility test is claimed.
The package script includes only Composer metadata, license, README,
configuration, and PHP source, rejects symlinks/unexpected source entries, and
scans its allowlist for obvious credentials. The resulting ZIP is local and
unpublished.

## Support and security

Use GitHub issues for reproducible, non-sensitive bugs. Account support and
private vulnerability reports: support@sendrepute.com. Never include API keys,
customer messages or unredacted logs. See [SECURITY.md](SECURITY.md).
MIT licensed; see LICENSE.
## Customer API client and operator console

`SendRepute\Laravel\CustomerApi\CustomerApiClient` covers all 49 operations of the customer API contract, including the three paid-result recovery operations under `/customer/paid-results`. Call any operation with `call($operationId, ['params' => [...], 'query' => [...], 'body' => [...]], $options)`.

- It uses one fixed HTTPS origin (`https://www.sendrepute.com/api`). Other hosts must be added to an exact allowlist.
- It never retries a request and refuses redirects and non-JSON responses. Responses are capped at `max_response_bytes`.
- Path and query parameters are checked against the contract patterns and bounds. Request bodies may only contain allowlisted fields, up to 512 KiB.
- Paid operations require `['paidConsent' => ['acknowledged' => true, 'expectedPriceMillicents' => N]]`. That price is written to, or checked against, the operation's price field.
- Billing and recovery actions require `['confirm' => true]`.

The operator console is **off by default**. Set `SENDREPUTE_CUSTOMER_API_KEY` and `SENDREPUTE_CUSTOMER_CONSOLE_ENABLED=true`, then define the gate:

```php
Gate::define('sendrepute-customer-api', fn ($user) => $user->is_admin);
```

The console is mounted at `customer_api.console.path`, behind `web`, `auth` and `can:sendrepute-customer-api`. Its safeguards:

- The API key stays on the server.
- Calls need the session CSRF token in `X-CSRF-Token` and a same-origin `Origin` header.
- A paid operation needs its quote operation to have run in the same session within the last 15 minutes. That quote is used up by the paid call.
- Only one paid or billing call can be in flight per session.
- Results are shown as inert JSON or a sandboxed preview, and can be downloaded as files.
- Hosted builder handoff stays disabled until `SENDREPUTE_HANDOFF_RETURN_ORIGIN` is set.

The existing mail classification hook is unchanged.

## Paid-intent ledger (anti-duplicate)

Every paid or billing console call is recorded in a durable intent ledger **before** the request is sent. The identity is the credential fingerprint, operation, method, path and canonical body. An identical request is refused (409 `INTENT_LOCKED` / `INTENT_COMPLETED`) from any session, worker or restart while the intent is pending, ambiguous (timeout, transport error, 5xx, 408/425, malformed response) or completed. Definitive 4xx refusals unlock it. The upstream replay identity (`recoveryId`, or `analysisId`) is generated once, persisted with the intent and reused on resend, so the server can deduplicate. Operators review intents with the **Paid intents** button and release one only with a reason and an explicit confirmation. Releasing a completed intent issues a fresh replay id for a deliberate second charge. Pending intents are never released online or by timeout, because a stalled worker may still have the request in flight. After a worker crash, stop every worker and run `$store->recoverPendingAfterShutdown(true, time())`; leftover pending intents become ambiguous for reconciliation and release. With no ledger configured, paid and billing operations return 503 `INTENT_STORE_REQUIRED`. The filesystem store supports a **single host only**, and it refuses to start unless you acknowledge that and its directory is absolute, `0700` and owned by the server user. Prices are always sent upstream for server-side enforcement, and a charge above consent is flagged. Classification (`classifyCustomerEmail`, POST /v1/classify) sends `priceAuthorization` with the four effective rates returned by this session's latest GET /v1/pricing plus the operator's ceiling. A confirmation that no longer matches the latest rates is refused (409 `PRICE_CONFIRMATION_STALE`) before anything is sent. The server re-checks rates and ceiling atomically at settlement (409 `PRICE_CHANGED`, no debit). Manual edit reclassification (`customerClassifyEmail`, POST /v1/classify/edit) has no price field in its request schema (`CustomerManualEditInput`, additionalProperties false); the server prices it authoritatively.

Configure `sendrepute.customer_api.console.intent_store`:

```env
# Recommended: a shared cache store with atomic locks (redis, database, memcached, dynamodb). array/null drivers are refused.
SENDREPUTE_INTENT_STORE=cache
SENDREPUTE_INTENT_CACHE_STORE=redis
# Or, on a single host only:
# SENDREPUTE_INTENT_STORE=filesystem
# SENDREPUTE_INTENT_PATH=/var/www/storage/sendrepute-intents
# SENDREPUTE_INTENT_SINGLE_HOST=true
```

Intents are stored without expiry, so use a cache store that does not evict keys.
