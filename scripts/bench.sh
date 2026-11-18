#!/usr/bin/env bash
# Memory matrix: bare vs preforked, OPcache CLI off vs on, N idle workers.
# Usage: scripts/bench.sh [workers=8] [settle_seconds=8]
set -u
cd "$(dirname "$0")/.."
N="${1:-8}"; SETTLE="${2:-8}"
[ -d app ] || php generate_app.php 3000 >/dev/null

total() { # prints "<processes> <total RSS MB> <total PSS MB>"
  local rss=0 pss=0 n=0
  for pid in $(pgrep -f "^$1"); do
    f="/proc/$pid/smaps_rollup"; [ -r "$f" ] || continue
    rss=$((rss + $(awk '/^Rss:/{print $2}' "$f"))); pss=$((pss + $(awk '/^Pss:/{print $2}' "$f"))); n=$((n+1))
  done
  echo "$n $((rss/1024)) $((pss/1024))"
}

avail() { awk '/^MemAvailable:/{print int($2/1024)}' /proc/meminfo; }
printf "%-26s %9s %13s %13s %16s\n" "setup ($N workers)" processes "total RSS MB" "total PSS MB" "RAM used MB*"
for oc in 0 1; do
  label="opcache.enable_cli=$oc"
  a0=$(avail)
  for i in $(seq "$N"); do php -d opcache.enable_cli=$oc worker.php & done
  sleep "$SETTLE"; read -r n rss pss < <(total lab-bare)
  printf "%-26s %9s %13s %13s %16s\n" "bare, $label" "$n" "$rss" "$pss" "$((a0 - $(avail)))"
  pkill -f '^lab-bare-worker'; wait 2>/dev/null

  sleep 2; a0=$(avail)
  php -d opcache.enable_cli=$oc prefork.php --fixed="$N" 2>/dev/null &
  sleep "$SETTLE"; read -r n rss pss < <(total lab-prefork)
  printf "%-26s %9s %13s %13s %16s\n" "prefork, $label" "$n" "$rss" "$pss" "$((a0 - $(avail)))"
  pkill -TERM -f '^lab-prefork-master'; wait 2>/dev/null
done
echo "* RAM used = drop in MemAvailable while the workers run (ground truth, a little noisy)"
