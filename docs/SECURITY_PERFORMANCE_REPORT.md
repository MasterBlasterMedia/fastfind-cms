# Security and performance review

Review date: 2026-10-22. This report distinguishes implemented defenses and executed checks from unperformed deployment assurance. See TEST_EXECUTION_REPORT.md for final suite counts.

## Correctness/security findings addressed

| Finding | Implemented treatment |
|---|---|
| Missing in-memory page defaults broke first revision | Model defaults plus regression assertions |
| Login rotated CSRF token but left old client token | Session response supplies token; Axios updates meta; real browser writes succeed |
| Save response overwrote edits made during request | Snapshot comparison preserves newer local fields and adopts server version |
| Stale deletion/restore or slug collision could destroy/fail inconsistently | Locked version checks, policy, shared validation, unique constraint handling |
| Concurrent owner demotion risk | Transactional locked owner protection |
| Generic block scalars accepted invalid properties/URLs | Shared typed definitions, bounded validation and URL normalization rules |
| Raw/malformed raster and orphaned persistence risk | Decode, pixel bounds, re-encode, thumbnails and failed-upload cleanup |
| Plugin archive code execution/traversal risk | Trusted publisher signature, hash, bounded ZIP inspection, private staging, inactive install and digest checks |
| Provider boot order/failure could leave consumers registered | Dependency graph ordering, cycle detection, failed provider/consumer disablement |
| PHP class reloading could crash upgrade | Same-request instance reuse and changed-release guard; fresh request required |
| Inactive client blocks remained in palette/renderers | Namespaced reactive registration cleanup and reactivation tests |
| SEO redirect cycles hid behind query/dot/absolute URL forms | Canonical source requirements and normalized local target cycle checks |
| Mobile content had low contrast/unfocusable scroll area | Text contrast fixes and keyboard-focusable code block; automated browser checks |

Composer and npm dependency advisory audits were executed; the original Vitest advisory findings were resolved by upgrading to the patched compatible release and reinstalling from lock. Repeat audits before deployment because advisory databases change. No independent penetration test has been conducted. Publisher signatures do not sandbox trusted PHP, and current backups are plaintext private files.

## Performance properties and limits

The production build uses route-level Vue chunks. The measured build after frontend integration had a 182.71kB main JavaScript bundle (68.45kB gzip), 22.87kB editor chunk (7.61kB gzip), and 46.69kB CSS (10.72kB gzip). These are build-tool output sizes, not measured browser latency or Core Web Vitals. The final build log is authoritative if subsequent UI changes alter these values.

Public search is ranked/paginated and bound to published search text. It uses LIKE and has no full-text index. Registry/document limits bound individual pages; media pixel/size limits bound transformations. Uploaded images are resized to 2,400px with 320px thumbnails. Views load by route and a small native catalog drives both PHP and Vue behavior.

Known scaling costs: each PHP request boots/verifies active plugins; file digests and manifest/dependency queries are not cached across requests. Sitemap generation currently collects eligible published snapshots in memory before slicing. Media usage scans current page content, and publication assembles flattened search text. Lifecycle tasks run synchronously unless plugins explicitly queue their expensive work. These are acceptable local implementation tradeoffs, not evidence of large-site capacity.

No load test, database concurrency benchmark, production profiler run, Lighthouse/Core Web Vitals measurement or memory-under-load study was executed. Before scaling, measure real page/search/SEO workloads, introduce correctly invalidated verified-release caching, stream sitemap enumeration, use relational media references, implement a production search index and validate shared locks/storage. Do not add a cache that can mix draft/private responses with public snapshots.
