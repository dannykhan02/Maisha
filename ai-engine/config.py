import os
from dotenv import load_dotenv
load_dotenv()

ANTHROPIC_API_KEY = os.getenv('ANTHROPIC_API_KEY', '')
OPENAI_API_KEY    = os.getenv('OPENAI_API_KEY', '')

LARAVEL_API_URL        = os.getenv('LARAVEL_API_URL', 'http://localhost:8000')
MAISHA_INTERNAL_SECRET = os.getenv('MAISHA_INTERNAL_SECRET', '')

MAX_PER_DAY  = int(os.getenv('MAX_UTAKULAA_PER_USER_PER_DAY', '10'))
MAX_PER_HOUR = int(os.getenv('MAX_UTAKULAA_PER_USER_PER_HOUR', '3'))

FLASK_TIMEOUT   = 15
LARAVEL_TIMEOUT = 10
