# Database-backed cooking context

Task `feature/database-chat-context` begins at fetched main
`b8c0b24f9c04981f1604f52ddc13244b9a3007e2` and intentionally includes deployed
`feature/evolving-recipe-chat` source through `54901855fe498c11df61647089f028d3310e6354`.
This preserves the current test app contract and avoids rolling back deployed
chat behavior. No integration into main is authorized.

`user_recipe_context` in the existing private VPS MariaDB database is the sole
runtime store of learned user cooking context. It has one row per `users.id`,
with a JSON-validated document (`state_json`) containing revision and facts. The
foreign key removes that user's context when their account is deleted. Fact IDs,
self/family attribution, and household visibility remain unchanged. Context is
now included in normal database backups; temporary conversation caches stay in
`state/recipe-chats/`. No app rebuild, new endpoint or UI is required.

A nonblocking MariaDB named lock serializes chats for one account across workers
while other accounts can continue. A single SQL UPDATE atomically stores context
and a pending successful-chat snapshot; after the conversation file is replaced,
a second UPDATE removes the pending snapshot. The next worker repairs an
interrupted SQL/cache commit before reading any conversation. Existing retry
identities, live authorization, invalid-delta rejection, corrections and forgetting
remain in place. A database error never falls back to stale preference files.

## One-time migration

This is explicit maintenance, never an automatic deployment/request migration.

1. Stop the existing backend and its AI workers so no old file writer can run.
2. Using the existing runtime image, private database connection and this source,
   run `php bin/migrate-recipe-context.php /state/recipe-user-context`.
   The command applies `bin/user-recipe-context.sql` and validates every original
   JSON profile against a real user by matching its hashed account filename.
   Unknown accounts, corrupt profiles, symlinks, or conflicting SQL records fail
   without overwriting data. All profiles import in one transaction and each is
   read back and compared before commit. Exact retries are idempotent.
3. Retain the original directory as a private, dated file backup outside the
   active preference path. Do not purge it. The importer never deletes inputs.
4. Run the normal source deployment and verify SQL/source, health and CPU policy.

If import fails, keep the original source directory and old backend code intact;
restart the previous backend to resume file-based operation while investigating.
After successful cutover, do not redeploy an old file-based backend against the
archived preferences: export current SQL context first if rollback is necessary.

## Validation

Focused disposable SQLite fixtures verify migration, account deletion cascade,
privacy, corrections/forgetting, invalid updates, concurrent-account behavior,
SQL-write failure, cache-write failure, finalization failure and recovery/replay.
The SQLite fixtures emulate MariaDB's named locks using test-only local locks;
production uses actual GET_LOCK/RELEASE_LOCK and no preference-file locks.
The existing AI socket tests cover fresh worker connections and revoked access.
No production data or live provider is used for development tests.

Validation on 2026-11-01: `php tests/recipe_context_migration.php` passed 11
checks; `php tests/recipe_user_context.php` passed 66; `php tests/recipe_chat.php`
passed 106. `python -m unittest discover -s tests -p ai_websocket.py` passed
13 real-socket tests with fresh workers, fake provider replies and disposable SQL.
PHP syntax and diff checks passed. No Flutter source/dependencies changed; no
Flutter tests, app build or main merge was performed.
