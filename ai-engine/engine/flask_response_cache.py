"""
In-memory per-user rate limiter for /api/utakulaa.

Design note: this is a single-process, in-memory store — correct for the
current dev/demo setup (one Flask process, no gunicorn multi-worker, no
Redis). It resets on restart and won't work if you ever scale to multiple
workers/processes. That's an acceptable tradeoff now; flag it again before
any production deploy with >1 worker.
"""

import time
import threading
from collections import defaultdict

from config import MAX_PER_HOUR, MAX_PER_DAY

_lock = threading.Lock()
_usage = defaultdict(list)  # user_id -> list of unix timestamps (successful calls only)

HOUR = 3600
DAY = 86400


def _prune(timestamps, now):
    # Only need to keep the last 24h — anything older is irrelevant to both limits
    return [t for t in timestamps if now - t < DAY]


def check_and_record(user_id):
    """
    Checks whether user_id is within rate limits. If allowed, records the
    hit immediately (so concurrent requests can't both slip through).

    Returns (allowed: bool, reason: str|None, retry_after_seconds: int|None)
    """
    now = time.time()
    with _lock:
        timestamps = _prune(_usage[user_id], now)

        per_hour = [t for t in timestamps if now - t < HOUR]
        if len(per_hour) >= MAX_PER_HOUR:
            oldest = min(per_hour)
            retry_after = int(HOUR - (now - oldest)) + 1
            _usage[user_id] = timestamps
            return False, 'hourly_limit_exceeded', retry_after

        if len(timestamps) >= MAX_PER_DAY:
            oldest = min(timestamps)
            retry_after = int(DAY - (now - oldest)) + 1
            _usage[user_id] = timestamps
            return False, 'daily_limit_exceeded', retry_after

        timestamps.append(now)
        _usage[user_id] = timestamps
        return True, None, None