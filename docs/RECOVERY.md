# Disaster recovery

Every procedure here has been run for real, not only written down. The date
and result of the last drill are at the end of each section.

## What is backed up, where

| What | How | Where | Retention |
|---|---|---|---|
| Each site's files | restic, nightly 02:00 + up to 90 min | append-only rest-server on the control host | 7 daily, 4 weekly, 3 monthly; 30 days after a site is deleted |
| Each site's database | `mysqldump --single-transaction`, same run | same | same |
| The control plane (database, `.env`, escrowed passwords, `/opt/codeinchrome/etc`) | sqlite `.backup` + restic, 06:15 | same, `control` repository | 14 daily, 8 weekly, 6 monthly |
| All of the above | rsync, 06:15, write-only and **no deletion** | replica on h4, `/srv/backups-replica` | grows only |

Customer hosts can **add** snapshots and never remove one. Retention runs only
on the control host. The replica refuses deletion from the control host too.

## The one secret you must keep

`CIC_CONTROL_BACKUP_PASSWORD` in the operator's `.env`. It opens the control
plane's backup, which contains every other key. Keep a copy in a password
manager: if this laptop and the control host were lost together, it is the
only way back.

## A site needs restoring

On the site's host:

```bash
/opt/codeinchrome/bin/cic-backup list <site-id>          # snapshots, as JSON
/opt/codeinchrome/bin/cic-backup restore <site-id>       # latest files + database
/opt/codeinchrome/bin/cic-backup restore <site-id> <id>  # a specific files snapshot
```

The container is stopped for the restore and always started again. Files are
restored beside the live copy and swapped in only when complete; the database
is dropped and reloaded. If it fails after the database was dropped, it says so
and names the stage - restore again.

*Drilled 2026-09-22 on h3: a deleted file, an overwritten row and a new table
were all put back; the site's own PHP read the restored database.*

## A customer host is lost

On the control host, with the escrowed password:

```bash
set -a; . /opt/codeinchrome/escrow/<host>.env; set +a
export RESTIC_REPOSITORY=/srv/backups-rest/<host>
restic snapshots --tag site:<site-id>
restic restore latest --tag site:<site-id>,kind:files --target /tmp/restore
restic dump latest /<site-id>.sql --tag site:<site-id>,kind:db > /tmp/<site-id>.sql
```

Then provision the site on another host and load both into it.

*Drilled 2026-09-22: a site's files and database recovered using only the
control host.*

## The control host is lost

Everything is on the replica (h4), and the operator's password opens it:

```bash
ssh root@<replica>
export RESTIC_PASSWORD=<CIC_CONTROL_BACKUP_PASSWORD from the operator's .env>
export RESTIC_REPOSITORY=/srv/backups-replica/control
restic --no-cache restore latest --tag control --target /tmp/control
# /tmp/control/var/backups/cic-control/control.sqlite   the control database
# /tmp/control/srv/control/.env                          APP_KEY, agent tokens, API keys
# /tmp/control/opt/codeinchrome/escrow/<host>.env        each host's backup password
```

With the escrowed passwords, every host's repository on the replica opens:

```bash
set -a; . /tmp/control/opt/codeinchrome/escrow/<host>.env; set +a
RESTIC_REPOSITORY=/srv/backups-replica/<host> restic --no-cache snapshots
```

To rebuild: bring up a new control host, run `infra/deploy-control.sh`, put
the recovered `control.sqlite` at `/var/lib/codeinchrome/control.sqlite` and
the recovered `.env` at `/srv/control/.env`, run
`infra/setup-backup-server.sh`, and copy the replica's repositories back into
`/srv/backups-rest/`.

*Drilled 2026-09-22 from h4 with only the operator's password: control
database recovered (integrity ok; users, sites, site_domains, subscriptions),
APP_KEY and all three agent tokens, all three escrowed passwords; every host's
repository opened (h1: 2, h3: 6, h4: 2 snapshots); a site's database row
recovered.*

## Known limits

- **The replica only grows.** Pack files pruned on the primary stay there.
  Compact it occasionally with the escrowed passwords (`restic prune` against
  each replica repository).
- **Files and database are taken seconds apart**, not at one instant.
- **One replica.** Object storage (R2, S3) as a third copy is a change to
  `RESTIC_REPOSITORY` and nothing else.
