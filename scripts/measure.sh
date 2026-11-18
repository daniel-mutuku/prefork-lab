#!/usr/bin/env bash
# Sums memory for every process whose title starts with the given prefix.
#   RSS = pages a process can touch, shared ones counted again in EVERY process (overstates)
#   PSS = shared pages split between the processes sharing them (the honest total)
# Usage: scripts/measure.sh lab-bare      or      scripts/measure.sh lab-prefork
prefix="${1:?usage: measure.sh <process-title-prefix>}"

total_rss=0; total_pss=0; count=0
for pid in $(pgrep -f "^$prefix"); do
  f="/proc/$pid/smaps_rollup"
  [ -r "$f" ] || continue
  rss=$(awk '/^Rss:/ {print $2}' "$f")
  pss=$(awk '/^Pss:/ {print $2}' "$f")
  printf "  pid %-7s %-22s rss %6d MB   pss %6d MB\n" "$pid" "$(tr -d '\0' < /proc/$pid/cmdline | cut -c1-22)" $((rss/1024)) $((pss/1024))
  total_rss=$((total_rss + rss)); total_pss=$((total_pss + pss)); count=$((count + 1))
done

echo "  processes: $count   total RSS: $((total_rss/1024)) MB   total PSS: $((total_pss/1024)) MB"
