# Retire InMotion CTR web hosting

Task branch `task/retire-inmotion-ctr`, based on main `a47e39b`.
The user authorized backup and retirement on 2026-11-05. Main integration remains
pending explicit approval. The active VPS MariaDB database is outside this work.

Invitations still bind to the recipient's verified email and are accepted or
declined in the installed app. Their emails now direct recipients to open the
app, without a web destination. Firebase still verifies email addresses; generated
verification links omit the InMotion continuation URL. Users manually return to
the app, which checks verification on resume. Rendered recipe pages retain their
recipe content, canonical links and media but omit the web-app household action.

Before deletion, the complete hosted `coffeybean.com` website and fresh exports
of both retained CTR databases are compressed and verified on Samba. This includes
the old CTR files, hidden configuration, originals and historical archives.
The broader site backup protects context; unrelated hosted projects are retained.
The backup uses the Samba share's existing permissions, as requested.

Backup folder: `/mnt/ha-share/CTR-InMotion-retirement-20261105T140637Z/`.
`manifest.json` records archive sizes, SHA-256 hashes and integrity checks;
`SHA256SUMS` allows later read-back verification. The site archive contains private
credentials and the SQL exports contain personal data; neither belongs in Git.

Removal scope: the dedicated `CoffeyTableRecipes` directory, CTR-only domain-root
Android/iOS association files, the two retained CTR databases and temporary export
artifacts. The SMTP mailbox, its domain/DNS configuration and unrelated projects
are preserved. Old InMotion deployment workflows are disabled to prevent content
from being republished. Installed mobile clients and Home Assistant use the VPS.

Validation in the development container: invitation email (14), verification (14),
account configuration, account flow (25), remaining account/HA (21), rendered
HTTP/media/HA bootstrap (34). Fixtures send no email and use no production data.

Account WebSocket fixture tests also passed (10), covering worker execution and
progress delivery with the updated account-service constructor.
