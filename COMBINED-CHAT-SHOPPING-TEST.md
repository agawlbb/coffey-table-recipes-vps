# Combined Android test backend — 2026-11-01

Temporary test/chat-and-shopping-photos branch, created from fetched main b8c0b24.
Intentionally combines recipe conversation 80b9ca2 and shopping photos 589dfbe.
Both original feature branches and main are preserved. No feature acceptance,
main integration, database schema change or app release is implied.

Existing production runtime already provides GD, JPEG, PNG, WebP and EXIF;
verified directly before rollout. No runtime rebuild is needed. Source deployment
uses existing deploy.py, preserving private configuration and persistent state.

Focused checks: recipe_chat.php (56), shopping_photos.php (orientation, resize,
metadata stripping, transparency, decode limits), shopping_lists.php,
shopping_socket.php and data_socket.php passed; composer validation passed.
