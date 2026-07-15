<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MedicationVisionService
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
You extract medication information from a photo of a prescription, pill bottle, or pharmacy label. Return ONLY a JSON object, no other text, no markdown fences.

Schema:
{
  "name": string | null,
  "dosage": string | null,
  "frequency": string | null,
  "timing": string | null,
  "confidence": number (0.0 to 1.0),
  "readable": boolean
}

Rules:
- If the image is not a medication label/prescription, set "readable": false and all other fields null.
- If a field is illegible or absent, set it to null — do not guess.
- Never use generic placeholders like "Tablet", "Medicine", "Pill", "Capsule",
  or similar non-specific words as the "name" field. A dosage form is not a
  medication name. If the actual product/brand/generic name is not clearly
  legible, set "name" to null instead of substituting the dosage form or any
  other guess.
- "confidence" must reflect your certainty in the SPECIFIC VALUES you
  extracted — especially "name" — not how clean or well-lit the image looks.
  A sharp, well-lit photo where the medication name is still illegible or
  absent should produce a LOW confidence, not a high one.
- Never include any text outside the JSON object.
PROMPT;

    /**
     * Download image from Twilio's authenticated media URL.
     */
    public function downloadTwilioMedia(string $mediaUrl): ?string
    {
        $sid   = config('services.twilio.account_sid');
        $token = config('services.twilio.auth_token');

        $response = Http::withBasicAuth($sid, $token)->get($mediaUrl);

        if ($response->failed()) {
            Log::error('Failed to download WhatsApp media from Twilio', [
                'url' => $mediaUrl,
                'status' => $response->status(),
            ]);
            return null;
        }

        return $response->body(); // raw image bytes
    }

    /**
     * Downscale so the longer edge is capped, to control Claude vision cost.
     * Returns raw JPEG bytes.
     */
    public function downscale(string $imageBytes, int $maxEdge = 1400): ?string
    {
        $image = @imagecreatefromstring($imageBytes);
        if (!$image) {
            Log::error('Failed to decode image for downscaling');
            return null;
        }

        $width  = imagesx($image);
        $height = imagesy($image);
        $longEdge = max($width, $height);

        if ($longEdge > $maxEdge) {
            $scale = $maxEdge / $longEdge;
            $newWidth  = (int) round($width * $scale);
            $newHeight = (int) round($height * $scale);

            $resized = imagescale($image, $newWidth, $newHeight);
            imagedestroy($image);
            $image = $resized;
        }

        ob_start();
        imagejpeg($image, null, 82); // quality 82 — good tradeoff for text legibility vs size
        $jpegBytes = ob_get_clean();
        imagedestroy($image);

        return $jpegBytes;
    }

    /**
     * Send the (downscaled) image to Claude vision and parse the extraction.
     * Returns the parsed array, or null on failure.
     */
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
                'max_tokens' => 300,
                'system' => [
                    [
                        'type' => 'text',
                        'text' => self::SYSTEM_PROMPT,
                        'cache_control' => ['type' => 'ephemeral'], // prompt caching — static prompt, cheap on repeat calls
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
                                'text' => 'Extract the medication information from this image.',
                            ],
                        ],
                    ],
                ],
            ]);

        if ($response->failed()) {
            Log::error('Claude vision extraction failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return null;
        }

        $text = $response->json('content.0.text');

        // The model is instructed not to wrap output in markdown fences, but
        // it doesn't always comply — strip them defensively before parsing.
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $text = trim($text);

        $parsed = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Claude vision returned unparseable JSON', ['raw' => $text]);
            return null;
        }

        return $parsed;
    }
}