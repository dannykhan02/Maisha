import os
from flask import request
from flask_restful import Resource
from engine.utakulaa_algorithm import run_utakulaa
from engine.flask_response_cache import check_and_record


class UtakulaaResource(Resource):
    def post(self):
        token    = request.headers.get('X-Maisha-Internal-Token', '')
        expected = os.getenv('MAISHA_INTERNAL_SECRET', '')
        if not expected or token != expected:
            return {'error': 'Unauthorized'}, 403

        payload = request.get_json(silent=True)
        if not payload:
            return {'error': 'Empty or invalid JSON payload'}, 400
        if 'budget_remaining_kes' not in payload:
            return {'error': 'budget_remaining_kes is required'}, 400
        if not payload.get('ingredients'):
            return {'error': 'ingredients list is required'}, 400

        user_id = payload.get('user_id')
        if not user_id:
            return {'error': 'user_id is required'}, 400

        allowed, reason, retry_after = check_and_record(user_id)
        if not allowed:
            return {
                'error': 'Rate limit exceeded',
                'reason': reason,
                'retry_after_seconds': retry_after,
            }, 429

        try:
            result = run_utakulaa(payload)
            return result, 200
        except Exception as e:
            print(f'[Utakulaa ERROR] {e}')
            return {
                'error':  'Algorithm error — please try again',
                'detail': str(e) if os.getenv('FLASK_DEBUG') == 'true' else None,
            }, 500