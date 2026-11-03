# Cooking sessions

Task: `task/cooking-sessions`. App base `0f3b822`; VPS base `c28b633`.

Starting cooking creates a server session and joins this installation. If the
recipe has an active session, choose the existing attempt or start another.
Starting a new session requires sign-in and a connection. Session progress uses
the recipe snapshot captured at creation, so later recipe edits do not change
step/action identities during cooking.

The household navigation drawer, including recipe detail and cooking mode, lists
recipe names with only **Open** (joined here) or **Join**. Both open the first
unchecked step; subsequent remote edits preserve the viewing position. Switching
dishes keeps other joined sessions and their timers active. Long-press goes
directly to household members; tapping one shares and sends an invitation. Access
is explicit, not automatically granted to the whole household. Invited sessions
remain in the recipient's drawer until ending/expiry, even after dismissing the
notification or initial mirror invitation.

Cooking has **End cooking session** at the bottom. Ending affects all participants
and cancels session timers; running timers require confirmation. Completing the
checklist or leaving the page does not end the session. The server starts the idle
period once no joined device is viewing that session in the foreground. It lasts
one hour plus the recipe's total estimate (or prep + cook when total is absent),
with a one-hour fallback. Reopening resets it; expiry cannot precede the latest
running timer's deadline. Foreground presence renews every 20 seconds with a
60-second server lease for unexpected disconnects. A lost connection can therefore
add up to a minute of grace. Expired sessions are removed on the next server sweep
or read; a 30-second sweep publishes expiry to connected clients. Recent ended
sessions retain cancellation tombstones for seven days.

## Transport and alarms

The existing authenticated WebSocket gains a `cooking` scope and
`/me/cooking-sessions` endpoints. Server-owned per-step and per-timer revisions
reject stale edits; operation IDs make retries idempotent. Different step edits
merge independently. Snapshots reconcile reconnects and app resume. Account
changes clear client session state immediately and reject delayed responses.
Household membership is rechecked for discovery, sharing and mutations.

Timers have session-scoped IDs and absolute deadlines. Native alarm state survives
app suspension; server time offsets adjust local countdowns/scheduling. Local
mobile start/edit/cancel operations are durably queued per account, preserving
deadlines, operation IDs and expected revisions. Steps require a connection;
failed edits remain unchecked/unchanged and report an actionable error.

Initial timer delivery and progress use WebSockets. Silent pushes supplement
cancellations and changes to existing timers for joined mobile devices. They
produce no timer-sharing banners or sounds. Background delivery is best effort:
an offline, suspended or force-stopped device can miss a cancellation and ring.
Visible invitation notifications are separate. Native account, installation,
session-membership and revision guards reject unrelated or stale timer updates.
The old account-wide receiving switch and Flutter timer-sharing coordinator are
removed. Existing server timer-device registration supplies push tokens; no new
credential or database setup is required.

## Server storage

The VPS stores sessions under the existing persistent `CTR_STORAGE/cooking`
directory. One locked JSON store is replaced atomically; the lock is separate
from the replaced file. The existing one-worker server owns broadcasts. Session
snapshots are filtered per authenticated user, and cooking recipe snapshots omit
private source text and editor-only metadata. WebSocket responses/broadcasts are
sent before the best-effort push sender runs. No schema migration or new service
is introduced. Existing deployment preserves the state directory.

## Validation

No app build,
release, native installation or main integration is part of this task. Native
background behavior and OS delivery still require physical-device acceptance in
an explicitly authorized build/release.

Server checks passed on 2026-11-03:

- PHP syntax checks for `CookingSessions`, `DataSocket`, `TimerPush` and `server.php`.
- `php tests/cooking_sessions.php`: discovery, explicit invitations, device joins,
  independent step edits, stale revisions, timer targeting, request replay,
  inactivity estimates, timer deadline protection, explicit ending and revoked
  household membership, using disposable SQLite and session storage.
- `php tests/timer_push.php`: credential reuse/renewal, silent iOS/Android session
  updates and visible invitation payloads, with a fake sender transport.
- `php tests/data_socket.php`, `php tests/authentication.php`,
  `php tests/shopping_socket.php`, `php tests/timer_sync.php`: existing data,
  identity, shopping and account-timer regressions passed.
- `/home/debian/projects/coffey-table-recipes-vps/.venv/bin/python -m unittest discover -s tests -p cooking_websocket.py`:
  both real WebSocket tests passed, including the 30-second expiry sweep without
  a client write. The existing `data_websocket.py` suite's five tests also passed.
- `git diff --check` passed. No production data was used in tests.

## Hosted backend — 2026-11-03

Implementation `27705ed` was published using the existing `python3 deploy.py`
copy-and-restart process. The deployed `CookingSessions.php`, `DataSocket.php`
and `server.php` SHA-256 values match that tested source. The deployment verified
`CpuShares=1024` and `cpu.max=max 100000`. Existing private configuration and
persistent state remain outside replaced code.

Read-only smoke checks passed: hosted public configuration returned HTTP 200;
the hosted cooking WebSocket scope recognized the request and rejected an invalid
token with HTTP-equivalent status 401. No live session, production test recipe,
notification, app package or release was created. Main remains unmerged.

