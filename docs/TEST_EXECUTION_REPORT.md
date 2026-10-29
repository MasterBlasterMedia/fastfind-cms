# Test execution report

## Dependency follow-up — 29 October 2026

The [first recovery CI run](https://github.com/MasterBlasterMedia/fastfind-cms/actions/runs/40862897885) at `9249958` passed frontend tests/build/browser checks and Docker. The backend audit blocked the run on the revised CVE-2026-45070 advisory before its PHP tests ran. Updated only `symfony/mime` from 7.4.19 to the patched 7.4.22, retaining PHP 8.3 compatibility and existing dependency constraints.

After the update, local Composer strict validation and the locked advisory audit passed with no advisories. All **143 PHP tests (680 assertions)** and **13 browser tests** passed again with zero skips and zero browser retries. Vue sources and built assets were unchanged from the successful 64-test run below. Check Actions for the follow-up commit's remote result; the first failed run remains historical evidence.

The [next CI run](https://github.com/MasterBlasterMedia/fastfind-cms/actions/runs/40863473475) passed the security audit, frontend/browser checks and Docker, then exposed a missing Vite manifest in the PHP-only job. The recovery HTTP-header test now uses Laravel's `withoutVite()` test helper; its status and security-header assertions remain intact. Re-ran all 143 PHP tests locally with the actual Vite manifest temporarily removed and restored it afterward: 680 assertions, zero failures or skips. The real asset build remains covered by the browser job.

## Account recovery verification — 28 October 2026

The password recovery update passed **220 tests** on `feature/fastfind-platform`: **143 Laravel tests (680 assertions), 64 Vue tests, and 13 real-browser tests**, with zero failures or skips. The original `fastfind` checkout remains clean on `main` at `b23de67`. No production deployment or external email delivery was performed.

- Laravel coverage adds active/inactive/unknown account behavior, trusted reset origins, hashed tokens, expiry and reuse rejection, password validation, rate limits, mail-failure token rollback, audit records, and session revocation. Existing permission tests now start a fresh session when switching test identities.
- Twelve recovery component/integration cases include delayed responses during navigation, preserving a newly opened reset link, and clearing authenticated application state after the form unmounts. Focused builder coverage remains 89.15% statements, 73.68% branches, 88.63% functions, and 97.03% lines; recovery code is tested but is outside that coverage include list.
- The browser flow reads the real local log-mail notification, resets once, rejects the old password and reused token, signs in with the new password, and verifies two previous browser sessions return HTTP 401. It checks recovery screens with axe and at 375px; the full suite now performs 12 selected-rule axe scans with zero violations. Desktop/mobile recovery screenshots were visually inspected.
- The full browser run also passed the uploaded-image decoding/executable rejection regression, publishing, plugin/SEO lifecycle, themes, permissions, and catalog rendering at 375/768/1280/1920px. It used one worker and zero retries.
- The production asset build passed (106 modules). Git whitespace checks passed. No dependency versions or database schema changed.

Executed commands: `scripts/php.ps1 artisan test --compact --log-junit storage/framework/testing/recovery-backend.xml`, `npm.cmd run test:coverage`, `npm.cmd run build`, and `npm.cmd run test:e2e` with `PW_CHANNEL=chrome`. Reports, disposable fixtures and private mail logs are ignored by Git. Recovery checks fixed stale-response handling and low-contrast authentication text; test setup corrections preserved session validation and real email rendering.

Remote CI for the preceding media fix passed on PHP 8.3/8.4, frontend and Docker jobs at [`002e1af`](https://github.com/MasterBlasterMedia/fastfind-cms/actions/runs/39871303441). That run does not validate this later recovery update; use the Actions run for the recovery commit for its remote result. Real SMTP, production database concurrency and deployment-specific verification remain outstanding; see [ACCOUNT_RECOVERY](ACCOUNT_RECOVERY.md).

## Historical snapshot — 22 October 2026

The remaining sections preserve the earlier verification record and its then-current implementation limits. The 28 October results and current [KNOWN_LIMITATIONS](KNOWN_LIMITATIONS.md) supersede those limits where explicitly updated above.

Verified checkout: `feature/fastfind-platform`, in `fastfind-platform`. The original `fastfind` checkout remains on `main` at `b23de67a21863f23a5c5a551644658d1be5ac4c2`. Its tracked working tree was clean when this report was prepared. Nothing was deployed publicly.

## Final executed totals

| Suite | Passed | Failed | Skipped | Evidence |
|---|---:|---:|---:|---|
| Laravel feature suite | 132 | 0 | 0 | 570 assertions; `storage/framework/testing/final-backend.xml` |
| Vue/Vitest suite | 52 | 0 | 0 | 3 test files; focused coverage in `coverage/` |
| Playwright real-browser suite | 12 | 0 | 0 | `playwright-report/index.html`; `test-results/.last-run.json` |

These are the independent suite totals: **196 tests passed**. The category table below overlaps those suites and must not be added to them. A count of layout/block types describes the registry entries exercised, not a claim that every possible editor interaction was repeated for each type.

## Required category results

| Test category | Executed | Passed | Failed | Blocked |
|---|---|---|---|---|
| Laravel unit tests | 0 dedicated tests | N/A | 0 | No separate unit suite implemented; service validation runs in Feature |
| Laravel feature tests | 132 tests | 132 | 0 | 0 |
| Vue component tests | 52 Vue tests, including 28 component cases | 52 | 0 | 0 |
| Page layout tests | 16 registered layouts: schema bounds, persistence and browser rendering | All 16 | 0 | 0 within this scope |
| Content block tests | 22 registered blocks: schema validation and Vue/browser rendering | All 22 | 0 | 0 within this scope |
| Drag-and-drop tests | 1 browser scenario plus builder operation tests | Passed | 0 | 0 within this scope |
| Responsive browser tests | 4 widths: 375, 768, 1280, 1920px | 4 | 0 | Other browsers/devices not executed |
| Publishing workflow tests | PHP workflow cases plus 2 save/publish/concurrency browser scenarios | Passed | 0 | 0 within this scope |
| Plugin lifecycle tests | 15 PHP lifecycle/robustness tests, 3 Vue lifecycle tests, 1 SEO browser scenario | 19 | 0 | Long-running HTTP runtimes unsupported |
| Plugin security tests | 14 signed-package tests and 9 redirect safety cases | 23 | 0 | Independent penetration test not performed |
| Theme tests | 1 PHP isolation/validation test and 1 browser theme/navigation scenario | 2 | 0 | 0 within this scope |
| Accessibility tests | 10 axe scans, plus component/browser keyboard assertions | 10 scans with zero selected-rule violations | 0 | Manual screen-reader and full WCAG 2.2 review not performed |
| Security checks | Composer and npm advisory audits | Both clear | 0 | External security assessment not performed |
| Production build | 1 final Vite production build | Passed | 0 | Production deployment not performed |

The drag scenario uses real browser dragging for sections and blocks, keyboard movement controls, cross-section movement, compatibility restrictions, duplication, undo/redo, saving and reload. Builder tests separately reject incompatible/full destinations and verify independent duplicates. This is not the full interaction-by-layout Cartesian matrix requested for an eventual production release.

The four responsive scenarios each render the complete seeded catalog in both the draft preview and public renderer. They assert no document-width overflow, four-column layouts resolving to 1/2/4/4 columns respectively, keyboard tab selection and carousel advancement. Screenshots are retained for inspection. They are **not** approved golden-image baselines or pixel-difference regression tests.

The accessibility checks cover both editor color modes and the two catalog surfaces at four widths. Axe uses `wcag2a`, `wcag2aa`, and `wcag21aa` tags. Zero findings in those states does not establish complete accessibility conformance.

## Backend breakdown

| Test class | Tests | Assertions |
|---|---:|---:|
| BackupAndHealthTest | 7 | 35 |
| BuilderDemoTest | 1 | 41 |
| ContentWorkflowTest | 5 | 18 |
| DocumentRegistryTest | 58 | 124 |
| PlatformContentTest | 23 | 143 |
| PluginLifecycleTest | 8 | 98 |
| PluginPackageSecurityTest | 14 | 37 |
| PluginRobustnessTest | 7 | 17 |
| SeoRedirectSafetyTest | 9 | 57 |
| **Total** | **132** | **570** |

The suite verifies publication snapshot isolation, stale-write rejection, restore conflicts, scheduling and inactive-author rejection, signed preview expiry/version binding, role enforcement, image decoding and usage protection, theme isolation, published-only search, backup integrity and disposable restoration. Plugin tests exercise an unrelated SDK fixture as well as FastFind SEO, signed ZIP rejection before entry execution, dependency failures, upgrade behavior and deactivation cleanup.

## Frontend and browser evidence

Vitest passed 21 builder cases, 28 component cases and 3 client extension lifecycle cases. Focused coverage is 89.15% statements (189/212), 73.68% branches (154/209), 88.63% functions (78/88), and 97.03% lines (131/135). The coverage include list is limited to `resources/js/builder` and the Block, SectionLayout and SchemaFields components; this is not whole-application coverage.

The 12 browser tests cover:

1. Save/reload, publish, draft isolation, history dialog focus, restoration and unpublish.
2. Edits during a delayed save, failed-save recovery and stale-write conflict preservation.
3. Section/block drag, keyboard moves, cross-section movement, duplication and persisted ordering.
4. SEO install/activate, generic page fields, server/client metadata, settings, redirects, sitemap and deactivation with the CMS still operational.
5. Theme application, navigation persistence and owner health display.
6. Viewer read-only behavior and server-side permission rejection.
7. A real raster upload rendered in the browser and rejection of executable content.
8. Editor accessibility in light and dark modes.
9. Complete catalog preview/public rendering at 375px.
10. Complete catalog preview/public rendering at 768px.
11. Complete catalog preview/public rendering at 1280px.
12. Complete catalog preview/public rendering at 1920px.

Playwright used one worker, zero retries and a real isolated Laravel server. Each run has its own database, storage, sessions, caches and logs under `storage/framework/testing/run-<UUID>`. Those runtime files and the generated reports are intentionally ignored by Git; the committed suites reproduce them. Representative screenshots were also visually inspected.

## Other executed checks

- All three core migrations applied, rolled back and applied again on a fresh disposable SQLite database.
- PHP syntax checks passed for 80 PHP source/test/script files.
- Composer strict validation, installed platform requirements and the locked dependency audit passed.
- npm audit reported zero vulnerabilities after upgrading the affected test dependencies.
- Vite built 105 modules. Main JavaScript: 182.71kB / 68.45kB gzip; editor chunk: 22.87kB / 7.61kB gzip; CSS: 46.69kB / 10.72kB gzip. Bundle size is not a latency benchmark.
- Git whitespace checks passed. Runtime databases, credentials, dependencies, build output and test reports are excluded from the source change.

## Environment and exact commands

Executed on Windows with PHP 8.4.26, Composer 2.10.3, Node 24.15.0, Playwright 1.64.1 and installed Google Chrome 155.0.8059.85. Dependencies are pinned by the committed lockfiles. Laravel is 13.38.0 and PHPUnit is 12.5.40. The lockfile targets PHP 8.3 compatibility; a separate local PHP 8.3 run was not executed.

From `C:\Projects\fastfind-independent-cms\fastfind-platform`, after [installation](INSTALLATION.md):

```powershell
.\scripts\composer.ps1 validate --strict
.\scripts\composer.ps1 check-platform-reqs
.\scripts\composer.ps1 audit --locked
New-Item -ItemType Directory -Force storage/framework/testing | Out-Null
.\scripts\php.ps1 artisan test --compact --log-junit storage/framework/testing/final-backend.xml
.\scripts\php.ps1 scripts/verify-migrations.php
npm.cmd ci
npm.cmd audit --audit-level=moderate
npm.cmd run test:coverage
npm.cmd run build
$env:PW_CHANNEL = 'chrome'
npm.cmd run test:e2e
```

Use installed Chrome only when it is available. Alternatively, run `npx.cmd playwright install chromium`, remove the `PW_CHANNEL` environment override, and run `npm.cmd run test:e2e`. Standard PHP/Composer commands and CI setup are documented in [TESTING](TESTING.md).

## Failures investigated and corrected

The untouched baseline had 3 failed and 2 passed tests; page creation attempted a revision before database defaults were reflected in memory. See [BASELINE_TEST_REPORT](BASELINE_TEST_REPORT.md). The feature branch fixes that defect.

Integration testing also exposed a stale post-login CSRF token, late-save responses replacing newer local edits, empty extension arrays breaking generic fields, legacy metadata loss, plugin client registration cleanup, redirect cycle normalization, low-contrast text, and a desktop container breakpoint that prevented four-column output at the normal content width. These received implementation fixes and regression coverage. Test isolation was corrected for Windows relative cache paths, and browser locators were scoped to the intended controls.

Sandboxed dependency network access, PHP writable-directory checks and Vitest temporary renames initially failed on this host. The affected checks subsequently ran with approved process/network access. Assertions were not disabled to turn these environment failures into passes; the final suites contain no skipped tests.

## Remaining verification and implementation

Docker builds, remote GitHub Actions, real PostgreSQL/MySQL services, external catalog integration, load/concurrency benchmarks, an independent penetration test, manual accessibility review and production/offsite recovery were not executed here. CI configuration is supplied but is not evidence of a remote successful run. Screenshot capture has no approved visual baseline comparison yet.

Custom content types/taxonomies, nested/reusable blocks, rich-text editing, account recovery/MFA and hosted marketplace services are not complete. See [KNOWN_LIMITATIONS](KNOWN_LIMITATIONS.md) for the implemented boundary and [SECURITY_PERFORMANCE_REPORT](SECURITY_PERFORMANCE_REPORT.md) for security/performance scope. This report does not declare the platform production-ready.
