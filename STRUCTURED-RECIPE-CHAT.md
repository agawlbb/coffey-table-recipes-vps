# Structured recipe chat and token accounting

Task branch `feature/structured-recipe-chat` starts at fetched VPS main
`b8c0b24f9c04981f1604f52ddc13244b9a3007e2`, then intentionally includes deployed
chat review/save and SQL context through `98a82fa60f1838e282e3eaca058bf6c5dd15b076`.
The companion app branch starts at fetched main
`9c840e26b3b61bbdcb7f0938986e6bcb3d006f6c` and includes prior app work through
`155faa4652d8ce12c6c8893cf8dc78aa381194dd`. No main integration is authorized.

## One cooking document

Each conversation has one authoritative `working_recipe`: cooking metadata,
ingredient rows/quantities/component titles, steps/actions, category labels and
concise variation metadata. It contains no database IDs, photographs, source
identity or nutrition. Editor row IDs/groups/action links are generated locally
using the existing import normalization. `recipe_text` is a derived display/source
view for installed clients, never an independent working specification once the
structured document exists. No new database schema, app endpoint or dependency
is introduced. User cooking context remains in the existing private SQL table.

AI returns message, show_recipe, recipe_action (patch/keep/undo), changes and
context_updates. For keep/undo, changes must be empty. A patch has at most 100
operations with op set/insert/remove, an allowlisted path, and a JSON-encoded
value_json string (null for remove). This small format supports metadata, lists,
list rows and individual row fields. It avoids repeating a large union schema or
a complete structured recipe for a small adjustment. Whole-list replacement is
available for initial creation/genuine rewrites. All paths, values, bounds, rows,
quantities, groups, actions and categories are validated before any state commit.
The existing recipe/editor quantity and timer normalization is reused. Invalid
patches or learning deltas change neither draft, undo history nor chat revision.
Canonical row field order prevents a JSON ordering-only change consuming undo.

Up to five prior structured snapshots are stored on the server. An explicit
conversational undo makes AI return only an undo decision and concise reply; the
server restores the snapshot exactly, even across explanation-only turns. Old
recipes are never sent to AI for undo. Undo never reverses durable preferences.

## Bounded AI input

The provider receives one current structured recipe, scoped cooking preferences,
small category/title reference data, undo availability, and at most eight recent
human/prose messages totaling 10000 characters. The latest request is retained
in full (existing 4000-character limit). Historical structured assistant blobs
are reduced to their message. There is no separate full original recipe, previous
recipe, old patches or sequence of full recipe drafts in model context. The full
human conversation stays in the local chat cache; only provider history is bounded.
Cached responses remain for exact retries and historical evidence.

When preferences are numerous, up to twelve optional English Likes/Prefers facts
are ranked by overlap with the current request/working recipe. Restrictions,
negative preferences and unknown/non-English kinds are always retained; the
server never guesses that an allergy/restriction is irrelevant. Explicit memory
management sees the complete household-scoped set, so corrections and forgetting
can reference existing fact IDs. Small profiles are sent intact. Existing
per-user/family/household privacy and live authorization checks are unchanged.

## Local Review

Review checks source revision/access and validates/assembles editor data locally.
A structured draft makes ZERO provider calls for Review, including switching
variation/edit or retrying a completed preparation. Missing essential fields get
a local question naming only what is absent. The app returns to Chat so that
question is visible. Save still uses the existing editor endpoint/revision/shared
edit safeguards; AI never persists recipe data. Explicit category changes follow
the working draft; unchanged categories/provenance/relationships are preserved.

New variations open without the original photo. There is no delayed visual AI
assessment or automatic reuse of a possibly misleading image; photos can be
added using the existing editor. Editing an existing recipe retains its photos.
Variation title/language/parent and stable import identities are assembled from
trusted source metadata and the current cooking draft.

Real text-only sessions already exist on installed clients. Their first Review
may perform ONE conversion of the earlier text (with the existing bounded retry
if the provider reaches its output cap). This is explicitly marked legacy in
usage accounting. The result is saved as the authoritative structured recipe;
later reviews are local. Alternatively the next normal chat turn initializes
structured data from legacy_draft in that same call, without a separate conversion
request. The old cached text/turns are retained. A snapshot predating that
bootstrap cannot be reconstructed locally, so the first bootstrap does not offer
a fabricated undo to an empty/old original recipe. Subsequent changes have exact
server snapshots. No full-cache migration or automatic schema job is run.

## Measurements

Private `state/recipe-chat-metrics.log` records operation, anonymous conversation
key, model, status, cache/legacy flags, provider calls, elapsed milliseconds and
actual input/output/reasoning/cached-input token usage. It never records user IDs,
keys, recipe content or conversation text. The file is 0600 and bounded to 2 MiB.
Missing provider usage is null/unknown, never a false zero. Local Review and
response replays record zero calls/tokens. Reasoning tokens are already included
in output tokens; cached input is already included in input tokens.

Run `php bin/report-recipe-chat-usage.php` in the runtime to summarize retained
operations, legacy conversions, calls/tokens, errors, cache hits, unknown usage
and average uncached elapsed time. This is operational measurement of the retained
log, not lifetime billing or a pricing estimate. No unsupported historic savings
percentage is claimed: prior full-session token usage was not collected.

A live synthetic session with the existing authorized model/credential passed:

| Operation | Input | Output | Reasoning (part of output) | Server ms | Calls |
| --- | ---: | ---: | ---: | ---: | ---: |
| Create egg soup | 1316 | 508 | 99 | 3423 | 1 |
| Change egg quantity | 1597 | 178 | 61 | 1427 | 1 |
| Undo the change | 1632 | 51 | 14 | 1509 | 1 |
| Review | 0 | 0 | 0 | 5.8 | 0 |

The three chat calls total 4545 input and 737 output tokens (including 174
reasoning tokens). Undo restored the first structured draft exactly. Review
opened the already-prepared editor data with no AI. These are illustrative dev
measurements, not a device/network latency guarantee or an old/new session cost
comparison. No production recipe/context was used in the probe.

## Validation

2026-11-01: 37 working-document checks; 92 structured chat checks; 10 legacy
conversion/report checks; 66 SQL cooking-context checks. The 14 real-socket AI
tests passed with disposable SQLite/fake providers. PHP syntax/diff checks passed.
Focused Flutter checks passed 39 tests across chat/import-method/variation files;
changed Dart files were formatted and analyzed without issues. The live synthetic
probe above additionally exercised the strict schema and real provider.
No app package, web deployment, release tag or main merge is included.
