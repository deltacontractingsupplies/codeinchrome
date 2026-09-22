#!/usr/bin/env bash
#
# Prove that one tenant cannot reach another tenant's database.
#
#   infra/verify-db-isolation.sh <host-ip> <site-a> <site-b>
#
# Runs infra/probe-db.php inside site B's container, as site B, with site B's
# own credentials, aimed at site A. The probe carries a positive control and
# exits 2 rather than reporting DENIEDs if B cannot use its own database.

set -Eeuo pipefail
cd "$(dirname "$0")/.."

ip=${1:?usage: verify-db-isolation.sh <host-ip> <site-a> <site-b>}
a=${2:?site a}
b=${3:?site b}

db="site_${a//-/_}"
user=$(ssh "root@$ip" "python3 -c 'import json;print(json.load(open(\"/srv/customers/$a/site.json\"))[\"dbUser\"])'")

scp -q infra/probe-db.php "root@$ip:/tmp/cic-probe-db.php"
trap 'ssh "root@$ip" rm -f /tmp/cic-probe-db.php' EXIT
ssh "root@$ip" "docker exec -i -u 33:33 cic-$b php -- '$db' '$user' < /tmp/cic-probe-db.php"
