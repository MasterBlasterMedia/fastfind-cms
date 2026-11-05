# External FastFind marketplace

FastFind's Studio can browse and install themes and plugins from a separate website. The catalog and ZIPs remain on that website or its approved download host. The CMS does not depend on WordPress or an embedded storefront. No marketplace domain is assumed, and this repository does not deploy one automatically.

## Connect an installation

Once your marketplace has public HTTPS URLs, set these in the CMS `.env`:

```dotenv
FASTFIND_MARKETPLACE_URL=https://market.example.com/catalog.json
FASTFIND_MARKETPLACE_SITE_URL=https://market.example.com/
FASTFIND_MARKETPLACE_HOSTS=market.example.com,downloads.example.com
FASTFIND_PLUGIN_PUBLISHER_KEY=BASE64_APPROVED_ED25519_PUBLIC_KEY
```

The example domains and key are placeholders. `FASTFIND_MARKETPLACE_URL` is the JSON catalog endpoint; `SITE_URL` is the optional human-facing storefront. `HOSTS` contains exact, lowercase approved domain names for all three types of URL: catalog, storefront and package downloads. No wildcard hosts, private networks, custom ports, HTTP, URL credentials or redirects are accepted. Public DNS is required. The PHP cURL extension is required for downloads.

`FASTFIND_PLUGIN_PUBLISHER_KEY` configures the built-in publisher ID `local-reviewed`. Add other approved publisher IDs/public keys to `config/plugins.php`'s `publishers` mapping using your secret/configuration management. The catalog cannot add a trusted key. Obtain public keys through an independently approved channel; never publish or upload private signing keys.

Run `php artisan config:clear` after changing local configuration; rebuild cached configuration during your normal production deployment. Owners see **Marketplace** in the Studio navigation. With no catalog URL, it shows connection guidance and links to the local theme/plugin libraries without invented packages.

If Windows PHP reports `cURL error 60`, configure `curl.cainfo` and `openssl.cafile` in the PHP configuration to an up-to-date trusted CA certificate bundle, then restart PHP. Keep TLS verification enabled. The bundled local runtime may require this configuration before remote downloads; a signed package can also be uploaded manually.

## Publish a catalog

Serve JSON in this form:

```json
{
  "schema_version": 1,
  "name": "FastFind Marketplace",
  "themes": [],
  "plugins": []
}
```

Each `themes` or `plugins` entry has this structure:

```json
{
  "manifest": { "...": "the complete theme.json or plugin.json from the signed ZIP" },
  "publisher": "local-reviewed",
  "download": {
    "url": "https://downloads.example.com/aurora-1.0.0.zip",
    "sha256": "64 lowercase hexadecimal characters from the packaging metadata",
    "signature": "base64 detached Ed25519 signature from the packaging metadata"
  }
}
```

This record is a structural example, not an installable release. Copy the **whole manifest** from the ZIP and the exact digest/signature/publisher from the packaging command's JSON output. Do not rename or edit the ZIP after signing. The installed manifest must match the catalog's validated manifest, including permissions, dependencies and theme defaults. Version 1 supports one release per type/ID, up to 200 records total and a 1 MB catalog. ZIP transfers are limited to 20 MB; each installer imposes its own stricter archive/expanded-size rules (themes default to a 256 KB ZIP).

Package commands from the CMS checkout:

```sh
php artisan fastfind:theme-package examples/themes/aurora --key=/private/signing-key.base64 --publisher=local-reviewed
php artisan fastfind:plugin-package examples/plugins/acme-notice --key=/private/signing-key.base64 --publisher=local-reviewed
```

Publish only ZIPs and public detached metadata, using immutable release URLs. All official and third-party packages use the same manifest validators, publisher trust and installers. FastFind SEO continues to use the public plugin SDK; no catalog-only activation hooks or core modifications are required.

## Install and update

1. The owner searches or filters the connected catalog and reviews a package.
2. The browser sends its type, ID, version and digest. The server fetches the catalog again and rejects a changed release.
3. FastFind checks the approved host/public DNS, pins the verified address for its HTTPS connection, disables redirects/proxies, and bounds response size/time. It verifies digest, signature and exact manifest identity before invoking the same installer used by manual uploads.
4. The package is installed **without activation**. Apply themes under **Themes**; activate plugins under **Plugins**.

Updates must have a higher version. Apply a different theme before upgrading the active theme; deactivate plugins before upgrading them. Bundled themes cannot be replaced by downloads. Previous version files are retained, but there is no automatic update or rollback orchestration. Signature verification establishes provenance/integrity, not code safety: PHP plugins execute with application privileges. Downloaded plugins with new Vue modules still require reviewed source integration and an asset rebuild, as documented in [PLUGIN_DEVELOPMENT](PLUGIN_DEVELOPMENT.md).

`GET /api/v1/studio/marketplace` returns `configured`, `source`, `name`, `site_url`, and `items`. Items include type/identity/description/version/publisher, digest, requirements, installed version, installability and blocking issues. Raw download URLs and signatures are kept server-side. `POST /api/v1/studio/marketplace/install` accepts `{type,id,version,sha256}` and returns `{type,id,version,status,message}`. Both endpoints require an active owner; installation is throttled to five requests/minute. Invalid packages are rejected before activation, and temporary downloads are removed on success/failure.

## Separate storefront starter

[examples/marketplace-site](../examples/marketplace-site/README.md) is a deployable static storefront with search, type filters, download links and downloadable signature JSON. It consumes the same `catalog.json` as the CMS. It ships with an empty collection so you can publish real, reviewed releases later. You can use another website/CMS as the storefront as long as its catalog follows this contract.

Payments, licensing, seller accounts, moderation, ratings, automatic updates, screenshots and a hosted production marketplace are not implemented. The earlier `/studio/plugin-catalog` endpoint remains a separate metadata-only local/remote discovery API for compatibility; new downloads use the marketplace API above.

Back up private installed package directories in addition to the database, media, application release, `APP_KEY` and publisher trust configuration. The native SQLite/media backup command does not include private theme/plugin release files.
