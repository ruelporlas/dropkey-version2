# DropKey Version 2

DropKey V2 is a WordPress plugin foundation for digital products, configurable tiers, and billing options. Checkout, subscriptions, licenses, protected downloads, and updates are planned for later milestones.

## Requirements

- WordPress 6.4 or newer
- PHP 7.4 or newer

## Milestone 1

- WordPress plugin bootstrap and activation hook.
- Versioned database installation using WordPress dbDelta().
- Product, tier, and billing-option tables using the active WordPress database prefix.
- Repository classes for basic create, read, update, list, and delete operations.
- Validation for names, statuses, activation limits, billing types, price, and currency.
- Parent/child deletion guards to prevent deleting products with tiers or tiers with billing options.

## Data model

- Product: name, slug, description, status, timestamps.
- Tier: product relationship, name, slug, description, activation limit, status, sort order, timestamps.
- Billing option: tier relationship, billing type (free, one_time, monthly, yearly), price, optional three-letter currency code, status, timestamps.

Tier activation limits are stored per tier, not globally. Price and cadence are stored on billing options. Foreign-key constraints are not used in this first schema migration; repository-level deletion guards protect parent/child relationships.

## Local installation

1. Place the dropkey-v2 directory in wp-content/plugins/.
2. In WordPress Admin, open Plugins and activate DropKey Version 2.
3. Activation creates the three DropKey tables using the site's configured database prefix.
4. Normal plugin loading checks the stored schema version and invokes the installer when it changes.

No checkout, payment gateway, licensing API, update API, download delivery, admin screens, or customer account UI is implemented in this milestone.
