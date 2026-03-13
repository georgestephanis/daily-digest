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

== Description ==

Daily Digest provides:

* A provider framework with a simple contract.
* A registry that supports built-in and custom providers.
* Per-user provider configuration in wp-admin.
* A unified digest view of activity across enabled providers.
* Admin-only logging controls and a log viewer screen.

Built-in providers currently expose extension points and settings fields, and can be connected via filters:

* daily_digest_provider_github_activity
* daily_digest_provider_clickup_activity
* daily_digest_provider_slack_activity

Each filter should return an array of activity items with at least:

* timestamp (parseable datetime)
* title

Optional fields:

* summary
* url
* type
* provider

== Installation ==

1. Upload the daily-digest folder to the /wp-content/plugins/ directory.
2. Activate the plugin through the Plugins menu in WordPress.
3. Go to Daily Digest > Settings.
4. Enable providers and connect each provider through Keyring.

== Provider Authentication Setup ==

Use the official provider docs for token/auth setup:

* GitHub
  * Create OAuth app: https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/creating-an-oauth-app
  * OAuth apps overview: https://docs.github.com/en/apps/oauth-apps
  * OAuth app authorization: https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/authorizing-oauth-apps
* ClickUp
  * API auth: https://developer.clickup.com/docs/authentication
  * Personal token setup: https://help.clickup.com/hc/en-us/articles/6303426241687-Use-the-ClickUp-API
* Slack
  * Create/manage app: https://api.slack.com/apps
  * Installing with OAuth: https://docs.slack.dev/authentication/installing-with-oauth
  * search:read scope reference: https://docs.slack.dev/reference/scopes/search.read
  * auth.test method reference: https://docs.slack.dev/reference/methods/auth.test

Daily Digest stores provider tokens and connection context in Keyring metadata.

Connection context (for example Slack user/team and ClickUp team IDs) is discovered from provider APIs during Keyring verification and displayed read-only in Daily Digest settings.

Slack setup steps:

1. Create a Slack app at https://api.slack.com/apps.
2. In OAuth & Permissions, add the user token scope search:read.
3. Add the Keyring callback URL shown in Tools > Keyring > Daily Digest Slack > Manage as a Redirect URL.
4. In Keyring manage screen for Daily Digest Slack, enter Client ID (API Key) and Client Secret (API Secret), then save.
5. Use Connect via Keyring in Daily Digest > Settings, complete Slack authorization, and run Test Connection.

== Extending ==

Register additional providers via:

do_action( 'daily_digest_register_providers', $provider_registry )

Where $provider_registry is an instance of DailyDigest\ProviderRegistry.

== Frequently Asked Questions ==

= Why is my digest empty? =

Most empty digest results are caused by one of these:

* The provider is not enabled in Daily Digest > Settings.
* Keyring connection is missing or invalid for the provider.
* The selected time window has no matching activity.

Use the provider Test Connection button in Settings and then refresh the digest.

= Who can view logs? =

Only users with the manage_options capability can access Daily Digest > Logs.

= Where are log files stored? =

Logs are written to the uploads directory in a daily-digest-logs folder.
The Logs page includes a direct button to open that location.

= Can I add my own provider integration? =

Yes. Register a provider using the daily_digest_register_providers action and implement the provider contract expected by Daily Digest.

== Development ==

Install coding standards tools:

* composer install

Install JavaScript build tools:

* npm install

Build admin React/DataViews assets:

* npm run build

Watch and rebuild assets during development:

* npm run start

Run JavaScript lint checks:

* npm run lint:js

Auto-fix JavaScript lint issues:

* npm run lint:js:fix

Run all lint checks (PHP + JavaScript):

* npm run lint

Auto-fix lint issues (PHP + JavaScript):

* npm run lint:fix

Run WordPress coding standards checks:

* composer lint

Auto-fix supported coding standards issues:

* composer lint:fix

Reusable release notes template:

* .github/release-template.md

GitHub auto-generated release note categories:

* .github/release.yml

== Changelog ==

= 0.1.0 =
* Initial public release.
