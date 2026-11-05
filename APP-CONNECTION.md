# Shared app connection

The app scope carries account, household, shopping, recipes, meal plans, cooking and timer traffic over one authenticated foreground websocket. Initial authentication sends current state; mutations push changed domain state before acknowledging the originating command. Existing public and integration scopes remain available.

Account worker responses return through this same socket. Client account commands are serialized instead of overlapping startup reads. Normal housekeeping only pushes cooking state when expiry changes it. Reauthentication reuses the existing socket. Existing authentication and household membership checks apply. Anonymous app connections can only use public endpoints.

Validation: unified websocket tests cover one-connection bidirectional traffic, four-client meal-plan updates, four-client account commands, anonymous public reads/private rejection and account isolation. Existing account/data/cooking protocol and PHP checks also pass. SQLite test fixtures use BEGIN IMMEDIATE to represent the row-locking transactions used in production.

Deploy by copying source and restarting with the existing deploy.py. No schema or runtime image change. First launch of app 2.7.6 clears old local caches and pending edits; server records remain untouched.
