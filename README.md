# Daily Digest

Daily Digest is a WordPress plugin that aggregates per-user activity from multiple external providers into one dashboard view.

## Features

- Unified digest view rendered in wp-admin with React + DataViews.
- Provider-based architecture with built-in GitHub, ClickUp, and Slack adapters.
- Per-user provider credentials and enable/disable settings.
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
3. Visit **Daily Digest → Settings** to configure provider credentials.
4. Visit **Daily Digest → Overview** to view activity.
5. Visit **Daily Digest → Logs** (admins only) for logging controls and log viewer.

## Provider Setup References

### GitHub
- Personal Access Tokens: https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens
- REST API Authentication: https://docs.github.com/en/rest/authentication/authenticating-to-the-rest-api

### ClickUp
- API Authentication: https://developer.clickup.com/docs/authentication
- Personal Token Setup: https://help.clickup.com/hc/en-us/articles/6303426241687-Use-the-ClickUp-API

### Slack
- Create/Manage App: https://api.slack.com/apps
- OAuth v2: https://api.slack.com/authentication/oauth-v2
- User Lookup: https://api.slack.com/methods/users.lookupByEmail

### Slack Credential Format (What Daily Digest Expects)

Daily Digest uses a Slack app Bot User token and a target Slack User ID.

Required fields in Settings:

- `Workspace Subdomain`: your workspace slug only (for example, `myworkspace` from `myworkspace.slack.com`)
- `Slack User ID`: the user whose messages you want to include (typically starts with `U`)
- `Bot User OAuth Token`: token that starts with `xoxb-`

### Slack Setup Steps (Recommended)

1. Go to https://api.slack.com/apps and click **Create New App**.
2. Choose your workspace.
3. In **OAuth & Permissions**, add Bot Token Scopes:
	- `channels:read`
	- `groups:read`
	- `channels:history`
	- `groups:history`
4. Install (or reinstall) the app to the workspace.
5. Copy the **Bot User OAuth Token** (`xoxb-...`).
6. Obtain the target Slack User ID:
	- via `users.lookupByEmail` (API method), or
	- from the Slack profile menu in the app/client.
7. In WordPress, open **Daily Digest → Settings** and enter:
	- Workspace subdomain,
	- Slack User ID,
	- Bot token.
8. Enable Slack provider and click **Test Credentials**.

### Common Slack Auth Issues

- Using a `xoxp-` user token instead of `xoxb-` bot token.
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
