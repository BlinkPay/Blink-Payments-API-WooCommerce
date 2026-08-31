=== BlinkPay NZ for WooCommerce ===
Contributors: reybabilonia
Tags: woocommerce, payment gateway, open banking, new zealand, bank payments
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.4
License: MIT
License URI: https://opensource.org/licenses/MIT

Accept New Zealand bank payments in WooCommerce through BlinkPay open banking. No card details, no card fees.

== Description ==

BlinkPay NZ for WooCommerce lets your customers pay directly from their New Zealand bank account using [BlinkPay](https://www.blinkpay.co.nz) open banking.

* **Blink PayNow** — one-off payments at checkout, via quick payments.
* **Refunds** — refund requests from the WooCommerce order screen retrieve the customer's account number for a manual bank transfer.

Customers are sent to BlinkPay's hosted gateway, choose their bank, and authorise the payment in their own online banking. Both the classic and block-based checkout are supported, as is high-performance order storage (HPOS).

= How payments work =

1. At checkout the plugin creates a quick payment and redirects the customer to BlinkPay's hosted gateway.
2. The customer picks their bank and authorises the payment.
3. Back on your site, the plugin confirms the outcome through the BlinkPay API. A completed payment marks the order Processing/Completed; a rejected consent or payment marks it Failed.
4. Anything still in flight parks the order On hold and WP-Cron re-checks it — every minute at first, backing off to every 2 hours — for up to 36 hours, so a payment that settles overnight completes automatically.

The return redirect alone is never treated as proof of payment — the outcome is always confirmed through the API.

= Refunds =

Refunding from the order screen uses BlinkPay's account-number refund, which does not move money. It retrieves the bank account the customer paid from and records it as a private order note; you then transfer the refund to that account from your own bank. The WooCommerce refund is the record of that manual transfer.

= Requirements =

* WordPress 6.0+ with WP-Cron enabled (used to confirm slow payments)
* WooCommerce 7.0+
* PHP 7.4+
* Store currency set to **NZD**
* BlinkPay merchant credentials (client ID and client secret) — [contact BlinkPay](https://www.blinkpay.co.nz/contact-us) to get onboarded. Sandbox and production credentials are separate.

= External service =

This plugin connects to the BlinkPay Debit API, a third-party service operated by BlinkPay Limited, to create payments, confirm their outcome and retrieve refund details. Depending on the **Sandbox mode** setting, requests are sent to `https://sandbox.debit.blinkpay.co.nz` or `https://debit.blinkpay.co.nz`. Order amounts, the order number, your configured bank statement particulars and a SHA-256 hash of the customer's billing email are sent to BlinkPay; the raw email address is never transmitted. Customers complete payment on BlinkPay's hosted gateway. Use of the service is governed by BlinkPay's [terms of use](https://www.blinkpay.co.nz/terms) and [privacy policy](https://www.blinkpay.co.nz/privacy).

= Security =

* Define `BLINKPAY_CLIENT_ID` and `BLINKPAY_CLIENT_SECRET` in `wp-config.php` to keep credentials out of the database; constants take precedence over saved settings.
* The plugin never stores bank account details for payments; the customer authorises directly with their bank.
* Debug logging never writes credentials or tokens.

== Installation ==

1. In WordPress admin, go to **Plugins → Add New**, search for "BlinkPay NZ for WooCommerce", then install and activate it.
2. Go to **WooCommerce → Settings → Payments → BlinkPay**.
3. Enter your **client ID** and **client secret**, leave **Sandbox mode** ticked, and enable the gateway.
4. Place a test order. Sandbox payments never move real money.
5. When you are ready to go live, replace the credentials with your production pair and untick **Sandbox mode**.

To keep the client secret out of the database, add the following to `wp-config.php` instead of saving credentials in the settings screen:

`define( 'BLINKPAY_CLIENT_ID', 'your-client-id' );`
`define( 'BLINKPAY_CLIENT_SECRET', 'your-client-secret' );`

== Frequently Asked Questions ==

= The gateway does not appear at checkout =

Check that the store currency is NZD and that both credentials are set.

= Orders stay on hold =

Bank settlement is asynchronous, and a payment made in the evening may not settle until the following morning. WP-Cron re-checks the payment — every minute at first, backing off to every 2 hours — for up to 36 hours, and the order moves to Processing automatically once the payment settles. If your host disables WP-Cron, trigger `wp-cron.php` from a real cron job. Only if the checks are exhausted after 36 hours should you verify the payment in the BlinkPay merchant portal and update the order manually.

= Does a WooCommerce refund send money back to the customer? =

No. BlinkPay does not transfer refunds. The refund retrieves the customer's bank account number into a private order note so you can make the transfer from your own bank.

= Which currencies are supported? =

New Zealand dollars only.

= How do I see what the plugin is doing? =

Enable **Debug logging** in the gateway settings, then read **WooCommerce → Status → Logs** (source `blinkpay`).

= Can developers customise the integration? =

Yes. The `wc_blinkpay_quick_payment_payload` filter modifies the quick payment request body and `wc_blinkpay_icon` filters the checkout icon URL. Source code and issues are on [GitHub](https://github.com/BlinkPay/Blink-Payments-API-WooCommerce).

== Changelog ==

= 1.0.4 =
* Use SHA-256 instead of MD5 for the access-token cache key.

= 1.0.3 =
* Renamed the plugin to BlinkPay NZ for WooCommerce (slug: blinkpay-nz-for-woocommerce).
* Added the BlinkPay logo to the classic checkout, the block checkout and the gateway settings screen.
* Resolved all Plugin Check findings.

= 1.0.0 =
* Initial release: Blink PayNow quick payments, account-number refunds, classic and block checkout support, HPOS compatibility.

== Upgrade Notice ==

= 1.0.3 =
Plugin renamed to BlinkPay NZ for WooCommerce.

= 1.0.0 =
Initial release.
