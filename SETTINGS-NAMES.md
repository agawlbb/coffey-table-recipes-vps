# Full names and household display names

This revision resumes task/settings-and-one-name at b303c59, replacing the earlier
single-name behavior with independent account and household names. No schema
migration, bulk data rewrite or production-data test is required.

POST /me/name accepts display_name (trimmed, 1–200 characters) and updates only
users.display_name, presented in the app as Full name. Bootstrap preserves the
stored full name instead of replacing it with Firebase login metadata or a
household nickname. Household creation does not change that full name.

POST /households/{household}/people/{person}/name is an account-scope command
accepting display_name. It updates only that household_people row. Adults may
change their own display name; household owners/admins may also change a linked
child account's display name. Other adults, unlinked people and people outside
the selected household cannot be renamed through this command. Children cannot
change either full or display names. The service checks membership, locks the
actor and target rows, and checks child/role permissions before saving. The legacy
own-person rename command uses the same rules.

Full names and household names remain independent, including for accounts with
multiple household memberships. Person IDs, favorites, memberships and cooking
history are retained. No automatic name propagation occurs.

Coordinate with the Flutter task/settings-and-one-name branch. Publish this
backend revision before distributing its corresponding app. This revision makes
no main merge, production-data change or runtime deployment.

Validation (2026-11-01):

- `php -l src/HouseholdService.php` and `php -l src/AccountService.php` passed.
- `php tests/accounts.php`: existing account checks plus independent full/display
  names, cross-household isolation, owner/admin child edits, ordinary adult self
  edits, child/outsider/adult/unlinked target denials, legacy route protection,
  validation and failed-write rollback.
- `php tests/data_socket.php`, `php tests/remaining_accounts.php` (21 checks),
  and `php tests/child_mode.php` passed.
- Existing project virtual environment: `python tests/account_websocket.py`
  passed 10 tests, including the household display-name command and preservation
  of the separate full name over the real local socket transport.
- Tests used disposable SQLite fixtures with simulated identity and mail services.
  No production database or mail service was used.
