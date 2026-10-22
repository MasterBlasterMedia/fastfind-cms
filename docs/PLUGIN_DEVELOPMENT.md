# FastFind plugin SDK v1

FastFind SEO is an ordinary plugin in `plugins/fastfind-seo`. Core code does not import its classes. The public contracts are currently the versioned `App\Plugins` namespace in this repository; they are not a separately published Composer SDK package.

## Trust and distribution

PHP plugins have the application's PHP and database privileges. Capability declarations do **not** sandbox them. Review code, dependencies, migrations and publisher identity before adding a publisher key. A cryptographic signature proves control of that key, not safety or independent publisher verification.

Only active owners may install, upgrade, activate, deactivate or uninstall. Session authentication and Laravel CSRF middleware protect management APIs. Installation validates compatibility and files but does not execute plugin PHP. Activation checks every installed file digest before requiring the entry file.

Bundled source paths in `config/plugins.php` are trusted as part of the reviewed application release. Uploaded packages require an operator-provisioned Ed25519 public key, a detached signature of the entire ZIP and its SHA-256 hash. They are stored under `storage/app/private/plugins`, outside the web root. The installer rejects traversal, drive paths, backslashes, reserved Windows names, case collisions, symlinks, unsupported file types, encrypted entries, oversized entries and excessive expansion. There is no automatic download-and-execute flow.

## Start a plugin

Copy `examples/plugins/acme-notice` into `plugins/acme-notice`, inspect it, then add its absolute `base_path('plugins/acme-notice')` to `plugins.bundled_paths`. This explicit source registration is a deployment trust decision. Any plugin uses this mechanism; SEO has no loader exception. Install and activate through the owner Plugin Manager.

`plugin.json`:

```json
{
  "id": "acme/notice",
  "name": "Acme Notice",
  "version": "1.0.0",
  "description": "A custom validated notice block",
  "entry": "plugin.php",
  "requires": {"fastfind": "^1.0", "php": "^8.3"},
  "permissions": ["content.extend"],
  "dependencies": {}
}
```

The entry returns an implementation of `App\Plugins\Contracts\Plugin`, normally extending `AbstractPlugin`. Use anonymous classes or `require_once` for supporting class definitions because lifecycle operations may load an entry more than once in a process.

## Public extension points

`register(PluginContext $context)` declares behavior; it must not run migrations or mutate content on every request.

| Method | Required capability | Contract |
| --- | --- | --- |
| `pageFields(label, fields, validator)` | `pages.write` | Scalar schema descriptors for the generic page editor; validator returns an array or throws Laravel ValidationException. Values live at `page.extensions[pluginId]`. |
| `settingsFields(fields, validator)` | `settings.manage` | Generic settings UI and server validation. `settings()` reads the namespaced values. |
| `head(provider)` | `pages.read` | Receives a published snapshot; returns title, description, canonical, allowed meta map and optional structured_data. Host encodes HTML and JSON. Never return raw HTML. |
| `route(method, path, capability, handler)` | matching capability | Authenticated `/api/v1/plugins/vendor/name/path`. Host enforces active plugin, active account and a Laravel Gate. |
| `publicRoute(path, handler)` | `public.routes` | Exact GET path outside reserved platform namespaces. Duplicate claims fail activation. |
| `publicResponse(handler)` | `public.routes` | May return a Response for a public GET or null. Suitable for validated redirects. |
| `adminView(id, label)` | `settings.manage` | Exposes a compiled Vue view to the administration shell. |
| `block(name, definition, validator)` | `content.extend` | Namespaced server block type `vendor/plugin/name`. Typed field validation runs before the additional plugin validator. |
| `layout(name, definition)` | `content.extend` | Namespaced layout with slots, allowed blocks, min/max and responsive metadata. |
| `listen(eventClass, listener)` | `events.listen` | Receives Laravel events such as `App\Events\PageChanged`. Listeners are gated by activation. |
| `schedule(name, callback)` | `schedule.run` | Runs each minute through `schedule:run`; queue expensive tasks. Exceptions are logged and audited. |
| `migrate()` | trusted server code | Runs migration files under the plugin's `migrations/` directory. Call from activate/upgrade, not register. |

Route capabilities map to server Gates: `pages.read` → `view-content`; `pages.write` → `edit-content`; `settings.manage` → `manage-site`. A manifest cannot grant an editor administrator privileges.

Field descriptors have `key`, `label`, `type`, optional `default`, and `options` for select fields. Options may be scalar values or `{value,label}` objects. Supported generic controls: text, textarea, number, boolean, select and URL. UI validation never replaces PHP validation.

## Publication and inactive data

