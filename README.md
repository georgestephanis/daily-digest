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

- Authentication: https://developer.clickup.com/docs/authentication
- OAuth Getting Started: https://developer.clickup.com/docs/Getting%20Started
- OAuth Token Endpoint: https://developer.clickup.com/reference/getaccesstoken

ClickUp uses OAuth in Keyring:

1. Create a ClickUp OAuth app and copy **client_id** and **secret**.
2. In **Tools -> Keyring -> Daily Digest ClickUp -> Manage**, set API Key = client_id and API Secret = secret.
3. In the ClickUp app settings, add the Keyring callback URL shown in that Manage screen as Redirect URL.
4. Connect ClickUp via Keyring and complete the OAuth authorization.

### Slack

- Create/Manage App: https://api.slack.com/apps
- Installing with OAuth: https://docs.slack.dev/authentication/installing-with-oauth
- `search:read` scope reference: https://docs.slack.dev/reference/scopes/search.read
- `auth.test` method reference: https://docs.slack.dev/reference/methods/auth.test

### Connection Metadata

Daily Digest stores provider tokens and connection context in Keyring metadata.

Connection context such as Slack user/team or ClickUp team IDs is discovered from provider APIs during Keyring verification and displayed read-only in Daily Digest settings.

### Slack Setup Steps (Recommended)

1. Go to https://api.slack.com/apps and click **Create New App**.
2. Choose your workspace.
3. In **OAuth & Permissions**, add the **User Token Scope** `search:read`.
4. In **OAuth & Permissions**, add this Redirect URL from Keyring Slack manage screen:
   - `Tools → Keyring → Daily Digest Slack → Manage`
5. In **Keyring → Daily Digest Slack → Manage**, paste:
   - **Client ID** into API Key
   - **Client Secret** into API Secret
6. Save credentials in Keyring.
7. In WordPress, open **Daily Digest → Settings** and click **Connect via Keyring** for Slack.
8. Complete Slack authorization and run **Test Connection**.

### Common Slack Auth Issues

- Missing `search:read` user scope on the Slack app.
- Redirect URL mismatch (`bad_redirect_uri`) between Slack app settings and Keyring callback URL.
- Existing connection was authorized before scope updates; reconnect to apply new scopes.

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
