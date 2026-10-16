<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;

class GeminiService
{
  private const MODEL = 'gemini-3.5-flash-lite';

  public function __construct(
    private PlatformInterface $platform
  ) {
  }

  public function translateContent(string $content, string $targetLanguage): string
  {
    $messages = new MessageBag(
      Message::forSystem(sprintf('You are a translator. Translate the user text into "%s". Return only the translation.', $targetLanguage)),
      Message::ofUser($content),
    );

    return $this->platform->invoke(self::MODEL, $messages)->getResult()->getContent();
  }

  /**
   * @return array<string, mixed>
   *
   * @throws \JsonException when the model output is not valid JSON
   */
  public function generateProductContentFor(array $data): array
  {

    $systemInstruction = <<<TEXT
You are an expert e-commerce catalog localizer and product specification generator.
Your job is to process raw product JSON data and return a modified version in French.

CRITICAL RULES:
1. OUTPUT FORMAT: Respond STRICTLY with valid JSON. Do not wrap the output in markdown backticks (no ```json). Do not add introductory or concluding text. Return ONLY the raw JSON object.
2. TRANSLATION REQUIREMENTS:
   - Translate all text values into natural, fluent French for an e-commerce context.
   - The final output must contain ONLY the French version (no English left in translated fields).
   - Fields to generate/translate into French: "title", "description", "customerSays", "features", and every object inside the "details" array.
3. SPECIFICATIONS & DETAILS TRANSLATION:
  - Generate a more detailed and accurate French description for the product.
   - For every item in "details", translate BOTH the key names ("type" or "name") AND their corresponding "value".
   - Convert imperial measurement units to French/metric standards where applicable (e.g., "inches" -> "pouces", "pounds" -> "livres", "hertz" -> "Hertz", "Table Mount" -> "Montage sur table").
   - Retain standard international technical acronyms and brand names as-is (e.g., "4K", "HDMI", "USB", "Wi-Fi", "Bluetooth", "Dolby Vision", "QLED", "Mini LED", "ATSC", "HEVC", "TOSHIBA", "AirPlay", "Google Cast").
4. PRESERVE STRUCTURE & METADATA:
   - Keep all non-text metadata fields intact with their exact original types and values (e.g., "id", "createdAt", "usdPrice", "overallRating", "variants", "asin", "urls", etc.).
   - Preserve the exact order and structure of keys in the input JSON.
5. REFINEMENT:
   - Improve the clarity, grammar, and style of the French text while maintaining the original meaning.
   - Ensure that product titles and descriptions are polished and suitable for an e-commerce catalog.
   - Remove any redundant or duplicate details in the product specifications. And keep the product details elements to max 50 items.
TEXT;

    $messages = new MessageBag(
      Message::forSystem($systemInstruction),
      Message::ofUser("Process the following product JSON data, translate all content to French, refine specs/title/description according to the rules, and output the updated JSON:\n\n" . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)),
    );

    $raw = $this->platform->invoke(self::MODEL, $messages)->getResult()->getContent();

    return $this->decodeJson($raw);
  }

  /**
   * @return array<string, mixed>
   *
   * @throws \JsonException when the model output is not valid JSON
   */
  public function generateProductContentFromTitle(string $title): array
  {
    $systemInstruction = <<<TEXT
You are an expert e-commerce catalog manager and technical data enrichment agent.
Your objective is to generate a comprehensive, accurate, and high-quality product payload in JSON format based ONLY on a provided product title and your extensive knowledge of electronic and consumer goods specifications.

CRITICAL OUTPUT RULES:
1. STRICT JSON OUTPUT: Respond ONLY with a valid JSON object matching the requested schema. Do NOT include markdown code fences (no ```json ... ```), introductions, or post-explanations.
2. SCHEMA COMPLIANCE: Adhere strictly to the JSON structure provided below. Do NOT rename keys, omit required keys, or add top-level keys outside the schema definition.
3. DATA ACCURACY: Ensure all generated data is factually correct and consistent with known product specifications. And make sure all text content is in French.
4. DATA COMPLETENESS:
   - Fill all technical specifications accurately (e.g., resolution, display technology, power, dimensions, connectivity, refresh rate).
   - Generate engaging, professional e-commerce content for fields like "description", "title", and bulleted "features".
   - Convert standard measurement units appropriately (e.g., "85 inches", "144 Hz", "4K UHD").
5. FALLBACK VALUES: If a non-critical metadata field cannot be inferred from the product model (e.g., specific internal database IDs, custom URLs, internal dates), set its value to null or its appropriate default string.

--- REQUIRING EXACT JSON SCHEMA STRUCTURE ---
{
    "title": "Full polished e-commerce product title string",
    "brand": "BRAND_NAME",
    "description": "Detailed multi-sentence product description highlighting features, target audience, and key benefits.",
    "customerSays": "Summary of common customer feedback, pros, and cons based on product performance.",
    "features": [
        "Feature 1: Short summary and explanation",
        "Feature 2: Short summary and explanation",
        "Feature 3: Short summary and explanation"
    ],
    "details": [
        {
            "type": "Display Technology",
            "value": "Mini LED, QLED"
        },
        {
            "type": "Resolution",
            "value": "4K"
        },
        {
            "type": "Refresh Rate",
            "value": "144 Hz"
        }
    ],
    "productCondition": "New",
    "available": true
}
TEXT;

    $userPrompt = "Generate the full product JSON details for the following product title:\n\n" . $title;

    $messages = new MessageBag(
      Message::forSystem($systemInstruction),
      Message::ofUser($userPrompt)
    );

    $raw = $this->platform->invoke(self::MODEL, $messages)->getResult()->getContent();

    return $this->decodeJson($raw);
  }

  /**
   * @return array<string, mixed>
   */
  private function decodeJson(string $raw): array
  {
    $json = trim($raw);
    // Strip ```json ... ``` fences if the model added them anyway
    $json = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $json) ?? $json;

    return json_decode(trim($json), true, 512, JSON_THROW_ON_ERROR);
  }

  public function runPrompt(string $prompt): string
  {
    $messages = new MessageBag(
      Message::forSystem('You are a prompt runner. Execute the user prompt.'),
      Message::ofUser($prompt),
    );

    return $this->platform->invoke(self::MODEL, $messages)->getResult()->getContent();
  }
}