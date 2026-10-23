# Teacher subject management

All endpoints require a teacher Bearer token, `users.status = active`, and
`teachers.status = active`. A `pending_review` profile must first be activated by
an active company administrator using `PATCH /api/v1/teacher/{teacher}/activate`.
Teacher subjects are separate from the administrative subject catalog.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/api/v1/teacher/subjects` | Paginated list of the authenticated teacher's subjects |
| POST | `/api/v1/teacher/subjects` | Create a teacher subject (201) |
| GET | `/api/v1/teacher/subjects/{teacherSubject}` | Read an owned subject |
| PUT/PATCH | `/api/v1/teacher/subjects/{teacherSubject}` | Update an owned subject |
| DELETE | `/api/v1/teacher/subjects/{teacherSubject}` | Delete an owned subject |

Create example:

```json
{
  "subject_id": 1,
  "price": 25,
  "group_enabled": true,
  "max_group_size": 10
}
```

`subject_id` and `price` are required when creating. The subject must exist and
must not already be associated with this teacher. Price must be between 0 and
9999999999.99. `group_enabled` is optional (defaults to false).
`max_group_size` is optional/nullable, or an integer from 1 to 100000. It is saved
as null when groups are disabled. `status` is optional (`active` or `inactive`,
defaults to `active`). Updates accept these same fields optionally.

`teacher_id` is always derived from the authenticated account. Supplying it in a
request cannot change ownership or select another teacher's list.

Unauthenticated requests return 401, disallowed roles/profiles return 403 for
list/create, inaccessible individual records return 404, and invalid input
returns 422. Administrative generic CRUD permissions remain separate.
