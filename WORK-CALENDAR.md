# Home Assistant calendar dates

Task `feat/work-calendar`, base `fde81a0`. Companion Flutter and HA work uses the
same task branch name in their existing repositories. Main integration is pending.

Run `bin/work-calendar.sql` once with the authorized schema maintenance identity
before deploying the changed backend. Routine source deployment must not run it.
The initial implementation was source-only. The owner subsequently authorized
backend setup/deployment, HA installation and a temporary Android build.
Deployment verification is recorded below.

The existing HA shopping connection can post `{"dates":["2026-11-10"]}` to
`/households/<id>/work-calendar` over its authenticated WebSocket. This replaces
only that household's occupied dates. Titles and event content are not accepted.
Revocation and household isolation use the existing connection checks.

Household app connections can read the same path and post `{"color":"#1976D2"}`.
App writes cannot publish dates, and HA writes cannot change color. The default
color is `#E09F3E`. Dates and color are included in the household library snapshot;
the existing write broadcasts refresh connected apps and change snapshot hashes.
HA uses its local timezone, exclusive event ends, event subscriptions/state change
notifications and a 60-second fallback poll. Its rolling window spans the previous
366 days and the next 730 days. Clearing the selection empties the dates.

Validation: `php tests/work_calendar.php`, `php tests/data_socket.php`, and
`php tests/meal_plans.php` passed. Syntax checks passed for WorkCalendar,
ShoppingSocket, DataSocket and HouseholdSnapshot. These use disposable SQLite
fixtures, including connection revocation, household isolation, replacement and
clearing of dates, color/date independence, application access and snapshots.
The MariaDB schema migration and a live HA/app/backend round trip remain rollout
checks; passing fixture tests does not establish those have been performed.

## Authorized backend setup and deployment — 2026-11-10

Deployed source commit `a5da643a9ccbb531435ff1847b320770f7d7b9f3` from
`feat/work-calendar`; no main integration. A read-only preflight confirmed the
household key is BIGINT UNSIGNED and the calendar table was absent. Applied the
committed additive SQL once; the resulting table was empty and its household
foreign key was verified. Existing tables and data were not modified by setup.

Compared the live backend before copying: only the three modified socket/snapshot
files, new WorkCalendar.php and bootstrap differed. Copied source and the setup
SQL, and restarted only backend. Dependencies, runtime image, private settings and
persistent data were retained. The first start failed because the new PHP file
inherited mode 600; corrected the two new public source/SQL files to 644 and
restarted successfully. The health endpoint returned HTTP 200/status ok; deployed
src and bootstrap checksum comparisons were empty. CPU shares remain 1024 and
cpu.max is `max 100000`. No fixtures ran against production data.

## Calendar connection repair — 2026-11-10

Task `fix/calendar-connection`, based on backend main `44c1261`; companion app
fix starts at `09e5894`. The released app omitted work-calendar from its socket
route list, so calendar color writes used HTTP and received Endpoint not found.
The durable color write retried on foregrounding and showed the existing local
save/send-error notice. The client fix sends that endpoint over the authenticated
shared WebSocket and retains the pending user change.

Calendar reads are optional decoration inside HouseholdSnapshot. A calendar read
exception is logged with its exception class, without account data or SQL details;
the snapshot supplies empty dates/default amber while the calendar is unreadable.
Recipe/account/shopping/timer data still bootstrap and broadcast. Recovery restores
the stored dates and color on the next snapshot. Stored calendar data is not
changed by fallback, and authorization remains outside that optional read.
This isolates calendar read exceptions; it is not a guarantee against unrelated
server/network/database failures or a stalled database query.

Validation: three new real-loopback WebSocket checks cover malformed calendar
JSON at initial authentication, a missing calendar table, and calendar failure
during broadcast followed by recovery on the same connection. The existing
one-connection bootstrap/bidirectional scenario and PHP work_calendar/data_socket
fixtures passed. PHP syntax and diff checks passed. Only disposable SQLite data
was used; no full suite or app build was run.

Deployment of repair source `1439d90` was authorized by the standing owner backend
repair/deployment instruction. Preflight found only HouseholdSnapshot.php changed
in live source. Copied that file with mode 644 and restarted only backend; no
schema setup, dependency/image change, private settings or data modification.
Health returned HTTP 200/status ok, deployed source checksum comparison was empty,
CPU shares remain 1024 and cpu.max is `max 100000`. Client foreground color retry
still requires the corrected app build; deploying this guard does not repair the
old client's HTTP routing. Main integration remains pending owner acceptance.
