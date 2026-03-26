# Subscribe

Newsletter subscription module for ProcessWire with multiple lists, double opt-in, honeypot spam protection, rate limiting, unsubscribe links, hookable events, and a full admin interface.

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Frontend form](#frontend-form)
- [PHP API](#php-api)
- [Hooks](#hooks)
- [Database schema](#database-schema)
- [Confirmation flow](#confirmation-flow)
- [Confirmation pages](#confirmation-pages)
- [Admin UI](#admin-ui)
- [Export](#export)
- [Uninstall](#uninstall)
- [License](#license)

## Features

- Multiple subscription lists with many-to-many subscriber relationships
- Double opt-in with configurable HTML confirmation email
- Honeypot field that traps bots without CAPTCHA
- IP-based rate limiting with configurable threshold and time window
- One-click unsubscribe endpoint with unique token per subscription
- Hookable `subscribed` event for integrating with other modules
- WireMail provider selection (default, SMTP, Brevo, etc.)
- Admin UI with sidebar list switcher, search/filter, status filter, pagination
- Import subscribers from CSV
- Export subscribers per list in JSON and CSV formats
- Resend confirmation email from admin for pending subscribers
- Public PHP API for use in order forms, contact forms, or any template
- Auto-migration from legacy single-table schema

## Requirements

- ProcessWire 3.x
- PHP 8.0+
- Alpine.js on the frontend (for the included form block)

## Installation

1. Copy the `Subscribe` directory to `/site/modules/Subscribe/`
2. In PW admin go to Modules > Refresh, then install **Subscribe**
3. **ProcessSubscribe** installs automatically and adds a **Subscribers** page under Setup

The module creates four database tables and two confirmation pages on install. See [Database schema](#database-schema) and [Confirmation pages](#confirmation-pages).

## Configuration

Go to Modules > Subscribe to configure:

**Send method** -- select WireMail provider (defaults to site setting). For SMTP or transactional providers, install the appropriate WireMail module first.

**Confirmation email** -- sender identity and email content:

- From Email (defaults to `$config->adminEmail`)
- From Name (defaults to hostname)
- Subject line
- Email body (HTML) with placeholders `{confirm_url}` and `{unsub_url}`. Leave empty to use the built-in default template.

**Redirect pages** -- pages shown after email confirmation. Auto-created on install, or select any page from the site tree.

**Rate limiting** -- max subscription attempts per IP within a time window (default: 3 attempts per 10 minutes).

## Frontend form

The module ships with `subscribe-form-block.php`, an Alpine.js form component with honeypot protection and inline success/error messages.

Include it in any template:

```php
<?php include $config->paths->siteModules . 'Subscribe/subscribe-form-block.php'; ?>
```

To target a specific list by ID:

```php
<?php $subscribeEndpoint = '/?subscribe=2'; ?>
<?php include $config->paths->siteModules . 'Subscribe/subscribe-form-block.php'; ?>
```

The form posts to `/?subscribe=1` by default. Alpine.js must be loaded on the page.

## PHP API

### subscribe($email, $listId = null)

Subscribe an email address. Handles validation, rate limiting, duplicate checks, and sends the confirmation email. Returns an associative array with `success` (bool) and `message` (string).

```php
$sub = $modules->get('Subscribe');

$result = $sub->subscribe('user@example.com');

// Subscribe to a specific list
$result = $sub->subscribe('user@example.com', 2);
```

### getSubscribers($listId, $status = 'active')

Retrieve subscribers for a list. Returns an array of associative arrays with keys: `id`, `email`, `ip`, `status`, `created_at`.

```php
$active  = $sub->getSubscribers(1);
$pending = $sub->getSubscribers(1, 'pending');
$all     = $sub->getSubscribers(1, 'all');
```

### resendConfirmation($subscriptionId)

Regenerate tokens and resend the confirmation email for a pending subscription. Returns `true` on success, `false` if the subscription is not found or not pending.

```php
$sub->resendConfirmation($subscriptionId);
```

## Hooks

The module fires a hookable `subscribed` event after a new subscription is created. Use it in `/site/ready.php` or any module:

```php
$wire->addHookAfter('Subscribe::subscribed', function(HookEvent $event) {
    $email          = $event->arguments(0);
    $listId         = $event->arguments(1);
    $subscriptionId = $event->arguments(2);

    // Send Telegram notification, log to a page, sync with CRM, etc.
});
```

## Database schema

Four tables are created on install:

| Table | Purpose |
|---|---|
| `subscribe_form_lists` | Named subscription lists |
| `subscribe_form_subscribers` | Unique email addresses with IP and timestamp |
| `subscribe_form_subscriptions` | Many-to-many: subscriber to list with status, confirm token, and unsubscribe token |
| `subscribe_form_ratelimit` | IP-based attempt tracking with automatic cleanup |

## Confirmation flow

1. User submits email via the form or PHP API
2. Subscriber record created (or found), subscription set to `pending`
3. Confirmation email sent with unique token link
4. User clicks `/?subscribe_confirm=TOKEN`
5. Token matched, status set to `active`, token cleared
6. User redirected to the success page

Subscription statuses:

| Status | Meaning |
|---|---|
| `pending` | Confirmation email sent, awaiting click |
| `active` | Confirmed and receiving emails |
| `unsubscribed` | Opted out via link or toggled in admin |

## Confirmation pages

On install, the module creates two pages with their templates:

| Page | Template | Purpose |
|---|---|---|
| `/subscribe-confirmed/` | `subscribe-confirmed` | Shown after successful email confirmation |
| `/subscribe-error/` | `subscribe-error` | Shown when token is invalid, expired, or already used |

To use custom pages, go to Modules > Subscribe > Redirect pages and select any page. Edit the template files at `/site/templates/subscribe-confirmed.php` and `/site/templates/subscribe-error.php` to customize content.

Both pages and templates are removed on uninstall.

## Admin UI

The ProcessSubscribe module adds a **Subscribers** page under Setup with:

- Sidebar list switcher with subscriber counts
- Create, rename, and delete lists
- Add subscriber directly (inserted as `active`, skips opt-in)
- Toggle subscriber status between active and unsubscribed
- Remove subscriber from a list
- Resend confirmation email for pending subscribers
- Search/filter by email
- Status filter dropdown (All / Active / Pending / Unsubscribed)
- Pagination at 50 subscribers per page
- Import subscribers from CSV file
- Export per list in JSON and CSV formats

## Export

Use the JSON and CSV buttons in the admin UI. Each export is scoped to the currently selected list.

## Uninstall

Uninstalling removes all four database tables, both confirmation pages, and their templates. Export your data first.

## License

Copyright (c) Maxim Semenov. Licensed under the MIT License.

[https://smnv.org](https://smnv.org) | [maxim@smnv.org](mailto:maxim@smnv.org)