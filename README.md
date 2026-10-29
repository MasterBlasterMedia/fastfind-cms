# FastFind CMS

An independent content management system built with **Laravel 13, Vue 3, and Sass**. FastFind combines a visual page builder, versioned publishing, and a native plugin SDK. It has no WordPress runtime dependency.

**Development branch: `feature/fastfind-platform`.** Clone that branch for the platform described below. The imported application is preserved in Git history at `b23de67`; the original local `main` checkout has not been replaced.

This is an actively developed platform, not a production-ready release. Read the [known limitations](docs/KNOWN_LIMITATIONS.md) before planning a deployment.

## What is included

- **Visual page builder:** 16 section layouts, 22 typed content blocks, drag-and-drop, keyboard movement, duplication, undo/redo, and responsive previews.
- **Publishing:** drafts separated from public snapshots, version checks, revisions/restoration, scheduling, review/archive APIs, and signed saved-version preview links.
- **Administration:** owner/admin/editor/viewer permissions, account activation, email password recovery with session invalidation, navigation, brand settings, audit activity, media management, and system health.
- **Media:** decoded/re-encoded raster uploads, thumbnails, dimension limits, and protection against deleting currently referenced media.
- **Plugins:** manifests, compatibility/dependency checks, lifecycle controls, signed ZIP installation, settings, routes, events, scheduled tasks, blocks, layouts, and compiled Vue views.
- **FastFind SEO:** page metadata, canonical/social tags, validated structured data, sitemaps, and redirects. It uses the **same public `PluginContext` APIs as third-party plugins**, with no SEO-specific loader exception.
- **Themes and operations:** Professional and Minimalist themes, validated design tokens, SQLite backups, verified disposable restoration, Docker development tooling, and GitHub Actions CI.
- **Verification:** Laravel, Vitest/Vue Test Utils, Playwright, and automated accessibility checks.

Custom collections/taxonomies, nested/reusable blocks, rich-text editing, email verification/MFA, and a hosted marketplace remain future work. The plugin catalog lists real reviewed local sources; it is not a live public marketplace.

## Requirements

| Dependency | Requirement |
|---|---|
| PHP | 8.3 or newer |
| PHP extensions | cURL, DOM, fileinfo, GD, mbstring, OpenSSL, PDO, `pdo_sqlite`, Sodium, XML/XMLWriter, Zip, and normal PHP core extensions |
| Composer | 2.x |
| Node.js | 22 or newer, with npm |
| Database | SQLite for the documented and locally tested setup |
| Git | Access to this repository |

Both dependency lockfiles are committed. Use `composer install` and `npm ci` to reproduce them. PostgreSQL/MySQL configuration is available, but those servers were not part of the recorded local validation. PHP, Composer, Node, databases, and build output are not bundled.

## Quick start — macOS / Linux

Run these commands for a **new checkout**. You need permission to clone the private repository.

```sh
git clone --branch feature/fastfind-platform https://github.com/MasterBlasterMedia/fastfind-cms.git
cd fastfind-cms

cp .env.example .env
composer install
php -r "if (!file_exists('database/database.sqlite')) { touch('database/database.sqlite'); }"
php artisan key:generate
php artisan migrate --seed
php artisan fastfind:owner
php artisan storage:link

npm ci
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

The owner command prompts for your name, email, and a password of at least 12 characters. **No default login is shipped.**

Open the website at **http://127.0.0.1:8000** and sign in to the administration workspace at **http://127.0.0.1:8000/studio**. Keep the server terminal running.

## Quick start — Windows PowerShell

Install PHP with the extensions above, Composer, and Node, then make their commands available on PATH. The repository's PowerShell wrappers can also use explicitly configured executables; see [installation details](docs/INSTALLATION.md).

```powershell
git clone --branch feature/fastfind-platform https://github.com/MasterBlasterMedia/fastfind-cms.git
Set-Location fastfind-cms

Copy-Item .env.example .env
composer install
php -r "if (!file_exists('database/database.sqlite')) { touch('database/database.sqlite'); }"
php artisan key:generate
php artisan migrate --seed
php artisan fastfind:owner
php artisan storage:link

