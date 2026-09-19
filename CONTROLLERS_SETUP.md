# Controllers package setup

Included CRUD controllers:

- TeacherController
- SubjectController
- TeacherSubjectController
- UnitController
- LessonController
- ContentController
- ContentAssetController
- GroupController
- GroupEnrollmentController
- PaymentController
- SubscriptionPlanController
- TeacherSubscriptionController

All API routes use `/api/v1/...` and are registered through `routes/api.php`.

After copying the files into the Laravel project, run:

```powershell
php artisan optimize:clear
php artisan migrate
php artisan route:list --path=api/v1
```

Do **not** run `migrate:fresh` on a database that contains real data.

The project currently has no completed Auth/Policy layer. These controllers are development-ready CRUD scaffolding, but write endpoints must be protected with authentication and authorization before public production deployment.
