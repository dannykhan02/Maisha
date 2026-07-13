import os
from flask import request
from flask_restful import Resource
from providers.router import get_intent


class IntentResource(Resource):
    def post(self):
        payload = request.get_json(silent=True)
        if not payload:
            return {'error': 'Empty or invalid JSON payload'}, 400

        message      = payload.get('message', '')
        user_context = payload.get('user_context', {})

        if not message:
            return {'error': 'message is required'}, 400

        intent = get_intent(message, user_context)

        return {
            'intent':     intent,
            'confidence': 1.0,
            'provider':   'claude',
        }, 200
