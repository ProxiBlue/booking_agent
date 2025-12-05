#!/bin/bash
# /usr/local/bin/check-redis-queue.sh
#
# Monitors Redis queue length and alerts if too many jobs are stuck
# Exit 0 = OK, Exit 1 = Warning, Exit 2 = Critical

REDIS_CLI="/usr/bin/redis-cli"
WARNING_THRESHOLD=100
CRITICAL_THRESHOLD=500

# Get queue length
QUEUE_LENGTH=$($REDIS_CLI LLEN jobs 2>/dev/null)

if [ -z "$QUEUE_LENGTH" ]; then
    echo "ERROR: Cannot connect to Redis"
    exit 2
fi

if [ "$QUEUE_LENGTH" -ge "$CRITICAL_THRESHOLD" ]; then
    echo "CRITICAL: Queue length is $QUEUE_LENGTH (threshold: $CRITICAL_THRESHOLD)"
    exit 2
elif [ "$QUEUE_LENGTH" -ge "$WARNING_THRESHOLD" ]; then
    echo "WARNING: Queue length is $QUEUE_LENGTH (threshold: $WARNING_THRESHOLD)"
    exit 1
else
    echo "OK: Queue length is $QUEUE_LENGTH"
    exit 0
fi