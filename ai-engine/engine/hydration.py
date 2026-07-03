# engine/hydration.py
#
# Hydration intelligence — daily water target + per-slot distribution.
#
# Rules:
#   Base: 35ml per kg bodyweight
#   Activity modifier: up to +1000ml for very active users
#   Condition modifiers: kidney disease restricts, diabetes/H.Pylori increase
#   Clamp: always between 1500ml (minimum safe) and 4000ml (maximum safe)
#
# Condition-specific drinking rules are woven into the slot distribution
# so they appear alongside meal suggestions, not as a separate feature.


def calculate_water_target(payload: dict) -> int:
    """
    Calculate the user's daily water target in ml.
    Returns an integer (ml).
    """
    weight_kg      = float(payload.get('weight_kg', 65) or 65)
    activity_level = payload.get('activity_level', 'moderate') or 'moderate'
    conditions     = payload.get('health_conditions', []) or []

    # Base: 35ml per kg
    base_ml = weight_kg * 35

    # Activity adjustments
    activity_additions = {
        'sedentary':   0,
        'light':       300,
        'moderate':    500,
        'active':      700,
        'very_active': 1000,
    }
    activity_ml = activity_additions.get(activity_level, 500)

    # Condition adjustments
    condition_adjustments = {
        'kidney_disease':    -500,   # hard restriction — kidneys cannot process excess
        'diabetes':          +300,   # extra hydration stabilises blood sugar
        'pre_diabetes':      +200,
        'h_pylori':          +200,   # dilutes stomach acid between meals
        'ulcer':             +150,
        'acid_reflux':       +100,
        'hypertension':      +200,
        'heart_disease':     +100,
        'anaemia':           +150,
        'gout':              +400,   # flushes uric acid
    }

    condition_ml = sum(
        condition_adjustments.get(c, 0)
        for c in conditions
    )

    total = base_ml + activity_ml + condition_ml

    # Kidney disease hard cap — never exceed 2000ml if kidney_disease present
    if 'kidney_disease' in conditions:
        total = min(total, 2000)

    # Global clamp: 1.5L minimum, 4L maximum
    return int(max(1500, min(total, 4000)))


def distribute_hydration(
    water_target_ml: int,
    active_slots: list,
    conditions: list,
) -> list:
    """
    Distribute water intake across the day with condition-specific timing rules.
    Returns a list of hydration reminders, each attached to a time/slot.

    Example output:
    [
      {'time': '07:00', 'slot': 'wakeup',    'ml': 400, 'note': '...'},
      {'time': '07:30', 'slot': 'breakfast', 'ml': 200, 'note': '...'},
      ...
    ]
    """
    reminders = []
    remaining = water_target_ml

    # Always start the day with water before anything else
    wake_ml = 400
    reminders.append({
        'time':  '06:30',
        'slot':  'wakeup',
        'ml':    wake_ml,
        'note':  'Start your day with 2 glasses before anything else.',
    })
    remaining -= wake_ml

    # Condition-specific pre-meal rules
    pre_meal_note = _pre_meal_note(conditions)

    # Build slot-by-slot reminders
    slot_schedule = _slot_schedule(active_slots)

    glasses_per_slot = max(1, remaining // (len(slot_schedule) * 250))
    slot_ml = glasses_per_slot * 250

    for slot_name, time_hint in slot_schedule:
        if remaining <= 0:
            break

        actual_ml = min(slot_ml, remaining)
        glasses   = max(1, round(actual_ml / 250))

        note = pre_meal_note if pre_meal_note else (
            f'{glasses} glass{"es" if glasses > 1 else ""} before {slot_name}.'
        )

        reminders.append({
            'time':  time_hint,
            'slot':  slot_name,
            'ml':    actual_ml,
            'note':  note,
        })
        remaining -= actual_ml

    # Evening top-up if anything remains
    if remaining > 0:
        reminders.append({
            'time':  '21:00',
            'slot':  'evening',
            'ml':    remaining,
            'note':  f'Final {round(remaining / 250)} glass(es) before bed.',
        })

    # Tea/coffee warning for anaemia users
    if 'anaemia' in conditions:
        reminders.append({
            'time':  'with_meals',
            'slot':  'reminder',
            'ml':    0,
            'note':  (
                'Avoid tea and coffee within 1 hour of iron-rich meals — '
                'tannins block iron absorption. Vitamin C (tomatoes, oranges) '
                'alongside iron-rich food increases absorption 2–3×.'
            ),
        })

    return reminders


def _pre_meal_note(conditions: list) -> str:
    """
    Condition-specific water timing rule shown before each meal slot.
    Returns empty string if no special rule applies.
    """
    if 'h_pylori' in conditions or 'ulcer' in conditions or 'acid_reflux' in conditions:
        return (
            '2 glasses 30 minutes BEFORE your meal — not during. '
            'Drinking during meals dilutes stomach acid needed for digestion.'
        )
    if 'diabetes' in conditions or 'pre_diabetes' in conditions:
        return (
            '1–2 glasses before your meal. '
            'Consistent hydration helps regulate blood sugar.'
        )
    if 'hypertension' in conditions:
        return '2 glasses before your meal — hydration supports blood pressure.'

    return ''


def _slot_schedule(active_slots: list) -> list:
    """
    Maps active slot names to time hints for hydration reminders.
    Returns list of (slot_name, time_hint) tuples.
    """
    default_times = {
        'breakfast':   '07:00',
        'snack_am':    '10:00',
        'lunch':       '12:30',
        'snack_pm':    '15:30',
        'dinner':      '18:30',
        'supper':      '19:00',
    }
    return [
        (slot, default_times.get(slot, '12:00'))
        for slot in active_slots
        if slot in default_times
    ]