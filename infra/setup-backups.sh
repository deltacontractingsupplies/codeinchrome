#!/usr/bin/env bash
#
# Set up nightly, encrypted, append-only, off-host backups for one host.
#
#   infra/setup-backups.sh h1 203.0.113.105
#
# Run by deploy-host.sh. Idempotent.
#
# Destination: the append-only rest-server on the control host
# (setup-backup-server.sh), over HTTPS. The host has a login that can ADD
# snapshots to its own repository and nothing else: it cannot delete or
# rewrite one, and it cannot see any other host's repository. So root on a
# compromised customer host cannot destroy that host's backups. Retention runs
# only on the control host (cic-backup-maintain).
#
# The repository password is escrowed on the control host. Without that,
# losing a host would mean losing the only key to its backups.

set -Eeuo pipefail
cd "$(dirname "$0")/.."
. infra/hosts.env

name=${1:?usage: setup-backups.sh <name> <ip>}
ip=${2:?usage: setup-backups.sh <name> <ip>}
target_ip=${CIC_CONTROL_HOST##*:}
domain=${CIC_BACKUP_DOMAIN:-backups.codeinchrome.com}

RESTIC_VERSION=0.19.1
RESTIC_SHA256=$(curl -fsSL "https://github.com/restic/restic/releases/download/v${RESTIC_VERSION}/SHA256SUMS" \
  | awk "/restic_${RESTIC_VERSION}_linux_amd64.bz2\$/{print \$1}")
[[ ${#RESTIC_SHA256} == 64 ]] || { echo "cannot read restic's published checksum" >&2; exit 1; }

for forbidden in $CIC_FORBIDDEN_HOSTS; do
  [[ "$ip" == "$forbidden" || "$target_ip" == "$forbidden" ]] && { echo "REFUSING: production host" >&2; exit 1; }
done

ok()  { printf '\033[32m  ok\033[0m %s\n' "$*"; }
die() { printf '\033[31mFAIL\033[0m %s\n' "$*" >&2; exit 1; }
src()    { ssh -o ConnectTimeout=20 -o StrictHostKeyChecking=accept-new "root@$ip" "$@"; }
target() { ssh -o ConnectTimeout=20 -o StrictHostKeyChecking=accept-new "root@$target_ip" "$@"; }

# ── 1. restic on both ends, verified against restic's published checksum ────
# The control host needs it too: it runs retention, and it is where a dead
# host is recovered from.
install_restic='
set -Eeuo pipefail
if ! /usr/local/bin/restic version 2>/dev/null | grep -q "restic $RESTIC_VERSION "; then
  command -v bunzip2 >/dev/null || DEBIAN_FRONTEND=noninteractive apt-get install -y -qq bzip2 >/dev/null
  tmp=$(mktemp -d)
  curl -fsSL -o "$tmp/r.bz2" "https://github.com/restic/restic/releases/download/v${RESTIC_VERSION}/restic_${RESTIC_VERSION}_linux_amd64.bz2"
  echo "$RESTIC_SHA256  $tmp/r.bz2" | sha256sum -c --quiet - || { echo "restic checksum MISMATCH" >&2; exit 1; }
  bunzip2 "$tmp/r.bz2" && install -m 0755 "$tmp/r" /usr/local/bin/restic
  rm -rf "$tmp"
fi'
for run in target src; do
  $run "RESTIC_VERSION=$RESTIC_VERSION RESTIC_SHA256=$RESTIC_SHA256 bash -s" <<<"$install_restic" >/dev/null 2>&1 \
    || die "could not install restic via $run"
done
ok "restic $RESTIC_VERSION on $name and on the control host, checksum verified"

# ── 2. the repository password (generated once, escrowed) ───────────────────
src 'bash -s' <<'REMOTE'
set -Eeuo pipefail
CIC=/opt/codeinchrome
if ! grep -q '^RESTIC_PASSWORD=' $CIC/etc/backup.env 2>/dev/null; then
  ( umask 077
    printf 'RESTIC_PASSWORD=%s\n' "$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')" >> $CIC/etc/backup.env )
fi
chmod 0600 $CIC/etc/backup.env
REMOTE
password_line=$(src 'grep ^RESTIC_PASSWORD= /opt/codeinchrome/etc/backup.env')
target "install -d -m 0700 /opt/codeinchrome/escrow && umask 077 && cat > /opt/codeinchrome/escrow/$name.env && chmod 0600 /opt/codeinchrome/escrow/$name.env" <<<"$password_line"
ok "repository password escrowed on the control host"

# ── 3. the host's login on the append-only server ───────────────────────────
# Created once; its password lives only on the host (0600) and as a bcrypt
# hash on the server.
if ! src 'grep -q "^RESTIC_REST_PASSWORD=" /opt/codeinchrome/etc/backup.env'; then
  rest_pw=$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')
  target "htpasswd -B -i /srv/backups-rest/.htpasswd $name" <<<"$rest_pw" >/dev/null 2>&1 \
    || die "could not create the backup login for $name"
  src "umask 077; printf 'RESTIC_REST_USERNAME=%s\nRESTIC_REST_PASSWORD=%s\n' '$name' '$rest_pw' >> /opt/codeinchrome/etc/backup.env"
  unset rest_pw
fi
src "sed -i '/^RESTIC_REPOSITORY=/d' /opt/codeinchrome/etc/backup.env && echo 'RESTIC_REPOSITORY=rest:https://$domain/$name/' >> /opt/codeinchrome/etc/backup.env"
ok "append-only login for $name, confined to its own repository"

# ── 4. move an existing SFTP-era repository across, snapshots intact ─────────
target "NAME=$name bash -s" <<'REMOTE'
set -Eeuo pipefail
old=/srv/backups/bk-$NAME/repo new=/srv/backups-rest/$NAME
if [[ -f $old/config && ! -e $new/config ]]; then
  mkdir -p "$new" && cp -a "$old/." "$new/"
  chown -R restserver:restserver "$new"
  echo "  moved $(ls "$new/snapshots" | wc -l) snapshot(s) from the SFTP repository"
fi
REMOTE

# ── 5. repository, tool and nightly timer on the host ───────────────────────
scp -q infra/cic-backup "root@$ip:/opt/codeinchrome/bin/cic-backup"
src 'bash -s' <<'REMOTE'
set -Eeuo pipefail
CIC=/opt/codeinchrome
chmod 0750 $CIC/bin/cic-backup
set -a; . $CIC/etc/backup.env; set +a
restic cat config >/dev/null 2>&1 || restic init >/dev/null

cat > /etc/systemd/system/cic-backup.service <<'UNIT'
[Unit]
Description=codeinchrome nightly site backups
After=docker.service cic-mounts.service
[Service]
Type=oneshot
ExecStart=/opt/codeinchrome/bin/cic-backup run
Nice=10
IOSchedulingClass=idle
UNIT
cat > /etc/systemd/system/cic-backup.timer <<'UNIT'
[Unit]
Description=codeinchrome nightly site backups
[Timer]
OnCalendar=*-*-* 02:00:00
# Spread across the fleet so every host does not hit the target at once.
RandomizedDelaySec=90min
Persistent=true
[Install]
WantedBy=timers.target
UNIT
systemctl daemon-reload
systemctl enable --now cic-backup.timer >/dev/null 2>&1
REMOTE
ok "repository ready; nightly timer enabled"

# ── 6. retire the SFTP account, which could delete ───────────────────────────
target "NAME=$name bash -s" <<'REMOTE'
set -Eeuo pipefail
rm -f "/etc/ssh/cic-backup-keys/bk-$NAME"
if id -u "bk-$NAME" >/dev/null 2>&1; then userdel "bk-$NAME" 2>/dev/null || true; fi
REMOTE
src 'rm -f /opt/codeinchrome/etc/backup_ed25519 /opt/codeinchrome/etc/backup_ed25519.pub; sed -i "/^Host cic-backup$/,/^$/d" /root/.ssh/config 2>/dev/null || true'
ok "SFTP backup account retired"

# ── 7. verify, including the property this whole design exists for ─────────
fails=0
check() { if eval "$2" >/dev/null 2>&1; then ok "$1"; else printf '\033[33m  !!\033[0m %s\n' "$1"; fails=$((fails+1)); fi; }
host_restic() { src "set -a; . /opt/codeinchrome/etc/backup.env; set +a; $*"; }

check "host can reach and read its repository" "host_restic 'restic cat config'"
check "timer is scheduled"                     "src 'systemctl is-active cic-backup.timer'"
# Positive control first: the host CAN add a snapshot...
check "host can ADD a snapshot" \
  "host_restic 'echo probe | restic backup --quiet --stdin --stdin-filename append-probe --tag append-probe'"
# ...and cannot remove one. This is what append-only is for. The strongest
# delete there is: forget every probe snapshot by its exact id. Passing means
# the attempt was made AND every one of them is still there afterwards.
src 'cat > /tmp/cic-append-probe.sh' <<'PROBE'
set -a; . /opt/codeinchrome/etc/backup.env; set +a
ids=$(restic snapshots --tag append-probe --json | python3 -c 'import json,sys; print(" ".join(s["short_id"] for s in json.load(sys.stdin)))')
[ -n "$ids" ] || { echo "no probe snapshots to try deleting"; exit 1; }
# shellcheck disable=SC2086
restic forget $ids >/dev/null 2>&1 || true
for i in $ids; do
  restic snapshots --json "$i" | grep -q "$i" || { echo "snapshot $i was DELETED"; exit 1; }
done
PROBE
check "host CANNOT delete a snapshot" "src 'bash /tmp/cic-append-probe.sh'"
src 'rm -f /tmp/cic-append-probe.sh'
check "host CANNOT reach another host's repository" \
  "! host_restic 'RESTIC_REPOSITORY=rest:https://$domain/__other__/ restic cat config'"
check "recoverable from the control host alone" \
  "target 'set -a; . /opt/codeinchrome/escrow/$name.env; set +a; install -d -m 0700 -o restserver -g restserver /var/cache/cic-restic; RESTIC_CACHE_DIR=/var/cache/cic-restic RESTIC_REPOSITORY=/srv/backups-rest/$name setpriv --reuid=restserver --regid=restserver --init-groups -- restic cat config'"
(( fails )) && die "$fails backup check(s) failed on $name"
printf '\033[32mbackups ready\033[0m  %s -> https://%s/%s/ (append-only)\n' "$name" "$domain" "$name"
