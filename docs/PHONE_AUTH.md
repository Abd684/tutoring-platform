# Phone authentication

Student and teacher registration no longer require email. Email may still be supplied as optional profile data. Existing accounts sign in using their stored phone number and password.

- `POST /api/v1/auth/login`: `phone`, `password`, and the existing device fields (`device_uuid`, `platform`, etc.).
- `POST /api/v1/teacher/login`: `phone`, `password`.
- `POST /api/v1/auth/device/transfer`: `phone`, `password`, and target device fields.
- Admin login: `phone`, `password`.

Registration retains name, password/password_confirmation, and existing student or teacher profile requirements. Phone numbers are unique across accounts and must be sent as strings, preserving leading zeroes. The stored format must be used when signing in.

Deploy with `php artisan migrate`. The migration stops if existing accounts share a phone number; resolve those records before retrying. No SMS or OTP flow is introduced. Clients must replace the email login field with phone.
