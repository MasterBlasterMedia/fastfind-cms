# FastFind — Security model and future development

## Threat boundaries

**Anonymous reader:** Can access published page snapshots, discoverable pages, site navigation and site name only. Draft document data, team information, media metadata and audit logs are available only through authenticated Studio endpoints.

**Logged-in viewer:** Can view the Studio. Cannot write pages or configuration.

**Editor:** Can author and publish pages, upload images, and work with page revisions. Cannot configure the site or other users.

**Administrator:** Can edit pages, edit navigation and site brand, and delete uploaded media. Cannot grant new users permissions.

**Owner:** Can manage all roles and user accounts. Owner creation is a local CLI-only procedure; there is no anonymous registration route.

## Existing defenses

- Laravel bcrypt/argon password hashing via Eloquent `hashed` cast, session cookie auth, login throttling, CSRF validation, session regeneration on login and logout.
- Server-side Gate authorization for every privileged endpoint. Active-account middleware rechecks active status on studio requests.
- Optimistic page versioning and database transactions to serialize edits and publication. Revision snapshots support restores.
- Page schema validation: only ten known block types, seven section layouts, simple scalar properties, constrained URLs, max sections, max blocks, and maximum text sizes.
- Public frontend renders text through Vue interpolation and known components; no `v-html` rendering of author content.
- Image upload restricted by server validation to common raster image formats and 5 MB; stored with generated filenames.
- Security response headers include CSP, frame denial, MIME sniffing denial, referrer and permissions policies. Production HTTPS is required for secure cookies and HSTS.
- Draft edits do not alter published document/title/URL and published search visibility is snapshotted separately.

## Before a production deployment

- Confirm all actual installed versions and patch security advisories.
- Exercise the PHP feature tests (`php artisan test`) and add end-to-end Playwright keyboard/mouse/browser tests.
- Review server configuration: proxy headers, cookie/domain/HTTPS security, CSP for deployed asset and media hosts.
- Consider strong owner/admin authentication (passkeys or TOTP MFA), session invalidation after role/password changes, invite-based onboarding and account recovery.
- Add database backup/restore drills, persistent audit log storage, rate limits per role and upload quotas.
- Add media antivirus checks, resizing and image transformations before displaying untrusted uploads at scale.
- Add ARIA live announcements for keyboard drag/drop; current editor offers keyboard reorder buttons but no screen-reader narration.
- Support content sanitization with a Markdown parser only if rich formatting is added; never send arbitrary user HTML to `v-html`.

## Roadmap beyond this usable foundation

- Scheduled releases, content approval workflow, per-collection permission controls and concurrent editor presence.
- Categories, tags, reusable content blocks, custom page types, page templates, global design tokens.
- Advanced search indexing, fuzzy matching, synonyms and analytics.
- Content APIs for headless clients, cache invalidation, redirects, localization and translation workflow.
- Media cropping, object-store uploads, file usage tracing, automated thumbnails and accessibility audits.
- Automated visual regression, performance tests, WCAG conformance audit, internationalization and multi-site.

These roadmap features are **not claimed to be implemented** in this archive.
