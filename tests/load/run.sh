#!/usr/bin/env bash
#
# Measure what each plan's container really serves.
#
#   tests/load/run.sh                  every paid plan and free
#   tests/load/run.sh starter pro      just these
#   KEEP=1 tests/load/run.sh ...       leave the bench site up afterwards
#
# A real site ("loadbench") is provisioned on the fleet like any customer's,
# given a storefront page (tests/load/app: 200 products in MySQL, a session
# per visitor, a Blade render), and put through each plan's limits in turn.
# k6 runs on a DIFFERENT host, so the generator never competes with the site,
# and targets the site's ORIGIN directly - the plan's capacity, not
# Cloudflare's cache.
#
# Each plan is stepped up in page views per second until a step fails the
# bar: p95 <= 500 ms, errors <= 1%, no dropped arrivals. The last passing
# step is the plan's capacity. Results land in tests/load/results/.
#
# Writes nothing on this machine but the small results files.

set -Eeuo pipefail
cd "$(dirname "$0")/../.."
. infra/hosts.env

PLANS=${*:-free starter pro studio}
SITE=loadbench
EMAIL=loadtest@codeinchrome.test
STEPS=${STEPS:-"2 4 6 8 10 15 20 30 40 50 60 80 100 130 160 200 250 300 400"}
P95_MAX_MS=${P95_MAX_MS:-500}
control_ip=${CIC_CONTROL_HOST##*:}
stamp=$(date -u +%Y%m%dT%H%MZ)
out=tests/load/results/$stamp
mkdir -p "$out"

ok()  { printf '\033[32m  ok\033[0m %s\n' "$*"; }
die() { printf '\033[31mFAIL\033[0m %s\n' "$*" >&2; exit 1; }
on()  { local ip=$1; shift; ssh -o ConnectTimeout=20 "root@$ip" "$@"; }
artisan() { on "$control_ip" "cd /srv/control && sudo -u codeinchrome php8.4 artisan $*"; }
tinker() { artisan "tinker --execute=$(printf %q "$1")" | tail -1; }

for forbidden in $CIC_FORBIDDEN_HOSTS; do
  for h in $CIC_HOSTS; do [[ ${h##*:} == "$forbidden" ]] && die "REFUSING: production host"; done
done

# ── the bench account and site ───────────────────────────────────────────────
tinker "\$u = App\Models\User::firstOrCreate(['email' => '$EMAIL'], ['name' => 'Load test', 'password' => Str::random(40)]); \$u->forceFill(['email_verified_at' => now(), 'plan' => 'studio'])->save(); echo 'ok';" >/dev/null
if [[ $(tinker "echo App\Models\Site::where('site_id', '$SITE')->where('status', 'live')->exists() ? 'yes' : 'no';") != yes ]]; then
  artisan "site:provision $EMAIL $SITE" >/dev/null || die "could not provision $SITE"
fi
host=$(tinker "echo App\Models\Site::where('site_id', '$SITE')->value('host');")
# (A loop ending on a false [[ ]] returns 1, which set -e treats as fatal -
# the first version of this died here without a word.)
site_ip=$(tr ' ' '\n' <<<"$CIC_HOSTS" | awk -F: -v h="$host" '$1 == h {print $2}')
gen_ip=$(tr ' ' '\n' <<<"$CIC_HOSTS" | awk -F: -v h="$host" '$1 != h {print $2; exit}')
[[ -n $site_ip && -n $gen_ip ]] || die "need the site's host and a different host to generate load"
ok "$SITE.codeinchrome.com on $host ($site_ip); load from $gen_ip"

# ── the storefront ───────────────────────────────────────────────────────────
COPYFILE_DISABLE=1 tar --no-xattrs -C tests/load/app -cf - . | on "$site_ip" "docker exec -i -u 33:33 cic-$SITE tar -xf - -C /var/www/html"
on "$site_ip" "docker exec -u 33:33 cic-$SITE php /var/www/html/artisan migrate --force -q && docker exec -u 33:33 cic-$SITE php /var/www/html/artisan optimize -q"
code=$(on "$gen_ip" "curl -sk -o /dev/null -w %{http_code} --resolve $SITE.codeinchrome.com:443:$site_ip https://$SITE.codeinchrome.com/shop")
[[ $code == 200 ]] || die "/shop answered $code"
ok "storefront deployed (routes, config and views cached, as a production deploy would)"
on "$gen_ip" 'docker image inspect grafana/k6 >/dev/null 2>&1 || docker pull -q grafana/k6 >/dev/null'

# ── each plan ────────────────────────────────────────────────────────────────
k6() { # rate duration
  on "$gen_ip" "docker run --rm -i --network host -e RATE=$1 -e DURATION=$2 -e HOST=$SITE.codeinchrome.com -e ORIGIN=$site_ip grafana/k6 run -q --no-color -" \
    < tests/load/shop.js 2>/dev/null | grep '^{' | tail -1
}

summary=$out/summary.md
printf '| plan | limits | page views/s | p95 at that rate | peak memory | OOM kills | apache processes |\n|---|---|---|---|---|---|---|\n' > "$summary"

for plan in $PLANS; do
  tinker "\$u = App\Models\User::where('email', '$EMAIL')->first(); \$u->update(['plan' => '$plan']); app(App\Fleet\PlanLimits::class)->applyTo(\$u); echo 'ok';" >/dev/null
  limits=$(on "$site_ip" "docker inspect -f '{{.HostConfig.NanoCpus}} {{.HostConfig.Memory}}' cic-$SITE" | awk '{printf "%.2g CPU, %d MB", $1/1e9, $2/1048576}')
  on "$site_ip" "docker restart cic-$SITE >/dev/null && sleep 3"
  k6 5 15s >/dev/null   # warm OPcache and the pool
  # The container's own cgroup (this kernel has no memory.peak): its
  # memory.current is sampled every second while each step runs, and
  # memory.events' oom_kill counts what the kernel killed for lack of memory.
  cg="/sys/fs/cgroup\$(sed -n 's|^0::||p' /proc/\$(docker inspect -f '{{.State.Pid}}' cic-$SITE)/cgroup)"
  oom_before=$(on "$site_ip" "awk '/^oom_kill /{print \$2}' $cg/memory.events")
  best=0 best_p95=- peak_mb=0
  for rate in $STEPS; do
    on "$site_ip" "rm -f /tmp/cic-mem; for i in \$(seq 1 34); do cat $cg/memory.current >> /tmp/cic-mem; sleep 1; done" &
    sampler=$!
    r=$(k6 "$rate" 30s)
    wait "$sampler" || true
    step_mb=$(on "$site_ip" "sort -n /tmp/cic-mem | tail -1" | awk '{printf "%d", $1/1048576}')
    (( step_mb > peak_mb )) && peak_mb=$step_mb
    echo "{\"plan\":\"$plan\",${r#\{}" >> "$out/$plan.jsonl"
    read -r p95 failed dropped achieved < <(python3 -c 'import json,sys; d=json.loads(sys.argv[1]); print(round(d["p95_ms"] or 1e9), d["failed_ratio"], d["dropped"], round(d["achieved_rps"],1))' "$r")
    pass=$(python3 -c "print(int($p95 <= $P95_MAX_MS and $failed <= 0.01 and $dropped <= $rate * 30 * 0.01))")
    printf '    %-8s %4s/s  p95 %5s ms  errors %5.1f%%  dropped %s  memory %s MB  %s\n' "$plan" "$rate" "$p95" "$(python3 -c "print($failed*100)")" "$dropped" "$step_mb" "$([[ $pass == 1 ]] && echo pass || echo FAIL)"
    [[ $pass == 1 ]] || break
    best=$rate best_p95=$p95
  done
  oom_after=$(on "$site_ip" "awk '/^oom_kill /{print \$2}' $cg/memory.events")
  workers=$(on "$site_ip" "docker exec cic-$SITE sh -c 'ps -C apache2 --no-headers | wc -l'")
  printf '| %s | %s | %s | %s ms | %s MB | %s | %s |\n' "$plan" "$limits" "$best" "$best_p95" "$peak_mb" "$((oom_after - oom_before))" "$workers" >> "$summary"
  ok "$plan: $best page views/s within the bar"
done

# Machine-readable for the pricing page (control/resources/capacity.json):
# only measured numbers are ever shown to customers.
python3 - "$out" "$stamp" > "$out/capacity.json" <<'PY'
import json, sys, glob, os
out, stamp = sys.argv[1], sys.argv[2]
plans = {}
for f in glob.glob(os.path.join(out, "*.jsonl")):
    rows = [json.loads(l) for l in open(f) if l.strip()]
    ok = [r for r in rows if (r.get("p95_ms") or 1e9) <= 500 and r.get("failed_ratio", 1) <= 0.01 and r.get("dropped", 0) <= r["rate"] * 30 * 0.01]
    if not rows:
        continue
    best = max(ok, key=lambda r: r["rate"]) if ok else None
    plans[rows[0]["plan"]] = {
        "page_views_per_second": best["rate"] if best else 0,
        "p95_ms": round(best["p95_ms"]) if best else None,
        "steps": [{k: r[k] for k in ("rate", "achieved_rps", "p95_ms", "failed_ratio", "dropped")} for r in rows],
    }
json.dump({"measured_at": stamp, "bar": {"p95_ms": 500, "errors": 0.01},
           "workload": "Laravel storefront page: session, 2 MySQL queries over 200 products, Blade render (tests/load/app)",
           "plans": plans}, sys.stdout, indent=2)
PY
cp "$out/capacity.json" control/resources/capacity.json
echo; cat "$summary"
if [[ ${KEEP:-0} != 1 ]]; then
  artisan "site:reap $SITE" >/dev/null && ok "bench site removed"
fi
