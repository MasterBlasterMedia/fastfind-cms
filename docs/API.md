# HTTP API

All paths below have prefix `/api/v1`. JSON responses use Laravel validation errors (`422`, `errors` map), unauthenticated `401`, denied `403`, and missing `404`. Stale versions are validation failures. Send `Accept: application/json`. Browser writes require a same-origin session and the `X-CSRF-TOKEN` from the HTML meta tag (or the latest session response). Login and a successful password reset rotate the token; the Axios client updates it. No bearer-token/external OAuth API is implemented.

## Public and session endpoints

| Method/path | Response or input |
|---|---|
| GET `/site` | Brand, visible navigation, public theme, active plugin IDs |
| GET `/home` | Featured/recent published content |
| GET `/pages/{publishedSlug}` | `{page}` from published snapshot plus normalized head metadata |
| GET `/search?q=...&page=1` | Ranked, paginated published/search-visible results; bounded query |
| GET `/session` | Current user (or null) and CSRF token |
| POST `/session` | `{email,password}`; login throttled; regenerated session and token |
| DELETE `/session` | Logout and invalidate session |
| POST `/password/forgot` | `{email}`; generic `{message}`; 6 requests/minute/IP and 5/hour/email key |
| POST `/password/reset` | `{email,token,password,password_confirmation}`; `{message,csrf_token}`; 10 attempts/minute/IP |
| GET `/preview/{id}?version=...&expires=...&signature=...` | Saved draft JSON for a valid signed link; subsequent version change returns 410 |

Recovery requires CSRF protection even for guests. Only active accounts can receive and use links; unknown/inactive addresses, the broker's 60-second resend throttle, and delivery failures share the generic request response. Links expire after 60 minutes and are single use. Passwords need at least 12 characters and at most 72 UTF-8 bytes. Reset invalidates existing sessions on their next request and does not log the user in. Invalid links return a generic `422` error; request-limit failures return `429`. See [ACCOUNT_RECOVERY](ACCOUNT_RECOVERY.md) for mail setup and the complete contract.

## Content studio

All `/studio/*` endpoints require an active authenticated account. Viewers can read content. Owners/admins/editors can mutate pages/media. Owner/admin can manage site navigation/themes/settings and delete unused media. Only owners can manage users/plugins or inspect health. PageWriter enforces policy even when called outside a controller.

| Method/path under `/studio` | Contract |
|---|---|
| GET `/dashboard` | Counts, five recent pages, eight recent audit events |
| GET `/health` | Live probes and warning/error details |
| GET `/content-schema` | Native and active plugin block/layout descriptors |
| GET `/pages?status=draft&page=1` | Paginated index, 30 per page |
| POST `/pages` | `{title}` → new starter page and revision, version 1 |
| GET `/pages/{id}` | Editable `{page}` |
| PUT `/pages/{id}` | Versioned complete editable fields, example below |
| DELETE `/pages/{id}` | `{version}`; permanently removes page/revisions |
| POST `/pages/{id}/publish` | `{version}`; validated saved draft becomes public snapshot |
| POST `/pages/{id}/unpublish` | `{version}`; removes public snapshot |
| POST `/pages/{id}/review` | `{version}`; review state without removing a live snapshot |
| POST `/pages/{id}/archive` | `{version}`; removes public snapshot and archives |
| POST `/pages/{id}/schedule` | `{version,scheduled_at}` future ISO date; scheduler publishes saved content |
| POST `/pages/{id}/preview-link` | Signed saved-version JSON URL and expiry |
| GET `/pages/{id}/revisions` | Latest 50 revision summaries |
| GET `/pages/{id}/revisions/{version}` | Revision snapshot, current fields, changed field names |
| POST `/pages/{id}/restore` | `{version,revision_version}`; new draft revision, publication unchanged |
| GET `/media?q=...` | Up to 100 media records with URLs, dimensions and thumbnails |
| POST `/media` | Multipart `file`, optional `alt`; supported raster image up to 5MB |
| DELETE `/media/{id}` | Refuses referenced media; authorized owner/admin |
| GET/PUT `/navigation` | GET `{navigation}`; PUT `{items:[{label,url,visible}]}`, max20 root-relative links |
| GET/PUT `/settings` | GET `{brand}`; PUT `{name,tagline}` |
| GET/PUT `/themes` | GET manifests/current selection; PUT `{id,settings:{accent,font,width}}` |
| GET/POST `/users` | GET `{users}`; POST `{name,email,password,role}` (12+ character password) |
| PATCH `/users/{id}` | `{role,is_active}`; protects the last active owner |

Example page save (use the current version and stable unique section/block IDs):

```json
{
  "version": 1,
  "title": "About",
  "slug": "about",
  "summary": "About our work",
  "show_in_search": true,
  "document": {
    "sections": [{
      "id": "section-about",
      "layout": "stack",
      "settings": {"background": "plain", "width": "normal"},
      "blocks": [{"id": "heading-about", "type": "heading", "props": {"text": "About", "level": 2}}]
    }]
  },
  "extensions": {}
}
```

Legacy `seo_title` and `seo_description` remain accepted. Omitted legacy values are preserved. Active plugins validate their own namespaced `extensions`; inactive values can be retained unchanged but cannot be edited. Empty extension maps may serialize as `[]` in PHP responses; the editor normalizes them to an object.

## Plugin endpoints

| Method/path | Contract |
|---|---|
| GET `/studio/extensions` | Active page fields, settings/admin descriptors and IDs |
| GET `/studio/plugins` | Discovered manifests, installation statuses, compatibility/issues |
| GET `/studio/plugin-catalog` | Local or configured remote metadata; never executable downloads |
| POST `/studio/plugins/packages` | Multipart signed ZIP: `file`, `publisher`, `signature` (base64), `sha256` |
| POST `/studio/plugins/{vendor}/{name}/{action}` | install, activate, deactivate, upgrade, uninstall; owner only |
| GET/PUT `/studio/plugins/{vendor}/{name}/settings` | GET fields/settings; PUT `{settings:{...}}`; declared API permissions enforced |
| GET/POST/PUT/PATCH/DELETE `/plugins/{vendor}/{name}/{path}` | Generic active-plugin dispatcher; registered method/path and gate required |

Uninstall preserves data unless `{purge:true}` is explicitly supplied. Upgrade requires deactivation and compatible dependencies. Public plugin routes are exact paths registered with the SDK, outside the API prefix. SEO registers `/sitemap.xml`, `/sitemap-pages.xml?page=1` and redirect callbacks; its authenticated route is GET/PUT `/plugins/fastfind/seo/redirects`, with PUT `{redirects:[{source,destination,status}]}`.

See [PLUGIN_DEVELOPMENT](PLUGIN_DEVELOPMENT.md) for capability enforcement, archive trust, migration/lifecycle behavior and sample code. The API is session-based v1; supported extension interfaces and compatibility constraints are documented in the SDK.
