# Developing and distributing FastFind themes

FastFind themes are installable, signed data packages. A theme selects a built-in public layout and safe design tokens; it does not modify saved page documents, revisions or published snapshots. Themes affect the public website. Administration dark mode remains a separate browser preference.

The shipped Professional and Minimalist themes and third-party packages use the same `App\Themes\ThemeManifest` validator and public renderer. Professional declares `layout: "editorial"`; Minimalist declares `layout: "minimal"`. A new theme can use either layout without adding theme-ID-specific Laravel or frontend code. The independent Aurora example lives at `examples/themes/aurora`.

## Manifest contract

Put `theme.json` at the root of the source directory and ZIP:

```json
{
  "id": "aurora",
  "name": "Aurora",
  "version": "1.0.0",
  "description": "A spacious violet and cream design for independent publishers.",
  "layout": "editorial",
  "requires": { "fastfind": "^1.0" },
  "defaults": {
    "accent": "#6841a5",
    "font": "sans",
    "width": "wide",
    "background": "#faf7ff",
    "surface": "#ffffff",
    "text": "#252034",
    "muted": "#60546f",
    "radius": "rounded",
    "spacing": "relaxed"
  }
}
```

Only the fields above are accepted. `id`, `name`, `version`, `description` and `defaults` are required. IDs match `[a-z][a-z0-9-]{1,79}`: 2–80 characters, beginning with a lowercase letter. Names are nonempty strings up to 100 bytes; descriptions up to 1,000 bytes. Versions are up to 50 bytes and use `major.minor.patch`, optionally followed by a hyphen and a prerelease identifier containing letters, digits, dots or hyphens; build metadata with `+` is unsupported.

`layout` accepts `editorial` or `minimal` and defaults to `editorial`. `requires` accepts only a `fastfind` Composer-compatible version constraint and defaults to `{ "fastfind": "^1.0" }`. Compatibility is checked against `plugins.platform_version`; incompatible packages cannot install or activate. `defaults` is an object of the following settings. Omitted tokens receive the common defaults shown below; unknown settings are rejected.

| Setting | Accepted values | Common default |
| --- | --- | --- |
| `accent` | Six-digit `#RRGGBB` color | `#3159d9` |
| `background` | Six-digit `#RRGGBB` color | `#f8f9f6` |
| `surface` | Six-digit `#RRGGBB` color | `#ffffff` |
| `text` | Six-digit `#RRGGBB` color | `#172431` |
| `muted` | Six-digit `#RRGGBB` color | `#52665c` |
| `font` | `sans`, `serif` | `sans` |
| `width` | `normal`, `wide` | `wide` |
| `radius` | `square`, `soft`, `rounded` | `soft` |
| `spacing` | `compact`, `normal`, `relaxed` | `normal` |

Fonts use local font stacks. Tokens cannot contain CSS functions, URLs, remote fonts or arbitrary style declarations. The public shell maps validated values to CSS variables through `resources/js/themes.js`; layout selection is generic. Earlier selections containing only accent, font and width continue to work, with the additional defaults filled in.

## Create and sign a package

1. Copy `examples/themes/aurora` to your theme source directory. Choose a unique ID and edit the manifest. Keep the shipped IDs reserved for the application.
2. Review the design on real content. Check homepage, search, every used block/layout, long text and media at desktop and mobile widths. Check keyboard focus and text contrast for the whole palette; syntax validation does not establish accessibility.
3. Provision an Ed25519 keypair through your key-management process. The CLI reads a base64-encoded, 64-byte Sodium Ed25519 secret key. Keep it outside the source directory, Git and web root. The receiving operator needs the corresponding base64-encoded, 32-byte public key through an independent trusted channel.
4. Run the package command from the application directory:

```sh
php artisan fastfind:theme-package examples/themes/aurora --key=/private/signing-key.base64 --publisher=local-reviewed
```

On the bundled Windows development environment, use the PHP wrapper:

