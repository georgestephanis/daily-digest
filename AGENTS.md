# Daily Digest Agent Guide

This file provides workspace-level guidance for AI coding agents working in this repository.

## Project Context

Daily Digest is a WordPress plugin that aggregates activity from external providers into a unified admin digest.

Core stack:

-   PHP 7.4+
-   WordPress 6.0+
-   Keyring-based provider authentication
-   React/DataViews admin UI built with @wordpress/scripts

## High-Level Architecture

-   Provider contract: `includes/contracts/providerinterface.php`
-   Provider base class: `includes/class-abstractprovider.php`
-   Provider registry: `includes/class-providerregistry.php`
-   Digest aggregation: `includes/class-digestservice.php`
-   Plugin composition root: `includes/class-plugin.php`
-   Keyring service classes: `includes/keyring-services/`
-   Provider implementations: `includes/providers/`

## Provider Implementation Pattern

When adding or changing a provider:

1. Add provider class in `includes/providers/` extending `DailyDigest\AbstractProvider`.
2. Implement:
    - `get_slug()`
    - `get_name()`
    - `get_keyring_service_name()` (if using a custom Keyring service name)
    - `test_credentials()`
    - `fetch_activity()`
3. Add provider to built-in registration in `includes/class-plugin.php`.
4. Register and load the provider's Keyring service in `includes/keyring-services.php` if needed.
5. Add provider metadata label/summary mappings in `includes/class-adminpage.php`.
6. Add logo assets and frontend mapping in `src/index.js` and `src/assets/provider-logos/`.
7. Rebuild assets with `npm run build` if `src/` changed.

## Coding Conventions

-   Follow WPCS/PHPCS rules from `phpcs.xml.dist`.
-   Use snake_case for PHP variable names.
-   Keep WordPress function calls with global namespace prefix in namespaced PHP files.
-   Use existing naming and docblock style in touched files.
-   Keep provider item schema normalized with keys: `provider`, `type`, `timestamp`, `title`, optional `summary`, `url`, `raw`.

## Validation Commands

Run from repository root:

-   Full lint: `npm run lint`
-   PHP lint only: `composer lint`
-   JS lint only: `npm run lint:js`
-   Build assets: `npm run build`

## Documentation Sync Expectations

When behavior changes, update both:

-   `README.md`
-   `readme.txt`

Keep menu/page wording aligned with current admin UI labels (for example, Connections vs Settings).
