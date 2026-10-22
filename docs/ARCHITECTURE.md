# FastFind platform architecture

## Audit and preservation (2026-10-20)

The supplied source is a small Laravel 13 / Vue 3 application, not a complete CMS platform. It has 65 tracked files and no previous Git repository, lockfiles, installed dependencies or executed runtime test evidence. The imported source is preserved at baseline commit `b23de67` on `main` in `../fastfind`. All platform work occurs in the separate `../fastfind-platform` checkout on `feature/fastfind-platform`. Existing databases and runtime files are not copied or reset.

Retain session-cookie authentication, escaped Vue rendering, the independent published snapshot, page revisions, optimistic version checks and native tables. Refactor duplicated layout/block lists into a shared catalog with authoritative PHP validation. Fix the login CSRF refresh and save-response race before extending the editor.

## Decision: public extension contracts first

Plugins implement the same `App\Plugins\Contracts\Plugin` contract, whether bundled or supplied by an independent developer. `PluginContext` exposes namespaced page fields, settings, admin views, routes, metadata providers, content registrations, events and scheduled callbacks. There are no SEO-specific branches in the manager, controllers, page writer or UI shell. FastFind SEO lives entirely under `plugins/fastfind-seo` and is the reference implementation.

Page extension values belong to a namespaced `extensions` JSON object. The core page writer validates active providers, preserves unchanged inactive values, rejects unknown namespaces and copies the result into revisions and published snapshots. Public providers receive the published snapshot. Deactivation removes provider behavior without destroying content. Legacy SEO columns are retained for existing content compatibility.

Plugin routes run through a generic dispatcher that enforces active installation, manifest capability declarations, authentication, active-account checks and Laravel Gates. A declaration is not a grant of user privileges. Metadata is structured data escaped by the host view; plugins cannot submit arbitrary head HTML through this API.

## Decision: reviewed PHP trust boundary

PHP plugins execute with application privileges; they are not sandboxed. Bundled source is reviewed with the application release. Uploaded ZIP packages require an explicitly configured publisher Ed25519 key, detached signature over the complete archive and SHA-256 integrity. Verification and bounded archive inspection precede extraction or PHP execution. Package files live outside public storage. Activation verifies installed file digests again. Only owners can install, upgrade, activate, deactivate or uninstall.

Lifecycle operations are locked and audited. Installation does not activate code. Deactivation preserves plugin data. Uninstall defaults to preservation; destructive plugin cleanup requires an explicit purge request. Upgrades require a deactivated plugin and retain the prior files for recovery. Database DDL cannot be universally rolled back; migration failure must remain visible and documented.

## Verification boundary

An independent fixture plugin must exercise the same contracts as SEO. Tests must prove draft isolation, validation, authorization, lifecycle failure behavior, package rejection and disabling SEO without breaking pages. Runtime results and remaining gaps are recorded separately; architecture descriptions are not test evidence.
