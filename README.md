# Coffey Table Recipes VPS backend

This repository contains the VPS data service at
`wss://ctr.coffeybean.com/shopping`. The same connection now carries shopping,
recipe reads/adds/edits, household snapshots, restaurants and meal plans.
Account-scoped WebSocket connections synchronize timers; unauthenticated,
read-only connections serve public recipe links. The persistent MySQL database
stays on InMotion. Account bootstrap, household setup, verification mail and
invitations use the account WebSocket scope; see [ACCOUNT-WEBSOCKET.md](ACCOUNT-WEBSOCKET.md).
Child-mode PIN policy and account administration remain in the separate
[InMotion repository](https://github.com/agawlbb/coffey-table-recipes-server).

AI imports, clarification turns, variations and image generation also use this
endpoint; see [AI-WEBSOCKET.md](AI-WEBSOCKET.md) for the matching working branches,
progress protocol and required runtime image update.

`server.php` runs the service; `src/` contains its code. Firebase ID tokens
authenticate app users. HA credentials are checked against the existing shared
SQL table, including revocation. Clients keep local data and a durable outbox.
Child mode hides editing controls in the app. Shopping writes do not make a
server-side child-mode policy call. Firebase authentication and household access
checks remain enforced by this service.

## Deployment

On the VPS, from this checkout:

```sh
python3 deploy.py
```

This copies source and locked dependencies into `/opt/services/ctr-backend/code/`
and restarts the one `ctr-backend` container. No cPanel credential is used.
Before deploying this migration, install the existing VPS timer sender credential
as `private/timer-push.json` (mode 600). AI uses the uploaded
`private/recipe-ai.local.json` (mode 600). Account actions require the existing
SMTP relay settings in `private/smtp.json` and account credential in
`private/firebase-admin.json` (mode 600); see the account setup above.
Credentials remain in `/opt/services/ctr-backend/private/`, and persistent state
in `/opt/services/ctr-backend/state/`. Both are outside Git and are preserved.
Routine source changes do not rebuild the runtime image or run schema/import jobs.
New uploaded photos live in `state/media/recipes/`; existing InMotion photo URLs
remain valid. Deployment also copies this repository's `backend.caddy` site and
reloads Caddy to serve the new photos through the service's restricted media
listener. No database migration, image build or app deployment is included.
Production uses CPU shares 1024 with no hard CPU quota, allowing idle CPU use.
Deployment verifies Docker CpuShares and effective cgroup cpu.max after restart.
Keep memory/swap, process, security and restart settings unchanged.

Docker/Compose, the existing Caddy route, private database/Firebase configuration
and locked Composer dependencies are setup prerequisites. Runtime image changes
are explicit: `sudo docker build -t ctr-backend:latest .`. Google Firebase uses
the existing project. No separate VPS Firebase project is needed.

## Development

Use `/home/debian/.local/bin/dev exec` for development commands. Install locked
PHP dependencies with `composer install --no-dev`. For wire tests, create this
project's `.venv` and install `requirements-test.txt`.

```sh
php tests/data_socket.php
php tests/meal_plans.php
php tests/timer_sync.php
php tests/timer_push.php
php tests/recipe_save_receipts.php
php tests/authentication.php
php tests/home_assistant_connections.php
php tests/shopping_socket.php
php tests/shopping_lists.php
php tests/shopping_sync_requests.php
.venv/bin/python -m unittest discover -s tests -p 'test_deployment.py'
.venv/bin/python -m unittest discover -s tests -p 'shopping_websocket.py'
.venv/bin/python -m unittest discover -s tests -p 'data_websocket.py'
```

Set `CTR_HA_SOURCE` to the HA checkout to include its actual client in the wire
test. Tests use disposable SQLite databases. `python3 tests/live_shopping.py`
separately checks public authenticated reads and credential revocation without
editing shopping items.

Initial source was extracted from the server repository's `migration/vps-backend`
commit `f28caaa4e8e1c5d1c13b341f87ef0a0056a9a3c5`, preserving its tested behavior.
Licensed AGPL-3.0-only; see LICENSE and NOTICE.md.
