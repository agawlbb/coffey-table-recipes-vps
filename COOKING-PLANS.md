# Combined cooking plans

Task `feature/combined-cooking`, based on fetched canonical main `759167f`.
Flutter companion uses the same task branch, based on `d0bc41f`.

`/households/{id}/cooking-plan` runs through the existing authenticated AI worker.
Generation resolves selected meal-entry IDs and recipe snapshots on the server;
it uses the generating user's existing RecipeUserContext and configured provider
credentials/model. The model supplies structured scheduling offsets for every
original step. Instructions, ingredient quantities/groups and timer actions are
preserved. Duplicate dishes receive distinct prefixed component identities.

Private reusable results live in `CTR_STORAGE/cooking-plans`. Matching includes
cooking snapshots, quantities, dish multiplicity and scoped personal context,
independent of selection order or dinner time. Sessions retain relative offsets;
changing their dinner instant does not call AI. No recipe schema migration,
new service, dependencies or credentials are required.

Combined session fields are `combined`, `dinner_at` (epoch milliseconds),
`started_at` (null until started), `plan_revision` and `plan_message`. Steps carry
`offset_seconds` relative to dinner and source recipe/step identities. Existing
session start/step/timer/share/end and WebSocket transport are reused. New `start`
and `dinner` session actions accept existing installation/operation identities.
Unstarted sessions expire one hour after dinner; started sessions retain existing
idle and timer protection. Reusable plans survive session expiry.

Feedback and history use the same cooking-plan endpoint. History makes no provider
call. Private conversations and retry receipts are stored using RecipeUserContext's
existing atomic context/conversation commit. Each author supplies their own context;
shared session snapshots never include private facts or feedback history. Fresh
server progress and time accompany every model turn. The model decides whether
to revise remaining offsets and returns evidence-backed personal context changes.
Newer completion, timers, starts, dinner changes or plan changes prevent application
of an obsolete AI timeline. Completed step offsets and live timer deadlines are
preserved. Feedback retries reuse saved replies rather than spending more tokens.

Start-alarm updates are an update-only `session:{id}:__plan_start` timer payload,
with existing account, installation and session guards. New native clients map it
to the device's opted-in `plan-start:{account}:{session}` alarm, and ignore it if
none exists. Shared changes do not create alarms on other devices. Existing
notifications, alarm preferences and best-effort background delivery still apply.

Validation and hosted delivery evidence follow when complete. No live provider
call or production-data mutation is used for development tests.

## Source verification — 2026-11-07

- `php tests/cooking_plans.php`: 27 checks passed, covering reuse, interleaved
  dishes, fresh progress, personal isolation, stale replies, preserved running
  timers, malformed responses and pre-cooking expiry.
- `php tests/cooking_sessions.php`, `php tests/ai_configuration.php` and
  `php tests/timer_push.php` passed their existing focused checks.
- `.venv/bin/python -m unittest discover -s tests -p ai_websocket.py -k combined_plan -v`
  (using the existing backend virtual environment): one real WebSocket scenario
  passed generation, cooking-subscriber broadcasts, dinner shifts without AI,
  feedback updates and personal-context persistence. The fixture uses a fake
  provider and disposable SQL, never hosted data.
- PHP syntax checks for changed application files and `git diff --check` passed.
- Flutter companion verification includes 44 passing final UI/session/logging
  tests, earlier passing planner/timer/socket/local-data checks and scoped analysis.

No live-provider evaluation, app build, release or main integration was performed.
Native alarm reception is source-reviewed and requires later device acceptance.
