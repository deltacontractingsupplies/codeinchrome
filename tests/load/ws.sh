#!/usr/bin/env bash
#
# Measure how many live WebSocket connections each paid plan holds.
#
#   tests/load/ws.sh                 starter pro studio
#   tests/load/ws.sh pro             just this plan
#   KEEP=1 tests/load/ws.sh ...      leave the bench site up afterwards
#
# The bench site ("loadbench", as in run.sh) gets Laravel Reverb and has its
# WebSockets switched on exactly as a customer would (the site's settings
# page), then each plan is stepped up in concurrent connections. A step
# passes when at least 99% of the connections subscribe and stay connected
# for the whole hold, no more than 1% close early, and 95% of broadcasts reach
# their listeners within 500 ms. The load comes from a different host, to the
# site's origin (the plan, not Cloudflare), through the host's Caddy as real
# visitors' connections are.
#
# Results are merged into control/resources/capacity.json as
# "websocket_connections" per plan. Writes nothing on this machine but the
# small results files.

set -Eeuo pipefail
cd "$(dirname "$0")/../.."
. infra/hosts.env

PLANS=${*:-starter pro studio}
SITE=loadbench
EMAIL=loadtest@codeinchrome.test
STEPS=${STEPS:-"100 250 500 1000 2000 3000 4000 6000 8000 10000"}
HOLD=${HOLD:-60}
RAMP=${RAMP:-20}
control_ip=${CIC_CONTROL_HOST##*:}
stamp=$(date -u +%Y%m%dT%H%MZ)
out=tests/load/results/ws-$stamp
mkdir -p "$out"

ok()  { printf '\033[32m  ok\033[0m %s\n' "$*"; }
die() { printf '\033[31mFAIL\033[0m %s\n' "$*" >&2; exit 1; }
on()  { local ip=$1; shift; ssh -o ConnectTimeout=20 "root@$ip" "$@"; }
artisan() { on "$control_ip" "cd /srv/control && sudo -u codeinchrome php8.4 artisan $*"; }
tinker() { artisan "tinker --execute=$(printf %q "$1")" | tail -1; }

for forbidden in $CIC_FORBIDDEN_HOSTS; do
  for h in $CIC_HOSTS; do [[ ${h##*:} == "$forbidden" ]] && die "REFUSING: production host"; done
done

# ── the bench site, as run.sh makes it ───────────────────────────────────────
tinker "\$u = App\Models\User::firstOrCreate(['email' => '$EMAIL'], ['name' => 'Load test', 'password' => Str::random(40)]); \$u->forceFill(['email_verified_at' => now(), 'plan' => 'studio'])->save(); echo 'ok';" >/dev/null
if [[ $(tinker "echo App\Models\Site::where('site_id', '$SITE')->where('status', 'live')->exists() ? 'yes' : 'no';") != yes ]]; then
  artisan "site:provision $EMAIL $SITE" >/dev/null || die "could not provision $SITE"
fi
host=$(tinker "echo App\Models\Site::where('site_id', '$SITE')->value('host');")
site_ip=$(tr ' ' '\n' <<<"$CIC_HOSTS" | awk -F: -v h="$host" '$1 == h {print $2}')
gen_ip=$(tr ' ' '\n' <<<"$CIC_HOSTS" | awk -F: -v h="$host" '$1 != h {print $2; exit}')
[[ -n $site_ip && -n $gen_ip ]] || die "need the site's host and a different host to generate load"
ok "$SITE.codeinchrome.com on $host ($site_ip); connections from $gen_ip"

in_site() { on "$site_ip" "docker exec -u 33:33 -e HOME=/tmp -e COMPOSER_HOME=/tmp/composer cic-$SITE sh -c $(printf %q "cd /var/www/html && $1")"; }

# ── Reverb, installed and switched on the way a customer would ───────────────
COPYFILE_DISABLE=1 tar --no-xattrs -C tests/load/app -cf - . | on "$site_ip" "docker exec -i -u 33:33 cic-$SITE tar -xf - -C /var/www/html"
in_site '[ -d vendor/laravel/reverb ] || composer require -q --no-interaction laravel/reverb'
key=$(in_site 'grep -s "^REVERB_APP_KEY=" .env | cut -d= -f2')
if [[ -z $key ]]; then
  key=$(openssl rand -hex 10)
  in_site "printf '%s\n' BROADCAST_CONNECTION=reverb REVERB_APP_ID=bench REVERB_APP_KEY=$key REVERB_APP_SECRET=$(openssl rand -hex 20) REVERB_HOST=127.0.0.1 REVERB_PORT=8081 REVERB_SCHEME=http REVERB_SERVER_HOST=0.0.0.0 REVERB_SERVER_PORT=8081 >> .env"
fi
in_site 'php artisan optimize -q'
tinker "\$s = App\Models\Site::where('site_id', '$SITE')->first(); \$s->update(['reverb' => true]); App\Fleet\AgentClient::for(\$s->host)->setBackground(\$s->site_id, false, false, true); echo 'ok';" >/dev/null
ok "Reverb installed and switched on"

on "$gen_ip" 'docker image inspect grafana/k6 >/dev/null 2>&1 || docker pull -q grafana/k6 >/dev/null'

k6ws() { # conns
  on "$gen_ip" "ulimit -n 65536; docker run --rm -i --ulimit nofile=65536:65536 --network host -e CONNS=$1 -e HOLD=$HOLD -e RAMP=$RAMP -e HOST=$SITE.codeinchrome.com -e ORIGIN=$site_ip -e KEY=$key grafana/k6 run -q --no-color -" \
    < tests/load/ws.js 2>/dev/null | grep '^{' | tail -1
}

summary=$out/summary.md
printf '| plan | limits | connections held | p95 delivery | peak memory | OOM kills |\n|---|---|---|---|---|---|\n' > "$summary"

for plan in $PLANS; do
  tinker "\$u = App\Models\User::where('email', '$EMAIL')->first(); \$u->update(['plan' => '$plan']); app(App\Fleet\PlanLimits::class)->applyTo(\$u); echo 'ok';" >/dev/null
  limits=$(on "$site_ip" "docker inspect -f '{{.HostConfig.NanoCpus}} {{.HostConfig.Memory}}' cic-$SITE" | awk '{printf "%.2g CPU, %d MB", $1/1e9, $2/1048576}')
  on "$site_ip" "docker restart cic-$SITE >/dev/null && sleep 8"
  cg="/sys/fs/cgroup\$(sed -n 's|^0::||p' /proc/\$(docker inspect -f '{{.State.Pid}}' cic-$SITE)/cgroup)"
  oom_before=$(on "$site_ip" "awk '/^oom_kill /{print \$2}' $cg/memory.events")
  best=0 best_p95=- peak_mb=0
  for conns in $STEPS; do
    secs=$((RAMP + HOLD + 5))
    on "$site_ip" "rm -f /tmp/cic-mem; for i in \$(seq 1 $secs); do cat $cg/memory.current >> /tmp/cic-mem; sleep 1; done" &
    sampler=$!
    in_site "php artisan bench:tick $((RAMP + HOLD))" >/dev/null &
    ticker=$!
    r=$(k6ws "$conns")
    wait "$sampler" "$ticker" || true
    step_mb=$(on "$site_ip" "sort -n /tmp/cic-mem | tail -1" | awk '{printf "%d", $1/1048576}')
    (( step_mb > peak_mb )) && peak_mb=$step_mb
    echo "{\"plan\":\"$plan\",${r#\{}" >> "$out/$plan.jsonl"
    read -r pass p95 line < <(python3 - "$r" <<'PY'
import json, sys
d = json.loads(sys.argv[1])
n = d["conns"]
p95 = d["p95_ms"] if d["p95_ms"] is not None else 1e9
ok = d["subscribed"] >= 0.99 * n and d["closed_early"] <= 0.01 * n and p95 <= 500 and d["ticks"] > 0
print(int(ok), round(p95), f'subscribed {d["subscribed"]}/{n}  closed early {d["closed_early"]}  ticks {d["ticks"]}')
PY
)
    printf '    %-8s %6s conns  p95 %5s ms  %s  memory %s MB  %s\n' "$plan" "$conns" "$p95" "$line" "$step_mb" "$([[ $pass == 1 ]] && echo pass || echo FAIL)"
    [[ $pass == 1 ]] || break
    best=$conns best_p95=$p95
  done
  oom_after=$(on "$site_ip" "awk '/^oom_kill /{print \$2}' $cg/memory.events")
  printf '| %s | %s | %s | %s ms | %s MB | %s |\n' "$plan" "$limits" "$best" "$best_p95" "$peak_mb" "$((oom_after - oom_before))" >> "$summary"
  ok "$plan: $best WebSocket connections within the bar"
done

# Merged into the page-view results, which stay as they were measured.
python3 - "$out" control/resources/capacity.json <<'PY'
import json, sys, glob, os
out, target = sys.argv[1], sys.argv[2]
cap = json.load(open(target)) if os.path.exists(target) else {"plans": {}}
for f in glob.glob(os.path.join(out, "*.jsonl")):
    rows = [json.loads(l) for l in open(f) if l.strip()]
    if not rows:
        continue
    good = [r for r in rows if r["subscribed"] >= 0.99 * r["conns"] and r["closed_early"] <= 0.01 * r["conns"]
            and (r["p95_ms"] or 1e9) <= 500 and r["ticks"] > 0]
    plan = cap.setdefault("plans", {}).setdefault(rows[0]["plan"], {})
    plan["websocket_connections"] = max((r["conns"] for r in good), default=0)
    plan["websocket_steps"] = rows
cap["websocket_bar"] = {"subscribed": 0.99, "closed_early": 0.01, "p95_delivery_ms": 500,
                        "workload": "Laravel Reverb in the site's container, one broadcast a second to every connection on a public channel (tests/load/ws.sh)"}
json.dump(cap, open(target, "w"), indent=2)
PY
echo; cat "$summary"
if [[ ${KEEP:-0} != 1 ]]; then
  artisan "site:reap $SITE" >/dev/null && ok "bench site removed"
fi