```powershell
.\scripts\php.ps1 artisan fastfind:theme-package examples/themes/aurora --key=C:\private\signing-key.base64 --publisher=local-reviewed
```

The command validates the manifest and writes a uniquely named ZIP plus a `.zip.json` metadata file to `storage/app/private/theme-packages` (`themes.output_path`). Metadata contains `type`, `id`, `version`, `publisher`, `sha256`, `signature` and `public_key`. The signature is a base64-encoded detached Ed25519 signature over the exact ZIP bytes; `sha256` is its lowercase hexadecimal digest. Changing any ZIP byte requires a new digest and signature. The secret key is never included.

The ZIP may contain exactly these root files, with exact capitalization:

- `theme.json` — required.
- `README.md` — optional author documentation.
- `LICENSE.txt` — optional license.

Directories, other filenames, duplicate paths, case variants, traversal/absolute paths, symlinks, special entries and encrypted entries are rejected. Nothing is extracted using archive-supplied paths. The installer reads the allowed files and writes them into its own private release directory. Defaults in `config/themes.php` limit the ZIP to 256 KiB, each expanded file to 64 KiB, and total expanded data to 192 KiB. JSON is decoded with a depth limit of 16.

## Publisher trust and installation

Theme and plugin installers share the operator-controlled `plugins.publishers` mapping. The default publisher ID `local-reviewed` uses `FASTFIND_PLUGIN_PUBLISHER_KEY` from the environment. Additional publishers must be explicitly provisioned in that mapping. The publisher ID uses the same 2–80-character lowercase slug format as theme IDs.

The public key in package metadata is informational. FastFind does not add it to the trust store automatically, and a catalog cannot grant trust. Verify the publisher identity and key independently before configuring it. A valid signature proves possession of that key; it does not establish design quality or license rights. Rebuild the configuration cache and restart relevant workers after changing operator configuration.

An active owner can upload a ZIP and its publisher, signature and SHA-256 in **Studio → Themes**, or install a listed theme from **Studio → Marketplace**. Installation adds the theme to the library without applying it. An active owner or administrator must select the theme and click **Apply theme**. Editors and viewers cannot activate themes; only owners can install packages.

The design sample in Themes shows the selected palette, typography and corners. It is not an isolated preview of every live page. Applying the theme changes the public site immediately, so inspect the live pages afterward. Stored selection errors or an unavailable/incompatible theme fall back to a validated available design; corrupt overrides fall back to theme defaults. Fallback does not rewrite the stored selection automatically.

## Distribution from a separate marketplace

The CMS can fetch a catalog and ZIP downloads hosted on a separate site. No marketplace domain is embedded in a theme. Operators configure:

```dotenv
FASTFIND_MARKETPLACE_URL=https://marketplace.example.com/catalog.json
FASTFIND_MARKETPLACE_SITE_URL=https://marketplace.example.com
FASTFIND_MARKETPLACE_HOSTS=marketplace.example.com,downloads.example.com
```

Replace the example domains with your actual site. Catalog, site and package URLs must use HTTPS on approved hosts and port 443. Downloads reject redirects and private/reserved network destinations. An unconfigured marketplace leaves manual signed ZIP installation available.

A schema-version-1 catalog contains `name`, `plugins` and `themes`. A theme entry includes its complete manifest, `publisher`, and `download: { "url": "...", "sha256": "...", "signature": "..." }`. Host the ZIP separately at that URL. Catalog names, IDs, versions and normalized manifests must match the downloaded package; FastFind rechecks identity, digest, trusted signature and archive rules before installation. Only one release per theme ID is listed in a catalog. The website/catalog is a distribution service; purchases, licensing, seller accounts and automatic updates are not supplied by the CMS.

## Upgrades and recovery

