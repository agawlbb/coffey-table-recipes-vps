# Coffey Table Recipes VPS backend

This repository contains the VPS data service at
`wss://ctr.coffeybean.com/shopping`. The same connection now carries shopping,
recipe reads/adds/edits, household snapshots, restaurants and meal plans.
Account-scoped WebSocket connections synchronize timers; unauthenticated,
read-only connections serve public recipe links. The persistent MariaDB database runs in the VPS database container on the private
`database-backend` network. Account bootstrap, household setup, verification mail and
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

Docker/Compose, the existing Caddy route, running VPS MariaDB and its external
`database-backend` network, private database/Firebase configuration
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


### Authorized feature deployment — 2026-10-31

The user explicitly requested deploying the backend from this feature branch and
building a test Android APK; merging into main remains unauthorized. Accepted main
`40d783c` was integrated into the feature branch to preserve the current VPS
routing, HTTP/media cutover and database-connection repair. The deployed source is
`8dc297aabcaedf7fa1bd01b7eecc303e50c74774`.

Post-integration checks passed: 81 chat fixtures, 92 variation checks, 294 import
checks, AI/account worker recovery/cancellation/detached-completion checks, 10 AI
socket integration tests, 4 deployer-preservation tests, 34 HTTP checks and media
migration/preservation fixtures. Providers and SQL were fake/disposable for tests.

The existing `deploy.py` copied source and restarted `ctr-backend` without building
a runtime image or running schema/data migrations. Installed source hashes match
the deployed commit; private configuration hashes were unchanged and the state
directory was preserved. CpuShares=1024 and unlimited cpu.max were verified.
Public HTTPS health and both association documents returned 200 JSON; private
configuration URLs returned 404. Public WebSocket authentication/heartbeat passed,
and AI authentication rejected an invalid token with 401. The live PHP runtime
loads and matches `RecipeChatService` on the intended AI route. No live provider
call or recipe save was used for deployment validation.

The app feature integrates accepted main and reserves signed Android test build
46, using source `9754546d62ea6d29a8833832f9ba8a571ed3cb30`. No web/iOS build,
release tag or main-branch change is part of this deployment.


## Per-user cooking context (feature branch)

`feature/ai-user-context` starts from fetched VPS main
`7d66c4dc49e650150df83843e4d91180578f5821`. Purpose: remember cooking preferences
learned in recipe chats and send them to future conversations for the same user.
This feature is unmerged and undeployed. The released Flutter app remains
unchanged; no new screen, control, endpoint, database schema or dependency is needed.
All implementation is on the VPS, with the existing approved private AI config.

Each message request loads the authenticated account's durable cooking context
and includes `cooking_context` in the provider input. Facts have an opaque ID,
`person` (`self` or the stated family name/relationship), and concise `preference`.
The provider receives no internal account or household identifier. Personal facts
follow the user between households. Family facts are private to that reporting
user and included only in the household where they were learned; they are not
copied into another family member's account or shared with other users.

AI returns a required `context_updates` array alongside its usual message,
readiness and agreed recipe specification. It may add, update or remove facts.
Every delta must quote supporting evidence verbatim from the latest user message.
Updates/removals must reference a currently visible existing fact ID; additions
receive a new server-owned ID. The full delta is validated before any state changes.
Duplicates are ignored; a correction replaces its existing fact. Hypotheticals,
one-dish exceptions, recipe contents, assistant suggestions and inferred medical
conditions are not enduring preference evidence. Current explicit requests take
priority over saved preferences. Users can correct or ask to forget preferences
in ordinary chat; no preference-management UI is added.

Valid learning commits on a successful chat turn, independently of recipe
review/Save, so discarding an unsaved dish does not erase learned preferences.
Recipe preparation still uses only the agreed cooking specification and does not
inject profile facts into recipe source, notes or attribution. Missing/invalid
AI deltas fail without changing context or advancing the chat. The request body
cannot supply an authoritative user identity or context snapshot.

Chat responses add `context_revision` and normalized `context_updates` (action,
fact ID, person and preference; supporting quotes are not returned as profile data).
Existing released clients ignore these extra fields and continue working. A cached
retry returns the original turn's response/revision but never reapplies its old
context updates or reverses a newer correction. Historical assistant messages do
not replay preference deltas into later model requests. Forgetting removes facts
from durable context; existing working conversation transcripts are not erased.

Persistent facts live in private `state/recipe-user-context/` files, outside the
replaceable code and disposable chat caches. Each account has one hashed filename
and lock, mode 0600 in a mode-0700 directory. At most 100 compact facts are kept,
with at most 100 updates per turn, an 80-character person label, 500-character
preference and 1,000-character supporting quote. Reaching the bound does not
silently discard other facts; existing chat can forget facts to make room.

One request per account holds the context lock while generating/committing, so two
simultaneous chats cannot overwrite corrections. Another account can keep working.
A busy account uses the existing recoverable `chat_in_progress` response. Atomic
file replacement and one bounded pending-chat snapshot recover an interruption
between saving preferences and the successful chat response cache. The snapshot
is removed after both files commit, or repaired before another conversation reads
context. Disk/write interruption uses the existing `ai_interrupted` code so the
released client retries the same turn. Malformed stored context is preserved for
repair rather than reset. Live account/membership authorization is rechecked
before durable chat/context changes as well as before the worker returns output.
Routine source deployment already preserves the state directory; no setup or
migration is introduced by this feature.

