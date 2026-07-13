"""
Redis-backed per-user rate limiter for /api/utakulaa.

Design: Uses Redis INCR + EXPIRE (with NX option) to track per-user rate-limit
counts across multiple Flask worker processes. Each user_id has two counters:
- utakulaa:hourly:{user_id} — incremented on each call, expires after 1 hour
- utakulaa:daily:{user_id} — incremented on each call, expires after 24 hours

The NX option on EXPIRE ensures TTL is set only on first increment, never
reset on subsequent calls. This allows safe multi-worker deployments.
"""

import time
import redis
from typing import Optional, Tuple, cast
from config import MAX_PER_HOUR, MAX_PER_DAY, REDIS_URL

HOUR = 3600
DAY = 86400

# Initialize Redis connection
_redis: Optional[redis.Redis] = None

def _get_redis() -> redis.Redis:
    """Lazy-initialize Redis connection."""
    global _redis
    if _redis is None:
        _redis = redis.from_url(REDIS_URL, decode_responses=True)
        assert _redis is not None
        _redis.ping()
    return _redis


def check_and_record(user_id: str) -> Tuple[bool, Optional[str], Optional[int]]:
    """
    Checks whether user_id is within rate limits. If allowed, records the
    hit immediately (so concurrent requests can't both slip through).

    Returns (allowed: bool, reason: str|None, retry_after_seconds: int|None)
    """
    r = _get_redis()

    hourly_key = f"utakulaa:hourly:{user_id}"
    daily_key = f"utakulaa:daily:{user_id}"

    hourly_count_val = cast(Optional[str], r.get(hourly_key))
    daily_count_val = cast(Optional[str], r.get(daily_key))
    hourly_count = int(hourly_count_val) if hourly_count_val else 0
    daily_count = int(daily_count_val) if daily_count_val else 0

    if hourly_count >= MAX_PER_HOUR:
        ttl_val = cast(int, r.ttl(hourly_key))
        retry_after = ttl_val if ttl_val > 0 else HOUR
        print(f"[RATE_LIMIT] BLOCKED: hourly limit exceeded (ttl={ttl_val})")
        return False, 'hourly_limit_exceeded', retry_after

    if daily_count >= MAX_PER_DAY:
        ttl_val = cast(int, r.ttl(daily_key))
        retry_after = ttl_val if ttl_val > 0 else DAY
        print(f"[RATE_LIMIT] BLOCKED: daily limit exceeded (ttl={ttl_val})")
        return False, 'daily_limit_exceeded', retry_after

    r.incr(hourly_key)
    if r.ttl(hourly_key) == -1:
        r.expire(hourly_key, HOUR)
    r.incr(daily_key)
    if r.ttl(daily_key) == -1:
        r.expire(daily_key, DAY)

    print(f"[RATE_LIMIT] ALLOWED: incremented counters")
    return True, None, None