An upgrade must have a strictly newer version and the same publisher ID as the installed theme. Packages cannot replace shipped themes. Switch to another theme before installing an upgrade, then explicitly apply the updated theme after inspection. Even a stale saved selection pointing to an unavailable package must be changed first; installing a package cannot silently make that selection active.

Installation and activation share a cache lock, and metadata/audit changes commit in a database transaction. Releases are written to unique directories under `storage/app/private/themes` (`themes.package_path`). Failed installations clean up their incomplete release directory. Successful upgrades retain the previous release files for operator recovery. They do not provide an automatic rollback, downgrade or uninstall command, and old releases are not automatically pruned. Use a shared lock-capable cache for multiple application nodes; multi-node deployment needs separate validation.

Before upgrading, take a coordinated backup of:

- The database, including `site_settings.theme` and `site_settings.theme_packages`, plus page content and audit records.
- The entire configured private theme package directory, including prior releases. Registry records contain release paths, so preserve or deliberately reconcile those paths during recovery.
- The corresponding application release, operator publisher configuration, original signed ZIPs and signature metadata.
- The existing application key, environment configuration and media through the normal protected backup process. Signing private keys belong in a separate protected key backup, never in theme ZIPs.

The built-in `fastfind:backup` command captures SQLite and public media. **It does not copy installed private theme release files, application source, environment configuration or signing keys.** Back up those separately. Restore into a separate deployment, check theme selection, content/publication state and package provenance, and use a controlled cutover. Retained release directories alone are not a complete backup or a supported database rollback. See [backup and restoration](BACKUP_RESTORE.md).

## APIs and extension boundaries

| API | Purpose |
| --- | --- |
| `ThemeManifest::read(string $path): array` | Read and validate a bounded manifest. |
| `ThemeManifest::validate(array $manifest): array` | Validate/normalize the shared shipped, package and catalog contract. |
| `ThemeManifest::issues(array $validatedManifest): array` | Report platform compatibility issues. |
| `ThemeManifest::settings(array $defaults, array $overrides = []): array` | Validate and fill supported design tokens. |
| `ThemePackageInstaller::install(string $archive, string $publisher, string $signature, string $sha256, int $actor): array` | Verify/install a package for an authorized owner. |
| `ThemeManager::all()`, `current()`, `activate(...)` | List installed designs, resolve current design, and apply a selection. |

`GET /api/v1/studio/themes` returns installed themes and current settings to owners/administrators. `POST /api/v1/studio/themes/packages` accepts multipart `file`, `publisher`, `signature`, `sha256` for owners and returns `{ "theme": ... }` with HTTP 201. `PUT /api/v1/studio/themes` accepts `{ "id": "aurora", "settings": { ... } }` for owners/administrators. Browser requests use the normal authenticated session and CSRF protection. `GET /api/v1/site` exposes the current public descriptor without private release paths.

Installed descriptors add `source` (`bundled` or `package`), `publisher` and `installed` to the manifest. Package metadata is persisted in the `theme_packages` site setting; the current ID and validated overrides are in `theme`. Audit actions are `theme.installed`, `theme.upgraded`, `theme.package_rejected` and `theme.activated`.

This theme API does not accept PHP, JavaScript, CSS, images, SVG, custom fonts, Vue components, arbitrary HTML templates or remote asset URLs. A theme composes the two existing layout families and supported tokens. New rendering primitives require an application/extension API change reviewed and shipped with the renderer, rather than executable code in a theme ZIP. There is no WordPress or Divi package compatibility. Theme switching leaves page-builder content intact.

## Verification

`ThemePackageTest` uses real ZIP files and Ed25519 signatures to cover installation, CLI packaging, upload/service authorization, archive and manifest rejection, inactive upgrades, previous-release retention, safe fallback and unchanged content. Run the focused checks with an isolated test database:

```sh
php artisan test --compact --filter='ThemePackageTest|theme_switch'
```

See [testing guidance](TESTING.md) for the full suite and browser verification. Check your actual theme on representative content and supported browsers before publishing its package.
