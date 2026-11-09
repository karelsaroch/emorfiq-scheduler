#!/usr/bin/env bash
# E2E: spustí 2 scheduler uzly nad sdílenou MySQL, nechá je běžet N minut
# a ověří invarianty (e2e:assert). Exit code = výsledek.
set -euo pipefail
cd "$(dirname "$0")/.."

MINUTES="${E2E_MINUTES:-4}"
COMPOSE=(docker compose --profile e2e)
ARTISAN=("${COMPOSE[@]}" exec -T -w /app/e2e/app node1 php artisan)

echo "▶ Starting e2e cluster (node1, node2) – observing ${MINUTES} minute ticks…"
"${COMPOSE[@]}" up -d --force-recreate e2e-init node1 node2

# Počkat na MINUTES ticků + 20 s, aby krátké úlohy poslední minuty doběhly.
wait_s=$(( 60 - $(date +%-S) + (MINUTES - 1) * 60 + 20 ))
echo "▶ Waiting ${wait_s}s (follow live: docker compose --profile e2e logs -f node1 node2)"
sleep "${wait_s}"

"${COMPOSE[@]}" logs --no-log-prefix node1 node2 | sort | tail -n $(( MINUTES * 2 + 2 ))
echo
"${ARTISAN[@]}" e2e:report
rc=0
"${ARTISAN[@]}" e2e:assert --min-minutes=$(( MINUTES - 1 )) || rc=$?

if [[ "${E2E_KEEP:-0}" != "1" ]]; then
    "${COMPOSE[@]}" stop node1 node2 > /dev/null
fi
exit "${rc}"
