# DropKey Version 2

DropKey V2 manages digital products, tiered licensing, private versioned ZIP releases, hosted checkout, payment events, and license fulfillment for WordPress plugins and other digital products.

## Requirements

- WordPress 6.4+
- PHP 7.4+
- HTTPS for the site and all licensing/payment endpoints
- PHP write permission for a private package directory outside the public document root
- OpenSSL for encrypted transactional-email retry storage
- A valid PayPal and/or Stripe merchant account for paid checkout

## Install and configure

1. Install and activate the plugin from the repository source.
2. Under **DropKey → Products**, create and publish a product.
3. Under **DropKey → Tiers**, create and publish a tier and set its maximum active website count.
4. Under **DropKey → Billing Options**, create and publish free, one-time, monthly, or yearly options.
5. Under **DropKey → ZIP Releases**, upload and publish a versioned ZIP. Paid and free checkout is blocked unless the product has a published release.
6. Under **DropKey → Payment Settings**, configure test/sandbox credentials and webhook identifiers. Do not enable live payments until the complete checkout and webhook path has been tested.
7. Create a WordPress page and place the shortcode `[dropkey_store]` in its content. The store lists each published plugin once; customers choose a plan and billing cycle on checkout.
8. Create a customer account page and place `[dropkey_account]` in its content. Customers must sign in to view their account-linked orders, subscriptions, and licenses.
9. Configure reliable outgoing WordPress email delivery (preferably through a transactional SMTP provider). DropKey queues purchase confirmations and retries failed sends, but actual inbox delivery depends on the site's mail configuration and cron execution.
10. Test the full purchase flow using sandbox payments: plan selection and total, new/existing account association, order confirmation details, purchase email, license activation, and recurring subscription webhooks.; secure account linking remains pending.

The public storefront lists published product tiers and billing options. Customers enter their email and continue to the provider-hosted payment page. DropKey does not store payment-card data.

### Stripe

Configure the Stripe secret key and webhook signing secret under **DropKey → Payment Settings**. Stripe Checkout uses provider-hosted checkout sessions and inline prices from the billing option. Recurring options use Stripe subscription Checkout mode.

Webhook endpoint:

`https://YOUR-DOMAIN/wp-json/dropkey/v1/webhooks/stripe`

Configure these events in Stripe as applicable:

- `checkout.session.completed`
- `checkout.session.async_payment_succeeded`
- `checkout.session.async_payment_failed`
- `invoice.payment_succeeded`
- `invoice.payment_failed`
- `customer.subscription.created`
- `customer.subscription.updated`
- `customer.subscription.deleted`
- `charge.refunded`
- `charge.dispute.created`

DropKey validates the Stripe signature and timestamp before processing the event. A successful browser return alone does not grant a paid license.

### PayPal

Configure the Client ID, Client Secret, environment, and webhook ID under **DropKey → Payment Settings**. Leave Live mode unchecked while testing.

Webhook endpoint:

`https://YOUR-DOMAIN/wp-json/dropkey/v1/webhooks/paypal`

Subscribe to the applicable events:

- `PAYMENT.CAPTURE.COMPLETED`
- `PAYMENT.CAPTURE.DENIED`
- `PAYMENT.CAPTURE.REFUNDED`
- `BILLING.SUBSCRIPTION.PAYMENT.COMPLETED`
- `BILLING.SUBSCRIPTION.PAYMENT.FAILED`
- `BILLING.SUBSCRIPTION.ACTIVATED`
- `BILLING.SUBSCRIPTION.UPDATED`
- `BILLING.SUBSCRIPTION.CANCELLED`
- `BILLING.SUBSCRIPTION.EXPIRED`
- `BILLING.SUBSCRIPTION.SUSPENDED`
- `CUSTOMER.DISPUTE.CREATED`

DropKey asks PayPal to verify webhook signatures through PayPal's verification API. One-time checkout is captured server-side after the customer returns from PayPal, with the order reference, amount, and currency checked before fulfillment.

**PayPal recurring plans:** create the product/plan in PayPal first, then paste the PayPal subscription Plan ID into the corresponding billing option. DropKey does not currently create PayPal catalog products or billing plans for you.

## Checkout and order lifecycle

