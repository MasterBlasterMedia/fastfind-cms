# Test plan

1. Preserve and run the imported source in a disposable baseline copy. Record dependency resolution, migration, backend tests and frontend build results.
2. Run isolated SQLite feature tests for authentication, role restrictions, content schema, save/publish/restore, URL validation and stale versions.
3. Discover every block and layout from the shared catalog and test valid defaults, constraints, persistence and rendering. Add frontend document-operation tests for move, duplicate, undo and save races.
4. Exercise plugin registration using both FastFind SEO and an unrelated test plugin. Verify installation, compatibility, dependencies, activation, settings, routes, page metadata, inactive behavior, upgrade and uninstall.
5. Reject bad signatures, untrusted publishers, altered files, path traversal, symlinks, collisions, oversized archives and unauthorized lifecycle requests before loading PHP.
6. Build production assets and open the actual application in Chromium. Test login, edit, reload, publish, private draft changes, failed saves and representative drag/keyboard operations. Check 375, 768, 1280 and 1920 pixel widths and accessibility.
7. Run dependency audits, PHP lint and available static analysis. Report unavailable PostgreSQL, Docker, coverage or browser capabilities as blocked, never passed.

Use disposable test databases; never reset an existing development or production database. Final execution evidence belongs in `TEST_EXECUTION_REPORT.md`.
