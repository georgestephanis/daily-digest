# Copilot Instructions for Daily Digest

## Purpose

Use these instructions when generating or editing code for this WordPress plugin.

## Requirements

-   Preserve compatibility with WordPress 6.0+ and PHP 7.4+.
-   Prefer incremental edits that match existing structure and naming.
-   Follow WPCS/PHPCS in `phpcs.xml.dist`.
-   Use snake_case for PHP variable names.
-   Keep namespaced WordPress function calls prefixed with `\` in namespaced files.

## Provider and Keyring Changes

When implementing a provider integration:

1. Add or update provider class in `includes/providers/`.
2. Register provider in `includes/class-plugin.php`.
3. Add or update Keyring service class in `includes/keyring-services/`.
4. Register Keyring service in `includes/keyring-services.php`.
5. Update provider metadata mappings in `includes/class-adminpage.php`.
6. Update provider logos/mapping in `src/assets/provider-logos/` and `src/index.js`.
7. Rebuild assets when `src/` files are modified.

## Testing and Validation

Before finishing changes, run:

-   `npm run lint`
-   `npm run build` (if frontend source changed)

Address PHPCS and JS lint issues before finalizing.

## Documentation

Keep plugin docs current when behavior changes:

-   `README.md`
-   `readme.txt`

Ensure provider lists, setup instructions, and admin menu labels match implementation.
