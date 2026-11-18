#!/usr/bin/env bash
# How fast does sharing decay while workers run jobs? Samples total PSS and
# average worker USS over time, for preforked workers with OPcache CLI off and on.
# Usage: scripts/decay.sh [workers=8] [seconds=60] [interval=10]
set -u
cd "$(dirname "$0")/.."
N="${1:-8}"; SECS="${2:-60}"; STEP="${3:-10}"

sample() {
  local pss=0 uss=0 w=0
  for pid in $(pgrep -f '^lab-prefork'); do
    f="/proc/$pid/smaps_rollup"; [ -r "$f" ] || continue
    pss=$((pss + $(awk '/^Pss:/{print $2}' "$f")))
    if tr -d '\0' < "/proc/$pid/cmdline" | grep -q worker; then
      uss=$((uss + $(awk '/^Private_Dirty:/{print $2}' "$f"))); w=$((w+1))
    fi
  done
  echo "$((pss/1024)) $(( w ? uss/1024/w : 0 ))"
}

printf "%-22s %6s %14s %20s\n" setup seconds "total PSS MB" "avg worker dirty MB"
for oc in 0 1; do
  php -d opcache.enable_cli=$oc prefork.php --fixed="$N" 2>/dev/null &
  for t in $(seq "$STEP" "$STEP" "$SECS"); do
    sleep "$STEP"; read -r pss uss < <(sample)
    printf "%-22s %6s %14s %20s\n" "opcache.enable_cli=$oc" "$t" "$pss" "$uss"
  done
  pkill -TERM -f '^lab-prefork-master'; wait 2>/dev/null
done
