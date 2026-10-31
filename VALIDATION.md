# Initial extraction validation — 2026-10-28

Source was extracted from server migration commit
`f28caaa4e8e1c5d1c13b341f87ef0a0056a9a3c5`. Runtime classes and the WebSocket
server retain the deployed behavior. Bootstrap, deployment paths and tests now
use this independent repository; no code is loaded from the inMotion checkout.

Verified using the existing shared development environment:

- 21 signed Firebase identity checks.
- PHP fixtures for HA credentials, socket access/isolation/revocation, shopping
  transaction replay/merge/deletion and persistent sync requests.
- All three real WebSocket tests, including the actual HA integration client.
- Two deployer tests: preserve private/state data while replacing code, and stop
  before mutation if dependencies are missing. No image build or cPanel operation.
- Strict locked Composer validation and PHP syntax checks.

Wire/transaction tests use disposable SQLite. No hosted shopping data, running
VPS service, inMotion files, app packages or installed HA integration were changed
for this repository extraction. There are no push-triggered deployment workflows.

## Shopping write repair

The owner requested app-only child-mode enforcement. Removed the server-side
policy callback and its HTTPS request to inMotion, which timed out and caused
503 responses on otherwise authenticated shopping writes. Firebase validation,
household membership, disabled-account checks, credential revocation and receipt
handling remain enforced. No schema or data migration is needed.

Verified 21 signed identity checks, socket access/receipt/revocation and HA
credential fixtures, all three real WebSocket tests (including the actual HA
client), two deployment-preservation tests, and changed PHP syntax. The source
branch starts at `a7f9c260990d8dc74cbd1f1c2aae53963d28427a`.

Deployed the repair through `python3 deploy.py`. The running container uses
`php server.php start`; its bootstrap, server and all runtime classes match this
branch. The public authenticated read passed. The first open-connection revocation
check failed without a detailed response; an immediate repeat passed both read
and revocation. No shopping items were modified by these checks, and temporary
credentials were revoked. No app packages were built or installed.

## Shared CPU policy retained through deployment

Updated source Compose from `cpus: 0.5` to `cpu_shares: 1024`, matching
`vps_admin` work commit `9463376` and the corrected installed configuration.
There is no separate runtime generator: deploy.py installs this source file.
Deployment now checks Docker CpuShares and effective cgroup cpu.max after restart.

All three deployment tests passed, including rejection of incorrect shares and a
hard effective quota. Resolved Compose contained no cpus, cpu_quota or CPU limit
under deploy.resources. Ran the corrected deployment and verified CpuShares=1024,
NanoCpus=0, CpuQuota=0 and cpu.max=`max 100000`. Installed Compose matched source.
A pre/post snapshot confirmed unchanged memory, swap/reservation/swappiness,
process, security, capability, root-filesystem, OOM and restart settings. No
image build, app build, schema/data change or changes to other services occurred.

## Remaining data transport — 2026-10-29

Branch `backend_migration`, purpose: migrate remaining recipe/household/meal-plan
and timer data using the shopping protocol, based on fetched `origin/main`
`eb49ad5a7187d5e50410490dafe6b4aa1aa751b5`. Services were copied from the InMotion
repository's accepted `5424e9d` implementation. SQL remains on InMotion; existing
transactions, revision guards and immutable save/operation IDs are preserved.
The VPS meal service requires a date for every new add/move.

Verified in the shared development container, using disposable SQLite fixtures:

- `php tests/data_socket.php`: authenticated recipe reads/adds/edits, immutable
  replay, stale-edit rejection, household/person names, membership/HA/disabled
  account boundaries, meal dates/conflicts and account-private timer replay.
- Existing shopping/identity/HA/sync-request fixtures passed, including 21 signed
  Firebase checks. Ported meal-plan (25 checks), timer and recipe-receipt fixtures
  passed. `php tests/timer_push.php` verifies valid-token reuse and expiry renewal
  for the long-running FCM sender using a local transport fixture.
- `CTR_HA_SOURCE=/home/debian/projects/home-assistant-coffey-table-recipes .venv/bin/python -m unittest discover -s tests -p shopping_websocket.py`:
  all three existing real WebSocket tests passed, including the actual HA client.
- `.venv/bin/python -m unittest discover -s tests -p data_websocket.py`: all three
  new wire tests passed: recipe saves/push/replay and household isolation; private
  timers and public read-only recipe links; persistent photo uploads/readback,
  unchanged old photo URLs, public image CORS and rejection of private-file URLs.
- `.venv/bin/python -m unittest discover -s tests -p test_deployment.py`: all three
  tests passed, including preservation of uploaded photos and sender credentials.
- Strict Composer validation, runtime/fixture PHP syntax, changed Python syntax
  and `git diff --check` passed.

Deployment remains `python3 deploy.py`: copy code, restart the existing service
and reload this repository's Caddy site for uploaded photos. Private configuration
and state remain outside replaced code. No image build or schema migration is
needed. The already-existing VPS timer sender was copied privately into the data
service's protected configuration (mode 600, matching Firebase project); no AI,
signup or cPanel credential was moved.

No service restart, hosted endpoint removal, app build or installation was
performed. No production data was used for development tests. These matching
`backend_migration` branches remain unmerged for review.

## Branch deployment — 2026-10-29

At the owner’s explicit request, deployed `backend_migration` runtime commit
`f3056931e90ead6ca6b91a5ffad1daca89916863` using the existing `python3 deploy.py`.
The app and InMotion branches were neither merged nor deployed.

- The existing backend restarted successfully with zero restarts afterward;
  Caddy reloaded the branch’s WebSocket and persistent-photo routes.
