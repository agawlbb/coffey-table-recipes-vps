# VPS AI execution

Branch `feature/vps-ai-websocket` starts at fetched `origin/main` `b977c8f`.
Matching app and InMotion branches start at `2920c38` and `c5503a3`.
The user approved integration and branch removal on October 31, 2026. Completed
source is integrated into main and the VPS is deployed from main. No app build,
shared-hosting deployment or additional rollback copy is requested.

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

The deployed runtime includes the artwork correction from source commit `0dfda05`.
The user has approved integration and VPS redeployment from main. Shared hosting
and app deployments are unchanged. Deployment evidence is recorded in VALIDATION.md.

## Automatically generated subcategory artwork

Recipe saves acknowledge the committed recipe first. The VPS then finds assigned
imported subcategories without their own artwork and starts an existing AI worker
to generate their covers. Completion broadcasts a new household snapshot, so this
does not depend on app-side category IDs or a category-image follow-up request.
This post-save job continues if the saving app disconnects; interactive AI requests
retain their existing connection-bound cancellation. Household image permissions
are checked before scheduling and again in the image service. Existing owned covers,
including those hosted on InMotion, are reused without a new provider call.

New subcategories start with an empty image key, displaying the existing neutral
placeholder until artwork is available, rather than inheriting a misleading parent
picture. Generation failures preserve the committed recipe and can retry on its
next save. No new UI, app build, schema migration or deployment backup is required.
