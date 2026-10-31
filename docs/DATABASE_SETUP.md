# Database setup

This package contains the agreed MySQL/MariaDB database structure for the tutoring platform (34 domain entities).

Important:
- `teacher_subjects.default_capacity` is intentionally removed.
- `escrow_transactions` is included and ready for future use. It may remain unused until the project needs escrow handling.
- The domain table `sessions` is reserved for teaching sessions, so the default Laravel database-session table was removed from the users migration. Set `SESSION_DRIVER=file` unless you later configure another framework session table.
- The old SQLite file is intentionally not included because the project is now using MySQL/MariaDB.

Recommended for a new empty development database:

```bash
php artisan config:clear
php artisan migrate:fresh
```

`migrate:fresh` deletes existing tables. Use it only while the database has no important data.
