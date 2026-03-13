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
4. Enable providers, configure non-secret fields, and connect each provider through Keyring.

== Provider Authentication Setup ==

Use the official provider docs for token/auth setup:

* GitHub
  * Personal access tokens: https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens
  * REST API auth: https://docs.github.com/en/rest/authentication/authenticating-to-the-rest-api
* ClickUp
  * API auth: https://developer.clickup.com/docs/authentication
  * Personal token setup: https://help.clickup.com/hc/en-us/articles/6303426241687-Use-the-ClickUp-API
* Slack
  * Create/manage app: https://api.slack.com/apps
  * OAuth v2 and token scopes: https://api.slack.com/authentication/oauth-v2
  * User lookup method (for User ID): https://api.slack.com/methods/users.lookupByEmail

Slack settings expected by Daily Digest:

* Workspace Subdomain: workspace slug only (example: myworkspace from myworkspace.slack.com)
* Slack User ID: target user ID (typically starts with U)
* Slack token is connected through Keyring (user token with search:read scope)

Slack setup steps:

1. Create a Slack app at https://api.slack.com/apps.
2. In OAuth & Permissions, ensure you can issue a user token with search:read scope.
3. Install or reinstall the app to your workspace.
4. Copy a User OAuth Token (xoxp-...).
5. Find the Slack User ID (users.lookupByEmail or profile tools).
6. Enter Slack workspace/user values in Daily Digest > Settings.
7. Use Connect via Keyring in Daily Digest > Settings and run Test Connection.

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
