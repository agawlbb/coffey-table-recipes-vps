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

Production cutover, 2026-10-31: migration 020 added all five columns using a
temporary ALTER grant, then restored the original SELECT/INSERT/UPDATE/DELETE
privileges. Three policy files were matched/imported for four SQL users; no files
were unmatched. Every copied flag/hash/counter/reset was read back and compared
inside the transaction, and the unchanged source state was checked again afterward.
Original files remain on InMotion. The protected one-time setup receipt is
`private/child-mode-sql-import.json`; deployment requires it before stopping the
backend, and does not run the import. No rollback image/archive was created.

Shared runtime `index.php` and `src/ChildMode.php` from `67ebd92` were copied using
the authenticated cPanel API and their contents read back and hash-verified. This
retires the old account/PIN/reset routes and makes retained write guards use SQL.
The web frontend was not rebuilt. Its older HTTP account routing is retired; use
the matching Android test build until the new web app is explicitly published.

VPS runtime `c9db933d0f04c13af75118feb5e2ae3e93ddfc2f` was copied/restarted with the
existing image and private/state directories. All 48 runtime PHP/lock/asset files
matched. CPU shares remained 1024 and effective cpu.max was `max 100000`. Live SQL
policy reads for all four users, public account rejection of an invalid token,
public WebSocket readiness and heartbeat passed. No real email was sent by these
deployment probes. Fresh-user/device behavior remains a manual test.
