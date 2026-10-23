# Verified deployment — 2026-10-24 (Asia/Damascus)

- Application: `https://mandobe.net/liqaa`.
- Target: `/home/mand/liqaa_app`; the separate application is untouched.
- Application release: `782f2cf2385c0df7f758234b3e6b282045d8ae65`.
- Later Git commits add deployment tooling and documentation only; the tested
  application source is unchanged.
- Source archive downloaded by exact commit into the private staging directory
  `/home/mand/liqaa-release-782f2cf`.
- Verified all 236 application/configuration/route/migration files against SHA256
  hashes of canonical Git blobs. Replaced 46 changed files atomically.
- Production PHP is 8.2.35. Installed `composer.lock` matches the tested release;
  no dependency installation was required.

## Backup and migration

Backup: `/home/mand/liqaa-backup-20261023-233151-782f2cf` (timestamp is UTC).
The private directory contains `code.tar.gz`, a complete `database.sql.gz`,
the changed-file backup, and the deployment manifest. Gzip integrity and tar
listing validation succeeded. Environment settings and user uploads were preserved.

Applied the missing October 16 device-constraint compatibility marker and legacy
session-slot conversion. All migrations are marked Ran, including the October 17
constraint marker already installed from the other release line. The 25 legacy
empty slots were archived/converted by the tested migration. Existing individual
bookings with enrollments are outside that conversion.

Cleared Laravel caches, rebuilt route cache, and brought the application live.
Deployment exit status: 0. Every installed source hash matched the release.

## Validation

- Before deployment: 142 local tests, 2401 assertions passed on the unchanged
  application release, including both merged regression suites.
- Live HTTP smoke checks: 98 passed, comprising 87 authenticated routes tested
  without credentials (401) and 11 public/health/curriculum reads (200).
- Production policy discovery verified with unsaved synthetic account objects:
  active teacher can `createOwn` a teacher subject; `pending_review` cannot.
- Smoke process exit status: 0, `SMOKE_OK`.
- No test accounts, access tokens, purchases, payments, or bookings were created
  for the production checks. Authenticated write workflows remain covered by
  the isolated test suite, rather than live writes to customer accounts.

Deployment and smoke logs remain in the private staging directory. The release
marker is `/home/mand/liqaa_app/storage/app/deployed-release.json`.
