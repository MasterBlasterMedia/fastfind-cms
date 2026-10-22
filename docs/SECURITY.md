# Security model

FastFind uses Laravel cookie sessions, regenerated authentication sessions, current CSRF tokens, hashed passwords, login throttling, active-account middleware and server-side policies/Gates. Authorization is checked by services as well as HTTP controllers. Owner/admin/editor can edit content; site management is owner/admin; users, plugin lifecycle and health are owner-only. Viewers can inspect drafts, so do not assign that role to public visitors.

## Content and publication

Public endpoints select published snapshots and public slugs. Saving a newer draft does not change the live document or metadata. Search and SEO sitemap use published visibility. Saved-version preview links expire after 30 minutes, become invalid after a version change, and forbid caching/indexing. Such URLs grant temporary draft access and should be handled as credentials.

The shared catalog rejects unknown layouts/blocks/properties, invalid types/URLs, duplicate IDs, incompatible layout placement and oversized documents. Vue renderers use escaped text; no user HTML renderer exists. Head metadata is structured, validated and escaped in Blade and DOM APIs; JSON-LD is encoded safely. URL validation rejects protocol-relative, backslash and unsafe scheme forms. Video embeds are restricted to supported YouTube/Vimeo forms. Media is bounded, decoded by GD, re-encoded and stripped of metadata; PHP/SVG uploads are not accepted. The bundled demonstration SVG is reviewed source, not user upload content.

Version checks prevent stale saves/publication/deletion. Restoration creates a new validated draft revision. The media service prevents deletion of files referenced by current drafts/publications, but does not preserve all historical revision media indefinitely. File storage and database transactions cannot provide cross-system atomicity under all failures; use backups.

## Plugin trust

**An executing PHP plugin has application privileges.** Manifests and capability checks constrain the SDK and route dispatcher, not arbitrary PHP. Install only code reviewed and trusted by the operator. There is no special bypass for official plugins: SEO calls the same public context APIs as the independent test/sample plugins.

Uploaded ZIPs require an operator-configured Ed25519 publisher key, detached signature over the exact ZIP bytes and SHA-256. A key inside a package never grants trust. Before execution, inspection rejects traversal, absolute/Windows unsafe paths, symlinks/special entries, duplicates, encrypted/oversized/decompression-abuse archives. Files are staged outside the webroot; installation leaves them inactive. Activation checks compatibility, active dependency versions and file digests. Dependency order is deterministic, cycles/startup failures disable consumers, and registered callbacks are removed on failure/deactivation. Lifecycle actions are locked and audited.

Keep `storage/app/private/plugins` and application code unwritable to untrusted users/processes. A publisher signature is not a security review. Long-lived PHP servers are unsupported; use request-scoped PHP and restart workers after changes. Upgrade requires deactivation and a fresh request if an older PHP release was loaded. DDL failures can require manual recovery. Uninstall preserves data unless explicit purge is requested. Client modules are reviewed/rebuilt with Vite; uploaded PHP packages do not dynamically inject JavaScript.

Catalogs are metadata only. Remote URLs must be HTTPS on an operator allowlist; public-address DNS validation, pinned resolution, no redirects, timeout and response bounds reduce SSRF exposure. No remote catalog grants package trust. No live external catalog integration was exercised locally.

## Operations

Use HTTPS, `APP_ENV=production`, `APP_DEBUG=false`, secure cookies, a private stable APP_KEY and a least-privilege web/DB account. Serve only `public/`; disable execution from media storage. Keep dependency lockfiles, update through review/tests and scan advisories regularly. Configure trusted proxies to match the deployment. Never put `.env`, signing secrets, databases, sessions or backups in the repository/webroot.

Native backups are plaintext private SQLite/media snapshots with checksum verification and disposable restoration. Offsite encryption, key escrow, monitoring and cutover remain operator work. Error details are logged privately; plugin UI uses generic failures. Audit events record changes but are not a tamper-proof external security log.

Account recovery, MFA, SSO and configurable role policies are not implemented. Automated security regression tests and dependency audits are documented in TEST_EXECUTION_REPORT.md; they are not a penetration test or production assurance. See KNOWN_LIMITATIONS.md before deployment.
