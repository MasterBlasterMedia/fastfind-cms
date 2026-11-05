# Aurora theme example

This independent theme uses the same manifest and rendering contract as the shipped FastFind themes. Copy it, choose a unique lowercase slug and semantic version, then change the safe design tokens. `layout` may be `editorial` or `minimal`; the remaining supported keys are illustrated in `theme.json`.

Package it with `php artisan fastfind:theme-package examples/themes/aurora --key=/private/signing-key.base64 --publisher=your-publisher`. The command writes a ZIP and detached signature metadata into private storage. Keep the signing key out of this directory and Git. A receiving installation must independently trust the publisher's Ed25519 public key in `plugins.publishers` before uploading or downloading the ZIP.

Theme ZIPs accept only `theme.json`, optional `README.md`, and optional `LICENSE.txt`. They cannot ship PHP, JavaScript, CSS, SVG or other assets. Themes compose the built-in public layout and design-token controls; executable templates and arbitrary stylesheet injection are not supported. Validate contrast and responsive presentation before distribution. Installation does not activate the theme; choose it in Themes afterward. Switch to another theme before installing a higher version.