- Installed bootstrap, server, all runtime PHP classes, Composer lock, Compose
  and Caddy site matched the selected source. CPU shares are 1024 and effective
  `cpu.max` is `max 100000`; no image build or database migration occurred.
- Live public TLS WebSocket shopping authentication/read and open-connection HA
  credential revocation passed. The temporary verification credential was revoked;
  no shopping items were edited.
- An existing public recipe’s detail and steps were read successfully through the
  new public WebSocket scope. Recipe contents and credentials were not printed.
  A missing recipe and an invalid media filename returned the expected errors.
- The deployed timer sender passed OAuth and FCM validate-only authorization;
  no notification was sent.

Authenticated app household snapshots, meal-plan writes and device timer delivery
remain for the owner’s matching-app testing. Blank-recipe automatic categorization
calls the new AI endpoint in the matching InMotion branch, which remains pending
that branch’s deployment. Existing signup and AI endpoints were left untouched.
# AI WebSocket working branch — 2026-10-30

`feature/vps-ai-websocket`, based on fetched `origin/main` `b977c8f`.
Source is prepared for review; do not merge until the user explicitly approves.

- PHP syntax passed for bootstrap/server/AI worker and every service, plus the
  new PHP worker/configuration fixtures. Changed Python files passed `py_compile`.
- Migrated AI fixtures passed: `tests/recipe_ai.php` (256), `recipe_photo.php`
  (30), `recipe_variation.php` (92, including the new persistent VPS cover check),
  `blank_recipe_categories.php` (18), `source_photos.php` (10),
  `website_import.php` (19), `website_photos.php` (8), `recipe_methods.php` (25),
  `recipe_descriptions.php` (18), and `recipe_wording.php` (25).
- `php tests/ai_jobs.php`: child crash recovery, repeated connection-key use,
  duplicate-run rejection and cancellation passed.
- `php tests/ai_configuration.php`: model pinning, private policy/extractor
  preservation and missing-credential rejection passed.
- `php tests/data_socket.php`: existing recipe/edit/replay, meal-date/conflict,
  timer-isolation/replay and access-revocation checks passed.
- `python3 -m unittest discover -s tests -p test_deployment.py`: all three
  mocked deployment/CPU-preservation checks passed. These did not deploy or restart
  anything. The AI credential and worker are required/preserved by deployment.
- Using the existing development Python environment with aiohttp,
  `python -m unittest discover -s tests -p ai_websocket.py`: all five real-socket
  tests passed. They cover progress with responsive heartbeats and other readers,
  clarification on one connection without saving, authorization/revocation,
  image generation/category media, blank categorization and error recovery.
- `python -m unittest discover -s tests -p data_websocket.py`: all three passed.
  `python -m unittest discover -s tests -p shopping_websocket.py`: two passed;
  the fixture-inapplicable sync-request test remained skipped.
- App full suite: 544 passed; its final progress-placement/long-connection
  follow-up passed 40 focused tests. Analysis passed; no app packages were built.

All AI provider responses were mocked and all SQL was disposable SQLite. No
production data, real API credential or paid provider request was used in tests.
The existing uploaded recipe credential is only referenced by its private runtime
path. The updated runtime image has not been built and the branch is not deployed.
No source was merged into main. Physical-device suspension, real-provider execution
and the rebuilt production runtime are not claimed verified.

# Deployment from feature/vps-ai-websocket

The user explicitly requested deploying this branch while retaining the earlier
instruction not to merge into main. Published runtime source is
`3bbd7723c6cd9bf0147e0ff1f735afc67d48a6bf`; this subsequent commit records evidence
only. Shared-hosting and app branches were neither deployed nor merged.

- Saved the previous source, Compose and Caddy configuration in
  `/var/backups/ctr-backend-before-ai-20261031T095222Z`. The previous image
  `sha256:1cec91ca6ec9eb989b07b669e3e727df8356ea5c3d56b187d27970114723765d`
  is retained as `ctr-backend:before-ai-20261031T095222Z`. The root-only
  `deployment.json` in that backup records source/image and verification metadata.
  Private credentials and persistent state/photos were not replaced.
- Built the backend runtime from this branch's Dockerfile. New image:
  `sha256:48d43a22111a500ed5853688f3db2406a97634c4d978db834ff24b3d36797e36`.
  Before restart, isolated image checks verified pdo_mysql, curl, mbstring,
  pcntl, DOM and OpenSSL; Poppler pdftotext 22.12.0 and Tesseract 5.3.0 ran.
  No app package was built.
- Ran the existing `python3 deploy.py` on the VPS host. Source copied and the
  backend container restarted successfully. Caddy reloaded successfully.
  CpuShares remains 1024; effective cpu.max is `max 100000`. The container was
  running afterward with zero restarts.
- Compared all 38 installed PHP runtime files against the requested source;
  every hash matched. The real runtime established database TLS and loaded the
  private AI configuration. Non-generating OpenAI model-access requests returned
  HTTP 200 for both the configured recipe and image models. No secret values
  were printed or stored in Git. The credential directory remains mode 700 and
  the AI JSON remains mode 600.
- Public `wss://ctr.coffeybean.com/shopping` recognized the new AI scope and
  rejected an invalid credential with `401 invalid_token`. Anonymous public-scope
  authentication and ping/pong succeeded.
- Ran `python3 tests/live_shopping.py`: authenticated public WSS shopping reads
  and revocation on the still-open connection passed. Its temporary verification
  credential was revoked; shopping items and recipe/account data were not edited.

These production checks validate deployment, configuration/model access and
socket availability. No paid AI generation, signed-in end-user AI/clarification
run, physical-device suspension test or app build/deployment was performed.
