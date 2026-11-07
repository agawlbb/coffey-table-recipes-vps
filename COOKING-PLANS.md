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

## Hosted backend — 2026-11-07

Implementation `38db9ec` is deployed at ctr.coffeybean.com using the existing
source-copy/restart path. Before deployment, hosted PHP/entry-point/lock files
matched the existing main checkout; no unrelated hosted source was overwritten.
The first deploy attempt stopped at filesystem ownership after stopping the
service. The privileged retry copied source; its immediate probe exposed a
read-permission problem on the newly created CookingPlans.php. Its deployed and
working-copy permissions were corrected to 0644 and the service restarted.
No deployment tooling or application behavior was changed to address ownership.

After recovery, all 66 checked installed PHP/entry/lock files matched task source,
public HTTPS `/health` returned 200 with status `ok`, and the container was running
with zero restarts. CPU settings remained CpuShares=1024 and `cpu.max=max 100000`.
Private configuration and persistent state remained outside the copied code tree;
no schema migration, recipe maintenance, live AI call or app publication ran.
An attempted private-file fingerprint inventory was incomplete because a private
backup-path record was unreadable; no pre/post private-hash claim is made.

The task remains separate from main. Native delivery and real cooking quality
still require device/user acceptance after an explicitly requested app build.

## Reasoning correction — 2026-11-07

CookingPlans now selects `gpt-6-luna` with explicit high reasoning and a 25,000
output-token allowance, independently of the global recipe-AI Mini override.
Generation and feedback use the same settings. The prompt requires backwards
planning from ready-to-serve dishes, upper timer ranges, realistic heating and
handling, and one cook's attention. AI continues to choose every schedule offset.
No recipe schema or server scheduling algorithm is introduced.

Cache matching includes effective model, reasoning, output allowance and prompt.
Existing sessions are preserved; old planner results cannot satisfy new corrected
requests. Token-limit exhaustion has a specific actionable error and leaves
sessions intact. Failure logs contain only model/effort/usage metadata, while
successful usage stays with the private saved AI result.

`php tests/cooking_plans.php`: 30 checks passed, including outgoing settings,
previous-cache exclusion and unchanged session content after token exhaustion.
The initial preservation assertion compared PHP object identity rather than data;
that fixture assertion was corrected. The targeted real WebSocket scenario passed.
Live isolated fixture evaluations with the selected model passed soup/bread,
four dishes, and feedback with completed steps/a running potato timer. All explicit
range/dependency checks passed; broiler watching did not overlap soup finishing.
Total tokens were respectively 6,222, 11,702 and 5,904. These bounded examples do
not establish reliability for every meal. No production recipes/data were used
as development fixtures and no full test suite was run.
