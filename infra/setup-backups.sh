#!/usr/bin/env bash
#
# Set up nightly, encrypted, off-host backups for one customer host.
#
#   infra/setup-backups.sh h1 203.0.113.105
#
# Run by deploy-host.sh. Idempotent.
#
# Destination: the control host, over SFTP, into a restic repository per
# source host. Each source host gets its own account there, chrooted to its own
# directory, SFTP only - no shell, no forwarding - so a compromised customer
# host can reach its own backups and nothing else. Swapping the destination for
# object storage (Cloudflare R2, S3) later is a change to RESTIC_REPOSITORY and
# nothing else.
#
# The repository password is generated on the source host AND escrowed on the
# control host, outside the chroot. Without the escrow, losing a host would
# mean losing the only key to its backups - the one situation backups exist for.
#
# Known limit, stated rather than hidden: the source host holds write access
# to its repository, so an attacker with root on a customer host could delete
# that host's backups. Append-only storage (restic rest-server --append-only,
# or object lock on R2) closes that; it is on the roadmap.

set -Eeuo pipefail
cd "$(dirname "$0")/.."
. infra/hosts.env

name=${1:?usage: setup-backups.sh <name> <ip>}
ip=${2:?usage: setup-backups.sh <name> <ip>}
target_ip=${CIC_CONTROL_HOST##*:}
user="bk-$name"

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

# ── 1. restic, verified against the checksum restic publishes ────────────────
# On the source AND on the target. The target holds the escrowed passwords;
# without restic there too, a dead customer host could not be recovered from
# the one machine that still has everything - found by the recovery drill.
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
target "RESTIC_VERSION=$RESTIC_VERSION RESTIC_SHA256=$RESTIC_SHA256 bash -s" <<<"$install_restic" >/dev/null 2>&1
src "RESTIC_VERSION=$RESTIC_VERSION RESTIC_SHA256=$RESTIC_SHA256 bash -s" <<'REMOTE'
set -Eeuo pipefail
if ! /usr/local/bin/restic version 2>/dev/null | grep -q "restic $RESTIC_VERSION "; then
  command -v bunzip2 >/dev/null || DEBIAN_FRONTEND=noninteractive apt-get install -y -qq bzip2 >/dev/null
  tmp=$(mktemp -d)
  curl -fsSL -o "$tmp/r.bz2" "https://github.com/restic/restic/releases/download/v${RESTIC_VERSION}/restic_${RESTIC_VERSION}_linux_amd64.bz2"
  echo "$RESTIC_SHA256  $tmp/r.bz2" | sha256sum -c --quiet - || { echo "restic checksum MISMATCH" >&2; exit 1; }
  bunzip2 "$tmp/r.bz2" && install -m 0755 "$tmp/r" /usr/local/bin/restic
  rm -rf "$tmp"
fi
/usr/local/bin/restic version | head -1
REMOTE
ok "restic $RESTIC_VERSION on $name, checksum verified"

# ── 2. the source host's identity and repository password ───────────────────
src 'bash -s' <<'REMOTE'
set -Eeuo pipefail
CIC=/opt/codeinchrome
[[ -f $CIC/etc/backup_ed25519 ]] || ssh-keygen -q -t ed25519 -N "" -C "codeinchrome-backup@$(hostname)" -f $CIC/etc/backup_ed25519
if [[ ! -s $CIC/etc/backup.env ]]; then
  ( umask 077
    printf 'RESTIC_PASSWORD=%s\n' "$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')" > $CIC/etc/backup.env )
fi
chmod 0600 $CIC/etc/backup.env $CIC/etc/backup_ed25519
REMOTE
pubkey=$(src 'cat /opt/codeinchrome/etc/backup_ed25519.pub')
[[ -n $pubkey ]] || die "no backup key on $name"
ok "backup key and repository password on $name"

# ── 3. a chrooted, SFTP-only account on the backup target ───────────────────
target "USER=$user PUBKEY='$pubkey' bash -s" <<'REMOTE'
set -Eeuo pipefail
id -u "$USER" >/dev/null 2>&1 || useradd -r -M -d / -s /usr/sbin/nologin "$USER"
# A chroot must be root-owned all the way down; the repository inside it is
# the only thing the account can write.
install -d -m 0755 -o root -g root /srv/backups "/srv/backups/$USER"
install -d -m 0700 -o "$USER" -g "$USER" "/srv/backups/$USER/repo"
install -d -m 0755 -o root -g root /etc/ssh/cic-backup-keys
printf 'restrict %s\n' "$PUBKEY" > "/etc/ssh/cic-backup-keys/$USER"
chmod 0644 "/etc/ssh/cic-backup-keys/$USER"

if ! grep -q "^# codeinchrome backup accounts" /etc/ssh/sshd_config; then
  cat >> /etc/ssh/sshd_config <<'SSHD'

# codeinchrome backup accounts: SFTP only, chrooted, no forwarding, no shell.
Match User bk-*
    AuthorizedKeysFile /etc/ssh/cic-backup-keys/%u
    ChrootDirectory /srv/backups/%u
    ForceCommand internal-sftp
    PasswordAuthentication no
    AllowTcpForwarding no
    AllowAgentForwarding no
    X11Forwarding no
    PermitTTY no
SSHD
  sshd -t || { echo "sshd config invalid; NOT reloading" >&2; exit 1; }
  systemctl reload ssh
fi
REMOTE
ok "SFTP-only, chrooted account $user on the backup target"

# ── 4. escrow the password where losing the source host cannot take it ──────
password_line=$(src 'grep ^RESTIC_PASSWORD= /opt/codeinchrome/etc/backup.env')
target "install -d -m 0700 /opt/codeinchrome/escrow && umask 077 && cat > /opt/codeinchrome/escrow/$name.env" <<<"$password_line"
target "chmod 0600 /opt/codeinchrome/escrow/$name.env"
ok "repository password escrowed on the control host"

# ── 5. repository, tool and nightly timer on the source host ────────────────
scp -q infra/cic-backup "root@$ip:/opt/codeinchrome/bin/cic-backup"
src "TARGET=$target_ip USER=$user bash -s" <<'REMOTE'
set -Eeuo pipefail
CIC=/opt/codeinchrome
chmod 0750 $CIC/bin/cic-backup
install -d -m 0700 /root/.ssh
if ! grep -q "^Host cic-backup$" /root/.ssh/config 2>/dev/null; then
  cat >> /root/.ssh/config <<CFG

Host cic-backup
    HostName $TARGET
    User $USER
    IdentityFile $CIC/etc/backup_ed25519
    IdentitiesOnly yes
    StrictHostKeyChecking accept-new
    ServerAliveInterval 30
CFG
  chmod 0600 /root/.ssh/config
fi
grep -q '^RESTIC_REPOSITORY=' $CIC/etc/backup.env || echo 'RESTIC_REPOSITORY=sftp:cic-backup:/repo' >> $CIC/etc/backup.env

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
ok "repository initialised; nightly timer enabled"

# ── 6. verify, rather than assume ───────────────────────────────────────────
fails=0
check() { if eval "$2" >/dev/null 2>&1; then ok "$1"; else printf '\033[33m  !!\033[0m %s\n' "$1"; fails=$((fails+1)); fi; }
check "repository is reachable and valid" "src 'set -a; . /opt/codeinchrome/etc/backup.env; restic cat config'"
check "timer is scheduled"               "src 'systemctl is-active cic-backup.timer'"
check "backup account has no shell"      "! src 'ssh -o BatchMode=yes cic-backup true'"
# The local end of `ssh -L` accepts a TCP connection whatever the server
# allows, so connecting proves nothing. What proves forwarding works is the
# far side's SSH banner arriving THROUGH the tunnel; it must not.
# Runs on the operator's machine, which may be macOS: no `timeout`, so the
# tunnel is backgrounded and killed, and the banner is read with python's
# socket timeout. (The first version used `timeout` and could never see a
# banner on macOS - the positive control is what caught it.)
probe_banner() { # $1 = ssh destination, $2 = local port
  ssh -o BatchMode=yes -o ConnectTimeout=10 -N -L "127.0.0.1:$2:127.0.0.1:22" "$1" 2>/dev/null &
  local pid=$!
  sleep 3
  python3 -c "import socket,sys
try:
    s = socket.create_connection(('127.0.0.1', int(sys.argv[1])), 3); s.settimeout(3); print(s.recv(4).decode(errors='replace'))
except Exception: pass" "$2"
  kill "$pid" 2>/dev/null || true
}
# shellcheck disable=SC2034 # used inside the eval'd check strings
forward_probe='timeout 10 ssh -o BatchMode=yes -N -L 127.0.0.1:19999:127.0.0.1:22 cic-backup 2>/dev/null & sleep 3
  timeout 3 bash -c "exec 3<>/dev/tcp/127.0.0.1/19999; head -c 4 <&3" 2>/dev/null; kill %1 2>/dev/null; true'
# Positive control first, from THIS machine, where root forwarding to the
# target is allowed: if the banner does not come through here, the probe is
# broken and "cannot forward" below would mean nothing.
check "positive control: the probe sees a working forward" '[[ "$(probe_banner "root@$target_ip" 19998)" == SSH-* ]]'
check "backup account cannot forward"    '[[ "$(src "$forward_probe")" != SSH-* ]]'
check "password escrowed"                "target 'test -s /opt/codeinchrome/escrow/$name.env'"
# The point of the escrow: the control host alone can open this repository.
check "recoverable from the control host alone" \
  "target 'set -a; . /opt/codeinchrome/escrow/$name.env; set +a; RESTIC_REPOSITORY=/srv/backups/$user/repo /usr/local/bin/restic cat config'"
(( fails )) && die "$fails backup check(s) failed on $name"
printf '\033[32mbackups ready\033[0m  %s -> %s:/srv/backups/%s\n' "$name" "$target_ip" "$user"
