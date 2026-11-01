# Single app name

Task: settings/account reorganization, based on e35e9c8.

POST /me/name is an authenticated account-scope command accepting display_name
(a trimmed, nonempty string up to 200 characters). It updates users and every
linked household_people row atomically. Child mode rejects the command. The
existing own-person rename route uses the same operation so older clients cannot
reintroduce separate linked names.

Bootstrap preserves stored app names instead of replacing them with Firebase
claims. Existing oldest linked household names take precedence for account
presentation, preserving the familiar household name without a bulk data rewrite.
An explicit name save resolves historical differences across linked households.
Unlinked people, person IDs, favorites, memberships and history are unchanged.
Household creation stores the entered Your name in both account and person rows.
No schema migration or production-data mutation is needed to install this source.

Coordinate with the Flutter task/settings-and-one-name branch. Deploy this account
command before distributing that app. This task branch is for review; no runtime
deployment or main merge was performed.

Validation (2026-11-01):

- `php tests/accounts.php`: account fixtures and single-name preservation,
  propagation, invalid input, child restrictions, stale login and atomic rollback.
- `php tests/remaining_accounts.php`: 21 administration and HA checks.
- `php tests/data_socket.php`: existing household/data operations, including the
  legacy rename route using the unified save operation.
- `php -l src/HouseholdService.php` and `php -l src/AccountService.php`.
- Existing project virtual environment: `python tests/account_websocket.py`:
  9 passing real local WebSocket tests, including the new name command.

Fixtures used disposable SQLite databases and simulated identity/mail services;
no production database or external mail was used.
