# Reconcile the two main histories — 2026-10-24

`origin/main` was rewritten from `5ed8f20` to `0502d9d`, both descending from
`425fbd4`. The merge retains both histories and their regression suites.
`backup-local-before-sync-20261024` preserves the previous local main, and
`origin/backup-main-before-overwrite-20261024` preserves the previous published main.

## Integration choices

- Keep teacher subject CRUD and ownership policies. Add the new active subject
  catalog, subscription history, dashboard and usage endpoints. Register only
  one POST handler for teacher subjects. Use upstream's stricter rejection of
  client-supplied teacher identity/status on creation and reject inactive subjects.
- Keep both student booking entry points. They share availability containment,
  enrollment validity and conflict checks. The legacy booking endpoint retains
  its 422 conflict response; the new availability booking endpoint uses 409.
  Teacher session conflicts use 409. Student access to another student's booking
  consistently returns 404. Teacher session conflict checks normalize offsets.
- Keep device-bound authentication and transfer locks. Both device service
  entry points delegate activation to the same implementation, including token
  and refresh revocation. Transfer decisions support explicit source binding
  and source-binding extraction from a persisted access token.
- Keep existing completion records and APIs. Add quiz submission, quiz/group
  read APIs, calendar, teacher directory/featured lists and shared avatars.
  Refresh the authenticated user after avatar writes so `/me` reflects them.
- Keep the existing session model invariant and legacy slot archival migration.
  Update the new dashboard fixture to use a real enrollment rather than an
  invalid empty individual session.
- Retain enrollment morph aliases and normalize legacy supported class names.
  Unsupported model types are still rejected.
- Deduplicate policy methods introduced by Git's textual auto-merge. Preserve
  active-user/profile checks, quiz publication checks and group membership rules.

## Database compatibility

The new avatar/progress migration checks for existing columns and tables, and
preserves profile image paths on rollback. Both active-device constraint
migration names are retained for installations with either history, but the
unique index is created once. Rolling back the later compatibility marker
does not remove the earlier migration's constraint.

`ReleaseMergeCompatibilityTest` exercises both constraint migration orders,
existing avatar data, and conflicts/cancellation across both booking APIs,
including equivalent timestamps with different timezone offsets. The combined
suite passed: **142 tests, 2401 assertions**. No deployed database is changed by this work.
