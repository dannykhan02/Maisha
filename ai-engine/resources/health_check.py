from flask_restful import Resource


class HealthCheck(Resource):
    def get(self):
        return {
            'status':  'ok',
            'service': 'maisha-ai',
            'version': '1.0.0',
        }, 200