Validation (2026-10-31): PHP syntax checks passed; `php tests/recipe_user_context.php`
passed 71 checks; `php tests/recipe_chat.php` passed 81; `php tests/ai_jobs.php`
passed AI/account crash, duplicate, cancellation and detached-completion checks;
`php tests/authentication.php` passed 21 signed-identity checks. The 12 tests in
`python -m unittest discover -s tests -p ai_websocket.py` passed using real sockets,
fake provider replies and disposable SQLite. Coverage includes fresh-worker/new-
conversation recall, account and family/household isolation, corrections, forgetting,
stale retries, invalid/ungrounded deltas, client-context spoofing, cache deletion,
concurrent accounts, interrupted two-file commit recovery and mid-run revocation.
No live provider call, production state change, Flutter build, deployment, release
or main merge is included. No Flutter source/dependency changed, so Flutter checks
were not run for this backend-only feature.


### Chat recipe visibility repair

The user reported that chat said it had created a recipe but showed only its
commentary. `recipe_text` was already generated and stored, but the released app
renders only the response's `message`; the actual cooking specification stayed
hidden until Review.

The backend now appends a clearly labeled **Current recipe draft** and its complete
text to the visible reply when first proposed or revised. AI is instructed to use
readable plain text with a title, ingredient lines and numbered method steps.
Provisional text is visible before readiness, so clarification and refinement can
refer to it. Advice with no recipe remains ordinary chat; unchanged analysis does
not repeat the whole draft. The provider also returns a `show_recipe` boolean so
an explicit request to see the recipe again displays even an unchanged draft. If the reply already contains the exact recipe, it is
not duplicated. Existing conversations whose draft was hidden get it on their next
new reply. Completed-turn retries retain their original cached response. Showing
text does not open the editor or save anything; Review/Save remain explicit.

This repair stays on `feature/ai-user-context` with the preference-context work.
It needs only backend deployment to work in the existing released clients; no app
code or rebuild is required. It is not deployed or merged by this source repair.
Focused validation uses `php tests/recipe_chat.php` (96 checks),
`php tests/recipe_user_context.php` (71), PHP syntax checks, and the 12 real-socket
AI tests with fake providers/disposable SQLite. Coverage includes full visible
recipe contents, provisional and revised drafts, unchanged/hypothetical discussion,
legacy conversations, duplicate suppression, cache/retry consistency and existing
context/access/review-save behavior. No paid AI call or production recipe mutation.


### Accepted context and visibility deployment — 2026-10-31

The user approved merging and deploying both changes. GitHub main merge
`d9177eff748bef6fa43b9a20977421264e079238` includes the exact feature source
verified by 96 chat checks, 71 context checks and 12 real-socket AI tests.
Main had no intervening changes, so integration did not alter that tested source.
The existing deployer copied code and restarted the VPS service successfully.
Public HTTPS health returned 200 with status ok; the live runtime loads the chat
service and reports running with zero restarts. Installed worker, chat service
and user-context source hashes match main. CpuShares=1024 and unlimited cpu.max
were verified. Persistent state and private configuration stay outside replaced
code. No app build, paid AI request, recipe write, database migration or InMotion
backend deployment was performed. This supersedes the earlier pending status.


## VPS database migration — 2026-11-01

The recipe database moved from InMotion MariaDB 10.11.19 to VPS MariaDB 11.8.9.
The backend joins the existing internal `database-backend` network alongside
`proxy`; MariaDB remains unexposed on host/public ports. The existing PDO MySQL
driver works with MariaDB; no application SQL or dependencies changed.

Private `database.json` now selects host `mariadb`, port 3306, database
`coffey_table_recipes` and application user `ctr_backend`. This user has only
SELECT/INSERT/UPDATE/DELETE on that database. Its generated password stays outside
Git. TLS is disabled for this local Docker connection, which stays on the private
network. Each other project must use its own database and user. Routine deployment
preserves these credentials and does not create databases or import data.

Clients were paused and the backend and recipe cleanup timer stopped for export.
A complete SQL export, original connection settings, source privileges and
verification manifests are retained under the root-only
`/opt/services/ctr-backend/backups/database-migration-20261101T123755Z/`.
The source database remains intact; its temporary export privileges were restored.
No views, routines, triggers or events were present after checking with elevated
source database permissions.

Rollback before new client writes: stop the backend and cleanup timer, restore
`database.inmotion.json` from that protected directory to private `database.json`
with its existing ownership/mode, and restart the backend and timer. After client
writes resume, preserve/reconcile new VPS data before any rollback. Do not restore
the old database over new data. The backup is on this VPS; it is not an off-server
backup. Existing InMotion data is retained separately.

Verification: all 31 tables matched exact row counts and per-row content hashes,
including 70 recipes, 1 household and 3 accounts. Column definitions, indexes,
foreign keys, check constraints and next auto-increment IDs matched. Composite
foreign-key checks found no orphans. Membership-authorized complete snapshots
passed for all 3 memberships. A restricted-user UPDATE/readback/ROLLBACK passed;
all table contents still matched afterward. The complete comparison was repeated
after the live probe, which creates then deletes its own temporary HA credential.

`dev exec python3 -m unittest discover -s tests -p test_deployment.py` passed all
4 deployment-preservation checks; `git diff --check` and `docker compose config
--quiet` passed. Actual deployed PHP/entry-point/lockfile hashes and Compose bytes
matched this source. Live runtime identity confirmed VPS MariaDB 11.8.9; HTTPS
health and existing recipe `/recipes/9` returned 200. The existing
`tests/live_shopping.py` probe passed authenticated public WSS shopping reads and
open-connection credential revocation. No existing shopping entries were edited.
The recipe cleanup timer was restored active; CpuShares=1024 and unlimited
cpu.max were verified. No app package or runtime image was built.

Protected SQL export SHA-256:
`4eb05d270b644875e2076648bb2eeb471e49da64d730b1411b859db69b495893`.
