# Accepted chat and shopping integration — 2026-11-01

The user accepted feature/chat-direct-save and shopping-item-photo and explicitly
requested their merge, branch cleanup and a web/Android/iOS release. This branch
integrates their complete dependent history from fetched main b8c0b24.
The separate task/photo-optimization branch is preserved and excluded.

Release validation: recipe_chat.php 60, recipe_chat_legacy.php 10,
recipe_user_context.php 66, shopping photo, shopping list, shopping socket and
data socket fixtures passed. Combined socket run covered 34 scenarios: one
intentional skip, 31 initial passes and two timeouts under concurrent Flutter
memory pressure; both timed-out AI scenarios passed when rerun without that load.
No runtime source differs from the verified deployed chat-direct-save source
1fc7e95, which includes the accepted shopping photos. No migration or InMotion
PHP/backend deployment is part of this release. Existing local task branches and
tags have been synchronized; only accepted merged branches are eligible for deletion.

Integration verified: canonical main contains the accepted history and matched
local main. All eight merged feature/test branches were removed locally and
remotely after verifying ancestry and clean worktrees. Unmerged
 task/photo-optimization is retained. The frontend release is immutable tag
v2.6.0, source b260ce67a23b54938f6f71b291d04162c9ca645e, build 52.
Web and Android have been published; signed iOS upload succeeded, with Apple
processing verification recorded in the Flutter release documentation.
Production health and both domain-root association endpoints return 200.
No backend runtime source changed after the already verified deployment.
