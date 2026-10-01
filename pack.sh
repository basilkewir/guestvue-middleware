#!/usr/bin/env bash
# Build ./xc_vm.tar.gz from the ./xc_vm tree so `./install` can consume it.
#
# The installer looks for a valid ./xc_vm.tar.gz next to itself before it tries
# to download anything. GitHub cannot store that archive (174 MB > 100 MB file
# limit), so the panel ships unpacked in git and this script recreates the
# archive in the exact layout the installer expects.
set -euo pipefail

cd "$(dirname "$0")"

if [ ! -d xc_vm ]; then
    echo "error: ./xc_vm directory not found next to pack.sh" >&2
    exit 1
fi

echo "Recreating runtime skeleton (empty dirs the panel expects)..."
mkdir -p xc_vm/backups
mkdir -p xc_vm/tmp/cidr xc_vm/tmp/logs xc_vm/tmp/divergence xc_vm/tmp/opened_cons \
         xc_vm/tmp/flood xc_vm/tmp/player xc_vm/tmp/signals xc_vm/tmp/crons \
         xc_vm/tmp/watch xc_vm/tmp/ministra \
         xc_vm/tmp/cache/series xc_vm/tmp/cache/lines xc_vm/tmp/cache/streams
mkdir -p xc_vm/content/playlists xc_vm/content/video xc_vm/content/vod \
         xc_vm/content/epg xc_vm/content/archive xc_vm/content/streams \
         xc_vm/content/created xc_vm/content/delayed
mkdir -p xc_vm/storage/images/enigma2 xc_vm/storage/images/admin

echo "Packing xc_vm.tar.gz (this takes a minute)..."
tar -czf xc_vm.tar.gz -C xc_vm .

echo
echo "Wrote $(pwd)/xc_vm.tar.gz ($(du -h xc_vm.tar.gz | cut -f1))"
echo "Next: sudo ./install"
