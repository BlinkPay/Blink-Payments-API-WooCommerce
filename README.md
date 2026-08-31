# BlinkPay NZ for WooCommerce

Accept New Zealand bank payments in WooCommerce through [BlinkPay](https://www.blinkpay.co.nz) open banking:

- **Blink PayNow** — one-off payments at checkout, via quick payments.
- **Refunds** — card payments are refunded through the card network in full or in part; bank payments retrieve the customer's account number for a manual bank transfer.

Customers are sent to BlinkPay's hosted gateway, choose their bank, and authorise the payment in their own online banking. No card details, no card fees.

## Requirements

- WordPress 6.0+ with WP-Cron enabled (used to confirm slow payments)
- WooCommerce 7.0+ (classic and block-based checkout are both supported)
- PHP 7.4+
- Store currency set to **NZD**
- BlinkPay merchant credentials (client ID and client secret) — [contact BlinkPay](https://www.blinkpay.co.nz/contact) to get onboarded. Sandbox and production credentials are separate.

## Installation

1. Download `blinkpay-nz-for-woocommerce.zip` from the latest [GitHub release](../../releases) — it is built by CI and unpacks to the plugin's canonical `blinkpay-nz-for-woocommerce/` directory.
2. In WordPress admin, go to **Plugins → Add New → Upload Plugin**, upload the ZIP and activate it.
3. Go to **WooCommerce → Settings → Payments → BlinkPay**.
4. Enter your **client ID** and **client secret**, leave **Sandbox mode** ticked, and enable the gateway.
5. Copy the **Callback URL** shown in the settings and register it in the BlinkPay client portal under **Settings → API** for the **sandbox** environment. Redirect URIs must be whitelisted before payments can be created, and each environment keeps its own whitelist.
6. Place a test order. Sandbox payments never move real money.
7. When you are ready to go live, replace the credentials with your production pair, untick **Sandbox mode**, and register the callback URL for the **production** environment.

### Keeping the client secret out of the database (recommended)

Instead of saving credentials in the settings screen, define them in `wp-config.php`; constants take precedence over saved settings:

```php
define( 'BLINKPAY_CLIENT_ID', 'your-client-id' );
define( 'BLINKPAY_CLIENT_SECRET', 'your-client-secret' );
```

## How it works

### Payments

1. At checkout the plugin creates a **quick payment** with the gateway flow and redirects the customer to BlinkPay's hosted gateway.
2. The customer picks their bank and authorises the payment.
3. Back on your site, the plugin retrieves the quick payment — the first retrieval is what initiates the debit — and polls briefly for the outcome.
4. `AcceptedSettlementCompleted` marks the order **Processing/Completed**; a rejected consent or payment marks it **Failed**; anything still in flight parks the order **On hold** and WP-Cron re-checks it — every minute at first, backing off to every 2 hours — for up to 36 hours, so a payment that settles overnight completes automatically.

The return redirect alone is never treated as proof of payment — the outcome is always confirmed through the API, as the gateway contract requires.

### Access tokens

The plugin requests an OAuth2 `client_credentials` token, caches it in a transient scoped to the environment and client ID, and reuses it until five minutes before its one-hour expiry, at which point the next request fetches a fresh one. A `401` (for example after a credential rotation) busts the cache and retries once.

### Refunds

How the payment settled — its `accepted_reason`, recorded when the payment completes — decides the refund path.

A card payment (`card_network_accepted`) is refunded with a money-moving type — `full_refund` when the whole order total is refunded, `partial_refund` (carrying the amount) otherwise — and both carry the configured PCR. A `201` from the refunds API does not mean the money has moved, so the plugin retrieves the refund and acts on its status: `failed` rejects the WooCommerce refund outright, `completed` is recorded as done, and anything else is noted with what the merchant must still do — authorise the refund from their own bank when the response carries a `consent_redirect`, or verify it completes in the merchant portal.

A bank payment (`source_bank_payment_sent`, or an order from before the reason was recorded) uses the `account_number` refund type, which **does not move money**. It retrieves the bank account number the customer paid from into a private order note — carrying the BlinkPay refund reference — so you can transfer the refund from your own bank, and adds a customer-visible note that the refund will arrive by bank transfer, so the outstanding obligation is not buried in a private note.

WooCommerce's own **Refund manually** button is hidden on BlinkPay orders — it would record money as returned without BlinkPay involvement — so every refund goes through **Refund via BlinkPay**.

## Order metadata reference

| Meta key | Meaning |
|---|---|
| `_blinkpay_quick_payment_id` | Quick payment ID for the order |
| `_blinkpay_payment_id` | BlinkPay payment ID (also set as the order's transaction ID) |
| `_blinkpay_accepted_reason` | How the payment settled (`source_bank_payment_sent` or `card_network_accepted`); selects the refund path |
| `_blinkpay_idempotency_*` | Idempotency keys, one per API operation per order |

## Extensibility

- `wc_blinkpay_quick_payment_payload` — filter the quick payment request body.
- `wc_blinkpay_icon` — filter the checkout logo URL (defaults to the bundled BlinkPay logo).

## Troubleshooting

- **The gateway does not appear at checkout** — check the store currency is NZD and both credentials are set.
- **Every payment fails with "We could not start your BlinkPay payment"** — the site's callback URL is probably not whitelisted. Copy the **Callback URL** from the gateway settings and register it in the BlinkPay client portal under **Settings → API** for the environment in use; sandbox and production each keep their own whitelist. The notes on the failed order name the exact error.
- **Orders stay on hold** — bank settlement is asynchronous, and an evening payment may not settle until the following morning; WP-Cron re-checks it on a backing-off schedule for up to 36 hours and completes the order automatically once the payment settles. If your host disables WP-Cron, trigger it from a real cron job. Only if the checks are exhausted after 36 hours should you verify the payment in the BlinkPay merchant portal and update the order manually.
- **Debug logging** — enable it in the gateway settings, then read **WooCommerce → Status → Logs** (source `blinkpay`). Credentials and tokens are never written to the log.

## Security notes

- The client secret is stored in the WordPress options table unless you use the `wp-config.php` constants above.
- The plugin never stores bank account details for payments; the customer authorises directly with their bank. An account-number refund records the customer's account number in a private order note so you can make the transfer; it never appears in customer-visible notes.
- The customer identifier sent to BlinkPay is a SHA-256 hash of the billing email, never the raw address.

## Development

### Local WordPress

`.wp-env.json` describes a disposable WordPress site (latest core, WooCommerce and Plugin Check) with this repository mounted as a plugin. It needs Docker and Node.

```sh
npx @wordpress/env start    # http://localhost:8888, admin / password
npx @wordpress/env stop     # `destroy` also wipes the database
```

### Unit tests

The PHPUnit suite in `tests/` runs against WordPress stubs, so it needs no WordPress installation — only PHP 7.4+ and Composer (on macOS: `brew install php composer`), or Docker:

```sh
composer install && composer test

# or without a local PHP:
docker run --rm -v "$PWD":/app -w /app composer:2 sh -c "composer install && composer test"
```

### Plugin Check

[Plugin Check](https://wordpress.org/plugins/plugin-check/) is the tool the WordPress.org review team runs against submissions. Run it before every release:

```sh
npx @wordpress/env run cli wp plugin check Blink-Payments-API-WooCommerce --slug=blinkpay-nz-for-woocommerce --exclude-directories=.github,.idea,tests,vendor --exclude-files=.gitignore,.wp-env.json,composer.json,composer.lock,phpunit.xml.dist,.phpunit.result.cache
```

`--slug` is required because wp-env mounts the plugin under the repository's directory name; without it every translated string is reported as a text-domain mismatch. The excludes skip files that exist only in the repository — CI leaves them out of the plugin zip. A clean run prints `Success: Checks complete. No errors found.`

### Releasing

CI lints every PHP file on PHP 7.4–8.4, runs the unit tests on PHP 7.4 and 8.4, and builds `blinkpay-nz-for-woocommerce.zip` on every push. Pushing a bare semver tag also attaches the zip to a GitHub release:

1. Bump `Version` and `WC tested up to` in `blinkpay-nz-for-woocommerce.php`, `WC_BLINKPAY_VERSION` in the same file, and `Stable tag`, `Tested up to` and the changelog in `readme.txt`. `Version`, `WC_BLINKPAY_VERSION`, `Stable tag` and the git tag must all carry the same version number — WordPress serves the zip named by `Stable tag`, `WC_BLINKPAY_VERSION` cache-busts the enqueued scripts, and the tag names the GitHub release, so a mismatch ships stale code or assets.
2. Run Plugin Check and place a sandbox test order.
3. `git tag -a 1.1.0 -m "1.1.0" && git push origin 1.1.0`

## Licence

MIT.
