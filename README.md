# FastFind CMS

**FastFind is an independent, self-hosted content management system** built with Laravel 13, Vue 3, and Sass. It is designed from the ground up; it contains no WordPress code, plugin dependencies, legacy imports, or third-party content-model assumptions.

## What is included

- **Public discovery site:** responsive homepage, featured published pages, search, navigation, reading experience, and per-page server-rendered SEO metadata.
- **Vue Content Studio:** searchable page index, overview dashboard, recent activity, native HTML drag-and-drop page builder, page properties inspector, page preview, revision history, and publish/unpublish.
- **Layouts:** stack, two- and three-column, grid, split, hero, feature. Section backgrounds and width variants.
- **Blocks:** heading, plain text, image, link button, quote, callout, divider, list, statistic, and expandable FAQ. All content is data and rendered by safe Vue templates, never unsanitized HTML.
- **Platform:** team roles, session login, media upload, navigation management, site-brand settings, audit events, editorial isolation of drafts from published snapshots.
- **Security:** disabled public registration, account active checks, CSRF-protected mutations, throttled login and search, rate-limited image upload, server-side permissions, approved block schemas, optimistic version conflict detection, MIME-limited images, and content security headers.

### Technology

- PHP 8.3+ with Laravel 13
- Vue 3, Vue Router, Axios, Vite, Sass
- SQLite (simple development), PostgreSQL or MySQL (deployment)
- Same-origin browser sessions, no client-side token storage

## Development setup (PHP/Composer/Node installed)

```bash
composer install
cp .env.example .env
mkdir -p storage/app/private
# On Linux / macOS:
touch database/database.sqlite
php artisan key:generate
php artisan migrate --seed
php artisan fastfind:owner
php artisan storage:link
npm install
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

Visit **http://127.0.0.1:8000** for the public site and **http://127.0.0.1:8000/studio** for the editor. You will create your own owner username and password when the command prompts you. **No default admin credentials are included.**

To develop Sass/Vue with live reload, run `npm run dev` in another terminal while the Laravel server runs. To test, run `php artisan test`.

### Docker option

Docker Compose runs the Laravel server on `127.0.0.1:8000`. Since compiled frontend assets are included in the archive, Node does not need to run inside Docker to preview it.

```bash
cp .env.example .env
touch database/database.sqlite
docker compose build
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate --seed
docker compose run --rm -it app php artisan fastfind:owner
docker compose run --rm app php artisan storage:link
docker compose up -d
```

**Note:** Docker-created files can be root-owned on Linux hosts. Set up user mapping or fix ownership if needed. The development server is **not** a production web server.

### Production checklist

1. Use PHP 8.3+, HTTPS, a real domain, and a web server directed **only** at `public/`.
2. Set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://...`, `SESSION_SECURE_COOKIE=true`, and a fresh strong `APP_KEY`. Keep `.env` outside web access.
3. Use your own database and backups; run `php artisan migrate --force`; run `npm ci && npm run build` with an existing, committed lockfile (or `npm install && npm run build` for the current source archive, which does **not** include a lockfile).
4. Ensure writable `storage/`, `bootstrap/cache/`, and private backups; configure queue, mail, access logs and monitoring appropriate to deployment.
5. Run `php artisan test`, browser-based tests, security review, vulnerability scanning and accessibility review before going live.
6. For production media, consider object storage, virus scanning, quotas, and image processing; the starter uses Laravel's local public disk.

## Architecture

`app/Models/` — first-party database entities.

`app/Services/DocumentSchema.php` — centralized allowlist for layouts and block types; validates all nested user content.

`app/Services/PageWriter.php` — transactional editor saves, revisions, publishing and audit records.

`app/Http/Controllers/` — thin HTTP API boundary; separates anonymous reads from authenticated studio mutations.

`resources/js/views/Editor.vue` — drag/drop composition, section and block inspector, keyboard reorder controls, live preview and history.

`resources/js/components/Block.vue` — safe public and editor block rendering (Vue escapes interpolation).

`resources/scss/app.scss` — complete responsive design system.

`database/migrations/` — fresh native schema; no external source tables.

### Roles

| Role | Read studio | Edit pages | Publish | Manage media | Manage branding & navigation | Manage users |
|---|---|---|---|---|---|---|
| Owner | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Admin | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| Editor | ✓ | ✓ | ✓ | Upload | — | — |
| Viewer | ✓ | — | — | View | — | — |

### Versioning and publication

Every page has a **draft** (`document`) and an independent **published snapshot**. Saving never replaces the published snapshot. Publishing copies the draft fields and document atomically, timestamps the publication, and creates a revision. Restoring an older version affects the draft only until republished. The public page URL is reserved separately from the draft slug. Editor writes require an expected `version`, preventing accidental overwriting of newer edits.

### API (same-origin `/api/v1`)

- Public GET: `/site`, `/home`, `/pages/{slug}`, `/search?q=text`, `/session`
- Session POST/DELETE: `/session`
- Studio GET: `/studio/dashboard`, `/studio/pages`, `/studio/pages/{id}`, `/studio/pages/{id}/revisions`, `/studio/media`, `/studio/navigation`, `/studio/settings`, `/studio/users`
- Studio mutations: POST `/studio/pages`, PUT `/studio/pages/{id}`, POST `/studio/pages/{id}/publish` and `/unpublish`, POST `/studio/pages/{id}/restore`, DELETE `/studio/pages/{id}`, POST `/studio/media`, DELETE `/studio/media/{id}`, PUT `/studio/navigation`, PUT `/studio/settings`, POST `/studio/users`, PATCH `/studio/users/{id}`.

See `docs/SECURITY_AND_ROADMAP.md` for known limitations and expansion ideas.

## Independent design

FastFind starts with its own visual language and content schema. It does not require any other CMS to be installed, running or exported. The sample pages are entirely new examples and are safe to delete.

## Verification performed for this archive

The Vue/Vite/Sass production build and PHP syntax checks run successfully in the provided build environment. **Laravel runtime tests and migrations were not executed there** because Composer dependencies and requisite local PHP extensions were not installed. CI and feature tests are included for execution after installing dependencies.
