# Coffey Table Recipes VPS backend

This repository contains the VPS data service at
`wss://ctr.coffeybean.com/shopping`. The same connection now carries shopping,
recipe reads/adds/edits, household snapshots, restaurants and meal plans.
Account-scoped WebSocket connections synchronize timers; unauthenticated,
read-only connections serve public recipe links. The persistent MySQL database
stays on InMotion. Account bootstrap, household setup, verification mail and
invitations use the account WebSocket scope; see [ACCOUNT-WEBSOCKET.md](ACCOUNT-WEBSOCKET.md).
Child-mode policy, PIN changes and administrator resets use the SQL-backed VPS
account scope; see [CHILD-MODE-SQL.md](CHILD-MODE-SQL.md). Remaining administration, Home Assistant setup, public recipe pages and managed images
now run here; see [REMAINING-PHP.md](REMAINING-PHP.md) for the one-time cutover.

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
Managed photos and category artwork live in `state/media/recipes/`. The one-time
verified transfer must finish before this cutover is deployed. Deployment also copies this repository's `backend.caddy` site and
reloads Caddy to serve the new photos through the service's restricted HTTP
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


## Conversational recipe creation and changes

Working branch `feature/ai-dish-chat`, based on `1257a920f9c09e1c6ca0407ee3dbb0fc315895f9`.
The companion app branch starts at `0f5c38bd7dbf13027b750e4f58b59d7bf889c09c`.
Do not merge or deploy this feature until the user approves integration; preserve
ongoing build/release work. There are no inMotion backend additions.

`POST /households/{household}/recipe-chat` runs through the existing VPS AI
WebSocket scope and isolated AI worker. All authenticated household members may
use it, regardless of `ai_images_enabled` or the legacy variation access policy.
Recipe-specific chats require a live recipe attached to the selected household.
The worker rechecks membership/account access before returning its result.

Request fields:

- `request_id`: 32 lowercase hex characters identifying the conversation.
- `turn_id`: 32 lowercase hex characters; unchanged retries reuse this value.
- `revision`: last acknowledged conversation revision, starting at integer 0.
- `mode`: `create`, `variation`, or `edit`; fixed for a conversation.
- `recipe_id`: a string ID for `variation` and `edit`, resolved server-side.
- `action`: `message` or `prepare`.
- `message`: at most 4,000 characters for `message`; empty for `prepare`.

Replies contain `message`, `ready` and `revision`. Readiness never saves or ends
the conversation. `prepare` uses only the server-held agreed cooking specification,
never a client-supplied draft or the speculative transcript. It invokes the
existing import standardizer and returns `draft` when ready for normal editor
review. Any standardizer questions become another chat reply instead. Only the
ordinary recipe save endpoint writes recipe data.

Private state under `storage/recipe-chats/` is scoped to actor, household and
session; a nonblocking per-session lock serializes turns. Cached successful turns
make lost-response retries idempotent. Turn-content reuse and stale revisions are
rejected. State replacement is atomic and files are mode 0600. Chat state is a
working cache, not a user-facing saved history; closing/restarting the app does
not restore a conversation. A conversation allows 60 message/preparation turns,
with one final preparation still allowed at the limit so the user can finish.

Cooking-only original recipe context is sent to the provider with response
storage disabled. Group titles and timer/temperature actions retain their cooking
meaning without database identifiers, photographs or private account metadata.
The model distinguishes hypothetical analysis from accepted changes and retains
a complete agreed recipe separately. New dishes use `ai_created`; variations use
`ai_variation` and a server-bound parent, with the existing visual review before any parent photograph is reused.
Edits retain recipe identity, revision, source, photos, categories and links, replace
cooking details and clear stale nutrition. Preparation rejects a changed original;
the ordinary editor still enforces save-time revision/shared-edit checks.

The existing private `recipe-ai.local.json` is reused. No schema, dependency,
transport, provider-key or infrastructure change is required. Feature publication
is source-only; no deployment, restart or app build is included.


Validation on 2026-10-31: `php tests/recipe_chat.php` passed 81 checks;
`php tests/recipe_variation.php` passed 92; `php tests/recipe_ai.php` passed 294.
`php tests/ai_jobs.php` passed crash, duplicate-run and cancellation checks.
`python -m unittest discover -s tests -p ai_websocket.py` passed 10 real-socket
integration tests using fake providers and disposable SQLite. PHP syntax checks
passed for the new/changed services, bootstrap and AI fixture worker;
`git diff --check` passed. The final conversation-limit and parent-cover tests
are included. No hosted data, live provider, deployment or service restart was
used. Main advanced independently during this task; the feature remains based on
the recorded starting commit pending authorized integration.
