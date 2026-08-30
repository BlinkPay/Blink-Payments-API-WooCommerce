# BlinkPay NZ for WooCommerce

Accept New Zealand bank payments in WooCommerce through [BlinkPay](https://www.blinkpay.co.nz) open banking:

- **Blink PayNow** — one-off payments at checkout, via quick payments.
- **Refunds** — refund requests from the WooCommerce order screen retrieve the customer's account number for a manual bank transfer.

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
5. Place a test order. Sandbox payments never move real money.
6. When you are ready to go live, replace the credentials with your production pair and untick **Sandbox mode**.

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
4. `AcceptedSettlementCompleted` marks the order **Processing/Completed**; a rejected consent or payment marks it **Failed**; anything still in flight parks the order **On hold** and WP-Cron re-checks every minute for up to 30 minutes.

The return redirect alone is never treated as proof of payment — the outcome is always confirmed through the API, as the gateway contract requires.

### Access tokens

The plugin requests an OAuth2 `client_credentials` token, caches it in a transient scoped to the environment and client ID, and reuses it until five minutes before its one-hour expiry, at which point the next request fetches a fresh one. A `401` (for example after a credential rotation) busts the cache and retries once.

### Refunds

Refunding from the order screen uses BlinkPay's `account_number` refund type, which **does not move money**. It retrieves the bank account number the customer paid from and records it as an order note; you then transfer the refund to that account from your own bank. The WooCommerce refund is the record of that manual transfer, and the note carries the BlinkPay refund reference.

## Order metadata reference

| Meta key | Meaning |
|---|---|
| `_blinkpay_quick_payment_id` | Quick payment ID for the order |
| `_blinkpay_payment_id` | BlinkPay payment ID (also set as the order's transaction ID) |
| `_blinkpay_idempotency_*` | Idempotency keys, one per API operation per order |

## Extensibility

- `wc_blinkpay_quick_payment_payload` — filter the quick payment request body.
- `wc_blinkpay_icon` — filter the checkout logo URL (defaults to the bundled BlinkPay logo).

## Troubleshooting

- **The gateway does not appear at checkout** — check the store currency is NZD and both credentials are set.
- **Orders stay on hold** — the payment outcome was still pending; WP-Cron re-checks it for 30 minutes. If your host disables WP-Cron, trigger it from a real cron job. After the checks are exhausted, verify the payment in the BlinkPay merchant portal and update the order manually.
- **Debug logging** — enable it in the gateway settings, then read **WooCommerce → Status → Logs** (source `blinkpay`). Credentials and tokens are never written to the log.

## Security notes

- The client secret is stored in the WordPress options table unless you use the `wp-config.php` constants above.
- The plugin never stores bank account details for payments; the customer authorises directly with their bank. A refund records the customer's account number in a private order note so you can make the transfer.
- The customer identifier sent to BlinkPay is a SHA-256 hash of the billing email, never the raw address.

## Development

### Local WordPress

`.wp-env.json` describes a disposable WordPress site (latest core, WooCommerce and Plugin Check) with this repository mounted as a plugin. It needs Docker and Node.

```sh
npx @wordpress/env start    # http://localhost:8888, admin / password
npx @wordpress/env stop     # `destroy` also wipes the database
```

### Plugin Check

[Plugin Check](https://wordpress.org/plugins/plugin-check/) is the tool the WordPress.org review team runs against submissions. Run it before every release:

```sh
npx @wordpress/env run cli wp plugin check Blink-Payments-API-WooCommerce --slug=blinkpay-nz-for-woocommerce --exclude-directories=.github,.idea --exclude-files=.gitignore,.wp-env.json
```

`--slug` is required because wp-env mounts the plugin under the repository's directory name; without it every translated string is reported as a text-domain mismatch. The excludes skip files that exist only in the repository — CI leaves them out of the plugin zip. A clean run prints `Success: Checks complete. No errors found.`

### Releasing

CI lints every PHP file on PHP 7.4–8.4 and builds `blinkpay-nz-for-woocommerce.zip` on every push. Pushing a bare semver tag also attaches the zip to a GitHub release:

1. Bump `Version` and `WC tested up to` in `blinkpay-nz-for-woocommerce.php`, `WC_BLINKPAY_VERSION` in the same file, and `Stable tag`, `Tested up to` and the changelog in `readme.txt`. `Version`, `WC_BLINKPAY_VERSION` and `Stable tag` must be identical.
2. Run Plugin Check and place a sandbox test order.
3. `git tag -a 1.1.0 -m "1.1.0" && git push origin 1.1.0`

## Licence

MIT.
