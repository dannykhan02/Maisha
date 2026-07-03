def variety_penalty(ingredient_id: int, history_map: dict) -> float:
    if ingredient_id not in history_map:
        return 0.0
    h = history_map[ingredient_id]
    days_ago = h.get('last_suggested_days_ago', 14)
    if days_ago >= 5:
        return 0.0
    recency = (5 - days_ago) * 2.0
    times_suggested = h.get('times_suggested', 0)
    overuse = max(0, times_suggested - 2) * 1.5
    times_ignored = h.get('times_ignored', 0)
    ignore_penalty = times_ignored * 2.0
    return recency + overuse + ignore_penalty