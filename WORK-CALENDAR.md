# Home Assistant calendar dates

Task `feat/work-calendar`, base `fde81a0`. Companion Flutter and HA work uses the
same task branch name in their existing repositories. Main integration is pending.

Run `bin/work-calendar.sql` once with the authorized schema maintenance identity
before deploying the changed backend. Routine source deployment must not run it.
No production schema or deployment is performed by this source implementation.

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
