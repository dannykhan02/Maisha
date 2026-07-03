from engine.nutrition_engine import calculate_bmr, calculate_tdee, adjust_for_goal, macro_split
from engine.meal_pattern_engine import distribute_calories

def get_slot_targets(active_slots: list, goals: list, payload: dict) -> tuple:
    weight = float(payload.get('weight_kg', 65) or 65)
    height = float(payload.get('height_cm', 165) or 165)
    age = int(payload.get('age', 30) or 30)
    gender = payload.get('gender', 'female')
    activity = payload.get('activity_level', 'moderate')
    budget = float(payload.get('budget_remaining_kes', 0) or 0)

    bmr = calculate_bmr(weight, height, age, gender)
    tdee = calculate_tdee(bmr, activity)
    primary_goal = goals[0] if goals else 'maintain'
    daily_kcal = adjust_for_goal(tdee, primary_goal)
    macros = macro_split(primary_goal, daily_kcal)

    slot_kcal = distribute_calories(daily_kcal, active_slots, goals)

    # Distribute budget proportionally to calorie share per slot
    n = len(active_slots)
    budget_per_slot = budget / n if n else 0

    targets = {}
    for slot in active_slots:
        kcal = slot_kcal.get(slot, 0)
        protein_g = round(macros['protein_g'] * (kcal / daily_kcal)) if daily_kcal else 0
        # Weight budget by this slot's share of total calories
        slot_share = (kcal / daily_kcal) if daily_kcal else (1 / n if n else 0)
        targets[slot] = {
            'kcal': kcal,
            'protein_g': protein_g,
            'budget_kes': round(budget * slot_share, 2),
        }
    return targets, []