#!/usr/bin/env bash
# Watchdog for the read-only SSHFS mount of gallery.peppasoft.com (systemd timer, every 2 minutes).
# systemd already restarts sshfs when the process exits. This catches the other case: sshfs still runs,
# but the mount hangs or says "Transport endpoint is not connected". Then it remounts and sends Telegram.
#
# A hung FUSE mount blocks every process that touches it, even "timeout ls" (the process waits in the
# kernel and ignores signals). So this script never waits on the mount itself:
#   - "is it mounted?" comes from /proc/self/mountinfo (no access to the mount),
#   - "does it answer?" is asked by a background ls; we wait at most 20 s for its result,
#   - recovery first ABORTS the FUSE connection, so every stuck request (PHP workers too) fails at once.
# Cost when healthy: one small directory read of the mount root per run.
set -u
M=/mnt/gallery-storage
STATE=/var/lib/gallery-storage-watchdog
mkdir -p "$STATE"

tg() { # $1 = receiver (monitoring | monitoring-critical), $2 = title, $3 = details
  local now; now=$(TZ=Asia/Tehran date '+%Y-%m-%d %H:%M:%S IRST')
  curl -sS -G --connect-timeout 5 -m 15 "https://sms.kimiasoft.com/sendMsg" \
    --data-urlencode "p=8798495" --data-urlencode "c=t" --data-urlencode "r=$1" \
    --data-urlencode "m=<b>$2</b>_C${now}_C$3_CGer1-gallery-storage-watchdog" >/dev/null 2>&1 || true
}

fuse_dev() { awk -v m="$M" '$5 == m { print $3 }' /proc/self/mountinfo | tail -1; } # e.g. "0:56"

alive() {
  [ -n "$(fuse_dev)" ] || return 1
  local tag="$STATE/probe.$$"
  rm -f "$tag".*
  ( ls -A "$M" > "$tag.out" 2>/dev/null && touch "$tag.ok" ) &
  for _ in $(seq 1 20); do
    if [ -f "$tag.ok" ]; then
      local n; n=$(wc -l < "$tag.out"); rm -f "$tag".*
      [ "$n" -gt 0 ]; return
    fi
    sleep 1
  done
  rm -f "$tag".*   # the background ls may stay stuck; the abort below frees it
  return 1
}

remount() {
  local dev; dev=$(fuse_dev)
  if [ -n "$dev" ] && [ -w "/sys/fs/fuse/connections/${dev#*:}/abort" ]; then
    echo 1 > "/sys/fs/fuse/connections/${dev#*:}/abort"
  fi
  umount -l "$M" 2>/dev/null || true
  systemctl kill -s KILL gallery-storage 2>/dev/null || true
  sleep 2
  timeout 60 systemctl restart gallery-storage
  sleep 8
}

if alive; then
  if [ -f "$STATE/down" ]; then
    rm -f "$STATE/down" "$STATE/alerted"
    tg monitoring "Gallery storage OK again" "$M is mounted and readable"
  fi
  exit 0
fi

logger -t gallery-storage-watchdog "mount $M does not answer, remounting"
[ -f "$STATE/down" ] || date +%s > "$STATE/down"
remount
if alive; then
  logger -t gallery-storage-watchdog "remount OK"
  rm -f "$STATE/down" "$STATE/alerted"
  tg monitoring "Gallery storage was reconnected" "$M did not answer; the watchdog remounted it and it works again"
  exit 0
fi

# Still down (network / storage box problem): alert once, then at most every hour. The timer keeps trying.
last=$(cat "$STATE/alerted" 2>/dev/null || echo 0)
if [ $(( $(date +%s) - last )) -ge 3600 ]; then
  date +%s > "$STATE/alerted"
  err=$(journalctl -u gallery-storage -n 3 --no-pager -o cat 2>/dev/null | tr '\n' ' ' | sed -e 's/&/\&amp;/g' -e 's/</\&lt;/g' -e 's/>/\&gt;/g' | cut -c1-400)
  tg monitoring-critical "Gallery storage is DOWN" "$M cannot be mounted. The site shows 'storage not available'._CLast log: $err"
fi
exit 1
