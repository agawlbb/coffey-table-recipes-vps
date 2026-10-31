# SQL child-mode policy

The `users.child_mode` flag replaces the InMotion PHP policy request. PIN hashes,
failed attempts, lockout deadlines and next-login reset timestamps are stored on
the same user row. New accounts default to disabled. Reads/changes/reset use row
locks and fail closed on missing users or SQL failures. PIN values and hashes are
never returned by the API.

The account scope now accepts `/me/child-mode` reads/changes and confirmed
`/admin/users/{id}/reset-child-mode` writes. Reset requires the existing application
administrator role. A token refresh alone does not clear a pending reset: Firebase
`auth_time` must be later than its timestamp. People flags are scoped to the
requester's households. Existing child-mode write rules remain unchanged; shopping
retains UI enforcement. Other account administration remains on its current backend.

Before this source deployment, apply the shared server repository's reviewed
`database/migrations/020_child_mode.sql` once. It adds five columns. Import the
existing protected `storage/child-mode/{sha256(uid)}.json` files using
`src/ChildModeImport.php` during setup, after retiring legacy PIN/reset HTTP writers.
The importer validates PIN hashes/counters, locks user rows, verifies copied values
and rejects overwriting existing SQL state. Missing source files retain the old
disabled default; unmatched historical files are counted and left untouched.
Preserve the original files. No schema/import action belongs in `deploy.py`.

Shared-backend write guards use the same SQL implementation, while its retired PIN
and reset routes return 410. The VPS contains no HTTP call to the InMotion policy
endpoint. Database access and authenticated SMTP still use the existing hosts.

Offline validation passed: PIN/default/isolation/lockout/next-login-reset checks;
policy import preservation and malformed/repeated-import rejection; 25 account
checks; five real account WebSocket tests including PIN and admin-reset access;
signed identity (21 checks), data sockets and four mocked deployment checks.
Production import and deployment evidence will be recorded after cutover.
