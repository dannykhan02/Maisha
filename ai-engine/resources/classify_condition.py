import os
from flask import request
from flask_restful import Resource
from providers.router import classify_health_condition

class ClassifyConditionResource(Resource):
    def post(self):
        token = request.headers.get('X-Maisha-Internal-Token', '')
        expected = os.getenv('MAISHA_INTERNAL_SECRET', '')
        if not expected or token != expected:
            return {'error': 'Unauthorized'}, 403

        payload = request.get_json(silent=True)
        if not payload or not payload.get('text'):
            return {'error': 'text is required'}, 400

        result = classify_health_condition(payload['text'])
        return result, 200