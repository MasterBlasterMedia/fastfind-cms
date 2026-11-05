# Separate FastFind marketplace starter

This folder is a standalone static website: no Laravel, database, shared CMS session or Node build is required. It provides search, theme/plugin filtering, ZIP links and downloadable signature metadata. Its empty catalog is intentional; no fictional releases or signing keys are included.

1. Copy this folder to a separate HTTPS static host. No site has been deployed by this change.
2. Package/sign reviewed releases with the FastFind CLI. Publish only the ZIPs and detached signature metadata, never private signing keys.
3. Populate `catalog.json` following [the marketplace contract](../../docs/MARKETPLACE.md). Each record contains the exact packaged manifest, publisher ID and download URL/digest/signature. Host ZIPs on this site or a separately approved HTTPS file host. Direct downloads must not redirect.
4. Preview from an HTTP server (opening `index.html` as a `file:` URL prevents catalog fetches). For example: `php -S 127.0.0.1:8090 -t examples/marketplace-site` from the repository root. Local HTTP is only for storefront preview; the CMS downloader requires public HTTPS.
5. Set the CMS catalog/storefront URLs and exact host allowlist, approve publisher public keys independently, and clear cached configuration. The CMS consumes the same `catalog.json` rendered by this site.

The site renders catalog text as text and only exposes HTTPS download links. It does not verify a ZIP itself; FastFind verifies signatures and integrity when installing. Payments, licenses, publisher accounts, moderation, ratings and automatic updates are outside this starter. Public keys delivered with a download do not automatically become trusted.
