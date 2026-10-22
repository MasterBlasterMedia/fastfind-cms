# FastFind CMS platform

Independent Laravel 13, Vue 3 and Sass CMS. Platform development is on `feature/fastfind-platform` in the separate `fastfind-platform` checkout. The imported application is preserved on `main` in `../fastfind` at `b23de67`.

## Implemented

- Session login, owner/admin/editor/viewer permissions, active-account checks and owner protection.
- Versioned page editing, revisions/restoration, independent public snapshots, publish/unpublish, scheduling and review/archive APIs, signed saved-version preview API.
- Shared registry of **16 section layouts and 22 blocks**, typed inspectors, native drag-and-drop, keyboard move controls, duplication, undo/redo and visual draft previews.
- Decoded/re-encoded image uploads, dimensions/thumbnails, usage protection, navigation, brand settings, ranked/paginated public search and owner system-health dashboard.
- Plugin contract, namespaced fields/settings/routes/events/tasks/blocks/layouts, lifecycle administration, signed ZIP verification, compatibility/dependency checks and a metadata-only catalog.
- **FastFind SEO uses the same public `PluginContext` APIs as independent plugins**: published metadata, social tags, structured data, sitemap and redirects. Disabling it removes its behavior and preserves content.
- Professional and Minimalist themes with validated public design tokens; private SQLite backups with verified disposable restoration.
- Backend, Vue and browser tests, locked dependencies, GitHub Actions workflow and development Docker tooling.

This is a functional platform implementation, **not a production-readiness claim**. See [known limitations](docs/KNOWN_LIMITATIONS.md) and [executed verification](docs/TEST_EXECUTION_REPORT.md). Custom collections/taxonomies, nested and reusable blocks, rich-text editing, account recovery/MFA and a hosted marketplace remain future work.

## Local setup

Requires PHP 8.3+, Composer 2, Node 22+, SQLite and the extensions listed in [INSTALLATION](docs/INSTALLATION.md). Run from this checkout. Copy the environment file only for a new installation; preserve existing keys/data when upgrading.

```sh
composer install
cp .env.example .env
php -r "if (!file_exists('database/database.sqlite')) { touch('database/database.sqlite'); }"
php artisan key:generate
php artisan migrate --seed
php artisan fastfind:owner
php artisan storage:link
npm ci
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

Open `http://127.0.0.1:8000/studio`. The owner command prompts for your credentials; there is no shipped development/production password. The catalog demo is created as a draft. Install and activate FastFind SEO from **Plugins** as an owner. Signed uploaded packages require operator-configured publisher trust; see the SDK.

Windows users with the prepared portable runtime can replace `php` with `.\scripts\php.ps1` and `composer` with `.\scripts\composer.ps1`. PowerShell can use `Copy-Item .env.example .env`. Exact Docker and Windows instructions are in [INSTALLATION](docs/INSTALLATION.md).

## Verification

```sh
php artisan test --compact
php scripts/verify-migrations.php
npm run test:coverage
npm run build
npx playwright install chromium
npm run test:e2e
composer audit --locked
npm audit --audit-level=moderate
```

Tests use disposable databases. Browser tests start their own isolated server on port 8123. On this Windows host, use `$env:PW_CHANNEL = 'chrome'; npm run test:e2e` for installed Chrome. See [TESTING](docs/TESTING.md) for scope, reports and environment details.

## Developer documentation

| Topic | Guide |
|---|---|
| Architecture and migration strategy | [ARCHITECTURE](docs/ARCHITECTURE.md), [DATABASE](docs/DATABASE.md) |
| Builder and editorial behavior | [PAGE_BUILDER](docs/PAGE_BUILDER.md), [CONTENT_WORKFLOWS](docs/CONTENT_WORKFLOWS.md) |
| HTTP contracts | [API](docs/API.md) |
| Third-party extensions and sample block | [PLUGIN_DEVELOPMENT](docs/PLUGIN_DEVELOPMENT.md), [Acme Notice example](examples/plugins/acme-notice/README.md) |
| Public themes | [THEME_DEVELOPMENT](docs/THEME_DEVELOPMENT.md) |
| Operations | [DEPLOYMENT](docs/DEPLOYMENT.md), [BACKUP_RESTORE](docs/BACKUP_RESTORE.md) |
| Security and performance | [SECURITY](docs/SECURITY.md), [SECURITY_PERFORMANCE_REPORT](docs/SECURITY_PERFORMANCE_REPORT.md) |
| Results and remaining work | [TEST_EXECUTION_REPORT](docs/TEST_EXECUTION_REPORT.md), [KNOWN_LIMITATIONS](docs/KNOWN_LIMITATIONS.md), [CHANGELOG](docs/CHANGELOG.md) |

Baseline audit documents are explicitly historical; they are not current feature lists.
