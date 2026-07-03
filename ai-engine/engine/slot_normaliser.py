# engine/slot_normaliser.py
CANONICAL_SLOTS = ['breakfast', 'snack_am', 'lunch', 'snack_pm', 'dinner', 'supper']

SLOT_MAP = {
    'morning':            'breakfast',
    'breakfast':          'breakfast',
    'am_meal':            'breakfast',
    'snack_am':           'snack_am',
    'morning_snack':      'snack_am',
    'pre_workout':        'snack_am',
    'am_snack':           'snack_am',
    'brunch':             'snack_am',
    'lunch':              'lunch',
    'midday':             'lunch',
    'chakula_cha_mchana': 'lunch',
    'noon':               'lunch',
    'snack_pm':           'snack_pm',
    'afternoon_snack':    'snack_pm',
    'post_workout':       'snack_pm',
    'pm_snack':           'snack_pm',
    'evening_snack':      'snack_pm',
    'dinner':             'dinner',
    'evening':            'dinner',
    'usiku':              'dinner',
    'supper':             'supper',
    'late_dinner':        'supper',
    'night':              'supper',
}

def normalise_slots(active_slots: list) -> list:
    seen = set()
    result = []
    for slot in active_slots:
        canonical = SLOT_MAP.get(slot.lower().strip())
        if canonical and canonical not in seen:
            seen.add(canonical)
            result.append(canonical)
        elif not canonical:
            print(f"[slot_normaliser] WARNING: unknown slot '{slot}' dropped")
    return result or ['breakfast', 'lunch', 'dinner']
