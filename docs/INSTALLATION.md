# Local installation and runtime

Use the `feature/fastfind-platform` branch. The original development workspace keeps this in a separate `fastfind-platform` checkout and preserves the baseline in `fastfind`; a new clone does not need that directory arrangement. Run every command below from the cloned application directory. This setup uses SQLite and a development server bound to loopback.

```sh
git clone --branch feature/fastfind-platform https://github.com/MasterBlasterMedia/fastfind-cms.git
cd fastfind-cms
```

## Requirements

- PHP 8.3 or newer; Composer 2. The committed Composer lock is resolved with a PHP 8.3.0 platform floor.
- PHP extensions: cURL, DOM, fileinfo, GD, mbstring, OpenSSL, PDO, Sodium and Zip, plus the normal PHP core extensions. Install `pdo_sqlite` for the documented database. PHPUnit also needs XML/XMLWriter.
- Node.js 22 or newer and npm. Both dependency lockfiles are committed; use `composer install` and `npm ci`.
- Writable `bootstrap/cache` and `storage` directories. Their empty-directory placeholders are committed.

## New local database

Copy `.env.example` to `.env` only if `.env` does not already exist. Keep the existing APP_KEY and database when upgrading an installation. On a new installation:

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

The owner command prompts privately for a new password. It does not ship a default account. Open `/studio` on `http://127.0.0.1:8000`. For Vue/Sass live reload, run `npm run dev` in a second terminal. Stop that process normally so `public/hot` is removed; a production build also clears the hot marker.

Scheduled publication needs Laravel's scheduler. Run `php artisan schedule:work` in a separate terminal when testing it. Production deployments should run the scheduler as a supervised service or their platform's scheduler.

## Windows portable runtime used for validation

An isolated runtime was downloaded under the parent workspace's `.runtime` directory, outside both Git checkouts. It is not part of the source distribution and does not modify system PATH. The test runtime is PHP 8.4.26 NTS x64 with Composer 2.10.3. SHA-256 values were checked against the official release metadata before execution:

| Artifact | Verified SHA-256 |
| --- | --- |
| PHP `php-8.4.26-nts-Win32-vs17-x64.zip` | `da68394f9193b7f6b89d0c76861a4034ae10efee7fd55a7255d8118c2acf70d7` |
| Composer 2.10.3 PHAR | `7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6` |

Sources: [official PHP Windows releases](https://windows.php.net/downloads/releases/releases.json), [official Composer downloads](https://getcomposer.org/download/).

The PowerShell wrappers locate PHP/Composer on PATH, then the adjacent `.runtime` directory. Override the executable paths with `PHP_BINARY` and `COMPOSER_BINARY` if needed; arguments are passed directly to the executable rather than assembled into a shell command.

```powershell
.\scripts\composer.ps1 install
Copy-Item .env.example .env  # New installations only
.\scripts\php.ps1 -r "if (!file_exists('database/database.sqlite')) { touch('database/database.sqlite'); }"
.\scripts\php.ps1 artisan key:generate
.\scripts\php.ps1 artisan migrate --seed
.\scripts\php.ps1 artisan fastfind:owner
.\scripts\php.ps1 artisan storage:link
npm.cmd ci
npm.cmd run build
.\scripts\php.ps1 artisan serve --host=127.0.0.1 --port=8000
```

Windows symlink creation may require Developer Mode or an elevated terminal for `storage:link`. Do not expose private storage instead. The portable runtime's `php.ini` enables cURL, GD, Sodium and the other listed extensions. Use `scripts/php.ps1 -m` and `scripts/composer.ps1 check-platform-reqs` to inspect your own runtime.

## Docker development option

The Dockerfile builds PHP 8.3 with the required extensions. Compose mounts this checkout and binds the app to `127.0.0.1:8000`. It expects `.env` and the SQLite file to exist. Create them as above, then:

```sh
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

The Node tools service uses a named volume for Linux `node_modules`, so it does not overwrite Windows packages. Docker can create root-owned bind-mount files on Linux; configure host user mapping if needed. Docker was unavailable on the validation host; the CI container job builds the image and checks its PHP extensions against the lockfile.

## Deployment boundary

`artisan serve` and this Compose file are development tools. A production deployment needs an HTTPS web server pointing only to `public/`, protected environment secrets, appropriate PHP process management, `APP_DEBUG=false`, secure cookies, database/storage backups and scheduler supervision. See [BACKUP_RESTORE.md](BACKUP_RESTORE.md) for the implemented SQLite recovery workflow and its limits. MySQL/PostgreSQL configuration exists, but this change's locally executed schema checks use SQLite.
