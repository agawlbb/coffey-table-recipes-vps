# Coffey Table Recipes VPS backend

This repository contains only the VPS service. It currently handles shopping
synchronization at `wss://ctr.coffeybean.com/shopping` and can host more backend
work later. The inMotion PHP backend remains in
[coffey-table-recipes-server](https://github.com/agawlbb/coffey-table-recipes-server),
branch `migration-take-2`. The database stays on inMotion.

`server.php` runs the service; `src/` contains its code. Firebase ID tokens
authenticate app users. HA credentials are checked against the existing shared
SQL table, including revocation. Clients keep local data and a durable outbox.
Existing child-mode policy remains on the account backend.

## Deployment

On the VPS, from this checkout:

```sh
python3 deploy.py
```

This copies source and locked dependencies into `/opt/services/ctr-backend/code/`
and restarts the one `ctr-backend` container. No cPanel credential is used.
Credentials remain in `/opt/services/ctr-backend/private/`, and persistent state
in `/opt/services/ctr-backend/state/`. Both are outside Git and are preserved.
Routine source changes do not rebuild the runtime image or run schema/import jobs.

Docker/Compose, the existing Caddy route, private database/Firebase configuration
and locked Composer dependencies are setup prerequisites. Runtime image changes
are explicit: `sudo docker build -t ctr-backend:latest .`. Google Firebase uses
the existing project. No separate VPS Firebase project is needed.

## Development

Use `/home/debian/.local/bin/dev exec` for development commands. Install locked
PHP dependencies with `composer install --no-dev`. For wire tests, create this
project's `.venv` and install `requirements-test.txt`.

```sh
php tests/authentication.php
php tests/home_assistant_connections.php
php tests/shopping_socket.php
php tests/shopping_lists.php
php tests/shopping_sync_requests.php
.venv/bin/python -m unittest discover -s tests -p 'test_deployment.py'
.venv/bin/python -m unittest discover -s tests -p 'shopping_websocket.py'
```

Set `CTR_HA_SOURCE` to the HA checkout to include its actual client in the wire
test. Tests use disposable SQLite databases. `python3 tests/live_shopping.py`
separately checks public authenticated reads and credential revocation without
editing shopping items.

Initial source was extracted from the server repository's `migration/vps-backend`
commit `f28caaa4e8e1c5d1c13b341f87ef0a0056a9a3c5`, preserving its tested behavior.
Licensed AGPL-3.0-only; see LICENSE and NOTICE.md.
