# Baseline gaps and acceptance policy

This file records findings from the initial complete source audit; the final changelog/test report identifies which gaps were addressed.

The baseline has no plugin discovery, lifecycle, package trust verification, marketplace client, public SDK, per-plugin settings, theme framework, structured custom collections, taxonomy, reusable global content, scheduled publication, secure preview links, workflow review/approval, rich text schema, backup/restore workflow, operational health panel, frontend unit tests, browser tests, or CI workflow.

Authentication lacks password reset UI, verification and multi-factor authentication. Permissions are three hard-coded Gates; content ownership is not considered. Media lacks decoding, resizing, dimensions, usage protection, folders and search. Search lacks relevance ranking, pagination, PostgreSQL full-text indexing and analytics. Operational database tables are absent for configurable database queue/cache/session drivers.

Builder validation covers shape, IDs, counts and generic scalar values, but not per-block required fields, permitted property names, semantic field types, nested content, layout-specific restrictions or registered extensions. The editor cannot safely validate every requested layout interaction because those layouts are not implemented yet.

Known correctness defects include a stale CSRF token after successful login, revision restore bypassing slug uniqueness validation, destructive deletion without a version check, an unhandled slug-creation race, and concurrent owner demotions. URL validation accepts backslashes that browsers normalize into different destinations. Upload persistence does not clean up on a database failure.

Do not infer completion from interface controls or documentation. A feature is verified only when its implementation and relevant tests execute successfully. A production-ready claim additionally requires the acceptance checks in the supplied development brief, including restore verification and a production database run.
