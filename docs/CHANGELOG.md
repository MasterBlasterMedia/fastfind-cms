# Platform development changelog

## Mail dependency security update — 2026-10-29

- Updated only `symfony/mime` from 7.4.19 to 7.4.22 after CI detected the revised CVE-2026-45070 advisory. Symfony confirms the previously advertised fix was not included in earlier releases; see its [release clarification](https://symfony.com/blog/about-the-security-entries-in-symfony-5-4-55-6-4-49-7-4-22-and-8-1-10).
- Composer strict validation and the locked security audit passed. All 143 PHP tests and 13 browser scenarios passed again with the patched dependency, including real local reset-email rendering and media handling.

## Account password recovery — 2026-10-28

- Added sign-in recovery and reset forms backed by Laravel's password broker, trusted configured link origins, hashed expiring tokens, rate limits and generic account lookup responses.
- Enforced confirmed passwords of at least 12 characters and at most 72 UTF-8 bytes; successful reset consumes the token, rotates remember credentials, records an audit event and requires a new sign-in.
- Added password-hash session checks so old sessions lose access after a reset, and private/no-store/no-referrer responses for recovery pages and endpoints.
- Added API, component and real browser regression coverage, including local notification delivery, token reuse and old-session rejection. See the execution report for verified results.
- Documented log-mailer development setup, SMTP configuration and remaining delivery/production verification in [ACCOUNT_RECOVERY](ACCOUNT_RECOVERY.md).

## CI media isolation fix — 2026-10-22

- Reproduced the GitHub Actions uploaded-image 404 with PHP `variables_order=GPCS`: the HTTP application used default storage while the test router served the isolated run storage.
- Bound the test application's storage directory explicitly before bootstrapping its configuration and kept `GPCS` in the browser launcher to exercise this configuration on every run.
- Strengthened browser assertions to require an HTTP 200 image response and decoded dimensions, and to await the rejected executable upload's HTTP 422 response and validation message.

## feature/fastfind-platform — 2026-10-22

Preserved imported source on `main` at `b23de67`; all development uses an isolated Git worktree. Added reproducible Composer/npm lockfiles, local runtime helpers, test runners, CI and development Docker support.

### CMS and builder

- Fixed model defaults/revision creation, post-login CSRF refresh, concurrent save response handling, stale delete/restore validation, owner demotion protection and URL normalization checks.
- Added policies, strict shared catalog validation, 16 layouts/22 blocks, typed inspectors, native and keyboard reordering, duplication, undo/redo, responsive draft preview and seeded catalog demonstration.
- Added scheduling/review/archive/signed preview/diff APIs, generic extension snapshots and after-commit events. Public results remain isolated from draft edits.
- Added raster decode/re-encode, metadata stripping, dimensions/thumbnails and referenced-media deletion checks; improved ranked/paginated search.

### Public extension system

- Added Plugin/AbstractPlugin/PluginContext contracts, manifest validation, dependency/version checks, lifecycle locks, integrity verification, settings, route dispatch, events/tasks, fields and builder registration.
- Added signed bounded ZIP staging, publisher trust configuration, Ed25519 packaging CLI, owner administration, local/remote metadata catalog and independent Acme Notice example.
- Built FastFind SEO entirely through public plugin APIs: generic editor fields, plugin admin/settings, escaped published head metadata, schema data, sitemap and redirect management.
- Added reviewed theme manifests and public tokens, Professional/Minimalist theme switching, owner system health, SQLite backups with verified disposable restoration.

### Verification and documentation

Backend feature/security tests, Vue component/operation tests, real browser workflows, responsive rendering/axe checks and migration roundtrips were added. See TEST_EXECUTION_REPORT.md for executed results and KNOWN_LIMITATIONS.md for incomplete requirements. The platform is not declared production-ready.