## Timer-range integration — 2026-11-03

Merged `task/timer-ranges` into `task/cooking-sessions`: app source `4c4f2bb`
and companion backend source `b6f8c1d`. Both task histories are preserved; main
is unchanged. The retired account-wide Flutter timer coordinator stays removed.

Range timers still start at the minimum and offer the remainder once. Session
snapshots, durable local timer operations and silent native updates now preserve
`extension_seconds`; extension operations carry their consumption flag through
the session endpoint. Server revisions and operation IDs prevent two devices
from consuming the same remainder. Manual timer changes/cancellation clear the
remainder. Native session/account guards and server-clock adjustment are retained.
Expired unused ranges can be restored without replaying an alarm. Web cooking
uses the same session extension rules.

This integration changes source only. It does not run database maintenance,
build packages, deploy either app/backend, or integrate into main. Earlier hosted
publication records above describe their respective source revisions.

Merged backend validation passed:

- PHP syntax checks for `bootstrap.php` and `src/CookingSessions.php`.
- `php tests/cooking_sessions.php`, `php tests/data_socket.php`,
  `php tests/timer_sync.php`, and `php tests/timer_push.php`.
- `php tests/recipe_ai.php` (299 checks), `php tests/recipe_working_draft.php`
  (37), `php tests/recipe_chat.php` (60), `php tests/recipe_variation.php` (92),
  and `php tests/timer_range_inventory.php`.
- Using `/home/debian/projects/coffey-table-recipes-vps/.venv/bin/python`,
  `-m unittest discover -s tests -p cooking_websocket.py` (2 tests) and
  `-m unittest discover -s tests -p data_websocket.py` (5 tests).
- No unresolved conflict markers; `git diff --check` passed. Fixtures use
  disposable databases and mocked AI/push transports, not production data.

## Merged backend test deployment — 2026-11-03

At the user's request, compared the hosted backend with merged task commit
`4d2f1f0fecda60f02e32f982151e18e5602b1be4`. Twelve source/entry/bin files differed,
so deployed that exact merged implementation with the existing `python3 deploy.py`
copy-and-restart process. All 68 checked source, bin, association and entry/lock
files then matched the task source. CPU policy verified `CpuShares=1024` and
`cpu.max=max 100000`.

Hosted `/health` returned HTTP 200 with `status=ok`; the cooking WebSocket scope
rejected an invalid token with status 401. These were read-only probes: no live
sessions, recipe edits, AI requests or notifications were created. Existing
private configuration and persistent data were preserved, and no database
maintenance/schema script was rerun. Main remains unchanged. The Android test APK
is built separately from app commit `4ba9b88ec2de9d476f1d35b6db1688677ff18503`.

## 2.7.0 release integration — 2026-11-03

The user approved including `task/photo-optimization` (`14bc218`) in this release.
The merge retains the image worker, original-image preservation, HTTP serving,
GD runtime checks and deployment preservation. Cooking session timer mutations
also accept a notification action against the exact original deadline when its
local start revision was not yet acknowledged. An action for a replaced deadline
is rejected; a consumed extension cannot be consumed again. Authentication and
joined-device authorization remain mandatory.

Validation: `php tests/cooking_sessions.php`, `php tests/data_socket.php`,
`php tests/timer_push.php`, `php tests/recipe_image_optimizer.php` (29 checks),
`php tests/http.php` (34 checks), and the existing backend virtual environment's
`python -m unittest discover -s tests -p test_deployment.py` (4 tests),
`data_websocket.py` (6 tests), and `cooking_websocket.py` (2 tests) passed.
No production data was used for these tests. Bootstrap/session PHP syntax and
diff checks passed. Release deployment and app verification are recorded in the
Flutter repository release evidence.

## 2.7.0 release deployment — 2026-11-03

Accepted cooking sessions, timer ranges and photo optimization are merged into
canonical main at `7c1cfd3`. Deployed using `python3 deploy.py`; existing GD runtime,
service restart, backend socket and CPU policy checks passed. Public health returned
`status:ok`. No inventory maintenance or database changes were rerun.

Integrated backend checks passed: cooking_sessions.php, data_socket.php,
timer_push.php, recipe_image_optimizer.php (29), http.php (34), and Python
unittest deployment (4), data websocket (6), cooking websocket (2).


## Menu swipe ending — 2026-11-03

Task `task/cooking-session-swipe`, based on main `adc5d60`, supports the companion
app task `task/cooking-ui-controls`. An authorized account can end a discoverable
session from its menu without joining that device or scheduling its alarms.
Membership/invitation access checks remain mandatory; timer, step and presence
mutations still require a joined device. Existing end cancellation and push/
WebSocket delivery are unchanged.

`php tests/cooking_sessions.php`, `php tests/data_socket.php` and
`php -l src/CookingSessions.php` passed. Added cases cover an unjoined device for
the same account, an invited account, inaccessible-account rejection, timer
mutation rejection before joining, cancellation, and no implicit join.

Deployed task source `bf7cf2e` using `python3 deploy.py` on 2026-11-03.
Service restart, socket readiness and CPU policy passed; public health returned
`status:ok`. The deployed CookingSessions.php hash matches the committed task
source. Main integration remains pending; no database changes were needed.
