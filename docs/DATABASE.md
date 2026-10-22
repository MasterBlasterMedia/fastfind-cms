# Database model and migrations

SQLite is the verified local database. MySQL/PostgreSQL connection configuration exists, but those servers have not been executed in this environment. Do not equate portable migration syntax with production database validation.

## Tables

| Table | Purpose and constraints |
|---|---|
| `users` | Unique email, hashed password, owner/admin/editor/viewer role, active flag |
| `password_reset_tokens` | Standard token storage; recovery endpoints/UI are not implemented |
| `pages` | Unique draft slug and nullable unique public slug, document JSON, status, monotonically increasing version, draft/public search visibility, independent public snapshot, scheduling timestamp, optional author/editor foreign keys |
| `page_revisions` | Immutable snapshot JSON, unique page/version pair, optional actor; cascades when its page is deleted |
| `navigation_items` | Ordered visible site-relative links |
| `media_assets` | Encoded public file/thumbnail paths, MIME/size/dimensions, alt text, uploader |
| `site_settings` | JSON values keyed by name, including brand, theme, scheduler heartbeat and settings preserved for uninstalled plugins |
| `audit_events` | Timestamped actor/action/subject/context; deleted users are set null |
| `plugin_installations` | Vendor/name primary key, installed version/status/source, private/local path, reviewed manifest/file digests, settings, diagnostic status |
| `fastfind_seo_redirects` | Plugin-owned table created on SEO activation: unique source, destination, HTTP redirect status |
| `migrations` | Laravel migration history, including plugin migrations |

The original migration is unchanged. Migration `000002_extend_content_platform` adds nullable page extension JSON and indexed schedule timestamp, plus nullable media dimensions/thumbnail path. Migration `000003_create_plugin_installations` adds plugin state. Existing pages retain their data and published snapshots. Model defaults fix newly created in-memory records before their first revision. No migration rewrites legacy SEO metadata or existing documents.

Page extension data is a bounded object keyed by plugin ID. Save, revision and publication keep it together with the document. Disabling a plugin preserves its values; enabled validators govern changes. Legacy `seo_title` and `seo_description` columns remain for compatibility and are preserved when omitted by the new editor. The public renderer uses the saved public snapshot, not current draft columns.

## Transactions and versions

Page writes lock the row, verify the expected version and policy, validate the whole document and extension payload, save a new version/revision, and emit a generic after-commit event. Restore validates historical values under the same transaction. Database uniqueness races are converted into validation failures. User updates lock owner rows to protect the last active owner. SQLite serializes writes differently from MySQL/PostgreSQL; production concurrency behavior still needs testing on the intended server.

Lifecycle operations use a cache lock. Plugin migration DDL is not universally transactional. Upgrade failure preserves the previous selected package metadata but may require operator repair of partially applied schema changes. The plugin SDK requires unique migration filenames and table namespaces. Deactivation does not drop tables; uninstall preserves data by default. SEO's explicit purge deletes its redirect rows but preserves page/revision metadata and its table.

## Apply and verify

Back up the actual installation before deploying a migration. Keep the existing `.env`, APP_KEY and database location. Apply only pending migrations:

```sh
php artisan migrate --force
php artisan migrate:status
```

For an isolated migration/rollback check, run:

```sh
php scripts/verify-migrations.php
```

This allocates a new temporary SQLite database, runs all core migrations, rolls back, and reruns them. It never runs a fresh/reset against the development database. `php artisan test --compact` uses in-memory SQLite; plugin tests separately exercise activation migrations.

Do not use `migrate:fresh` or `migrate:rollback` on live data to deploy this branch. Down migrations remove the corresponding new fields/tables and their data. Recover an older release using a verified backup and a separate recovery environment.

Database-backed queues/cache/sessions are not provisioned by these migrations. The documented installation uses file sessions/cache and synchronous jobs. Provision and validate the appropriate Laravel tables/services before changing those drivers.
