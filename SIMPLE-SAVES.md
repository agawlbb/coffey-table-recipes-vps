# Last update wins

Task branch starts from backend `d122df5`. This change is not deployed.

Saves no longer compare a submitted recipe, meal-plan, shopping, restaurant,
step or timer edit version against stored state. The last submitted save wins.
A later recipe save recreates child rows that an intervening save removed.
Preparing a recipe from a chat is not blocked because its original recipe changed.

Shopping full-list saves overwrite the list. Offline removals use `remove_ids`
to remove only those identities from the current list, including when repeated.
Items added later with different identities survive. Old merge/rebase bookkeeping
and shopping receipt storage are removed. The existing request-ID acknowledgment
field remains for shopping clients; it is an echo of the committed request,
not a comparison against a data version.

Authentication, membership, valid-input checks, transactional writes, recipe
creation receipts and existing timer behavior are retained. Database counters
needed by existing history/timer delivery formats are still produced, but saves
are not rejected for carrying a stale counter or no counter. AI conversation
turn identifiers still identify a conversation request; they do not prevent
saving a recipe that changed on another device.

Snapshot errors send a safe error code before closing, so clients log an actual
failure and reconnect. Logs contain exception class/code rather than private
exception messages. Account reads close their cursors before starting snapshot
transactions. The four-client SQLite protocol test exposed the lingering cursor
as SQLITE_BUSY; closing it fixes the disconnect without another retry mechanism.

Validation: ten PHP fixture programs pass (shopping, sockets, meal plans, recipe
editing/deletion, data contracts, timers, cooking, creation receipts, authentication,
and accounts), plus recipe-chat tests. Unified app, account, data, shopping and
cooking websocket checks pass against disposable fixtures. The optional actual
Home Assistant client check is separate; no HA installation or production state
is changed by these tests. App-side validation is in docs/simple-offline-saves.md
in the app repository.

Creation alone uses persistent delivery receipts. Edits never replay an old
receipt instead of updating the server: explicitly resubmitting an offline
edit is a new write and must overwrite what is there now.
