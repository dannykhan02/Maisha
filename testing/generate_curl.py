import json
import urllib.parse

COLLECTION_PATH = "/home/dan/Development/code/Wu-Tang/flask/January/maisha/testing/aisha_Postman_Collection.json"

with open(COLLECTION_PATH) as f:
    data = json.load(f)

out_lines = []
out_lines.append('#!/bin/bash')
out_lines.append('')
out_lines.append('# Base URLs – change these to your actual endpoints')
out_lines.append('BASE_URL="http://localhost:5000"')
out_lines.append('LARAVEL_URL="http://localhost:8000"')
out_lines.append('')
out_lines.append('# Internal secret (must match Flask .env)')
out_lines.append('FLASK_SECRET="your_maisha_internal_secret_here"')
out_lines.append('')
out_lines.append('echo "Testing Maisha Phase 2 endpoints..."')
out_lines.append('')

def to_curl(req, name):
    method = req['method']
    url = req['url'].replace('{{base_url}}', '${BASE_URL}').replace('{{laravel_url}}', '${LARAVEL_URL}')
    headers = []
    for h in req.get('header', []):
        if h.get('key') and h.get('value'):
            headers.append(f"-H '{h['key']}: {h['value']}'")
    # Add internal token for Flask endpoints if needed
    if 'flask' in url or '/api/health' in url or '/api/utakulaa' in url or '/api/hydration' in url or '/api/meal-categories' in url:
        headers.append("-H 'X-Maisha-Internal-Token: ${FLASK_SECRET}'")
    body = req.get('body', {}).get('raw', '')
    if body:
        # Escape double quotes and collapse newlines
        body_escaped = body.replace('"', '\\"')
        body_clean = ' '.join(body_escaped.splitlines())
        return f"curl -X {method} {url} {' '.join(headers)} -d '{body_clean}'"
    else:
        return f"curl -X {method} {url} {' '.join(headers)}"

for group in data['item']:
    group_name = group['name']
    out_lines.append(f'echo "===== {group_name} ====="')
    for req_item in group['item']:
        req_name = req_item['name']
        req = req_item['request']
        out_lines.append(f'echo ">>> {req_name}"')
        out_lines.append(to_curl(req, req_name))
        out_lines.append('echo ""')
        out_lines.append('sleep 1')
    out_lines.append('')

with open('run_tests.sh', 'w') as f:
    f.write('\n'.join(out_lines))

print("Generated run_tests.sh with all curl commands.")
print("Make it executable: chmod +x run_tests.sh")
print("Then run: ./run_tests.sh")