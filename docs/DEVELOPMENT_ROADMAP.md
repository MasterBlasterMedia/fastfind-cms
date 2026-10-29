# Development roadmap and implementation boundaries

The supplied master brief describes a multi-release platform. Work prioritizes secure, functional, verified features over interface placeholders.

1. Audit and stabilize: preserve baseline, install isolated runtimes, execute baseline, fix CSRF and save races, establish lockfiles and automated checks.
2. CMS foundation and builder: authoritative catalog, strict validation, revisions/publishing and conflict protection, accessible editing and registry-derived tests.
3. Plugin ecosystem: public contracts, reviewed package installer, lifecycle, settings and route authorization, extension fields, admin UI, developer examples.
4. Reference plugin: FastFind SEO implemented exclusively through those contracts, including published metadata, sitemap, redirects and structured data.
5. Themes and operations: independent theme tokens, health checks and reproducible deployment tooling as verification time permits.
6. Account recovery: email reset requests and forms, expiring single-use tokens, trusted reset URLs, rate limits and session invalidation are implemented. SMTP delivery still needs verification in the intended deployment.
7. Further production work: mandatory multi-person approvals, richer content types/taxonomies, email verification/MFA, PostgreSQL search, remote marketplace infrastructure, offsite production recovery drills, deployment hardening and broad accessibility/performance measurement. Scheduled publication, bounded raster transformations and disposable SQLite restoration are now implemented; see the current changelog and test report.

The execution report and feature gaps document distinguish implemented work from this roadmap. No production-readiness claim is implied.
