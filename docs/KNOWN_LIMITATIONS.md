# Known limitations and production work

This document describes the final implemented scope. Earlier baseline audit files describe the imported application. Verification results are in TEST_EXECUTION_REPORT.md.

## Content and editor

- Pages are the native content model. Custom collections, taxonomies, reusable global content and fine-grained ownership/author roles are not implemented.
- The builder has 16 layouts and 22 typed scalar-property blocks. It has no arbitrary nesting, reusable block library, forms/submissions, rich-text WYSIWYG, custom CSS/HTML or automatic content migrations between plugin versions. Collection editors use structured newline fields.
- Review, archive, scheduling, signed preview and revision diffs are implemented APIs; only ordinary save/publish/unpublish/history/restore and visual draft preview have dedicated editor controls. Review is a status, not enforced separation of author and approver.
- Undo/redo has 50 in-memory steps; navigating/reloading loses history and unsaved changes after confirmation. There is no offline editing, local recovery store, live collaboration or automatic conflict merge. A conflict preserves input and requires deliberate resolution.
- Current search ranks parameterized LIKE matches and paginates results. It is not PostgreSQL full-text search and has no typo tolerance, analytics or external indexing service.
- Raster uploads are re-encoded; animation and originals are not preserved. Media has no folder hierarchy, bulk operations or detailed UI usage list. Usage checks and sitemap enumeration need indexing/streaming at large scale.

## Extensions and themes

- Plugins run trusted PHP with the application's privileges. Capability declarations are API checks, not a process sandbox. A trusted publisher can ship dangerous code; signatures establish integrity/provenance, not safety.
- Reviewed local installation, signed ZIP uploads and owner-requested downloads from a configured external marketplace are implemented for plugins/themes. A separate static storefront starter is included. No production marketplace is hosted; moderation, reviews, billing/licensing, seller accounts, automatic updates and rollback orchestration are not implemented.
- Browser modules are compiled by Vite from reviewed `plugins/**/resources/admin.js`. Uploaded server packages do not automatically inject new JavaScript; operators must review/copy client sources and rebuild. There is no runtime remote-code loader.
- PHP class loading requires request-scoped PHP. Octane/RoadRunner/Swoole are unsupported; restart long-running workers on code changes. Upgrades require deactivation; database DDL may need manual recovery after failure.
- Deactivation preserves namespaced fields. Documents containing custom plugin blocks/layouts need the plugin re-enabled or those blocks removed before saving. Public rendering shows an unavailable-component fallback.
- Themes are data manifests interpreted by the same renderer for bundled and signed custom ZIP packages. Two designs and an Aurora example are included. Custom palettes, font stacks, width, corners, spacing and two layout presets are supported. Executable theme files, arbitrary CSS/HTML/JS, remote fonts/assets, theme-specific Vue components and header/footer template editing are not supported. Design samples are not an isolated live-site preview.

## Accounts and operations

- Email password recovery is implemented for active accounts; inbox delivery requires configured SMTP and deployment verification. Email verification, MFA, configurable RBAC, personal API tokens and SSO are not implemented. Owners provision accounts; plan administrative recovery for inaccessible mailboxes before deployment. See [ACCOUNT_RECOVERY](ACCOUNT_RECOVERY.md).
- SQLite is the executed database. MySQL/PostgreSQL support has not been validated with real servers, and native backups support only SQLite/local media. Horizontal scaling, distributed lock behavior and concurrent database races need deployment-specific testing.
- Backups are plaintext private snapshots; offsite encryption/retention and live recovery cutover remain operator responsibilities. The tested restore is a disposable database/media restore with integrity and write checks.
- Queue health reports uninstrumented worker liveness; a configured queue is not proof of a running worker. Scheduler service must be deployed and monitored separately.
- Docker is development tooling using PHP's development server, not a production image. [GitHub Actions run 39871303441](https://github.com/MasterBlasterMedia/fastfind-cms/actions/runs/39871303441) passed PHP 8.3/8.4, frontend/browser and container checks for commit `002e1af` on 22 October 2026. Local Docker execution and a production deployment remain unverified; that run does not validate later commits.
- No independent penetration test, load benchmark, uptime test, screen-reader study, cross-browser/device matrix, production deployment or production recovery drill has been completed. Automated axe checks cover selected rendered states, not an accessibility certification.
- Browser screenshots are captured and representative states were inspected, but approved image baselines and automated pixel-difference regression checks are not implemented. Layout/schema discovery tests and catalog browser coverage do not exhaust every interaction for every layout.

Before production: choose/test the actual database and storage, verify reset-email delivery and implement required MFA, deploy TLS/FPM/web server and scheduler/workers, manage secrets and publisher trust, test offsite recovery and performance at expected scale, and conduct manual security/accessibility review.
