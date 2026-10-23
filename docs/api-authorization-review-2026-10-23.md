# API authorization review — 2026-10-23

Reviewed the currently registered API routes in `routes/api.php`,
`routes/teacher.php`, and `routes/student.php` against controller actions,
implicit model bindings, role middleware, and explicit policy abilities.

## Repairs

- Added authenticated teacher subject management at `/api/v1/teacher/subjects`.
  Dedicated `createOwn` and `viewOwnAny` abilities require an active user and
  active teacher profile. Record operations use the ownership-aware `manage`
  ability. Administrative CRUD permissions remain unchanged.
- Teacher identity comes from the authenticated account for both creation and
  updates; client-supplied `teacher_id` cannot select another owner.
- Public curriculum browse endpoints now check ancestor visibility. Suspended
  users, pending/suspended teachers, inactive catalog subjects/courses, and
  unpublished units/lessons cannot expose descendants through direct URLs.
- Teacher subject route names use `teacher.subjects.*` to keep them separate
  from potential administrative catalog routes.

## Verification

- Full local suite: 81 tests passed, 1832 assertions.
- `ApiRouteIntegrityTest` checks all registered API controller actions, public
  method visibility, model-binding parameter names, and unique route names.
- It also requests every authenticated API route as a guest, and every route
  with role middleware using each disallowed account role. For nonexistent
  bound records, 404 is accepted alongside 403 for the wrong-role checks.
- Teacher subject tests cover CRUD, ownership, identity spoofing, duplicate
  subjects, validation, profile/account states, and administrator activation.
- Curriculum tests cover direct descendant URLs after each ancestor is hidden.
- Existing tests exercise student purchases, payments, bookings, quizzes,
  attendance, device sessions/transfers, protected media, and teacher workflows.

No additional generic-administrator policy was found incorrectly wired to a
currently registered teacher or student workflow. Legacy session cancellation
and booking URLs are retained for compatibility. This review and local test
suite do not verify the deployed server's code, route cache, data, or environment.