npm.cmd ci
npm.cmd run build
php artisan serve --host=127.0.0.1 --port=8000
```

Windows may require Developer Mode or an elevated terminal to create the public media symlink with `storage:link`. The portable runtime used during development is outside this repository; a new clone must supply its own runtime. If PHP/Composer are not on PATH, see the `PHP_BINARY` and `COMPOSER_BINARY` overrides in [INSTALLATION](docs/INSTALLATION.md).

## First steps in FastFind

1. Sign in at `/studio` using the owner account you created.
2. Open **Pages**. The seeded **Builder catalog** is a draft containing every supported layout and block.
3. Create or edit a page, add sections and blocks, preview it, and save a draft. Publish when you want that snapshot to become public.
4. Open **Plugins**, install **FastFind SEO**, then activate it. Its generic page fields and SEO administration view become available.
5. Open **Themes** to select a public theme, and configure navigation and site settings.
6. Open **System health** to check storage, database, scheduler, and plugin status.

Editing a published page does not immediately change its public content. Publish again to replace the public snapshot. Deactivating SEO removes its behavior while retaining stored page data.

## Development and configuration

For frontend live reload, keep `php artisan serve` running and start `npm run dev` in a second terminal. For scheduled publication and plugin minute tasks, run this in another terminal:

```sh
php artisan schedule:work
```

The scheduler is optional for ordinary manual publishing. Queue tasks run synchronously by default, so this setup does not require Redis or a separate queue worker.

`.env.example` selects local SQLite, file sessions/cache, and a loopback application URL. Key settings are:

| Setting | Local default / purpose |
|---|---|
| `APP_URL` | `http://127.0.0.1:8000`; keep it aligned with the URL you use |
| `APP_KEY` | Generated once for a new installation; preserve it when updating |
| `DB_CONNECTION` | `sqlite` |
| `DB_DATABASE` | Unset uses `database/database.sqlite`; set an absolute path if overriding |
| `SESSION_DRIVER`, `CACHE_STORE` | `file` |
| `QUEUE_CONNECTION` | `sync` |
| `MAIL_MAILER` | `log`; writes notifications to private logs instead of sending email |
| `FASTFIND_BACKUP_SCHEDULED` | Optional `true` enables daily backup when the scheduler runs |

Password recovery is available through **Forgot your password?** on the sign-in screen. Configure SMTP and the trusted `APP_URL` for inbox delivery; local log-mailer links are normally in `storage/logs/laravel.log`. See [account recovery setup](docs/ACCOUNT_RECOVERY.md) for configuration, expiration, rate limits, and session behavior.

Keep `.env`, databases, uploaded media, backups, and signing keys out of Git. Updating an existing installation must preserve its `.env`, `APP_KEY`, database, and media. Back up first, install locked dependencies, run ordinary migrations, and rebuild assets. Never use `migrate:fresh` on an installation whose content you want to keep.

## Docker development setup

Docker provides an alternative to installing PHP and Node directly. After cloning the development branch, create `.env` and the empty SQLite file:

```sh
cp .env.example .env
touch database/database.sqlite
docker compose build
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate --seed
docker compose run --rm app php artisan fastfind:owner
docker compose run --rm app php artisan storage:link
docker compose run --rm node npm ci
docker compose run --rm node npm run build
docker compose --profile scheduler up -d app scheduler
```

On Windows, replace the first two commands with `Copy-Item .env.example .env` and `New-Item -ItemType File database/database.sqlite` for a new installation. Open http://127.0.0.1:8000/studio. Stop services with `docker compose --profile scheduler down`.

Compose uses bind-mounted application files and a separate Node dependency volume. This image runs PHP's development server; it is not a production web-server image. See [deployment guidance](docs/DEPLOYMENT.md) for TLS, PHP process management, secrets, backups, and releases.

## Tests and checks

From a prepared checkout:

```sh
composer validate --strict
composer check-platform-reqs
composer audit --locked
php artisan test --compact
php scripts/verify-migrations.php
npm audit --audit-level=moderate
npm run test:coverage
npm run build
npx playwright install chromium
npm run test:e2e
```

