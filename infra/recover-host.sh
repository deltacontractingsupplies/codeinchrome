#!/usr/bin/env bash
#
# A customer host is lost: bring every one of its sites back on the others,
# from its backups - files, version history and database - and point their
# names at their new homes.
#
#   infra/recover-host.sh hN            recover every site the control plane has on hN
#   infra/recover-host.sh hN site-a     just these
#   CIC_DRY_RUN=1 infra/recover-host.sh hN
#
# Runs from the operator's machine and works ON THE CONTROL HOST, which holds
# every host's backup repository (/srv/backups-rest/hN, append-only) and its
# escrowed password. The dead host is never contacted. For each site:
#
#   1. restic, as the repository's owner: the latest files snapshot restored
#      (app and history.git), the latest database dump taken out
#   2. packed as the transfer endpoints expect (app as a tar.gz, history.git
#      as a tar.gz, the dump gzipped) in a private directory
#   3. `artisan fleet:recover-site`: a fresh site on the host with the most
#      room, the three loaded and checked, the site asked for a page, and
#      only then its DNS switched
#   4. the temporary copies removed
#
# Idempotent: a site already recovered (its row is on a live host) is skipped.
# What it restores is the last nightly backup: anything written after it is
# on the lost host only. Mark the lost host draining first, so nothing new
# lands there: CIC_HOST_STATES="hN:draining" in infra/hosts.env, deploy-control.sh.

set -Eeuo pipefail
cd "$(dirname "$0")/.."
# shellcheck disable=SC1091
. infra/hosts.env

lost=${1:?usage: recover-host.sh <hN> [site ...]}
shift
control_ip=${CIC_CONTROL_HOST##*:}
ok()  { printf '\033[32m  ok\033[0m %s\n' "$*"; }
die() { printf '\033[31mFAIL\033[0m %s\n' "$*" >&2; exit 1; }
[[ $lost =~ ^h[0-9]+$ ]] || die "the host is named hN: $lost"

control() { ssh -o ConnectTimeout=20 "root@$control_ip" "$@"; }
artisan() { control "cd /srv/control && sudo -u codeinchrome php8.4 artisan $*"; }

if (( $# )); then
  sites=("$@")
else
  mapfile -t sites < <(artisan "tinker --execute=\"echo App\\\\Models\\\\Site::where('host', '$lost')->whereIn('status', ['live', 'suspended'])->pluck('site_id')->join(PHP_EOL);\"" | grep -E '^[a-z0-9][a-z0-9-]+$' || true)
fi
(( ${#sites[@]} )) || { echo "no sites recorded on $lost"; exit 0; }
echo "recovering ${#sites[@]} site(s) from $lost's backups: ${sites[*]}"

failed=0
for site in "${sites[@]}"; do
  [[ $site =~ ^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$ ]] || { echo "skipping invalid name $site"; continue; }
  echo "== $site"
  if [[ ${CIC_DRY_RUN:-0} == 1 ]]; then
    control "RESTIC_PASSWORD=\$(. /opt/codeinchrome/escrow/$lost.env; echo \"\$RESTIC_PASSWORD\") RESTIC_REPOSITORY=/srv/backups-rest/$lost RESTIC_CACHE_DIR=/var/cache/cic-restic setpriv --reuid=restserver --regid=restserver --init-groups -- restic snapshots --tag site:$site --latest 2 --compact" | sed 's/^/  /'
    continue
  fi
  # 1-2: out of the repository, packed for the transfer endpoints.
  if ! control "bash -s" <<REMOTE
set -Eeuo pipefail
work=/srv/cic-recover/$site
install -d -m 0711 -o root -g root /srv/cic-recover
rm -rf "\$work" && install -d -m 0700 -o restserver -g restserver "\$work"
export RESTIC_REPOSITORY=/srv/backups-rest/$lost RESTIC_CACHE_DIR=/var/cache/cic-restic
export RESTIC_PASSWORD=\$(. /opt/codeinchrome/escrow/$lost.env; echo "\$RESTIC_PASSWORD")
as_owner() { setpriv --reuid=restserver --regid=restserver --init-groups -- "\$@"; }
as_owner restic restore latest --tag site:$site,kind:files --target "\$work/tree" >/dev/null
as_owner sh -c "restic dump latest /$site.sql --tag site:$site,kind:db | gzip > '\$work/db.sql.gz'"
app=\$(find "\$work/tree" -type d -path "*/$site/vol/app" | head -1)
hist=\$(find "\$work/tree" -type d -path "*/$site/vol/history.git" | head -1)
[[ -n \$app ]] || { echo "no app directory in the snapshot" >&2; exit 1; }
tar -C "\$app" -czf "\$work/files.tar.gz" .
[[ -n \$hist ]] && tar -C "\$(dirname "\$hist")" -czf "\$work/history.tar.gz" history.git
rm -rf "\$work/tree"
chown -R codeinchrome:codeinchrome "\$work"
ls -la "\$work" | tail -n +2 | awk '{print "  " \$5, \$9}'
REMOTE
  then
    echo "  could not recover $site from the backup" >&2
    failed=$((failed + 1))
    continue
  fi
  # 3: onto the host with the most room, checked, then switched.
  work=/srv/cic-recover/$site
  to=$(artisan "tinker --execute=\"echo App\\\\Fleet\\\\Provisioner::make()->chooseHost(App\\\\Models\\\\Site::where('site_id', '$site')->value('memory_limit') ?? '640m', '$lost');\"" | tail -1)
  history_opt=""
  control "test -f $work/history.tar.gz" && history_opt="--history=$work/history.tar.gz"
  if artisan "fleet:recover-site $site --to=$to --db=$work/db.sql.gz --files=$work/files.tar.gz $history_opt"; then
    ok "$site is back, on $to"
  else
    failed=$((failed + 1))
  fi
  # 4: the copies go, whatever happened.
  control "rm -rf $work"
done

[[ ${CIC_DRY_RUN:-0} == 1 ]] && { echo "dry run: nothing was changed"; exit 0; }
(( failed == 0 )) || die "$failed site(s) not recovered; see above. Re-run for just those: infra/recover-host.sh $lost <site>"
printf '\033[32mevery site of %s is recovered.\033[0m Remove %s from infra/hosts.env when you are sure it is gone.\n' "$lost" "$lost"
