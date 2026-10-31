> تحديث 2026-10-31: هذا وصف مرحلة سابقة. العقد النهائي الحالي هو [FRONTEND_CONTRACT_FINAL_AR.md](FRONTEND_CONTRACT_FINAL_AR.md)، والفهرس الكامل [APIS_FULL_AR.md](APIS_FULL_AR.md).

# Teaching sessions independent of online enrollments

## Data model

- `individuals`: `id`, `student_id`, `teacher_subject_id`, `status` (`active`, `completed`, `cancelled`), optional `starts_at`, `ends_at`, `notes`, and timestamps. One record describes a student studying a teacher subject and can have many sessions. Teacher is derived from the teacher subject.
- `group_students`: direct membership with `group_id`, `student_id`, `status` (`active`, `left`, `removed`), `joined_at`, `left_at`. Unique `(group_id, student_id)`; rejoining reactivates the record. No enrollment relationship.
- `sessions`: polymorphic `type` (`individual` or `group`) and `type_id`; target relationship uses explicit morph aliases. `teacher_id`, `availability_id`, times, capacity and status remain. `group_id` and `enrollment_id` are removed. Individual capacity is always one.
- `attendance`: `student_id` replaces `enrollment_id`; unique `(session_id, student_id)`. Only actual participants can be marked by the owning teacher.
- `sessions.progress_notes` is optional free text (maximum 10,000 characters through API); `progress_updated_at` is assigned by the server when notes change. Notes can be cleared or updated on completed sessions. Cancelled sessions cannot record progress.
- `content_assets` and online content access remain unchanged. `enrollments` continues to represent online content purchases and the existing financial record for paid bookings, but sessions and attendance never refer to it.

## Teacher API

All routes below require an active teacher and only operate on their own records.

- `GET/POST /api/v1/teacher/individuals`: create with `student_id`, `teacher_subject_id`, optional dates and notes.
- `GET/PATCH /api/v1/teacher/individuals/{individual}`: update status, dates or notes. Student/subject cannot be reassigned. Pending sessions must be cancelled/rescheduled before changing status/dates to exclude them.
- `POST /api/v1/teacher/groups/{id}/students`: `student_id` (replaces the old enrollment membership route).
- `DELETE /api/v1/teacher/groups/{id}/students/{student}`: end membership and keep its record.
- `POST /api/v1/teacher/sessions`: `type`, `type_id`, `starts_at`, `ends_at`, optional `availability_id`, `capacity`, `progress_notes`. Teacher is derived from authentication.
- `PATCH /api/v1/teacher/sessions/{session}`: schedule updates; changing type requires type_id too. Sessions with attendance cannot change participants. Paid sessions use the paid rescheduling workflow.
- `PATCH /api/v1/teacher/sessions/{session}/progress`: `{ "progress_notes": "Page 32, exercise 4" }`. `null` clears notes. The ordinary session PATCH also accepts a notes-only update on completed/paid sessions.
- `POST /api/v1/teacher/sessions/{id}/attendance`: `{ "records": [{ "student_id": 12, "status": "present" }] }`.

## Student booking and payment

Availability booking endpoints take `individual_id` instead of `enrollment_id`. The individual must belong to the student and the same teacher and cover the requested interval. Availability/calendar responses now use `eligible_individual_ids` and `has_eligible_individual_in_period`. Online enrollment alone does not allow booking or group membership.

Student session lists return `type`, `type_id`, subject information and progress; they no longer expose an enrollment object. Group counts use `active_members_count`.

Paid lesson checkout additionally requires `teacher_subject_id` belonging to the availability's teacher. Approval creates an individual target and a session. Payment holds, refunds and rescheduling retain their financial records. The paid individual cannot be reused through ordinary free booking. Cancelling/refunding an online enrollment does not remove group memberships or unrelated sessions.

## Migration / deployment

1. Back up the database and deploy during a maintenance window (MySQL DDL is not transactional).
2. Run `php artisan migrate`. This also includes the preceding phone authentication migration.
3. The teaching migration resolves subjects for old booked sessions, creates individual targets, copies group memberships by student, and converts attendance before dropping old references and `group_enrollments`.
4. Missing/ambiguous legacy subjects, missing group targets or duplicate attendance stop migration rather than guessing or discarding records. Resolve them before retrying. For an ambiguous old session, add an explicit enrollment ID => teacher_subject ID entry to `config/teaching.php` under `legacy_enrollment_subjects` (clear/rebuild config cache before migration). Legacy paid bookings without a selected subject can only be approved automatically if the teacher has exactly one subject; otherwise assign the subject explicitly.
5. Ship clients with the new request/response contract. Rebuild route caches as part of deployment.

The migration is intentionally not automatically reversible: new teaching records cannot be converted back to online enrollments without inventing purchases. Rollback requires the pre-migration database backup.
