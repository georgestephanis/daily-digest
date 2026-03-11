=== Daily Digest ===
Contributors: daily-digest
Tags: digest, activity, integrations
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A framework plugin that aggregates per-user activity from multiple providers (GitHub, ClickUp, Slack, and custom providers).

CI: [![PHPCS](https://github.com/georgestephanis/daily-digest/actions/workflows/phpcs.yml/badge.svg)](https://github.com/georgestephanis/daily-digest/actions/workflows/phpcs.yml)

== Description ==

Daily Digest provides:

- A provider framework with a simple contract.
- A registry that supports built-in and custom providers.
- Per-user provider configuration in wp-admin.
- A unified digest table of activity across enabled providers.

Built-in providers currently expose extension points and settings fields, and can be connected via filters:

- `daily_digest_provider_github_activity`
- `daily_digest_provider_clickup_activity`
- `daily_digest_provider_slack_activity`

Each filter should return an array of activity items with at least:

- `timestamp` (parseable datetime)
- `title`

Optional fields:

- `summary`
- `url`
- `type`
- `provider`

== Installation ==

1. Upload the `daily-digest` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the Plugins menu in WordPress.
3. Go to Daily Digest > Settings.
4. Enable and configure providers for the logged-in user.

== Provider Authentication Setup ==

Use the official provider docs for token/auth setup:

- GitHub
	- Personal access tokens: https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens
	- REST API auth: https://docs.github.com/en/rest/authentication/authenticating-to-the-rest-api
- ClickUp
	- API auth: https://developer.clickup.com/docs/authentication
	- Personal token setup: https://help.clickup.com/hc/en-us/articles/6303426241687-Use-the-ClickUp-API
- Slack
	- Create/manage app: https://api.slack.com/apps
	- OAuth v2 and token scopes: https://api.slack.com/authentication/oauth-v2
	- User lookup method (for User ID): https://api.slack.com/methods/users.lookupByEmail

== Extending ==

Register additional providers via:

- `do_action( 'daily_digest_register_providers', $provider_registry )`

Where `$provider_registry` is an instance of `DailyDigest\ProviderRegistry`.

== Development ==

Install coding standards tools:

- `composer install`

Run WordPress coding standards checks:

- `composer lint`

Auto-fix supported coding standards issues:

- `composer lint:fix`

Reusable release notes template:

- `.github/release-template.md`

GitHub auto-generated release note categories:

- `.github/release.yml`