On Linux, Playwright may need system libraries: `npx playwright install --with-deps chromium`. On Windows, you can use installed Chrome instead: `$env:PW_CHANNEL = 'chrome'; npm.cmd run test:e2e`.

Backend tests use in-memory SQLite. The migration check creates a disposable database. Browser tests launch their own isolated server on **port 8123**, with a new database/storage directory per run. Do not point tests at a live installation. Reports are generated under `coverage/`, `playwright-report/`, and `test-results/` and are ignored by Git.

Recorded local verification on **28 October 2026** passed **143 Laravel tests, 64 Vue tests, and 13 real-browser tests**, plus the production asset build. Browser coverage includes 375/768/1280/1920px and 12 axe scans. Earlier dependency audits and migration roundtrips remain recorded separately. These results describe the tested scope, not full security/accessibility certification. See the [test execution report](docs/TEST_EXECUTION_REPORT.md).

GitHub Actions is configured to check PHP 8.3/8.4, frontend tests/build, Chromium browser tests, and the Docker image. Check the repository's **Actions** tab for remote results; local results and CI results are separate evidence.

## Troubleshooting

| Symptom | Check |
|---|---|
| Composer reports a missing extension | Run `php -m` and `php --ini`; enable the extension in the CLI PHP configuration, then run `composer check-platform-reqs`. |
| Missing application key | On a new installation only, run `php artisan key:generate`. Preserve existing keys. |
| SQLite connection fails | Confirm `pdo_sqlite` is enabled and the database file and parent directory are writable. |
| Missing Vite manifest / blank assets | Run `npm ci` and `npm run build`; remove a stale `public/hot` file only after stopping Vite. |
| Uploaded images are unavailable | Run `php artisan storage:link` and check public storage permissions. |
| A password reset email is missing | The default log mailer does not send email. Check private logs locally, or SMTP configuration/provider delivery in production; see [ACCOUNT_RECOVERY](docs/ACCOUNT_RECOVERY.md). |
| A configuration change is ignored | Run `php artisan config:clear`, then restart the local server. |
| Port 8000 is occupied | Use another `artisan serve --port=...` value and update `APP_URL` to match. |
| Browser tests cannot start | Free port 8123, install the browser, and inspect the printed per-run PHP log path. |

## Developer documentation

| Topic | Guide |
|---|---|
| Installation and Windows wrappers | [INSTALLATION](docs/INSTALLATION.md) |
| Architecture and database | [ARCHITECTURE](docs/ARCHITECTURE.md), [DATABASE](docs/DATABASE.md) |
| Builder and publishing | [PAGE_BUILDER](docs/PAGE_BUILDER.md), [CONTENT_WORKFLOWS](docs/CONTENT_WORKFLOWS.md) |
| HTTP contracts | [API](docs/API.md) |
| Password recovery and mail setup | [ACCOUNT_RECOVERY](docs/ACCOUNT_RECOVERY.md) |
| Plugin SDK and independent example | [PLUGIN_DEVELOPMENT](docs/PLUGIN_DEVELOPMENT.md), [Acme Notice](examples/plugins/acme-notice/README.md) |
| Theme development | [THEME_DEVELOPMENT](docs/THEME_DEVELOPMENT.md) |
| Operations and recovery | [DEPLOYMENT](docs/DEPLOYMENT.md), [BACKUP_RESTORE](docs/BACKUP_RESTORE.md) |
| Security and performance | [SECURITY](docs/SECURITY.md), [SECURITY_PERFORMANCE_REPORT](docs/SECURITY_PERFORMANCE_REPORT.md) |
| Verification | [TESTING](docs/TESTING.md), [TEST_EXECUTION_REPORT](docs/TEST_EXECUTION_REPORT.md) |
| Project status | [KNOWN_LIMITATIONS](docs/KNOWN_LIMITATIONS.md), [CHANGELOG](docs/CHANGELOG.md), [DEVELOPMENT_ROADMAP](docs/DEVELOPMENT_ROADMAP.md) |

Baseline audit documents describe the imported application and are retained as historical evidence. PHP plugins execute trusted server code with application privileges; capability declarations and package signatures are not a PHP sandbox.
