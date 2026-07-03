import requests
import config

def get_ingredients() -> list:
    try:
        r = requests.get(
            f'{config.LARAVEL_API_URL}/api/ingredients',
            timeout=config.LARAVEL_TIMEOUT
        )
        r.raise_for_status()
        return r.json().get('data', [])
    except Exception as e:
        print(f'[LaravelClient] get_ingredients failed: {e}')
        return []
