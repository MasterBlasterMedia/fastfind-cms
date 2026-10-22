# Deployment and operational setup

The included Docker image/Compose file runs PHP's **development server**. It is useful for local evaluation and is not a hardened production web tier. Docker and remote CI were not run on the validation host. Production needs a separately reviewed PHP-FPM/web-server deployment, TLS, secret management and the actual database/storage tested at expected load.

## Local Docker

From a new checkout, create `.env` from the example and an empty `database/database.sqlite`. Preserve existing files when upgrading. Compose mounts this checkout at `/app`, so dependencies/assets are generated in the mounted project:

```sh
docker compose build
docker compose run --rm app composer install --no-interaction --prefer-dist
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate --seed
docker compose run --rm -it app php artisan fastfind:owner
docker compose run --rm app php artisan storage:link
docker compose run --rm node npm ci
docker compose run --rm node npm run build
docker compose up -d app
```

Open `http://127.0.0.1:8000`. Node is a tooling profile; explicitly running the service enables it. Enable local scheduler processing with `docker compose --profile scheduler up -d`. On Linux, arrange UID/GID mapping and writable-directory ownership for mounted files; do not make the whole application world-writable.

## Release procedure

1. Run the tests/build/audits in TESTING.md using the intended PHP version. Verify the target DB engine, storage, scheduler and trusted plugins in a staging deployment. SQLite is the only database executed locally.
2. Take and verify a backup before schema changes. Preserve APP_KEY, database, media and trusted plugin files/settings independently of the release directory. Native backup limitations are in BACKUP_RESTORE.md.
3. Provision production environment variables: `APP_ENV=production`, `APP_DEBUG=false`, HTTPS `APP_URL`, `SESSION_SECURE_COOKIE=true`, stable secret APP_KEY and database credentials/path. Use file sessions/cache/sync queue unless other services and required tables are provisioned.
4. Build reviewed frontend sources with `npm ci` and `npm run build`. The deployed artifact must include `public/build` and no stale `public/hot`. Install PHP dependencies with `composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader` and check runtime extensions with `composer check-platform-reqs`.
5. Enter a maintenance window for schema-changing releases (`php artisan down`), pause relevant workers, and run `php artisan migrate --force`. Never run fresh/reset commands on the live database. Generate the public-storage link for the deployment. Ensure only cache/storage directories require web-process writes.
6. Apply configuration/view caches with `php artisan config:cache` and `php artisan view:cache`. Route/event caching should be validated in staging for the exact release and trusted plugins before adopting it. Plugin dispatcher registration is per request, not a need to rewrite Laravel core routes.
7. Restart PHP/queue/scheduler workers on code or plugin changes. Resume the site with `php artisan up`; verify login/write access, draft isolation, public pages/media, SEO and health. Maintain an older release plus verified backup for controlled recovery.

Never rotate APP_KEY as part of ordinary deployment. Never overwrite live data to test restoration. Composer/Node dependencies are release inputs; no build tool needs a public production endpoint.

## Scheduler and workers

Run Laravel's scheduler every minute from the application directory using the service account and correct PHP binary:

```cron
* * * * * cd /srv/fastfind && /usr/bin/php artisan schedule:run >> /var/log/fastfind-scheduler.log 2>&1
```

This dispatches scheduled publication and generic plugin minute callbacks. Enable `FASTFIND_BACKUP_SCHEDULED=true` only after checking backup storage/retention; the configured backup is daily at 02:00 in application time. `php artisan schedule:work` is convenient locally. Monitor scheduler heartbeat in owner System health. Scheduled publication uses the original actor's current permissions and fails safely if those permissions no longer apply.

Synchronous queue mode needs no queue worker. If adopting an external queue, provision its tables/service and supervisor, and instrument liveness; the existing health check explicitly does not claim to know an external worker is running. Long-lived HTTP runtimes such as Octane are unsupported because PHP plugin classes cannot be unloaded between releases. Lifecycle locks must use a shared supported cache when deploying multiple nodes; multi-node behavior has not been validated.

## Plugin release operations

Keep publisher public keys in operator-managed configuration. Keep signing private keys outside the web process and source tree. Review and sign packages, then install inactive and inspect requested capabilities/version compatibility. Deactivate dependents before their provider. Back up before upgrading; a failed DDL migration may require manual repair even if previous files remain selected. Review/copy browser module sources into the release and rebuild assets for client extensions. Follow PLUGIN_DEVELOPMENT.md for request-scoped class loading and data retention.

## Recovery and monitoring

Use the separate-environment recovery procedure in BACKUP_RESTORE.md. Monitor disk capacity, application errors, plugin startup errors, scheduler failures, login anomalies and backup verification. Provide offsite encrypted backups and external alerts. Add rate/load testing, account recovery/MFA and independent security/accessibility assessment before declaring the deployment production-ready.
