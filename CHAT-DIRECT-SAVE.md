# Chat refinements — 2026-11-01

Branch feature/chat-direct-save starts at fetched main b8c0b24 and intentionally
integrates the deployed combined test branch f305221. Shopping photos are accepted;
that feature is preserved. Main integration is not authorized by this task.

Every recipe change request now explicitly gets the same holistic evaluation,
regardless of direct or hypothetical wording. The AI considers flavor/seasoning,
moisture/fat, texture/binding, handling, method, heat, timing, doneness, yield and
relevant private preferences. It applies sensible consequences immediately and
explains them conversationally. Full history/context and complete draft replies
remain; there is no second cooking evaluation on Save.

For variations the existing authorized local-parent-cover reader is reused.
The prepared draft carries the original cover's bytes, caption and attribution
with a new-photo ID. The normal recipe save creates an independent media file.
No image AI or arbitrary URL fetch is involved. Missing/unreadable source images
return an actionable retry error instead of silently omitting the picture.
Edits retain their existing identity, revision, source and photos.

Validation:
- Changed PHP files linted; recipe_chat.php 60 checks, recipe_variation.php 92,
  recipe_chat_legacy.php 10 and recipe_user_context.php 66 passed.
- All 14 existing AI socket scenarios passed; the new socket scenario additionally
  verifies prepare -> ordinary recipe save -> receipt replay, a byte-identical
  independent photo file, and unchanged original recipe. The new test passed
  after correcting its handling of normal snapshot broadcasts.
- Two live synthetic gpt-5.4-mini requests, “Change these to turkey burgers” and
  “What if I use ground turkey instead of beef?”, both considered moisture,
  binding, seasoning, chipotle preference, handling and poultry doneness in chat
  and updated the complete draft. Actual proposals varied naturally. Chat took
  5.09/6.41 seconds; local preparation took 7 milliseconds and zero AI calls.
  Disposable synthetic recipe/context only; no private user transcript used.

The Flutter counterpart removes View changes, replaces Review and save with Save,
skips the editor, retains shared-edit confirmation/retry receipts, and opens the
saved recipe viewer. No app build/release is authorized by “implement”.

Deployment: source 954fbfd copied with the existing deploy.py and backend
restarted. Public health returned 200; invalid AI WebSocket authentication was
rejected with 401. Deployed chat/variation source matched the task checkout;
shopping-photo service is retained unchanged. CPU shares 1024, unlimited cpu.max.
No InMotion deployment, database migration, main merge, app build or release.
