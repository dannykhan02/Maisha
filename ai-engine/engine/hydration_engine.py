# engine/hydration_engine.py

def calculate_water_target(payload: dict) -> int:
    """
    Calculate the user's daily water target in ml from a payload dict.
    Fields used: weight_kg, activity_level, health_conditions.
    """
    weight_kg = float(payload.get('weight_kg', 65) or 65)
    activity_level = payload.get('activity_level', 'moderate') or 'moderate'
    conditions = payload.get('health_conditions', []) or []

    base = weight_kg * 35
    activity_add = {'sedentary':0, 'light':300, 'moderate':500, 'active':700, 'very_active':1000}
    act_ml = activity_add.get(activity_level, 500)

    cond_adj = {
        'diabetes': 300, 'pre_diabetes': 200, 'h_pylori': 200,
        'ulcer': 150, 'acid_reflux': 100, 'hypertension': 200,
        'heart_disease': 100, 'anaemia': 150, 'kidney_disease': -500,
        'pregnancy': 400, 'breastfeeding': 600
    }
    cond_ml = sum(cond_adj.get(c, 0) for c in conditions)
    total = base + act_ml + cond_ml
    if 'kidney_disease' in conditions:
        total = min(total, 2000)
    return max(1500, min(int(total), 4000))


def distribute_hydration(target_ml: int, active_slots: list, conditions: list) -> list:
    """
    Distribute water intake across the day with condition-specific timing rules.
    Returns a list of reminders.
    """
    reminders = []
    remaining = target_ml
    # Wake up
    wake_ml = 400
    reminders.append({'time': '06:30', 'slot': 'wakeup', 'ml': wake_ml,
                      'note': 'Start your day with 2 glasses before anything else.'})
    remaining -= wake_ml

    # Condition pre‑meal note
    pre_note = ""
    if any(c in conditions for c in ['h_pylori','ulcer','acid_reflux']):
        pre_note = "2 glasses 30 minutes BEFORE your meal — not during. Drinking during meals dilutes stomach acid needed for digestion."
    elif any(c in conditions for c in ['diabetes','pre_diabetes']):
        pre_note = "1–2 glasses before your meal. Consistent hydration helps regulate blood sugar."
    elif 'hypertension' in conditions:
        pre_note = "2 glasses before your meal — hydration supports blood pressure."

    slot_times = {'breakfast':'07:00', 'snack_am':'10:00', 'lunch':'12:30', 'snack_pm':'15:30', 'dinner':'19:00', 'supper':'21:00',
                  'snack_pm':'15:30', 'dinner':'18:30', 'supper':'19:00'}
    glasses = max(1, remaining // (len(active_slots) * 250))
    slot_ml = glasses * 250
    for slot in active_slots:
        if remaining <= 0:
            break
        actual = min(slot_ml, remaining)
        glasses_num = max(1, round(actual / 250))
        note = pre_note if pre_note else f'{glasses_num} glass{"es" if glasses_num>1 else ""} before {slot}.'
        reminders.append({'time': slot_times.get(slot, '12:00'), 'slot': slot,
                          'ml': actual, 'note': note})
        remaining -= actual

    if remaining > 0:
        reminders.append({'time': '21:00', 'slot': 'evening', 'ml': remaining,
                          'note': f'Final {round(remaining/250)} glass(es) before bed.'})
    if 'anaemia' in conditions:
        reminders.append({'time': 'with_meals', 'slot': 'reminder', 'ml': 0,
                          'note': 'Avoid tea and coffee within 1 hour of iron‑rich meals — tannins block iron absorption.'})
    return reminders