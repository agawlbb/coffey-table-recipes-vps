# Recipe timer ranges

Task `task/timer-ranges`, based on fetched main `c28b633f7b46faedc9547e218f0f814417b40ed3`.
The app companion uses the same branch name, base `0f3b822afe3a6677f2ba231eaec9ac911f262d3f`.
Both worktrees are separate from the running `task/cooking-sessions` worktrees.
That task overlaps timer code and will need intentional integration; its source
and working trees were not changed by this task.

`recipe_step_actions.duration_seconds` remains the minimum; nullable
`duration_max_seconds` stores the upper bound. Saving validates positive timed
actions and maximum >= minimum. Existing actions whose unchanged duration is
submitted without the maximum retain their stored range. Explicit null clears it.
Reads, chat drafts and variation seeds preserve the maximum.

Imports normalize explicit numeric ranges (including fractional hours) and a
same-stage bounded continuation such as “cook 3 minutes, check, add up to 2 minutes”
to one 180–300 second timer. Separate stages remain separate. Unsupported upper
bounds are discarded; reviewer anecdotes, storage limits and early-check offsets
do not become ranges. No live AI/provider request was used for validation.

Running timers store `extension_seconds` in SQL and native storage. Initial
alarms use the minimum. At expiry the app offers the difference. Accepting consumes
the extension; its next expiry is ordinary. Manual timer changes clear the
extension. Expired ranges are retained in account snapshots for seven days so
opening another opted-in device can recover the offer without replaying an alarm.
The server serializes changes with existing revision checks, rejects a second
extension and replays successful retries without consuming it twice. Offline
initial range starts can be published after their minimum expires, before a queued
extension. Push payloads include the same metadata; delivery does not prove an OS
alarm sounded. No native package was built or installed.

## One-time hosted inventory — 2026-11-03

Surveyed all **69 active recipes, 526 steps and 188 actions**. Reviewed 66 candidate
ranges. Two references were excluded: a reviewer-reported baking-time range and
an offset to begin checking noodles early. Eight existing midpoint timers were
reviewed against their exact instructions and adjusted to the stated minimum.

Added the two columns with the existing database administrator after backing up
table definitions. The restricted runtime account initially denied ALTER; its
permissions were not expanded. Schema setup is separate from deployment:
`bin/timer-ranges.sql`. Normal-privilege `bin/timer-ranges.php survey|apply` is a
one-time maintenance helper, not an app feature or scheduled process.

Applied **62 existing timer updates and 2 missing range timers across 30 recipes**
in one transaction. Per-side/per-batch wording remains in instructions and
existing labels. Optional instructions remain optional. Full recipe/step/action
snapshots and the reviewed plan are private mode-0600 files under
`/opt/services/ctr-backend/state/maintenance/timer-ranges-20261103/`; none is in Git.
The first pre-schema inventory and table definitions are also retained there.

Before writing, locked recipes and compared complete current recipe/step/action
snapshots with the reviewed plan. Read back each range and compared every original
action (including label and unrelated fields) and every instruction before commit.
Post-commit checks verified instructions and exactly one revision advance per
changed recipe. A fresh survey proposed zero further automatic corrections.
Recovery can restore original action rows and remove precisely the two inserted
rows from the private snapshots after checking for subsequent edits; do not
blindly overwrite later user changes.

## Validation

- `php tests/recipe_ai.php`: 299 checks, including range endpoints, conditional
  additions, fractional hours, separate stages and invented upper bounds.
- `php tests/recipe_working_draft.php`: 37 checks.
- `php tests/recipe_chat.php`: 60 checks; `php tests/recipe_variation.php`: 92 checks.
- `php tests/timer_sync.php`: existing isolation/replay checks plus expiry retention,
  extension consumption, replay and competing-extension rejection.
- `php tests/timer_range_inventory.php`: disposable SQLite; exclusions, missing
  timers, stale-edit rollback, label/instruction preservation and repeat survey.
- `php tests/data_socket.php`: range save/load, omitted maximum preservation,
  invalid-range rollback, existing recipe/meal/timer access and replay checks.
- `php tests/timer_push.php` and `php tests/recipe_save_receipts.php` passed.
- Changed PHP syntax checks and `git diff --check` passed.

No main integration, app build, release or hosted Flutter deployment is included.

## Backend publication and deployment

Backend source `f16cc62` was pushed to `origin/task/timer-ranges` and deployed with
the existing source-copy deployer. Before deployment, installed source matched
fetched main, so no independently deployed Cooking sessions source was replaced.
After deployment, all 65 checked source/bin/entry/lock files matched this task.
Persistent maintenance snapshots remain present outside replaced code. The backend
is running with zero restarts, CPU shares 1024 and unlimited `cpu.max`; public
HTTPS `/health` returned status `ok`. No runtime image was rebuilt.

Five real WebSocket tests passed with
`/home/debian/projects/coffey-table-recipes-vps/.venv/bin/python -m unittest discover -s tests -p data_websocket.py`.
Final hosted checks confirmed 64 ranged actions across 30 recipes, all 526 active
instructions unchanged, and unchanged action records in the other 39 recipes.
The separate app branch passed affected-file analysis and 102 distinct focused
Flutter tests; see its `docs/timer-ranges.md` for exact commands and the interrupted
initial combined run. Device/native alarm execution remains unverified.

Both task branches remain pending explicit main-integration approval. The backend
is deployed, while the Flutter app requires a separately authorized build/release.
