# engine/portion_engine.py
# Category-level hand-portion defaults.
# Per-ingredient portion_reference is deferred to the seeder research pass.
# This is a named placeholder, not a final answer — see PORTION_NOTE below.

PORTION_NOTE = (
    "Category-typical portion language. Per-ingredient gram-accurate sizing "
    "requires portion_reference on the ingredients table (seeder research pass)."
)

CATEGORY_PORTION_GRAMS = {
    'protein':   {'unit': 'palm',        'grams': 100},
    'legume':    {'unit': 'cupped hand', 'grams': 120},
    'staple':    {'unit': 'cupped hand', 'grams': 120},
    'vegetable': {'unit': 'fist',        'grams': 80},
    'fruit':     {'unit': 'fist',        'grams': 80},
    'fat':       {'unit': 'thumb',       'grams': 14},
    'drink':     {'unit': 'glass',       'grams': 250},
}


def describe_portion(ingredient: dict, target_grams: float) -> str:
    """
    Converts a gram target to hand-portion language.
    Falls back to raw grams if category is not mapped or target is zero.
    Never crashes on unknown category — unknown = grams fallback.
    """
    if target_grams <= 0:
        return ''

    category = ingredient.get('category', '')
    config   = CATEGORY_PORTION_GRAMS.get(category)

    if not config:
        return f'{target_grams:.0f}g'

    units   = target_grams / config['grams']
    rounded = round(units * 2) / 2  # nearest 0.5

    if rounded <= 0:
        return f'{target_grams:.0f}g'

    unit_label = config['unit']

    if rounded == 0.5:
        return f'half a {unit_label}'
    if rounded == 1:
        return f'1 {unit_label}'
    return f'{rounded:g} {unit_label}s'