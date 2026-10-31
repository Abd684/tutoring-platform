# Production scalability requirement

This project is intended for real market use. The minimum design target is **10,000 registered/active users**.

This target is a project requirement, not a guarantee that a single local XAMPP process can serve 10,000 simultaneous requests. Capacity under real traffic must be verified by load testing on the production infrastructure.

## Rules applied in this controller package

- No unbounded `Model::all()` list endpoints.
- Every index endpoint is paginated; default `20`, hard maximum `100` rows per request.
- Common filters use indexed database columns.
- A dedicated migration adds indexes for the controller query patterns.
- Relationships are eager-loaded selectively to reduce N+1 queries.
- API requests are rate-limited (`120` requests/minute per authenticated user, falling back to IP before Auth is implemented).
- Group enrollment uses a database transaction plus `lockForUpdate()` so concurrent requests cannot overfill a group.
- Financial records that are already succeeded are not hard-deleted through the generic CRUD controller.

## Required before production launch

- Add authentication and Policies/authorization to all non-public endpoints.
- Payment creation/status transitions must move behind `PaymentService` and payment-provider verification/idempotency.
- Use Redis for cache, queues and rate limiting when the application is deployed at scale.
- Keep videos/PDFs in private object storage, not on the application server.
- Run queue workers separately from web workers.
- Enable OPcache and production Laravel caches.
- Use production MySQL/MariaDB with monitoring, backups and slow-query logging.
- Perform load tests for the expected concurrent traffic, especially booking/group enrollment, playback-token and payment flows.
