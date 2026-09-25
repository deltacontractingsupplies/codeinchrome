#!/usr/bin/env bash
#
# Back up the control plane itself, and replicate every backup off its host.
#
#   infra/setup-control-backup.sh
#
# Idempotent. Two jobs, both on the control host, nightly after retention:
#
# 1. cic-control-backup: an encrypted restic snapshot of what cannot be
#    rebuilt - the control database (a consistent sqlite .backup, never a copy
#    of the live file), the application's .env (APP_KEY and every credential),
#    the escrowed repository passwords, and /opt/codeinchrome/etc.
#    Its password is NOT kept only on the control host: it is written into the
#    operator's local .env (gitignored) as CIC_CONTROL_BACKUP_PASSWORD. Losing
#    the control host must not mean losing the key to its own backup.
#
# 2. cic-replicate: every repository (all customer hosts' and the control
#    plane's) rsync'd to a second host. They are restic repositories, so the
#    replica host can read nothing in them. The control host's key there is
#    pinned by rrsync to one directory, write-only, and with deletion refused
#    (-no-del): the replica only ever GROWS. A compromised control host can
#    add files but cannot remove a single one, so it cannot take the replica
#    down with it. The cost is that pack files pruned on the primary stay on
#    the replica; restic tolerates unreferenced packs, and the operator can
#    compact the replica with the passwords in the control backup.

set -Eeuo pipefail
cd "$(dirname "$0")/.."
. infra/hosts.env

