# Baseline feature audit

Audited 20 October 2026 before platform changes. The existing Laravel 13 / Vue 3 application provides session login/logout, owner/admin/editor/viewer roles, page CRUD, a block editor, revision restoration, publish/unpublish, navigation, raster image uploads, brand settings, user management, and public search.

Publishing stores a separate public snapshot, slug and search text. Draft edits preserve the last publication; optimistic version checks and database transactions protect saves and publication. These are foundations to retain. Search uses a bounded parameterized LIKE query. Web routes apply Laravel session and CSRF middleware. Passwords use Laravel's hashed cast; public output uses Vue and Blade escaping.

The original five backend tests cover draft isolation, editor restrictions, duplicate IDs/unsafe links, conflicting saves, and anonymous API access. They do not establish that all other features are correct. Baseline runtime results and subsequent verification are recorded in the test report.

The original builder has seven section layouts and ten scalar-property block types. The administration is a single component, the public renderer uses hard-coded branches, and the backend uses one large studio controller. SEO fields are core columns with a small server-side title/description bridge. There are no plugin or theme extension contracts in the baseline.

Retain: existing URLs, data, publication isolation, revision history, responsive visual identity, and secure session authentication. Refactor: content definitions, validation, authorization boundaries, and extension metadata. Complete: plugin lifecycle, public SDK, SEO integration, theme independence and meaningful automated verification.
