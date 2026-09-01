=== BlinkPay NZ for WooCommerce ===
Contributors: reybabilonia
Tags: woocommerce, payment gateway, open banking, new zealand, bank payments
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Accept New Zealand bank payments in WooCommerce through BlinkPay open banking, with card payments where enabled for your merchant account.

== Description ==

BlinkPay NZ for WooCommerce lets your customers pay directly from their New Zealand bank account using [BlinkPay](https://www.blinkpay.co.nz) open banking.

* **Blink PayNow** — one-off payments at checkout, via quick payments.
* **Refunds** — card payments are refunded through the card network in full or in part; bank payments show the customer's account number on the order screen (fetched live, never stored) for a manual bank transfer.

Customers are sent to BlinkPay's hosted gateway, choose their bank — or card, when card payments are enabled for your BlinkPay merchant account — and authorise the payment. Card is where BlinkPay surcharging applies; see the Surcharges section below. Both the classic and block-based checkout are supported, as is high-performance order storage (HPOS).

= How payments work =

1. At checkout the plugin creates a quick payment and redirects the customer to BlinkPay's hosted gateway.
2. The customer picks their bank and authorises the payment.
3. Back on your site, the plugin confirms the outcome through the BlinkPay API. A completed payment marks the order Processing/Completed; a rejected consent or payment marks it Failed.
4. Anything still in flight parks the order On hold and WP-Cron re-checks it — every minute at first, backing off to every 2 hours — for up to 36 hours, so a payment that settles overnight completes automatically.

The return redirect alone is never treated as proof of payment — the outcome is always confirmed through the API.

= Refunds =

How the customer paid decides how a refund works. A card payment is refunded through the card network: the plugin requests a full or partial refund and BlinkPay moves the money back — the order notes record the refund's status, and a refund still processing should be verified in the BlinkPay merchant portal. A bank payment is refunded with BlinkPay's account-number refund, which does not move money: the bank account the customer paid from is shown in the BlinkPay manual refunds panel on the order screen so you can transfer the refund from your own bank — the panel fetches the number live from BlinkPay each time it renders, so it is never stored in WordPress — and a customer-visible note says the refund will arrive by bank transfer.

WooCommerce's own **Refund manually** button is hidden on BlinkPay orders — it would record money as returned without BlinkPay involvement — so every refund goes through **Refund via BlinkPay**. That button is only offered when your BlinkPay credentials include the refund permissions (create:refund and view:refund); contact BlinkPay if you need them enabled.

= Surcharges =

If surcharging is enabled for your BlinkPay merchant account, BlinkPay adds the surcharge on top of the order total once the customer selects a payment method on the hosted gateway, and the customer sees and authorises the combined amount there. WooCommerce still records the order total as paid; the amount actually charged and the surcharge are recorded on the order as a note and as metadata for reconciliation. The plugin also verifies the paid amount against the order total before completing an order — an order paid for the wrong amount, in either direction, is placed on hold for you to review rather than completed silently.

= Requirements =

* WordPress 6.0+ with WP-Cron enabled (used to confirm slow payments)
* WooCommerce 7.0+
* PHP 7.4+
* Store currency set to **NZD**
* BlinkPay merchant credentials (client ID and client secret) — [contact BlinkPay](https://www.blinkpay.co.nz/contact-us) to get onboarded. Sandbox and production credentials are separate.

= External service =

This plugin connects to the BlinkPay Debit API, a third-party service operated by BlinkPay Limited, to create payments, confirm their outcome and retrieve refund details. Depending on the **Sandbox mode** setting, requests are sent to `https://sandbox.debit.blinkpay.co.nz` or `https://debit.blinkpay.co.nz`. Order amounts, the order number and order ID, your configured bank statement particulars and a SHA-256-hashed customer identifier — the WooCommerce customer ID for registered customers, otherwise the billing email address — are sent to BlinkPay so payments can be attributed to a customer. Hashing keeps the raw value out of the request, but it is not anonymisation: a hash of a known email address can be matched back to that address. Customers complete payment on BlinkPay's hosted gateway. Use of the service is governed by BlinkPay's [terms of use](https://www.blinkpay.co.nz/terms) and [privacy policy](https://www.blinkpay.co.nz/privacy).

= Security =

* Define `BLINKPAY_CLIENT_ID` and `BLINKPAY_CLIENT_SECRET` in `wp-config.php` to keep credentials out of the database; constants take precedence over saved settings.
* The plugin never stores bank account details for payments; the customer authorises directly with their bank.
* Debug logging never writes credentials or tokens.

== Installation ==

1. In WordPress admin, go to **Plugins → Add New**, search for "BlinkPay NZ for WooCommerce", then install and activate it.
2. Go to **WooCommerce → Settings → Payments → BlinkPay**.
3. Enter your **client ID** and **client secret**, leave **Sandbox mode** ticked, and enable the gateway.
4. Copy the **Callback URL** shown in the settings and register it in the BlinkPay client portal under **Settings → API** for the **sandbox** environment. Redirect URIs must be whitelisted before payments can be created, and each environment keeps its own whitelist.
5. Place a test order. Sandbox payments never move real money.
6. When you are ready to go live, replace the credentials with your production pair, untick **Sandbox mode**, and register the callback URL for the **production** environment.

To keep the client secret out of the database, add the following to `wp-config.php` instead of saving credentials in the settings screen:

`define( 'BLINKPAY_CLIENT_ID', 'your-client-id' );`
`define( 'BLINKPAY_CLIENT_SECRET', 'your-client-secret' );`

== Frequently Asked Questions ==

= The gateway does not appear at checkout =

Check that the store currency is NZD and that both credentials are set.

= Every payment fails with "We could not start your BlinkPay payment" =

The most likely cause is that this site's callback URL is not whitelisted for your merchant account. Copy the **Callback URL** from the gateway settings and register it in the BlinkPay client portal under **Settings → API** for the environment you are using — sandbox and production each keep their own whitelist. The notes on the failed order name the exact error.

= Orders stay on hold =

Bank settlement is asynchronous, and a payment made in the evening may not settle until the following morning. WP-Cron re-checks the payment — every minute at first, backing off to every 2 hours — for up to 36 hours, and the order moves to Processing automatically once the payment settles. If your host disables WP-Cron, trigger `wp-cron.php` from a real cron job. Only if the checks are exhausted after 36 hours should you verify the payment in the BlinkPay merchant portal and update the order manually.

= Does a WooCommerce refund send money back to the customer? =

For card payments, yes: the plugin requests a full or partial refund and BlinkPay moves the money back through the card network. For bank payments, no: the customer's bank account number is shown in the BlinkPay manual refunds panel on the order screen so you can make the transfer from your own bank — the order note says so explicitly, and the customer is told to expect a bank transfer.

= Which currencies are supported? =

New Zealand dollars only.

= How do I see what the plugin is doing? =

Enable **Debug logging** in the gateway settings, then read **WooCommerce → Status → Logs** (source `blinkpay`).

= Can developers customise the integration? =

Yes. The `wc_blinkpay_quick_payment_payload` filter modifies the quick payment request body and `wc_blinkpay_icon` filters the checkout icon URL. Source code and issues are on [GitHub](https://github.com/BlinkPay/Blink-Payments-API-WooCommerce).

== Changelog ==

= 1.1.0 =
* Confirm every gateway return through the API before failing an order, so a stale failure status replayed from browser history cannot fail an in-flight payment.
* Verify the paid amount against the order total before completing an order, and record the amount actually charged and any BlinkPay surcharge for reconciliation.
* Serialise order completion under a per-order lock, so a customer refresh and the scheduled status check cannot complete an order twice or overwrite a paid order.
* Schedule the deferred status check as soon as the payment is created, and back polling off progressively across the 36-hour settlement window.
* Choose the refund type from how the payment settled, hide WooCommerce's manual refund button on BlinkPay orders, and offer refunds only when the credentials carry the refund permissions.
* Scope idempotency keys to the payment attempt, so a retried checkout creates a fresh payment.
* Show the callback URL in the gateway settings, and name redirect-URI whitelisting as the likely cause when payment creation is rejected.
* Send the order ID as the PCR code for exact reconciliation, and derive the hashed customer identifier from the customer ID — omitted entirely when no per-customer value exists.
* Distinguish a customer-declined consent from a bank-rejected payment in the order notes.
* Send the OAuth token request in the documented form encoding, and correct the checkout copy for merchant accounts with card payments enabled.
* Flag a completed payment whose amount differs from the order total in either direction, not only underpayments.
* Run refunds under the per-order lock, refusing a second submission while one is in flight, and reject manual money-carrying refunds server-side rather than only hiding the button.
* Refund a surcharged payment as a partial for the exact amount requested.
* Keep orders still awaiting a payment outcome out of WooCommerce's unpaid-order cancellation, and surface a payment that settles after its order was cancelled by parking the order on hold with a prominent note instead of discarding the payment silently.
* Confirm an order's existing quick payment before a retried checkout can create another, so a customer who authorised at their bank but never returned to the site cannot be debited twice.
* Make the per-order lock genuinely atomic with a token-checked release, so two concurrent operations can never both proceed and an operation that overruns cannot free its successor's lock.
* Hold the per-order lock across payment creation, so a double-submitted checkout cannot create two payments for one order.
* Discard the previous attempt's payment state on a retried checkout, so a refund always targets the payment that settled — never a rejected earlier attempt.
* Keep a terminal consent over an in-flight payment pending rather than failed, so a retry can never start a second debit while money may still be moving.
* Park a completed payment whose amount the API did not report on hold for the merchant, instead of completing it unverified.
* Show the customer's account number for a bank refund in a panel on the order screen, fetched live from BlinkPay each time and never stored in WordPress.
* Bound the unpaid-order cancellation exemption by order age, so a site whose cron is not running cannot hold stock indefinitely.
* Return a retried order to pending while the customer pays the fresh payment, and release every lock even when a third-party hook throws.

= 1.0.4 =
* Use SHA-256 instead of MD5 for the access-token cache key.

= 1.0.3 =
* Renamed the plugin to BlinkPay NZ for WooCommerce (slug: blinkpay-nz-for-woocommerce).
* Added the BlinkPay logo to the classic checkout, the block checkout and the gateway settings screen.
* Resolved all Plugin Check findings.

= 1.0.0 =
* Initial release: Blink PayNow quick payments, account-number refunds, classic and block checkout support, HPOS compatibility.

== Upgrade Notice ==

= 1.1.0 =
Safer payment confirmation: API-confirmed outcomes, duplicate-completion protection, paid-amount verification, surcharge recording, smarter refunds and clearer failure notes.

= 1.0.3 =
Plugin renamed to BlinkPay NZ for WooCommerce.

= 1.0.0 =
Initial release.