control_ip=${CIC_CONTROL_HOST##*:}
replica_name=${CIC_BACKUP_REPLICA:-h4}
replica_ip=$(tr ' ' '\n' <<<"$CIC_HOSTS" | awk -F: -v n="$replica_name" '$1==n{print $2}')
[[ -n $replica_ip ]] || { echo "replica host $replica_name is not in CIC_HOSTS" >&2; exit 1; }

for forbidden in $CIC_FORBIDDEN_HOSTS; do
  [[ "$replica_ip" == "$forbidden" || "$control_ip" == "$forbidden" ]] && { echo "REFUSING: production host" >&2; exit 1; }
done

ok()  { printf '\033[32m  ok\033[0m %s\n' "$*"; }
die() { printf '\033[31mFAIL\033[0m %s\n' "$*" >&2; exit 1; }
control() { ssh -o ConnectTimeout=20 -o StrictHostKeyChecking=accept-new "root@$control_ip" "$@"; }
replica() { ssh -o ConnectTimeout=20 -o StrictHostKeyChecking=accept-new "root@$replica_ip" "$@"; }

# ── the control backup's password: generated once, held by the operator too ──
if ! grep -q '^CIC_CONTROL_BACKUP_PASSWORD=' .env; then
  printf '\n# Password for the control plane'"'"'s own backup. Kept here because the\n# control host must not be the only holder of the key to its own backup.\nCIC_CONTROL_BACKUP_PASSWORD=%s\n' \
    "$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')" >> .env
  chmod 600 .env
fi
password=$(grep '^CIC_CONTROL_BACKUP_PASSWORD=' .env | cut -d= -f2-)
control "umask 077; printf 'RESTIC_PASSWORD=%s\nRESTIC_REPOSITORY=/srv/backups-rest/control\n' '$password' > /opt/codeinchrome/etc/control-backup.env"
ok "control backup password held on the control host AND in the operator's .env"

# ── job 1: the control plane's own backup ────────────────────────────────────
control 'DEBIAN_FRONTEND=noninteractive apt-get install -y -qq sqlite3 >/dev/null'
control 'cat > /opt/codeinchrome/bin/cic-control-backup' <<'SCRIPT'
#!/usr/bin/env bash
# Nightly snapshot of the control plane. See infra/setup-control-backup.sh.
set -Eeuo pipefail
set -a; . /opt/codeinchrome/etc/control-backup.env; set +a
# The server user's cache, for the commands run as that user. Root-run restic
# gets --no-cache: root writing into this cache would leave files the server
# user cannot read, and break retention on the next run.
export RESTIC_CACHE_DIR=/var/cache/cic-restic
install -d -m 0700 -o restserver -g restserver "$RESTIC_CACHE_DIR"
stage=/var/backups/cic-control
install -d -m 0700 -o codeinchrome -g codeinchrome "$stage"
# sqlite's online backup API: a consistent copy while the app keeps writing.
# Copying the live file with cp can capture a torn page mid-transaction.
# As codeinchrome, never root: the database runs in WAL mode, and if root
# opened it first it would create control.sqlite-shm as root, which the app
# (codeinchrome) could then not write - every sign-in would fail.
as_app() { runuser -u codeinchrome -- "$@"; }
# A copy left by a run that failed part-way (root's, before this ran as the
# app) would make the next copy "attempt to write a readonly database".
rm -f "$stage/control.sqlite"
as_app sqlite3 -cmd '.timeout 10000' /var/lib/codeinchrome/control.sqlite ".backup '$stage/control.sqlite'"
as_app sqlite3 "$stage/control.sqlite" 'PRAGMA integrity_check' | grep -qx ok \
  || { echo "the database copy failed its integrity check; not backing it up" >&2; exit 1; }
as_owner() { setpriv --reuid=restserver --regid=restserver --init-groups -- "$@"; }
[[ -f $RESTIC_REPOSITORY/config ]] || as_owner restic init >/dev/null
# A run that died leaves its lock behind, and every later run refuses: that
# stopped this backup for 32 hours (2026-09-22/24) with nothing but a failed
# unit to show for it. unlock removes only STALE locks (a dead process, or
# older than 30 minutes); a live run's lock stays.
restic --no-cache unlock --quiet
# restic reads the files as root (they are root-only); the repository must end
# up owned by rest-server's user, so ownership is restored afterwards.
restic --no-cache backup --quiet --tag control \
  "$stage/control.sqlite" /srv/control/.env /opt/codeinchrome/escrow /opt/codeinchrome/etc
chown -R restserver:restserver "$RESTIC_REPOSITORY"
as_owner restic forget --quiet --tag control --keep-daily 14 --keep-weekly 8 --keep-monthly 6 >/dev/null
rm -f "$stage/control.sqlite"
# Proof of a complete run, read by the control plane's monitoring (as the
# app's user, so it can read it; never written on failure - set -e).
as_app touch /var/lib/codeinchrome/control-backup.ok
SCRIPT
control 'chmod 0750 /opt/codeinchrome/bin/cic-control-backup'
ok "cic-control-backup installed"

# ── job 2: replication to a second host ──────────────────────────────────────
control 'test -f /root/.ssh/cic_replica_ed25519 || ssh-keygen -q -t ed25519 -N "" -C "codeinchrome-replica" -f /root/.ssh/cic_replica_ed25519'
pubkey=$(control 'cat /root/.ssh/cic_replica_ed25519.pub')
replica "PUBKEY='$pubkey' bash -s" <<'REMOTE'
set -Eeuo pipefail
id -u cicreplica >/dev/null 2>&1 || useradd -r -m -d /home/cicreplica -s /bin/sh cicreplica
install -d -m 0700 -o cicreplica -g cicreplica /srv/backups-replica
install -d -m 0700 -o cicreplica -g cicreplica /home/cicreplica/.ssh
# rrsync pins the key to one directory and to rsync alone; -wo makes it
# write-only, so the control host cannot read the replica back through it.
printf 'command="/usr/bin/rrsync -wo -no-del /srv/backups-replica",restrict %s\n' "$PUBKEY" > /home/cicreplica/.ssh/authorized_keys
chown cicreplica:cicreplica /home/cicreplica/.ssh/authorized_keys
chmod 0600 /home/cicreplica/.ssh/authorized_keys
REMOTE
control "cat > /opt/codeinchrome/bin/cic-replicate" <<SCRIPT
#!/usr/bin/env bash
# Replicate every backup repository to $replica_name. See infra/setup-control-backup.sh.
set -Eeuo pipefail
# No --delete: the replica refuses deletions anyway (rrsync -no-del), and
# asking would only make the run fail. locks/ is skipped so a restore from the
# replica never trips over a stale lock copied mid-backup.
rsync -a \\
  -e "ssh -i /root/.ssh/cic_replica_ed25519 -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new" \\
  --exclude .htpasswd --exclude 'locks/*' \\
  /srv/backups-rest/ cicreplica@$replica_ip:/
SCRIPT
control 'chmod 0750 /opt/codeinchrome/bin/cic-replicate'
ok "replica account on $replica_name: rrsync, write-only, no deletion, one directory"

# ── job 3: an off-provider copy (optional) ───────────────────────────────────
# The replica above is on another host of the SAME provider. This copies the
# same encrypted repositories to an S3-compatible bucket elsewhere (Cloudflare
# R2, Backblaze B2, ...) once /opt/codeinchrome/etc/offsite.env exists - until
# then it does nothing. Same rule as the replica: it only ever grows. rclone
# `copy` never deletes, and --immutable refuses to overwrite a file that is
# already there (restic never rewrites one). Deletion must also be refused BY
# THE BUCKET (a lock/retention rule), so a compromised control host holding
# the key still cannot erase it.
#
# offsite.env (root, 0600) - the owner's credentials:
#   RCLONE_CONFIG_OFFSITE_TYPE=s3
#   RCLONE_CONFIG_OFFSITE_PROVIDER=Other
#   RCLONE_CONFIG_OFFSITE_ENDPOINT=https://<account>.r2.cloudflarestorage.com
#   RCLONE_CONFIG_OFFSITE_ACCESS_KEY_ID=...
#   RCLONE_CONFIG_OFFSITE_SECRET_ACCESS_KEY=...
#   RCLONE_CONFIG_OFFSITE_NO_CHECK_BUCKET=true
#   OFFSITE_PATH=<bucket>/backups-rest
control 'DEBIAN_FRONTEND=noninteractive apt-get install -y -qq rclone >/dev/null'
control 'cat > /opt/codeinchrome/bin/cic-replicate-offsite' <<'SCRIPT'
#!/usr/bin/env bash
# Copy every backup repository off the provider. See infra/setup-control-backup.sh.
set -Eeuo pipefail
env_file=/opt/codeinchrome/etc/offsite.env
[[ -f $env_file ]] || { echo "no off-site target configured ($env_file); skipped"; exit 0; }
set -a; . "$env_file"; set +a
[[ -n ${OFFSITE_PATH:-} ]] || { echo "OFFSITE_PATH is not set in $env_file" >&2; exit 1; }
rclone copy --immutable --transfers 4 --checkers 8 \
  --exclude .htpasswd --exclude 'locks/**' \
  /srv/backups-rest "offsite:$OFFSITE_PATH"
# Proof of a complete run for the control plane's monitoring (App\Fleet\Monitoring).
runuser -u codeinchrome -- touch /var/lib/codeinchrome/offsite.ok
SCRIPT
control 'chmod 0750 /opt/codeinchrome/bin/cic-replicate-offsite'
ok "off-site copy installed ($(control 'test -f /opt/codeinchrome/etc/offsite.env && echo configured || echo "not configured: skipped until offsite.env exists"'))"

control 'bash -s' <<'REMOTE'
set -Eeuo pipefail
cat > /etc/systemd/system/cic-control-backup.service <<'UNIT'
[Unit]
Description=codeinchrome: back up the control plane, then replicate all backups
[Service]
Type=oneshot
ExecStart=/opt/codeinchrome/bin/cic-control-backup
ExecStart=/opt/codeinchrome/bin/cic-replicate
ExecStart=/opt/codeinchrome/bin/cic-replicate-offsite
Nice=10
IOSchedulingClass=idle
UNIT
cat > /etc/systemd/system/cic-control-backup.timer <<'UNIT'
[Unit]
Description=codeinchrome control backup and replication
[Timer]
# After host backups (02:00-03:30) and retention (05:30).
OnCalendar=*-*-* 06:15:00
Persistent=true
[Install]
WantedBy=timers.target
UNIT
systemctl daemon-reload
systemctl enable --now cic-control-backup.timer >/dev/null 2>&1
REMOTE
ok "nightly timer on the control host"

# ── run it now, and prove each property ─────────────────────────────────────
control 'systemctl start cic-control-backup.service' || die "the first run failed: $(control 'journalctl -u cic-control-backup -n 15 -o cat')"

fails=0
check() { if eval "$2" >/dev/null 2>&1; then ok "$1"; else printf '\033[33m  !!\033[0m %s\n' "$1"; fails=$((fails+1)); fi; }
check "control snapshot exists and contains the database" \
  "control 'set -a; . /opt/codeinchrome/etc/control-backup.env; set +a; restic --no-cache ls latest --tag control | grep -q control.sqlite'"
check "every repository is on the replica" \
  "[[ \$(control 'ls /srv/backups-rest | grep -v htpasswd | sort | tr \"\\n\" \" \"') == \$(replica 'ls /srv/backups-replica | sort | tr \"\\n\" \" \"') ]]"
check "the replica key cannot read the replica back" \
  "! control 'rsync -e \"ssh -i /root/.ssh/cic_replica_ed25519 -o IdentitiesOnly=yes\" cicreplica@$replica_ip:/ /tmp/cic-readback-probe/'"
check "the replica key cannot DELETE on the replica" \
  "control 'd=\$(mktemp -d); touch \$d/.probe; rsync -a --delete -e \"ssh -i /root/.ssh/cic_replica_ed25519 -o IdentitiesOnly=yes\" \$d/ cicreplica@$replica_ip:/control/ 2>/dev/null; rm -rf \$d; true' && replica 'test -f /srv/backups-replica/control/config'"
check "the replica key cannot get a shell" \
  "! control 'ssh -i /root/.ssh/cic_replica_ed25519 -o IdentitiesOnly=yes -o BatchMode=yes cicreplica@$replica_ip id'"
# The point of holding the password off-host: THIS machine, with nothing but
# the operator's .env, can open the control backup on the replica.
check "the operator's password opens the control backup on the replica" \
  "replica 'RESTIC_PASSWORD=$password RESTIC_REPOSITORY=/srv/backups-replica/control /usr/local/bin/restic --no-cache cat config'"
control 'rm -rf /tmp/cic-readback-probe'
(( fails )) && die "$fails check(s) failed"
printf '\033[32mcontrol backup ready\033[0m  nightly, replicated to %s\n' "$replica_name"
