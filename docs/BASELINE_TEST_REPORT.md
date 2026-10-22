# Baseline verification — 20 October 2026

The original checkout at `b23de67a21863f23a5c5a551644658d1be5ac4c2` was archived with `git archive HEAD` into a disposable parent-workspace `.runtime/baseline` directory before implementation. Dependencies, environment setup and test databases were created only in that disposable copy. No application code in the baseline was fixed to make tests pass.

## Setup evidence

The baseline had no Composer or npm lockfile. Composer resolved Laravel 13.38.0 and PHPUnit 12.5.40 under PHP 8.4.26. Its first package-discovery script failed because `bootstrap/cache` was absent from the Git archive. Creating the required writable framework directories and copying `.env.example` to `.env` allowed the real tests to run. The feature checkout now tracks the empty-directory placeholders and dependency lockfiles.

## Results

| Check | Observed result |
| --- | --- |
| Laravel feature suite | 3 failed, 2 passed; 6 assertions |
| SQLite fresh migration | Passed; original `2026_10_20_000001_create_core` migration applied |
| Frontend dependency install | Completed in the isolated copy |
| Baseline production build | Generated `public/build/manifest.json` and assets in the isolated copy |

All three failing tests stopped at page creation: `page_revisions.version` violated its NOT NULL constraint. The newly inserted Page instance did not contain database-default values when `PageWriter::snapshot` ran. This prevented the suite from reaching its publication, builder-validation and stale-write assertions. The two passing tests checked restricted settings/user operations and anonymous studio access.

An initial run without `.env` also reported missing-environment-file warnings. Those setup warnings were removed by copying the example environment; the three page-creation failures remained. Baseline output was retained outside the repository at `.runtime/baseline-test-output.txt`.

## Feature-branch verification

The feature branch fixes baseline page defaults and expands the backend and frontend coverage. Its installation adds explicit cURL/GD/Sodium/Zip requirements, retains the PHP 8.3 compatibility floor, commits both dependency lockfiles and supplies a fresh/rollback/fresh SQLite check. See [TESTING.md](TESTING.md) for commands and the final execution report for the complete resulting test run. This baseline report records the original failures rather than rewriting them as successful tests after fixes.
