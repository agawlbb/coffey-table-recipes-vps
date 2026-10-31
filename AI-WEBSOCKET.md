# VPS AI execution

Branch `feature/vps-ai-websocket` starts at fetched `origin/main` `b977c8f`.
Matching app and InMotion branches start at `2920c38` and `c5503a3`.
Do not merge these branches until the user approves.

The existing `/shopping` WebSocket accepts `scope: ai` with a Firebase token and
an optional household ID. Household requests require that household; parent
category administration checks the account administrator role. HA credentials
cannot use this scope. Each request and the final result recheck account and
household access. Image permissions retain their existing checks.

AI request paths are recipe-import (including existing answers), recipe-photo,
recipe-category, category-image, recipes/{id}/variation and admin/parent-categories
(including its category-image route). Requests retain their existing JSON bodies
and result shapes. An `id` identifies each request, progress and final response.
Progress frames contain `type: progress`, `id`, `message` and `elapsed_seconds`.
The socket stays open for later clarification turns; no conversation data is
logged or sent to other household subscribers.

AiJobs starts one isolated PHP child per active connection, at most four children
in total. It polls private pipes without blocking the socket event loop, relays
actual execution stages and elapsed-time updates, and stops children when their
connection closes or the ten-minute limit expires. Provider requests may run up
to eight minutes. Crashes return a typed error rather than leaving a request
pending. Ordinary synchronization continues in the existing worker.

The previously uploaded credential stays at
`/opt/services/ctr-backend/private/recipe-ai.local.json`, mode 600, mounted read-only
as `/run/ctr-private/recipe-ai.local.json`. Runtime configuration pins the existing
recipe model and preserves private access/extractor settings. No credential is
copied into source, the app, a socket frame, or a log. Tests use dummy credentials
and disposable SQLite, with no production SQL or paid provider calls.

Before deploying accepted source, rebuild the existing runtime image from the
updated Dockerfile to include DOM, Poppler and Tesseract, then use `deploy.py`.
Deployment requires the AI credential and preserves private/state directories.
New images persist in `state/media/recipes`; the restricted existing HTTP listener
also serves `category-{id}.jpg`. Old media stays on InMotion. Variation cover
review reads only authorized stored keys, from VPS storage or the fixed original
media host. No database migration is needed.

The VPS runtime from source commit `3bbd772` is deployed from this branch on the
user's explicit instruction. The branch remains unmerged. Shared hosting and app
deployments are unchanged. Deployment evidence is recorded in VALIDATION.md.
