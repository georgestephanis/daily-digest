# Daily Digest

Daily Digest is a WordPress plugin that aggregates per-user activity from multiple external providers into one dashboard view.

## Features

- Unified digest view rendered in wp-admin with React + DataViews.
- Provider-based architecture with built-in GitHub, ClickUp, and Slack adapters.
- Per-user Keyring-backed provider connections with enable/disable settings.
- Admin-only logging tools and log viewer page.
- REST API endpoints for digest loading, provider credential testing/saving, and log retrieval.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- Node.js (for JS builds)
- Composer (for PHP tooling)

## Installation

1. Place this folder at `wp-content/plugins/daily-digest`.
2. Activate **Daily Digest** in wp-admin.
3. Visit **Daily Digest → Settings** to enable providers and connect each provider through Keyring.
4. Visit **Daily Digest → Overview** to view activity.
5. Visit **Daily Digest → Logs** (admins only) for logging controls and log viewer.

## Provider Setup References

### GitHub

- Create OAuth App: https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/creating-an-oauth-app
- OAuth Apps Overview: https://docs.github.com/en/apps/oauth-apps
- Authorize OAuth Apps: https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/authorizing-oauth-apps

### ClickUp

- API Authentication: https://developer.clickup.com/docs/authentication
- Personal Token Setup: https://help.clickup.com/hc/en-us/articles/6303426241687-Use-the-ClickUp-API

### Slack

- Create/Manage App: https://api.slack.com/apps
- OAuth v2: https://api.slack.com/authentication/oauth-v2
- User Lookup: https://api.slack.com/methods/users.lookupByEmail

### Connection Metadata

Daily Digest stores provider tokens and connection context in Keyring metadata.

Connection context such as Slack user/team or ClickUp team IDs is discovered from provider APIs during Keyring verification and displayed read-only in Daily Digest settings.

### Slack Setup Steps (Recommended)

1. Go to https://api.slack.com/apps and click **Create New App**.
2. Choose your workspace.
3. In **OAuth & Permissions**, add Bot Token Scopes:
   - `channels:read`
   - `groups:read`
   - `channels:history`
   - `groups:history`
4. Install (or reinstall) the app to the workspace.
5. Copy a **User OAuth Token** (`xoxp-...`) with `search:read` scope.
6. Obtain the target Slack User ID:
   - via `users.lookupByEmail` (API method), or
   - from the Slack profile menu in the app/client.
7. In WordPress, open **Daily Digest → Settings** and click **Connect via Keyring** for Slack.
8. Run **Test Connection**.

### Common Slack Auth Issues

- Using a bot token (`xoxb-`) instead of a user token (`xoxp-`) for search APIs.
- Missing history/read scopes on the Slack app.
- App not installed (or not reinstalled after scope changes).
- Slack User ID is an email/username instead of an actual user ID.

## Development

### Install dependencies

```bash
composer install
npm install
```

### Build assets

```bash
npm run build
```

### Watch assets

```bash
npm run start
```

### Lint

```bash
npm run lint
```

### Auto-fix lint issues

```bash
npm run lint:fix
```

### Individual lint commands

```bash
composer lint
composer lint:fix
npm run lint:js
npm run lint:js:fix
```

## Extending

Register additional providers using the plugin action:

```php
do_action( 'daily_digest_register_providers', $provider_registry );
```

`$provider_registry` is an instance of `DailyDigest\ProviderRegistry`.

## CI

- PHPCS workflow: `.github/workflows/phpcs.yml`
- Release notes template: `.github/release-template.md`
- Release categories: `.github/release.yml`