- Public checkout endpoint: `POST /wp-json/dropkey/v1/checkout/sessions`
- PayPal capture endpoint: `POST /wp-json/dropkey/v1/checkout/paypal/capture`
- Payment webhook endpoints: `POST /wp-json/dropkey/v1/webhooks/stripe` and `POST /wp-json/dropkey/v1/webhooks/paypal`
- Checkout attempts are limited per client IP and email address.
- Order records preserve provider references and payment status; separate payment history supports refund/dispute matching.
- Provider event IDs are stored with a uniqueness constraint to prevent duplicate webhook fulfillment.
- Licenses are issued only after a free acquisition flow or a trusted provider-confirmed payment/capture.
- Paid fulfillment creates a hashed license key, a fresh protected download link, and an encrypted transactional email queue entry.
- Email sends are attempted through WP-Cron with bounded retries. Failed messages can be retried under **DropKey → Orders**.
- Subscription payment failures use the initial three-day grace policy. The daily lifecycle task suspends access after the grace period expires.
- Administrators can request cancellation at the end of the paid period under **DropKey → Orders → Subscriptions**. Stripe cancellation is scheduled with Stripe; PayPal cancellation is sent when the paid-through date arrives.
- Refunded orders suspend their license; chargeback/dispute events mark the order disputed and suspend the license. Confirm that this policy matches your merchant workflow before going live.

## Private package storage

DropKey does not store packages in the WordPress Media Library. Configure an absolute directory outside the WordPress directory and public document root through `wp-config.php` or a site-specific plugin:

```php
add_filter( 'dk2_private_storage_path', function () {
    return '/absolute/path/outside/the/public/web/root/dropkey-v2-private';
} );
```

The directory must be writable by PHP. DropKey adds an `.htaccess` denial file and `index.php` as defense in depth, but those files do not protect Nginx or every server configuration. Confirm server-level access is denied before storing real product ZIPs.

## Licensing API and client

Base URL: `https://YOUR-DOMAIN/wp-json/dropkey/v1`

- `POST /licenses/activate`: JSON `license_key`, `site_url`
- `POST /licenses/deactivate`: JSON `license_key`, `site_url`
- `POST /licenses/validate`: JSON `license_key`, optional `site_url`
- `POST /products/{product-slug}/updates`: JSON `license_key`, `site_url`, `current_version`
- `GET /downloads/{token}`: protected ZIP delivery
- `POST /checkout/sessions`: JSON `billing_option_id`, `customer_email`, `gateway`, optional same-site `success_url` and `cancel_url`
- `POST /checkout/paypal/capture`: JSON `order_id` (DropKey public order ID) and `paypal_order_id`

Copy `sdk/dropkey-license-client.php` into a plugin distributed through DropKey. It requires HTTPS, keeps TLS verification enabled, disables redirects for API calls, and does not embed an administrator secret.

```php
require_once plugin_dir_path( __FILE__ ) . 'includes/dropkey-license-client.php';

$dropkey = new DropKey_License_Client( 'https://your-dropkey-site.example', 'your-product-slug' );
$result = $dropkey->activate( $license_key );

if ( is_wp_error( $result ) ) {
    // A transport error is not proof that a license is invalid. Offer retry.
}
```

## Customer account area

Add `[dropkey_account]` to a WordPress page to provide a signed-in customer dashboard with linked licenses, copyable license keys for newly issued licenses, activation-site management, subscription status, purchase history, fresh protected download links, and profile/password updates. Customers cannot cancel subscriptions from this page; cancellation remains administrator-managed at the end of the paid period. Customer records are queried by linked WordPress user ID, not email alone. Guest purchases remain unlinked until a verified account-claim flow is implemented. Older licenses issued before encrypted key storage was added cannot have their original key recovered from the hash.

License keys remain hash-checked for validation and are additionally encrypted with authenticated AES-256-GCM for display to their owner. The encryption key is derived from the site's WordPress auth salt; keep WordPress salts stable or encrypted keys may no longer be decryptable.

## Admin interface

- **Products:** product create/edit/publish/archive and next-step links.
- **Tiers:** configurable activation limits.
- **Billing Options:** price/currency and PayPal recurring Plan ID.
- **ZIP Releases:** versioned private package upload.
- **Licenses:** manual grants, status changes, activations, and protected download links.
- **Orders:** orders, subscriptions, cancellation-at-period-end controls, and failed email retries.
- **Payment Settings:** provider credentials and webhook configuration.

Manual license issuance is an administrator grant, not proof of a payment.

## Automated verification

GitHub Actions lints PHP on PHP 7.4, 8.0, and 8.3 and runs network-free contract tests for Stripe signature verification, PayPal's provider-verified webhook flow, and the reusable PHP client.

These checks are not a substitute for running the plugin inside WordPress or testing real provider sandbox events. WordPress schema migration, storefront rendering, real Stripe/PayPal checkout, provider webhook delivery, email delivery, refund/dispute processing, and subscription lifecycle behavior still require end-to-end testing before production use.

## Not yet implemented

- Verified guest-purchase account linking and customer identity verification.
- Automated PayPal catalog/plan creation.
- Full integration into WordPress's Plugins update screen.
- Complete production security review, concurrency testing, and full WordPress integration test suite.
- Taxes, coupons, invoices, and trials (deferred from the initial MVP).
