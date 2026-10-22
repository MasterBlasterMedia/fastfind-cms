# Content, media and theme API behavior

All studio endpoints require an active authenticated session and Laravel CSRF protection. Content mutations require an owner, administrator or editor; theme/navigation settings require an owner or administrator; account changes require an owner. PagePolicy also protects PageWriter calls outside HTTP controllers.

## Versioned publication

New pages start at version 1 with a matching revision. Updates, publish/unpublish, archive, review, scheduling and restoration increment the version. DELETE requires `{ "version": currentVersion }` and rejects stale deletions. Saves and restoration validate unique slugs inside the write transaction; database uniqueness races become validation errors rather than unhandled SQL responses.

`POST /api/v1/studio/pages/{id}/schedule` takes `version` and a future ISO-8601 `scheduled_at`. The scheduler runs `php artisan fastfind:publish-scheduled` every minute. It publishes through the same schema, authorization, extension and snapshot service as manual publication. A deactivated or demoted editor cannot publish via a stale schedule. Failed publications remain scheduled for operator correction and are logged. Production must run Laravel's scheduler; a saved timestamp does not itself start a worker.

`POST .../review` records an unpublished page as `in_review`; `POST .../archive` removes its public snapshot and marks it archived. These are workflow states, not a mandatory separate-person editorial approval system. Review of already published content preserves the current live publication. Scheduling an already published page also preserves its current live snapshot until the due publication. Saving a scheduled draft updates the content that will be published; cancel with unpublish.

`GET .../revisions/{version}` returns the historical snapshot, current editable values and changed field names. Restore creates a new revision and does not change a live snapshot until publication. Generic `extensions` data is validated through the plugin SDK and frozen into revisions and published snapshots. Disabling a plugin retains its existing data.

PageChanged events run after successful commit, with the Page model and action (`created`, `saved`, `restored`, `publish`, `unpublish`, `review`, `archive`, `scheduled`, `deleted`). They are generic application events available to official and independent plugins alike.

## Preview access

`POST .../preview-link` returns a signed URL to `GET /api/v1/preview/{id}` that expires after 30 minutes. This is a JSON preview API intended for clients; the editor also has its own visual preview. The link grants access to one saved version. A subsequent save invalidates it with HTTP 410. Responses forbid caching and carry `X-Robots-Tag: noindex, nofollow`. Treat the URL as a temporary access credential.

## Shared content registry

`resources/content/registry.json` is the shared core catalog. `GET /api/v1/studio/content-schema` adds active plugin registrations. Each block defines named fields, scalar types, length/range/enum constraints and defaults. Collection fields use bounded newline records; embedded URLs are validated independently. Only approved HTTPS YouTube/Vimeo video patterns are accepted. Raw HTML is not a content field type.

Section settings support background, width, spacing and alignment. Layouts declare block limits and optional allowed block lists. The server enforces those constraints, unique section/block IDs, 40 sections, 300 total blocks and 16KB per block. Independent plugin blocks must register a server validator and may not override existing types. Unknown types fail validation; a disabled block provider must be re-enabled or its blocks removed before that document can be saved. Public rendering handles unknown blocks without executing arbitrary markup.

## Media

Uploads accept JPEG, PNG, GIF and WebP up to 5MB, 12 megapixels and 6,000 pixels per side. PHP GD must decode the content. The library applies available EXIF orientation, re-encodes to WebP when supported (PNG fallback), strips source metadata, limits output to 2,400 pixels and makes a 320-pixel thumbnail. Animated GIFs become a static first-frame image. Source originals are not retained. Responses include width, height, thumbnail URL, MIME and encoded size.

Deleting a file checks both drafts and current publications, including extension data, and refuses removal while referenced. Historical revisions are not a permanent file-retention system: restoring an old revision after its unreferenced media has been deleted may require replacing that media. Current usage checks scan pages; a relational usage index is needed at large scale. Failed upload persistence removes newly written files. Storage must be configured and writable.

## Themes

Reviewed theme manifests live under `resources/themes/{id}/theme.json`. Professional and Minimalist are bundled. `GET /api/v1/studio/themes` lists them; `PUT /api/v1/studio/themes` selects `{ "id": "minimalist", "settings": { "accent": "#33564b", "font": "serif", "width": "normal" } }`. Settings accept hex colors and bounded font/width tokens only. Public site responses include the selection. Theme switching never rewrites pages or their snapshots. These themes are safe data manifests interpreted by the public Vue renderer; uploading executable theme packages is not implemented.
