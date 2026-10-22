# Verification commands

Run commands from the feature checkout after the installation steps. PHP examples work with `php` on PATH; on Windows replace `php` with `.\scripts\php.ps1` and `composer` with `.\scripts\composer.ps1` when using the portable runtime.

```sh
composer validate --strict
composer check-platform-reqs
composer audit --locked
php artisan test --compact
php scripts/verify-migrations.php
npm ci
npm audit --audit-level=moderate
npm run test:coverage
npm run build
npx playwright install chromium
npm run test:e2e
```

`php artisan test` uses an in-memory SQLite database from `phpunit.xml`. The schema script always allocates a new temporary SQLite file, bypasses cached application configuration, runs all migrations, rolls them back, reruns them, and deletes its temporary files. It does not run against the configured development database.

The frontend suite uses Vitest 4.1.12+, Vue Test Utils and jsdom. Its tests cover edits made while a save is in flight, concurrency version adoption, plugin field preservation, independent section duplication, incompatible/full-layout rejection, block moves, URL and video restrictions, all registered block renderers, HTML escaping, keyboard tabs, carousel controls and typed schema inputs. `npm test` runs without coverage; `npm run test:watch` watches local changes.

Coverage is intentionally scoped to `resources/js/builder` and the `Block`, `SectionLayout`, and `SchemaFields` components. It is not an application-wide coverage claim. HTML and JSON summaries are generated under ignored `coverage/`. The final Vue run passed 52 tests with 89.15% statements, 73.68% branches and 97.03% lines across that scope. The additional extension tests exercise deactivation, reactivation, failed-registration cleanup and reactive component behavior.

Browser tests use the committed Playwright setup and disposable fixtures. Each run creates `storage/framework/testing/run-<UUID>` and sets it as Laravel's storage root, isolating its database, media, plugins, views, sessions, caches and logs. `scripts/setup-e2e.php` refuses to reuse an existing database and requires a new `e2e-*.sqlite` file inside that run's `framework/testing` directory with `APP_ENV=testing` and no database URL override. Its router serves `/storage` only from the run's own public media, without creating or changing the development media symlink. The fixture accounts are for those disposable tests only.

The launcher prints its run directory and PHP log path. PHP setup output and request logs are captured in the run's `logs/php-server.log`, while Laravel logs stay in that same private run tree. These ignored run directories remain available for debugging. Browser traces and reports stay in ignored `test-results` and `playwright-report` directories. CI uploads logs and browser reports on failure, without uploading fixture databases or session files. See the final execution report for browser run results.

## CI

The workflow runs backend tests and schema roundtrips on PHP 8.3 and 8.4. A Node 22 job runs locked dependency installation, audit, frontend coverage, production build and Chromium browser tests. A container job builds the development Docker image and checks its extensions against `composer.lock`. GitHub Actions execution is separate from local validation; configuring the workflow does not mean it has already run remotely.

## Environment limits observed

On the Windows validation host, sandboxed networking could not resolve package registries; dependency downloads succeeded with approved network access. PHP's writable-directory checks and Vitest temporary-file renames also failed under the Windows sandbox. The backend and frontend suites were executed with approved local process access after those environment failures. Do not suppress assertions or ignore required extensions to work around such failures.

PHP 8.4.26 and Node 24.15.0 were used locally. PHP 8.3 compatibility is represented in the lockfile and CI matrix; a separate local PHP 8.3 executable was not tested. Docker and MySQL/PostgreSQL services were not available on the validation host.
