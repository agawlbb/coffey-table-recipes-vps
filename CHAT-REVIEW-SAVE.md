# Review preparation and save destination

Task branch `feature/chat-review-save` begins at fetched main
`b8c0b24f9c04981f1604f52ddc13244b9a3007e2`, then intentionally includes the
deployed database-chat-context branch through `9cb457dd9776f228f8789c617268bc55416a9d78`.
No main integration is authorized. The companion app branch has the same name.

## Failure diagnosis and handling

The user reported “AI could not finish this recipe. Try importing just the
ingredients and cooking instructions.” That exact message comes from the shared
standardizer when the provider response is not completed. Chat preparation used
the importer's 7000-token cap with medium reasoning even though the cooking draft
was already developed. The historical provider envelope was not stored, so its
precise incomplete reason is unknown. A token-budget problem is a plausible
cause, not a proven retrospective diagnosis.

[Official Responses guidance](https://developers.openai.com/api/docs/guides/reasoning)
explains that output limits include reasoning tokens and exhausted limits return
incomplete/max_output_tokens. Chat review now explicitly uses low reasoning and
a 12000-token budget. Exactly one retry with 20000 tokens is allowed only for
that reported cause, with progress feedback. Content filtering, refusals, HTTP
errors and transport failures are not automatically retried. Each review call
has a 240-second transport limit so two calls stay below the worker deadline.
Ordinary imports and standalone variations retain 7000/medium and 480 seconds.

Incomplete responses log only sanitized reason, output/reasoning token counts
and configured cap. No prompts, keys, account IDs or recipe contents are logged.
Partial replies are rejected. Chat preparation translates import errors into
guidance saying the chat/draft are still here and to retry Review and save.
Failure does not advance the chat revision or discard its working recipe.

## Review protocol

A conversation may use `mode: existing`, bound to its authorized original recipe.
Message turns do not choose variation/edit. The prompt tells AI that the user
will choose later. On prepare, this mode requires `save_as: variation` or
`save_as: edit`. The destination is part of the turn fingerprint so a successful
response cannot be replayed under another destination. A subsequent new prepare
turn can choose the other outcome without restarting the chat. Existing installed
clients using fixed create/variation/edit modes continue working unchanged.

A nonempty server-held working recipe is enough to request review; the model's
ready flag no longer blocks it. Missing essentials can become normal clarification
questions. Original revision checks, authorized recipe lookup, photo review,
variation parent binding, edit provenance/photos/shared-edit guards, database
context, replay recovery and editor-only save remain in place.

## Validation

2026-11-01: 126 chat checks, 294 import checks, 92 variation checks and 66 context
checks passed. All 14 real-socket AI tests passed against disposable SQL/fake
providers. New coverage includes review while not ready, deferred destinations,
save-destination replay rejection, preserved identity/photos, one bounded budget
retry, partial JSON rejection, failure recovery and no implicit recipe save.
A live synthetic text recipe probe using the authorized existing credential
returned completed JSON with ingredients/steps in 4.1 seconds: 822 output tokens,
including 198 reasoning tokens. This checks the actual request shape/model with
the new settings, not a reproduction of the user's lost envelope or a guaranteed
latency. No production recipe, database mutation or context change was used.
PHP syntax/diff checks passed. The companion app has 37 focused widget tests.
No app package, web deployment, release tag or main merge is included.


## Verified backend deployment — 2026-11-01

VPS source `8df3346` was deployed from this feature branch with the existing
source-copy/restart command. Private configuration hashes were unchanged and
installed runtime hashes match the source. The container is running with zero
restarts; CpuShares=1024 and unlimited cpu.max were verified. Public HTTPS health
returned 200/ok and invalid AI credentials were rejected with 401 over WSS.
No database schema/data changes, InMotion deployment or main merge occurred.
The current installed test app benefits from the preparation/error repair, but
the new review button, moving indicator and deferred save choice require the
companion app package. No additional app build was requested or started.
