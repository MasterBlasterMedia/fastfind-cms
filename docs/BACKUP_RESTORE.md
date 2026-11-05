# SQLite backup and disposable restoration

The implemented backup workflow supports **SQLite plus local public media storage**. It does not claim PostgreSQL/MySQL/S3 backup support or an automated production restore. Server-administrator access is required to run the CLI. Backups contain private data and remain under `storage/app/private/backups`, outside both the web root and public-media root.

Installed theme/plugin release directories in private storage are not included. Preserve `storage/app/private/themes` and the configured plugin package directory separately, together with the application release, `APP_KEY`, publisher trust configuration and original signed packages. Restore those files with the matching database package metadata before validating a recovery deployment.

```sh
php artisan fastfind:backup
php artisan fastfind:backup-verify 20261020T220000Z-00000000-0000-4000-8000-000000000000
```

Use the actual identifier printed by the first command for verification. Each backup contains `database.sqlite`, copied media, a SHA-256/size manifest and a successful verification report. `VACUUM INTO` creates a consistent SQLite database snapshot. The command then restores the database and media into a disposable private directory, checks every checksum, SQLite integrity, foreign keys and table counts, and performs a write/read probe in the disposable database. It removes that disposable restore and leaves the original database untouched. A failed creation cleans up its incomplete backup; verification failures preserve the original backup for diagnosis.

Limits default to 20,000 media files and 2GB total size. Source and archive symlinks, unsafe manifest paths and arbitrary backup path arguments are rejected. A cache lock prevents simultaneous captures. Media can change independently of the database; put the application into maintenance mode and pause workers for a coordinated production capture, especially while uploads/deletions are active.

Backups preserve all existing verified snapshots by default. Pruning is explicit:

```sh
php artisan fastfind:backup --retain=7
```

This keeps the seven newest verified snapshots and deletes older verified backup directories. Failed/unrecognized directories are not considered for pruning. Choose retention based on available disk capacity and recovery requirements.

Set `FASTFIND_BACKUP_SCHEDULED=true` to enable the configured daily 02:00 application-timezone schedule, then run Laravel's scheduler (`php artisan schedule:work` locally or the deployment's scheduler service). Scheduled captures preserve all snapshots unless an operator explicitly invokes retention. Monitor disk usage; scheduled backups require a functioning scheduler.

## Recovery procedure

1. Preserve the damaged deployment and take a fresh copy of all relevant files before changing anything.
2. Run `fastfind:backup-verify` on the selected snapshot. Check the report and confirm its date and expected table/media counts.
3. Prepare a separate recovery deployment using the matching application release and trusted plugin code. Provision its environment, database path, storage permissions and original encryption key through the secret-management process.
4. Copy the verified `database.sqlite` and `media/` tree into that separate deployment's configured SQLite path and public disk. Do not overwrite the live deployment during validation.
5. Verify authentication, draft/public separation, page rendering, media, plugin activation and relevant scheduled operations in the recovery deployment. Take it live only through the deployment's controlled cutover procedure.

The CLI deliberately has no overwrite-live-database command. This protects existing user data. Full production cutover and restoration of external services remain operator procedures.

## Scope and key handling

Backups are plaintext in private storage. SHA-256 checksums detect accidental corruption; they are not publisher signatures or protection against an attacker who can rewrite both files and manifest. Use operating-system access controls and an encrypted backup destination/offsite storage under your control. Protect transport and document offsite retention independently.

The backup does not include `.env`, APP_KEY, deployment code, trusted plugin package files, external service data or S3 objects. Preserve the application key and other required secrets in a separate protected recovery process. Losing an encryption key can make encrypted application data unusable even when the database backup is intact. Version-controlled application deployments and verified plugin distribution provide the code portion of recovery.

`BackupAndHealthTest` exercises a real SQLite snapshot, disposable database/media restoration, database write verification, source isolation, corruption rejection, path traversal rejection, limits and retention. Refer to the final test report for whether these tests actually ran and passed in the current environment. No PostgreSQL production restoration has been tested by this workflow.
