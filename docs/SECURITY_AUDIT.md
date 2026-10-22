# Security audit

Audited 20 October 2026. This is source review, not a claim of penetration-test completion. Dependency audit and executed test results belong in the final verification report.

## Existing defenses

Laravel session authentication, login throttling, session regeneration, logout invalidation, web CSRF middleware, hashed passwords, route-level active-account checks, authorization Gates on mutations, bounded input, parameterized search queries, escaped public templates, a content security policy, non-executable raster-only upload types, private local storage, and separate published snapshots are present.

## Confirmed findings

| Finding | Impact | Required treatment |
|---|---|---|
| Login rotates the CSRF token but does not return it | Authenticated writes can fail after login | Return the current token with session responses and update the client |
| Slash/backslash URL forms pass validation | Browser normalization permits unintended external navigation | Shared URL validation rejects backslashes, control characters and protocol-relative forms |
| Restore bypasses input uniqueness validation | A historical slug now owned by another page yields a database error | Validate restored identity under the write transaction |
| Owner count and mutation are separate | Concurrent cross-demotions can remove all active owners | Lock owner/account rows within one transaction |
| Page deletion ignores expected version | A stale editor can delete newer work | Require and compare version while locking the page |
| Broad scalar block schema | Invalid types break renderers; unrecognized fields are stored | Registry-based field validation and layout restrictions |
| Image persistence lacks decoding and cleanup | Malformed images/orphaned files | Decode/verify image content and remove the new file if persistence fails |
| Media deletion does not check usage | Published pages can lose images | Inspect draft and published references before deletion |

## Operational requirements and boundaries

Secure cookies must be enabled on HTTPS deployments. Production debug must remain disabled. APP_KEY and credentials must remain outside Git. Database queue/cache/session drivers need their corresponding tables before selection. Account deactivation must deny further administrative requests, including plugin routes.

An executing third-party PHP plugin has the application's privileges. Manifest capabilities and application Gates control registration/API behavior; they are not a PHP sandbox. Installation must verify reviewed publisher signatures and package hashes, reject traversal/symlinks/unsafe archives, enforce limits and owner authorization, and stage outside public storage before executing any code. Do not install unverified executable code.

Password reset, verification, MFA, comprehensive role policy tests, production PostgreSQL testing, external vulnerability assessment, operational backup restoration and dependency advisory verification remain independent acceptance work unless marked executed in the final report.
