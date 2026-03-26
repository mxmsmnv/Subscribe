# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [1.0.4] - 2026-03-26

### Added

- Multiple subscription lists with many-to-many subscriber relationships
- Double opt-in flow with configurable HTML confirmation email (`{confirm_url}`, `{unsub_url}`)
- Honeypot field for bot protection without CAPTCHA
- IP-based rate limiting with configurable threshold and time window
- One-click unsubscribe endpoint with unique token per subscription
- Hookable `___subscribed()` method fired after new subscription
- WireMail provider selector (default, SMTP, Brevo, etc.)
- Configurable sender identity (From Email, From Name, Subject)
- Confirmation success and error pages auto-created on install
- Configurable redirect pages via module settings
- Admin Process module with sidebar list switcher and subscriber counts
- Add, toggle, remove subscribers from admin UI
- Create, rename, delete lists
- Resend confirmation email for pending subscribers
- Search/filter by email with live input
- Status filter dropdown (All / Active / Pending / Unsubscribed)
- Pagination at 50 subscribers per page
- Import subscribers from CSV
- Export per list in JSON and CSV formats
- Public PHP API: `subscribe()`, `getSubscribers()`, `resendConfirmation()`
- AJAX endpoint via `/?subscribe=1` (or `/?subscribe=$listId`)
- Alpine.js frontend form block with inline messages
- Auto-migration from legacy single-table schema
- Integration examples for order forms, contact forms, hooks