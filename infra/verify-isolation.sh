#!/usr/bin/env bash
#
# Prove, on a live host, that one customer cannot reach another.
#
#   ssh root@HOST 'bash -s' < infra/verify-isolation.sh
#
# Needs two sites present. Defaults to demo/tenant2; override with
# CIC_A and CIC_B. This is the test that found the one isolation failure we
# have actually had: with every site on the shared default docker bridge, one
# tenant read 70,403 bytes of another tenant's live app off 172.17.0.2:8080,
# straight past Caddy. Fixed by icc=false plus a per-site network - and this
# script is how we know it stayed fixed.
#
# Deliberately NOT `set -e`: a failing docker exec is the PASSING result here,
# and an earlier version of this script died on the first one - printing three
# green positive controls and then nothing, which reads like a pass.
A=${CIC_A:-demo}
B=${CIC_B:-tenant2}
C=cic-$B
echo "SECRET_OF_DEMO_TENANT" > /srv/customers/$A/app/.tenant-secret
chown 33:33 /srv/customers/$A/app/.tenant-secret

echo "=== POSITIVE CONTROLS - if these fail, every DENIED below is vacuous ==="
ctl() { printf '  %-46s ' "$1"; shift
  out=$(docker exec -u 33:33 $C sh -c "$*" 2>&1)
  [ $? -eq 0 ] && [ -n "$out" ] && echo "YES" || { echo "NO - test is vacuous"; exit 1; }; }
ctl "container exists and runs a shell"   'echo alive'
ctl "it can read its OWN files"           'echo mine > /var/www/html/own.txt && cat /var/www/html/own.txt'
ctl "outbound HTTP works at all"          'php -r "echo @file_get_contents(\"http://1.1.1.1\") ? 1 : 0;"'

echo "=== isolation claims - every line must say DENIED ==="
fails=0
probe() { printf '  %-46s ' "$1"; shift
  out=$(docker exec -u 33:33 $C sh -c "$*" 2>&1); rc=$?
  if [ $rc -eq 0 ] && [ -n "$out" ]; then
    echo "*** REACHED: $(echo "$out" | head -1 | cut -c1-40) ***"; fails=$((fails+1))
  else echo "DENIED"; fi; }

probe "read the other tenants .env"          "cat /srv/customers/$A/app/.env"
probe "read demo secret via the host path"   'cat /srv/customers/$A/app/.tenant-secret'
probe "list other customers"                 'ls /srv/customers'
probe "the cloud metadata service"           'php -r "echo @file_get_contents(\"http://169.254.169.254/hetzner/v1/metadata/hostname\", false, stream_context_create([\"http\"=>[\"timeout\"=>5]]));"'
probe "see the host control-plane dir"       'ls /opt/codeinchrome'
probe "read the agent token"                 'cat /opt/codeinchrome/etc/agent.env'
probe "read host /etc/shadow"                'cat /etc/shadow'
probe "read another tenants vhost"           "cat /opt/codeinchrome/caddy/sites/$A.caddy"
probe "read another tenants access log"      "cat /var/log/caddy/$A.log"
probe "reach the docker socket"              'ls -l /var/run/docker.sock'
probe "write outside its volume (ro rootfs)" 'touch /etc/evil && echo wrote'
probe "caddy admin API on container lo"      'php -r "$r=@file_get_contents(\"http://127.0.0.1:2019/config/\"); echo $r?:\"\";"'
probe "caddy admin API via docker gateway"   'php -r "$r=@file_get_contents(\"http://172.17.0.1:2019/config/\"); echo $r?:\"\";"'
probe "cic-agent via docker gateway"         'php -r "$r=@file_get_contents(\"http://172.17.0.1:9440/v1/sites\"); echo $r?:\"\";"'
probe "SMTP egress (spam)"                   'php -r "$f=@fsockopen(\"1.1.1.1\",587,$e,$s,4); echo $f?\"open\":\"\";"'
probe "mining pool port 3333"                'php -r "$f=@fsockopen(\"1.1.1.1\",3333,$e,$s,4); echo $f?\"open\":\"\";"'

echo "=== can it reach the OTHER tenants container on the docker network? ==="
DEMO_IP=$(docker inspect cic-$A --format '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}')
printf '  %-46s ' "reach cic-$A at $DEMO_IP:8080"
out=$(docker exec -u 33:33 $C sh -c "php -r '\$r=@file_get_contents(\"http://$DEMO_IP:8080/\"); echo \$r?strlen(\$r):\"\";'" 2>&1)
if [ -n "$out" ]; then echo "*** REACHED ($out bytes) - tenants share the bridge ***"; fails=$((fails+1)); else echo "DENIED"; fi

echo "=== ceilings, read from docker rather than from what we asked for ==="
docker inspect $C --format '  cpus={{.HostConfig.NanoCpus}} mem={{.HostConfig.Memory}} swap={{.HostConfig.MemorySwap}} pids={{.HostConfig.PidsLimit}} readonly={{.HostConfig.ReadonlyRootfs}} drop={{.HostConfig.CapDrop}} add={{.HostConfig.CapAdd}} secopt={{.HostConfig.SecurityOpt}}'
rm -f /srv/customers/$A/app/.tenant-secret
echo
[ $fails -eq 0 ] && echo "RESULT: all isolation claims held" || { echo "RESULT: $fails ISOLATION FAILURE(S)"; exit 1; }
