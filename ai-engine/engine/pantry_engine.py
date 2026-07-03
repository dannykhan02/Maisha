def resolve_pantry_cost(ingredient: dict) -> float:
    if ingredient.get('_in_pantry'):
        tier = ingredient.get('_pantry_tier', 3)
        if tier in (1,2):
            return 0.0
    return float(ingredient.get('price_kes', 0))

def mark_pantry_items(ingredients: list, pantry: list) -> list:
    pantry_map = {item['ingredient_id']: item for item in pantry}
    for ing in ingredients:
        pid = ing.get('id')
        if pid in pantry_map:
            p = pantry_map[pid]
            ing['_in_pantry'] = True
            ing['_pantry_tier'] = p.get('tier', 3)
            ing['_pantry_qty'] = p.get('quantity')
        else:
            ing['_in_pantry'] = False
            ing['_pantry_tier'] = 3
    return ingredients