The core page writer validates active extension fields and freezes them in revisions and published snapshots. Saving a draft cannot change public metadata. Missing extension namespaces preserve previous values. Disabled or unknown namespaces cannot be created or changed; existing inactive values remain intact. A removed custom block cannot be newly validated or republished until its provider is restored; keep required plugin versions available when restoring old revisions.

## Vue administration and renderers

A reviewed source plugin can include `resources/admin.js` exporting `pluginId` and `views`. For example, `export const pluginId = 'acme/example'; export const views = { settings: SettingsView };`. Declare the corresponding view with `adminView('settings', 'Example settings')`. The shell opens `/studio/extensions/acme/example/settings` only for active registered views.

Modules are compiled with the application through Vite. Uploading PHP never evaluates arbitrary uploaded JavaScript. Deploy new Vue modules in reviewed source and run `npm run build`. Generic page fields/settings work without a custom Vue module. A module may export `register()` to register custom block definitions and renderers when active. Use `registerBlock` from `resources/js/builder/registry.js` and `registerBlockRenderer` from `resources/js/builder/renderers.js`; the example demonstrates escaped Vue rendering.

Use the same `vendor/plugin/name` namespace for client block and layout IDs as the server. The client compares the server's active plugin list with its registered modules. Deactivation removes that plugin's namespaced block definitions, layout definitions and renderers; open palettes and content components react to the removal. Saved content is retained and displays an unavailable-block message until its renderer is restored. Reactivation calls `register()` again, so it should only declare definitions and renderers, without one-time application side effects. Repeated refreshes while active do not register duplicate definitions.

If `register()` throws, the client removes its partially registered namespaced entries, keeps other plugins operational and reports a concise interface-loading error. The next metadata refresh can retry registration after a corrected source build. This lifecycle cleanup is not a JavaScript sandbox: compiled modules remain trusted application code and must not register unrelated IDs, global event listeners or untracked side effects.

## Lifecycle and updates

1. Install stores reviewed metadata and integrity digests in an inactive state.
2. Activate checks PHP/FastFind compatibility and active dependencies, executes activation/migrations, then registers behavior.
3. Deactivate disables behavior before its hook runs; a failed hook cannot keep the plugin enabled. Data stays intact.
4. Upgrade requires deactivation and a higher version. Verified new package files are stored separately; failure leaves the previous selection and reports an error. Database migrations may have irreversible effects, so take and validate backups before upgrades. Automatic database rollback is not claimed.
5. Uninstall requires deactivation. By default, plugin settings, package files and plugin-owned data are retained. `purge:true` invokes trusted plugin cleanup and removes retained settings. SEO preserves page/revision metadata even during purge.

Package file rollback is an operator procedure using the retained previous package path recorded in the audit event. Do not overwrite an active plugin directory in place. For bundled plugins, keep the previous application release in Git; modifying bundled files invalidates recorded digests until an explicit upgrade/reinstall.

PHP classes cannot be unloaded or replaced within one process. Deploy lifecycle changes with normal request-scoped PHP execution and restart queue workers after upgrades. Laravel Octane or another long-lived HTTP container is not supported by this loader. Tests that load the same named plugin class from a second release directory use a fresh PHP process, matching this boundary.

## Signing a reviewed release

Keep private keys outside the repository and web root. Provision an Ed25519 keypair using your organization's key-management process. Give the receiving operator the public key through an independent approved channel. Configure `plugins.publishers[publisherId]`; the local development key uses `FASTFIND_PLUGIN_PUBLISHER_KEY` and publisher ID `local-reviewed`.

```bash
php artisan fastfind:plugin-package plugins/acme-notice --key=/private/signing-key.base64 --publisher=local-reviewed
```

The command writes a ZIP and detached metadata in private storage. Upload the ZIP, publisher ID, signature and SHA-256 to the owner package endpoint. Never put private signing keys in manifests or uploaded archives.

## Catalog API

`GET /api/v1/studio/plugin-catalog` returns the actual local reviewed source manifests by default, explicitly labeled `source: local`. It is not a launched marketplace. `FASTFIND_PLUGIN_CATALOG_URL` optionally selects an HTTPS metadata catalog with an explicitly allowlisted host in `FASTFIND_PLUGIN_CATALOG_HOSTS`. Private/reserved DNS addresses and redirects are rejected, and the connection pins the checked IP. Remote catalogs cannot grant trust or cause PHP downloads/execution. The v1 response is `{source, name, plugins:[manifest...]}`. Publisher reputation, hosted downloads, reviews, screenshots and remote update installation remain future marketplace work.

## Tests

`PluginLifecycleTest` exercises both SEO and an unrelated fixture through the same SDK. `PluginPackageSecurityTest` signs real ZIP fixtures and verifies rejection before entry execution. Add your own migration, activation, schema, publish isolation and deactivation regressions. Every content type must satisfy the catalog's server and Vue rendering tests.
