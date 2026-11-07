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

Hosted follow-up: implementation `bf0cf2c` deployed through the existing privileged
source-copy/restart procedure. All 66 checked installed source files matched;
HTTPS health returned 200/ok; CpuShares=1024 and unlimited cpu.max were verified.
No schema or recipe maintenance was performed. At the user's explicit request,
the reported old soup/bread session was ended through the normal cancellation
path and its single matching saved AI result removed. The normal ended-session
tombstone remains for device reconciliation; it is absent from active plans.
Cancellation delivery was requested for every joined device, including the
original device. Recipes, private cooking context and unrelated plans were retained.

## Full preparation and reasoning comparison — 2026-11-07

The response format now permits additional session-only preparation rows using
`prep:` identities, an instruction and selected source-recipe references. Existing
rows retain their IDs/instructions and use null preparation metadata. All original
steps must remain. Added prep is merged into the session snapshot and ordered by
its AI-assigned time; no recipe records or timers are rewritten. Completed prep
retains its schedule during feedback. Shared snapshots include the new rows.
Initial generation must include gathering, ingredient preparation and finishing
in the checklist, with no extra work before it described only in notes. The first
entry therefore covers the entire job and drives the existing start-alarm logic.

Selected effort is **medium** for both generation and feedback, with Luna and the
25,000-token allowance retained. A paired three-fixture comparison had medians
36.02 seconds (medium) and 173.91 seconds (high). Review found an active-preparation
overlap in one medium result; the prompt was clarified to reserve full hands-on
intervals. A subsequent medium recheck passed all three fixtures, including added
hands-on-event checks, at 81.93/62.03/30.63 seconds (median 62.03). The high baseline
predates that last clarification. This is bounded evaluation, not a general speed
or planning guarantee. No production data was used as a development fixture.

Cache identity includes the actual response format as well as model, effort,
output allowance and instructions. Private successful-result metadata includes
elapsed request milliseconds and tokens; routine success is not logged. Existing
incomplete-response handling preserves the current session.

Focused validation: 37 PHP plan checks passed, including preparation first-step
insertion, preserved source steps/timers, completing prep, completed-prep feedback,
rejected unknown recipe references, and shared prep snapshots. The real local
WebSocket generation/shift/feedback case passed with added prep in its fake
provider. PHP syntax and changed-file diff review passed. Flutter's focused UI
checks and their limits are recorded in the companion document.

Hosted implementation `83f4614` deployed through the existing privileged
source-copy/restart procedure. Pre-deploy source matched the previous task
revision; all 66 checked installed source files matched the new task source.
HTTPS health returned 200/ok; CpuShares=1024 and unlimited cpu.max were retained.
At the user's explicit request, the specifically reported current soup/bread plan
was ended using a maintenance installation identity so normal cancellation delivery
covers all joined devices. Its single matching cached result was removed. The
normal ended-session tombstone is retained for reconciliation; the plan is absent
from active sessions. Recipes, personal context and unrelated sessions were not
changed. No schema migration, app build, release or main integration ran.


## Shared connection follow-up — 2026-11-07

Continued task from backend `c4bf94b` and app `1166583`. Authentication renewal
for the same user/scope preserves active workers; changing identity/scope still
separates their work. The existing 120-request/minute protection applies to
identified data requests, leaving authentication and keepalives available.
A rate error therefore fails its request rather than disconnecting the client.
AI worker capacity limits remain request-local.

Feedback accepts `current_progress` containing the device's displayed completed
steps, timers, start and dinner time. It is supplied to AI alongside fresh server
state, without requiring the client to wait for other saves. It does not write
client-reported progress over canonical server progress. Planner settings and
generation prompts/cache identities are unchanged.

Validation: 38 cooking-plan PHP checks; PHP syntax checks; seven real app-socket
scenarios including rate errors followed by keepalive/authentication; the existing
combined-plan wire scenario; and same-connection authentication renewal during a
running AI request (two selected AI socket scenarios). These use disposable SQL
and a fake provider. No full suite, live AI request, database reset or app build.

Hosted source `bd11647` was copied and the existing backend restarted. All 62
tracked PHP/entry/lock files checked match that commit; public `/health` returned
HTTP 200 with status ok. Deployment retained CpuShares=1024 and unlimited cpu.max.
Private configuration and persistent state were preserved; no setup migration or
plan deletion ran. App source is committed as `9c100e6`; a new native package has
not been built. Both changes remain on their task branches pending acceptance.


## Explicit More reasoning upgrade — 2026-11-07

Continued backend `6369764` with app `5243666`. New combined plans use Luna low
and expose reasoning_effort=low in existing session snapshots. The existing
cooking-plan endpoint accepts action=more_reasoning, session_id, installation_id,
operation_id and optional displayed current_progress. It replans with medium,
the requesting author's context and current server progress. A successful plan
update persists reasoning_effort=medium; later feedback uses that effort.
Previously created sessions retain their medium behavior when the field is absent.
No automatic escalation or UI setting was added. Normal recipe data is unchanged.

Completed offsets and timer deadlines are preserved using existing plan-update
behavior. Existing progress-change handling leaves obsolete results unapplied;
AI failures leave the current plan intact. Once medium is saved, retrying the
upgrade returns the current session without spending more provider tokens.
Replanning does not create synthetic feedback messages or personal context facts.
Low generation remains cached separately by the existing planner/context key.

48 PHP fixture checks passed, including low/default feedback, explicit medium,
continued medium feedback, saved upgrade reuse, failure preservation, progress,
timers and personal context. The real combined-plan wire scenario passes low
creation, dinner shifts, feedback, explicit medium upgrade and cooking-subscriber
broadcasts. PHP syntax and diff checks passed. Fixtures use disposable SQL and a
fake provider; no full suite or live provider evaluation was run for this change.

Backend source `6576e1f` was deployed by copying source and restarting the
existing service. All 62 checked PHP/entry/lock files match that commit;
public health returned HTTP 200/status ok. CPU policy remains CpuShares=1024
with unlimited cpu.max. Private configuration and persistent state were preserved.
Companion app source `f880128` is ready on its task branch; no APK was built.
