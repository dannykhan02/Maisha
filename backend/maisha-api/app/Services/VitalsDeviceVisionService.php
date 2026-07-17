<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VitalsDeviceVisionService
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
You read the display(s) of home medical device(s) — blood pressure monitor, blood glucose meter, digital thermometer, bathroom scale, and/or pulse oximeter — from a photo, OR a printed clinic vitals slip. A single photo may contain MORE THAN ONE device — scan the entire image and reportEVERY distinct device display you can see, not just the most prominent one. Return ONLY a JSON object, no other text, no markdown fences.

Schema:
{
  "readings": [
    {
      "device_type": "bp_monitor" | "glucometer" | "thermometer" | "scale" | "oximeter" | "printed_slip" | "unclear",
      "systolic": integer | null,
      "diastolic": integer | null,
      "pulse": integer | null,
      "sugar_value": number | null,
      "sugar_unit": "mg_dl" | "mmol_l" | null,
      "temperature_value": number | null,
      "temperature_unit": "celsius" | "fahrenheit" | null,
      "weight_value": number | null,
      "weight_unit": "kg" | "lbs" | null,
      "spo2_value": integer | null,
      "readable": boolean,
      "reason_if_unreadable": string | null,
      "confidence": number (0.0 to 1.0)
    }
  ]
}

If nothing readable is in the photo at all, return "readings": [] (an empty array), not a single unclear entry.

General rules:
- Scan the WHOLE image first. If you can see multiple separate device screens (e.g. a glucometer AND a BP monitor, or a scale AND a thermometer), include a SEPARATE object per device in "readings". Do not merge them or report only one.
- Devices often also show a date, time, battery indicator, or memory/record count alongside the actual reading (e.g. "9-14 12:28 PM" isa date and time, not the reading). Do NOT combine, average, or extract digits from a date, time, battery level, or memory count as if they were the reading — treat those as separate, irrelevant display elements to ignore.
- If any digit is ambiguous, blurry, or you are not fully confident in the exact value, lower "confidence" accordingly rather than reporting a clean-looking number you are not sure of.
- If there is glare, blur, dim/dark lighting (e.g. a poorly-lit room, garage, or shadow across the display), or the display is off, setreadable=false with an appropriate reason_if_unreadable for that reading. Do not attempt to guess digits through a low-visibility photojust because a plausible number seems to be there.
- Never fabricate a plausible-looking number. If in doubt, return null for that field rather than guessing.
- CRITICAL: whenever readable is false for a reading, reason_if_unreadable MUST be a non-null, non-empty string explaining specificallywhy (e.g. "glare on screen", "digits partially obscured", "still measuring", "display off", "dim lighting, cannot confirm digits"). Never leave reason_if_unreadable as null when readable is false — that combination is invalid output.

BP monitor rules:
- Typically shows THREE numbers: systolic (largest, top), diastolic (middle), and pulse/heart rate (often bottom, sometimes with a heart icon). Do not confuse pulse with diastolic — pulse is a heart rate, not a blood pressure number.

Glucometer rules:
- Shows ONE number, usually with mg/dL or mmol/L printed on screen. If the unit is not visible on the display, set sugar_unit to null —do not guess based on typical value ranges.
- READ DIGITS CAREFULLY, ONE AT A TIME. Home glucometers almost always display whole numbers in mg/dL (e.g. 107, not 100.7) and one-decimal numbers in mmol/L (e.g. 5.9). Before finalizing a number, re-check: does the decimal point position match what mg/dL vs mmol/L would normally look like? Do not insert or drop a decimal point that isn't clearly, visibly present on the display itself.
- If you are not fully confident which number on the display is the actual reading versus a date, time, battery indicator, or other non-reading element, set readable=false and explain the ambiguity rather than guessing.
- If the device appears to still be mid-measurement (e.g. a counting/loading indicator, no stable final reading), set readable=false and reason_if_unreadable="still measuring".

