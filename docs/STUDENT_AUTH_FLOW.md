# Student authentication flow

Implementation follows the SDD device model: `devices` -> `student_devices` -> `device_sessions` -> `device_events`.

- Register: creates `users` + `students`.
- Login: first device is registered; same active device can log in; another device returns `DEVICE_TRANSFER_REQUIRED`.
- Logout: revokes the current access/session state but keeps the registered device binding active.
- Refresh: requires the refresh token + matching device UUID + active account/device/session. Refresh token is rotated.
- Device transfer: verifies credentials, revokes old device/session state, activates the new device, then issues a new session.
- Admin suspension: revokes student access/refresh sessions without deleting the registered device history.
- Access token lifetime: 15 minutes by default. Student access tokens use the `student` ability.
- Refresh lifetime: 30 days by default and does not extend indefinitely on rotation.

Teacher authentication and subscription code remains in place and uses its existing `RefreshToken` flow.

## Controller / route separation (2026-09-24)
- Student auth moved from `AuthController` to `StudentAuthController`.
- `AuthController` remains for the teacher auth flow.
- Student auth routes moved from `routes/api.php` to `routes/student.php`.
- `bootstrap/app.php` mounts `routes/student.php` under `api/v1`, preserving `/api/v1/auth/*` and `/api/v1/me`.
- Existing teacher route mounting under `api/v1/teacher` is preserved.
