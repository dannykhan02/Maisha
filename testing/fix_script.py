import re

with open('maisha_test.sh', 'r') as f:
    content = f.read()

original_length = len(content)

# ── FIX 1: James token (printf -v instead of eval) ──────────────────────────
# Find and replace the entire register_and_login function
old_func_pattern = r'register_and_login\(\) \{[^}]+(?:\{[^}]*\}[^}]*)*\}'

new_func = '''register_and_login() {
  local varname="$1" name="$2" email="$3" pass="$4"
  api_post "$LARAVEL/register" \\
    "{\\"name\\":\\"$name\\",\\"email\\":\\"$email\\",\\"password\\":\\"$pass\\",\\"password_confirmation\\":\\"$pass\\"}"
  if [[ "$HTTP_CODE" == "429" ]]; then
    echo -e "  ${YELLOW}Rate limited on register, sleeping 8s${NC}"
    sleep 8
    api_post "$LARAVEL/register" \\
      "{\\"name\\":\\"$name\\",\\"email\\":\\"$email\\",\\"password\\":\\"$pass\\",\\"password_confirmation\\":\\"$pass\\"}"
  fi
  if [[ "$HTTP_CODE" != "201" ]]; then
    echo -e "  ${RED}Registration failed for $email (HTTP $HTTP_CODE): $BODY${NC}"
    exit 1
  fi
  api_post "$LARAVEL/login" \\
    "{\\"email\\":\\"$email\\",\\"password\\":\\"$pass\\"}"
  local token
  token=$(echo "$BODY" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('token',''))" 2>/dev/null)
  printf -v "TOKEN_${varname}" '%s' "$token"
  echo -e "  ${CYAN}Token [${varname}]: ${token:0:20}${NC}"
}'''

# Do line-by-line replacement — most reliable approach
lines = content.split('\n')
start_idx = None
end_idx = None
depth = 0

for i, line in enumerate(lines):
    if 'register_and_login()' in line and '{' in line and start_idx is None:
        start_idx = i
        depth = line.count('{') - line.count('}')
        continue
    if start_idx is not None:
        depth += line.count('{') - line.count('}')
        if depth <= 0:
            end_idx = i
            break

if start_idx is not None and end_idx is not None:
    print(f"Found register_and_login: lines {start_idx+1}-{end_idx+1}")
    lines[start_idx:end_idx+1] = new_func.split('\n')
    content = '\n'.join(lines)
    print("FIX 1 APPLIED: register_and_login replaced")
else:
    print(f"FIX 1 FAILED: start={start_idx} end={end_idx}")
    print("First 20 lines:")
    for i,l in enumerate(lines[:20]):
        print(f"  {i+1}: {l}")

# ── FIX 2: Add frequency+duration_type to all medication POST payloads ───────
# Find every profile/medications POST payload and add missing fields
med_lines = []
result_lines = content.split('\n')
i = 0
fixes = 0
while i < len(result_lines):
    line = result_lines[i]
    # Look for medication POST data lines
    if ("'profile/medications'" in line or '/profile/medications' in line) and 'DELETE' not in line and 'GET' not in line:
        # Check next few lines for the JSON payload
        j = i
        while j < min(i+5, len(result_lines)):
            if result_lines[j].strip().startswith("'") and '"name"' in result_lines[j] and 'frequency' not in result_lines[j]:
                old_line = result_lines[j]
                # Add frequency and duration_type before closing brace
                new_line = old_line.rstrip()
                if new_line.endswith("}'"):
                    new_line = new_line[:-2] + ',"frequency":"once_daily","duration_type":"ongoing"}\''
                    result_lines[j] = new_line
                    fixes += 1
                    print(f"FIX 2 line {j+1}: added frequency")
            j += 1
    i += 1

content = '\n'.join(result_lines)
print(f"FIX 2: {fixes} medication payloads updated")

with open('maisha_test.sh', 'w') as f:
    f.write(content)

print(f"\nOriginal size: {original_length} bytes")
print(f"New size: {len(content)} bytes")
print("DONE — verify with: grep -n 'printf -v' maisha_test.sh")
