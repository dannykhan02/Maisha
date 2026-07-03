def calculate_bmr(weight_kg: float, height_cm: float, age: int, gender: str) -> float:
    if gender == 'male':
        return 10 * weight_kg + 6.25 * height_cm - 5 * age + 5
    else:
        return 10 * weight_kg + 6.25 * height_cm - 5 * age - 161

def calculate_tdee(bmr: float, activity_level: str) -> float:
    multipliers = {
        'sedentary': 1.2, 'light': 1.375, 'moderate': 1.55,
        'active': 1.725, 'very_active': 1.9
    }
    return bmr * multipliers.get(activity_level, 1.55)

def adjust_for_goal(tdee: float, goal: str) -> int:
    if goal == 'weight_loss':
        return max(1200, int(tdee - 500))
    elif goal == 'muscle_gain':
        return int(tdee + 300)
    elif goal == 'diabetic_control':
        return max(1200, int(tdee - 200))
    elif goal == 'maintain':
        return int(tdee)
    else:
        return int(tdee)

def macro_split(goal: str, daily_kcal: int) -> dict:
    splits = {
        'weight_loss':       {'protein':0.35, 'carbs':0.35, 'fat':0.30},
        'muscle_gain':       {'protein':0.40, 'carbs':0.40, 'fat':0.20},
        'diabetic_control':  {'protein':0.30, 'carbs':0.30, 'fat':0.40},
        'maintain':          {'protein':0.30, 'carbs':0.45, 'fat':0.25},
        'heart_health':      {'protein':0.30, 'carbs':0.40, 'fat':0.30},
        'anaemia':           {'protein':0.35, 'carbs':0.35, 'fat':0.30},
        'kidney_support':    {'protein':0.20, 'carbs':0.50, 'fat':0.30},
        'digestive_health':  {'protein':0.25, 'carbs':0.45, 'fat':0.30},
    }
    split = splits.get(goal, splits['maintain'])
    return {
        'protein_g': round(daily_kcal * split['protein'] / 4),
        'carbs_g':   round(daily_kcal * split['carbs'] / 4),
        'fat_g':     round(daily_kcal * split['fat'] / 9),
    }