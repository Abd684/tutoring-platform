# Authorization policies

Every model in `app/Models` has an automatically discovered Laravel policy in
`app/Policies`. Controllers call `authorize` before generic CRUD operations.
No global administrator override bypasses the policy checks.

| Operation | Permission |
| --- | --- |
| Generic CRUD lists, creation, update, deletion | Active company administrator |
| Read a private record | Active administrator or active owner, where the policy declares an owner path |
| Read teacher-owned curriculum records | Active owning teacher or active administrator |
| Teacher content uploads and asset management | Active owning teacher, following lesson → unit → teacher subject |
| Teacher sessions and availability | Active owning teacher; lists are scoped in the controller |
| Record session attendance | Active owning teacher; enrollment membership is validated by the controller |
| Teacher group creation and membership changes | Active owning teacher; capacity and enrollment checks remain in the controller |
| Subscribe to a plan | Active teacher account and profile |
| Student purchases, playback, quizzes and bookings | Active student account and profile; ownership, enrollment, signatures and business rules remain in their dedicated workflows |
| Payment review, account activation and suspension | Active company administrator |
| Restore and force-delete | Denied |

`ResourcePolicy` centralizes administrative CRUD, owner reads and explicit
teacher `manage` / `upload` abilities. Per-model policies declare ownership
relationship paths. Generic lists remain administrative because their queries
can include records belonging to multiple users. Dedicated student and teacher
lists keep their existing query scopes.

Generic mutations stay administrative even for owners: they accept arbitrary
foreign keys, financial amounts, scores or account statuses. Owners use the
dedicated endpoints that validate the related records and assign identity from
the authenticated account. Session creation explicitly rejects `teacher_id`
supplied by the client.

Inaccessible teacher-owned records use 404 where the ownership policy is
applied. Existing workflow-specific error responses and business checks remain
in place. Public curriculum and location browsing do not require private-record
permissions. Commented routes in `routes/api.php` remain disabled.

`tests/Feature/PolicyAuthorizationTest.php` checks discovery for every model,
administrative permissions, suspended accounts, cross-account reads, nested
curriculum ownership and session role/ownership checks.
