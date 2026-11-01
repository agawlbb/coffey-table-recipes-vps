# Restore conversational recipe reasoning — 2026-11-01

Task branch: feature/restore-recipe-reasoning. Based on fetched main b8c0b24,
with feature/structured-recipe-chat (34b26f0) intentionally integrated to retain
SQL cooking context and the existing deployed draft/review work. No main merge.

This supersedes the compact patch protocol in STRUCTURED-RECIPE-CHAT.md.
The cooking and private-context policy paragraphs from 98a82fa are restored
verbatim, with a fixture comparison. Full history, all household-scoped user
preferences, original recipe, current draft and previous draft reach the model.
The model and 10000-token output budget are unchanged; forced low reasoning,
preference ranking and short-history truncation are removed.

Only the draft representation changes: each reply carries the complete recipe
in editor fields rather than free text or patches. The existing import schema
supplies types, not its extraction instructions: those instructions prohibit
new ingredients and methods and would interfere with cooking adaptations.
The original conversational instructions govern cooking judgment. Cooking
explanation belongs in chat; the app labels its separate recipe tab Draft recipe.

Review and save imports the current draft locally without cooking reevaluation,
reconstruction or image generation. Variation/edit choice remains at Review.
Edit preserves trusted identity, revision, photos and source; a variation has
its trusted parent and independent identity. Existing structured sessions are
retained. Old text-only sessions require one format conversion, explicitly
instructed to preserve the agreed cooking decisions, then review is local.
Private cooking context remains in SQL. No schema/privilege changes.

Validation:
- PHP lint on changed service files.
- recipe_chat.php: 56 checks, including original policy/settings, full context,
  complete recipe history, replay/failure atomicity, metadata privacy and local review.
- recipe_chat_legacy.php: 10; recipe_user_context.php: 66.
- recipe_ai.php: 294; recipe_variation.php: 92; recipe_methods.php: 25.
- ai_websocket.py: all 14 real socket/worker tests passed with disposable SQL and fake provider.
- Flutter: changed-file analysis passed; all 30 recipe_chat_test.dart tests passed.
- Live synthetic gpt-5.4-mini/default-reasoning turkey-burger conversation:
  discussed lean meat/dryness, texture, seasoning and remembered chipotle preference;
  updated draft seasoning, handling, heat and 165F internal temperature.
  Chat 3.75 seconds; local Review 0.007 seconds, zero provider calls/tokens;
  reviewed ingredients exactly matched the displayed draft. Model prose remains
  nondeterministic; this is a regression observation, not a guarantee of wording.
  No private user chat/recipe data was used or retained by the probe.

No APK build or app release is part of this task. Installed build 50 understands
this backend response shape; the Draft recipe label requires a subsequent app build.
