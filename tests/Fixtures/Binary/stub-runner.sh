#!/bin/sh
# Minimal spool-protocol peer for SpoolClientTest. Implements just
# enough of the runner side (probe answer, done/timeout/running
# statuses, cancel delivery) to verify the coordinator end over real
# files and real processes. NOT a tool runner: it never executes
# analysis tools.
#
# Usage: stub-runner.sh SPOOL RESULTS MODE [TOOL]
#   MODE=probe    answer the first probe job, then exit
#   MODE=ok       answer the first analyze job with canned outputs, then exit
#   MODE=timeout  mark the first analyze job timed out, then exit
#   MODE=slow     mark running, then wait for cancel (or 25s) and record it
#
# Canned payloads arrive via env: STUB_EVIDENCE (json), STUB_TOOLOUT (text).
set -u

SPOOL=$1
RESULTS=$2
MODE=$3
TOOL=${4:-objdump}

wait_job() {
    i=0
    while [ "$i" -lt 100 ]; do
        for d in "$SPOOL"/*/; do
            [ -f "${d}job.json" ] || continue
            echo "$d"
            return 0
        done
        i=$((i + 1))
        sleep 0.1
    done
    echo "no job" >&2
    return 1
}

JOBDIR=$(wait_job) || exit 1
JOBID=$(basename "$JOBDIR")
mkdir -p "$RESULTS/$JOBID"

case "$MODE" in
    probe)
        printf '{"schema":1,"tool":"%s","tool_version":"stub-1.0","runner_arch":"%s","image_digest":"stub","tool_image":"stub"}' \
            "$TOOL" "$(uname -m)" > "$RESULTS/$JOBID/status.json"
        ;;
    ok)
        printf '{"schema":1,"state":"running"}' > "$RESULTS/$JOBID/status.json"
        sleep 0.3
        printf '%s' "${STUB_EVIDENCE:-null}" > "$RESULTS/$JOBID/evidence.json"
        if [ -n "${STUB_TOOLOUT:-}" ]; then
            printf '%s' "$STUB_TOOLOUT" > "$RESULTS/$JOBID/tool.out"
        fi
        printf '{"schema":1,"state":"done","exit_code":0}' > "$RESULTS/$JOBID/status.json"
        ;;
    timeout)
        printf '{"schema":1,"state":"timeout","wall_seconds":1}' > "$RESULTS/$JOBID/status.json"
        ;;
    slow)
        printf '{"schema":1,"state":"running"}' > "$RESULTS/$JOBID/status.json"
        i=0
        while [ "$i" -lt 250 ]; do
            if [ -f "$RESULTS/$JOBID/cancel" ]; then
                echo "cancel-seen" > "$RESULTS/$JOBID/stub-marker.txt"
                printf '{"schema":1,"state":"cancelled"}' > "$RESULTS/$JOBID/status.json"
                exit 0
            fi
            i=$((i + 1))
            sleep 0.1
        done
        ;;
esac
exit 0
