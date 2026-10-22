# FastFind themes

FastFind currently discovers reviewed local theme manifests from `resources/themes/*/theme.json`. Theme settings affect the public Vue shell and content renderer. They do not modify page documents, revisions or published snapshots, and do not restyle the administration workspace.

## Available themes

| ID | Appearance | Defaults |
| --- | --- | --- |
| `professional` | Editorial hero artwork, card illustrations and generous spacing | Accent `#3159d9`, sans serif, wide |
| `minimalist` | Centered introduction, no hero artwork, restrained cards and serif typography | Accent `#33564b`, serif, normal width |

Both themes use the same persisted page content. The `/studio/themes` screen lets authorized owners and administrators select a theme and change its accent, typography and content width. Applying a theme updates the site setting in a database transaction and records an audit event.

## Manifest

```json
{
  "id": "professional",
  "name": "FastFind Professional",
  "version": "1.0.0",
  "description": "Warm surfaces and an editorial presence.",
  "defaults": {
    "accent": "#3159d9",
    "font": "sans",
    "width": "wide"
  }
}
```

Only three customization tokens are accepted:

- `accent`: a six-digit hexadecimal color beginning with `#`.
- `font`: `sans` or `serif`.
- `width`: `normal` or `wide`.

Unknown settings are rejected by the server. Font choices are local font stacks; this implementation does not download remote font resources. Color syntax validation does not guarantee accessible contrast for every user-selected accent, so test contrast when changing a palette.

`GET /api/v1/studio/themes` returns discovered themes and the selected settings. `PUT /api/v1/studio/themes` accepts `{ "id": "minimalist", "settings": { ... } }`. `GET /api/v1/site` includes the active theme descriptor. The Vue public shell reads that descriptor, sets `data-theme`, and applies the validated `--theme-accent`, `--theme-font` and `--theme-width` CSS variables.

## Add a reviewed local theme

1. Add a manifest in a new directory under `resources/themes`. Give it a unique ID and valid defaults.
2. Add its reviewed stylesheet rules to the source build. Scope them under `.public-shell[data-theme='your-id']`; use the existing content classes and design tokens. Avoid global administration overrides.
3. Run `npm run build` and the automated tests. Verify homepage, search, each registered layout/block, long content and image behavior at 375, 768, 1280 and 1920 pixels. Include keyboard, focus and contrast checks.
4. Apply the discovered theme through the Themes screen. Verify that a published page remains published and that an unpublished draft is not exposed by switching themes.

A manifest alone changes only the supported token values. A distinct new design needs its reviewed CSS included in the build. The existing generic page renderer remains responsible for content semantics and safe output.

## Current boundaries

The implemented system is local discovery, selection, safe token configuration, two real public designs and audited activation. The selection cards are design samples, not an isolated live-site preview. There is no ZIP theme installer, marketplace theme downloader, theme-specific Vue component registration API, compatibility solver, automatic update/rollback workflow, arbitrary header/footer editor or remote asset loader yet.

Do not place executable PHP or unreviewed JavaScript in a theme package and assume the manifest makes it safe. Any future executable-theme installer must use the same publisher trust, signature, integrity, archive-validation and review controls as server plugins. Current local theme changes should be reviewed and released through source control and the asset build.

Administration dark mode is a separate preference. It is stored in browser storage as a display choice and does not change the public site's theme or store authentication credentials.

## Files

- `resources/themes/professional/theme.json` and `resources/themes/minimalist/theme.json`: shipped descriptors.
- `app/Services/ThemeManager.php`: discovery, validation, persistence and audit event.
- `resources/js/views/Themes.vue`: theme selection and settings.
- `resources/js/App.vue`: public theme application.
- `resources/scss/platform.scss`: public theme rules, layout styling and separate administration presentation.

Consult `TESTING.md` for executed checks. A successful theme build alone is not a claim of WCAG conformance or complete cross-browser coverage.