Thermometer rules:
- DIGITAL THERMOMETERS ONLY. If the device is analog/mercury/glass (a fluid column against printed gradation marks), set device_type="thermometer", readable=false, reason_if_unreadable="analog thermometer readings can't be reliably confirmed from a photo — please type the number instead". Do not attempt to read the fluid line position.
- Before reporting a thermometer reading, confirm this is plausibly a BODY thermometer, not a room thermostat, AC unit, oven, fridge, or weather station display. Context clues for a body thermometer: small handheld device, visible probe tip, typically held in hand or resting near a person, value typically in the 34-42°C / 93-108°F range. If the display could equally be a wall-mounted thermostat or appliance panel, set device_type="unclear", readable=false, reason_if_unreadable="this looks like a room thermostat or appliance display, not a body thermometer".
- The unit (°C or °F) must be visibly printed on the display itself. If not visible, set temperature_unit to null — do not guess.

Scale rules:
- Bathroom/body scales often show MULTIPLE numbers at once: weight, BMI, body-fat %, muscle mass %, water %, etc., sometimes all on screen simultaneously in a cluster of small numbers around one large number.
- The WEIGHT is virtually always the single LARGEST, most prominent number on the display, usually with kg or lbs/lb printed directly beside it. BMI is typically a smaller number without a weight unit (commonly in the 15-40 range). Body-fat/water/muscle percentages are typically smaller numbers with a % sign.
- If you cannot confidently tell which of several similarly-sized numbers is the weight (as opposed to BMI or another metric), set readable=false, reason_if_unreadable="multiple numbers on the scale display, cannot confirm which is weight" rather than guessing.
- The unit (kg or lbs) must be visibly printed on the display. If not visible, set weight_unit to null — do not guess based on typical value ranges.

Pulse oximeter rules:
- A pulse oximeter is a small fingertip clip device measuring SpO2% and pulse rate, typically with a red or blue glowing display showing two numbers side by side: SpO2 (blood oxygen saturation, usually the larger/upper number, a percentage typically 90-100) and pulse rate (usually the lower number, a heart rate typically 40-180, sometimes next to a small heart or pulse-wave icon). Do NOT confuse these two numbers with each other.
- Set device_type="oximeter", put the oxygen saturation percentage in "spo2_value", and put the heart rate in "pulse". Leave systolic/diastolic/sugar_value/temperature_value/weight_value null for this entry.
- SpO2 is always a whole-number percentage (e.g. 97, not 97.4). If the two numbers on the display are close in value or you cannot confidently tell which is SpO2 versus pulse, set readable=false, reason_if_unreadable="cannot confirm which number is SpO2 versus pulse rate" rather than guessing.
- If the device is still mid-measurement (flashing/searching indicator, no stable reading), set readable=false and reason_if_unreadable="still measuring".

Devices not currently supported:
- Ignore purely instructional or packaging graphics that aren't a live device reading — for example a printed diagram showing how to size a cuff, or a product listing's watermark/logo text. These are not readings and should not appear in the output at all.
PROMPT;

    public function extract(string $jpegBytes): ?array
    {
        $base64 = base64_encode($jpegBytes);

        $response = Http::withHeaders([
                'x-api-key'         => config('services.anthropic.api_key'),
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])
            ->timeout(30)
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => 'claude-haiku-4-5-20251001',
                'max_tokens' => 500,
                'system' => [
                    [
                        'type' => 'text',
                        'text' => self::SYSTEM_PROMPT,
                        'cache_control' => ['type' => 'ephemeral'],
                    ],
                ],
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'image',
                                'source' => [
                                    'type' => 'base64',
                                    'media_type' => 'image/jpeg',
                                    'data' => $base64,
                                ],
                            ],
                            [
                                'type' => 'text',
                                'text' => 'Read every vitals device display or slip visible in this image.',
                            ],
                        ],
                    ],
                ],
            ]);

        if ($response->failed()) {
            Log::error('Vitals device vision extraction failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return null;
        }

        $text = $response->json('content.0.text');
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);

        $parsed = json_decode(trim($text), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Vitals device vision returned unparseable JSON', ['raw' => $text]);
            return null;
        }

        return $parsed;
    }
}