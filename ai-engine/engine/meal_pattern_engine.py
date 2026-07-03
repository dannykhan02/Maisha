def validate_meal_pattern(active_slots: list, goals: list, conditions: list) -> list:
    """
    Validate the user's meal pattern against their goals and conditions.
    Returns a list of warning messages.
    """
    warnings = []
    num_meals = len(active_slots)

    if 'weight_loss' in goals and num_meals > 3:
        warnings.append(
            "You have 5 meals selected for weight loss. Consider 3 meals to reduce calorie intake, "
            "or lower calories per meal."
        )
    if any(c in conditions for c in ['diabetes', 'pre_diabetes']) and num_meals < 4:
        warnings.append(
            "For diabetes, 4-5 smaller meals help stabilise blood sugar. Consider adding snacks."
        )
    if any(c in conditions for c in ['h_pylori', 'ulcer']) and num_meals < 4:
        warnings.append(
            "Ulcer management: eat 5-6 small meals to avoid an empty stomach. "
            "Your current plan may cause irritation."
        )
    return warnings


def distribute_calories(total_kcal: int, slots: list, goals: list) -> dict:
    """
    Distribute total daily calories across meal slots.
    Returns a dict {slot_name: kcal_target}.
    """
    if len(slots) == 3:
        # breakfast 25%, lunch 40%, dinner 35%
        return {
            slots[0]: int(total_kcal * 0.25),
            slots[1]: int(total_kcal * 0.40),
            slots[2]: int(total_kcal * 0.35),
        }
    elif len(slots) == 4:
        # breakfast 20%, lunch 30%, dinner 25%, snack 25%
        return {
            slots[0]: int(total_kcal * 0.20),
            slots[1]: int(total_kcal * 0.30),
            slots[2]: int(total_kcal * 0.25),
            slots[3]: int(total_kcal * 0.25),
        }
    else:
        per = total_kcal // len(slots)
        return {slot: per for slot in slots}