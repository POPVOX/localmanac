<?php

namespace App\Services\Extraction;

use App\Models\Article;
use App\Models\IssueArea;
use Illuminate\Support\Arr;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

class Enricher
{
    /**
     * @return array{
     *   analysis: array{
     *     dimensions: array{comprehensibility: float, orientation: float, representation: float, agency: float, relevance: float, timeliness: float},
     *     justifications: array{comprehensibility: string, orientation: string, representation: string, agency: string, relevance: string, timeliness: string},
     *     opportunities: array<int, array{type: string, date: string|null, time: string|null, location: string|null, url: string|null, description: string, evidence: array<int, array{quote: string, start?: int, end?: int}>}>,
     *     confidence: float
     *   },
     *   enrichment: array{
     *     people: array<int, array{name: string, role: string|null, confidence: float, evidence: array<int, array{quote: string, start?: int, end?: int}>>>,
     *     organizations: array<int, array{name: string, type_guess: string, confidence: float, evidence: array<int, array{quote: string, start?: int, end?: int}>>>,
     *     locations: array<int, array{name: string, address: string|null, confidence: float, evidence: array<int, array{quote: string, start?: int, end?: int}>>>,
     *     keywords: array<int, array{keyword: string, confidence: float, evidence: array<int, array{quote: string, start?: int, end?: int}>>>,
     *     issue_areas: array<int, array{slug: string, confidence: float, evidence: array<int, array{quote: string, start?: int, end?: int}>>>,
     *     confidence: float
     *   },
     *   confidence: float
     * }
     */
    public function enrich(Article $article): array
    {
        if (! (bool) config('enrichment.enabled', true)) {
            return $this->emptyPayload();
        }

        $article->loadMissing(['body', 'scraper.organization', 'city']);

        $cleanedText = trim((string) ($article->body?->cleaned_text ?? ''));

        if ($cleanedText === '') {
            return $this->emptyPayload();
        }

        $minChars = (int) config('enrichment.min_cleaned_text_chars', 800);
        $length = mb_strlen($cleanedText);

        if ($length < $minChars) {
            return $this->emptyPayload();
        }

        $maxChars = (int) config('enrichment.max_text_chars', 18000);
        if ($length > $maxChars) {
            $cleanedText = mb_substr($cleanedText, 0, $maxChars);
        }

        $issueAreaSlugs = IssueArea::query()
            ->where('city_id', $article->city_id)
            ->orderBy('slug')
            ->pluck('slug')
            ->filter()
            ->values()
            ->all();

        try {
            $response = Prism::structured()
                ->using(
                    config('enrichment.provider', 'openai'),
                    config('enrichment.model', 'gpt-4o-mini')
                )
                ->withSchema($this->schema($issueAreaSlugs))
                ->withPrompt($this->prompt($article, $cleanedText, $issueAreaSlugs))
                ->withClientOptions([
                    'timeout' => (int) config('enrichment.http_timeout', 120),
                ])
                ->withClientRetry(
                    (int) config('enrichment.http_retries', 2),
                    (int) config('enrichment.http_retry_sleep_ms', 250)
                )
                ->asStructured();

            return $this->normalizePayload($response->structured, $issueAreaSlugs);
        } catch (\Throwable $e) {
            report($e);

            return $this->emptyPayload();
        }
    }

    /**
     * @return array{
     *   analysis: array{
     *     dimensions: array{comprehensibility: float, orientation: float, representation: float, agency: float, relevance: float, timeliness: float},
     *     justifications: array{comprehensibility: string, orientation: string, representation: string, agency: string, relevance: string, timeliness: string},
     *     opportunities: array<int, array{type: string, date: string|null, time: string|null, location: string|null, url: string|null, description: string, evidence: array<int, array{quote: string, start?: int, end?: int}>}>,
     *     confidence: float
     *   },
     *   enrichment: array{
     *     people: array<int, array{name: string, role: string|null, confidence: float, evidence: array<int, array{quote: string, start?: int, end?: int}>>>,
     *     organizations: array<int, array{name: string, type_guess: string, confidence: float, evidence: array<int, array{quote: string, start?: int, end?: int}>>>,
     *     locations: array<int, array{name: string, address: string|null, confidence: float, evidence: array<int, array{quote: string, start?: int, end?: int}>>>,
     *     keywords: array<int, array{keyword: string, confidence: float, evidence: array<int, array{quote: string, start?: int, end?: int}>>>,
     *     issue_areas: array<int, array{slug: string, confidence: float, evidence: array<int, array{quote: string, start?: int, end?: int}>>>,
     *     confidence: float
     *   },
     *   confidence: float
     * }
     */
    private function emptyPayload(): array
    {
        return [
            'analysis' => [
                'dimensions' => [
                    'comprehensibility' => 0.0,
                    'orientation' => 0.0,
                    'representation' => 0.0,
                    'agency' => 0.0,
                    'relevance' => 0.0,
                    'timeliness' => 0.0,
                ],
                'justifications' => [
                    'comprehensibility' => '',
                    'orientation' => '',
                    'representation' => '',
                    'agency' => '',
                    'relevance' => '',
                    'timeliness' => '',
                ],
                'opportunities' => [],
                'confidence' => 0.0,
            ],
            'enrichment' => [
                'people' => [],
                'organizations' => [],
                'locations' => [],
                'keywords' => [],
                'issue_areas' => [],
                'confidence' => 0.0,
            ],
            'confidence' => 0.0,
        ];
    }

    /**
     * @param  array<int, string>  $issueAreaSlugs
     */
    private function prompt(Article $article, string $cleanedText, array $issueAreaSlugs): string
    {
        $issueAreas = $issueAreaSlugs === []
            ? 'None. Return an empty issue_areas array.'
            : implode(', ', $issueAreaSlugs);

        $cityName = $article->city?->name ?? 'Unknown';
        $title = $article->title ?? 'Untitled';
        $organization = $article->scraper?->organization?->name ?? 'Unknown';

        return <<<PROMPT
You are an information extraction system for a civic intelligence platform.

Your job is to extract structured, reviewable signals from a single local news or government article.
Be precise, conservative, and evidence-driven.

DO NOT infer facts that are not explicitly stated.
DO NOT invent people, organizations, issue areas, or actions.
If something is unclear, omit it.

All outputs may be reviewed by humans and may guide civic participation.

---

ARTICLE CONTEXT
City: {$cityName}
Article title: {$title}
Source organization: {$organization}

Allowed issue areas (slugs): {$issueAreas}

---

EXTRACTION TASKS

1) PEOPLE
Extract only explicitly named individuals.
Include:
- Full name as written
- Role or title only if explicitly stated
- Evidence quote
Do NOT:
- Guess roles
- Infer affiliations
- Include unnamed officials

2) ORGANIZATIONS
Extract explicitly named organizations.
Include:
- Organization name
- type_guess must be one of: government|news_media|nonprofit|business|school|other
- Evidence quote
Do NOT invent organizations.

3) LOCATIONS
Extract specific named locations only.
Include:
- Location name
- Address only if verbatim
- Evidence quote
Do NOT extract vague references.

4) KEYWORDS / TOPICS
Extract normalized topical keywords.
Rules:
- Civic/process-focused terms
- Lowercase
- No duplicates
- Max 15 keywords
Include evidence for each keyword.

5) ISSUE AREAS
Choose ONLY from the allowed issue areas above.
Do NOT invent new issue areas.
Only include an issue area if the article is substantively about it.

---

6) CIVIC RELEVANCE ANALYSIS
For this article, provide the following analysis:

SCORING ANCHORS (use these as guidance):
- 0.0 = not present at all
- 0.3 = weakly present / implied but not explicit
- 0.6 = clearly present
- 0.9 = strongly present with specifics (dates, locations, instructions, named parties)

REQUIRED BEHAVIOR:
- If the article text contains substantive civic/process language (e.g., "notice", "public hearing", "meeting", "ordinance", "comment period", "deadline", "application", "vote", "bond", "zoning", "permit"), you MUST NOT return 0.0 for all six dimensions. Provide best-estimate scores based on the text.
- If the text contains "NOTICE OF PUBLIC HEARING" or an explicit hearing/meeting date, then:
  - timeliness MUST be > 0.0
  - agency MUST be > 0.0
  Unless the event is clearly long past and no action is possible.
- If cleaned_text appears substantive (e.g., length > 800 chars and includes civic/process language), confidence MUST be >= 0.2. Use very low confidence only when content is mostly boilerplate, unreadable, or lacks any civic context.

EXAMPLE (public hearing notice):
If the text is a "NOTICE OF PUBLIC HEARING" with a date, a reasonable output often looks like:
{
  "dimensions": {
    "comprehensibility": 0.7,
    "orientation": 0.4,
    "representation": 0.3,
    "agency": 0.6,
    "relevance": 0.5,
    "timeliness": 0.8
  },
  "justifications": {
    "comprehensibility": "The notice uses formal language but clearly states a public hearing will occur on a specific date.",
    "orientation": "It describes the purpose of the hearing and the type of action under consideration.",
    "representation": "It references the City governing body and the issuer but includes few affected individuals.",
    "agency": "It states a public hearing will be conducted, indicating a participation opportunity.",
    "relevance": "It concerns a local government action that may affect residents and public finance decisions.",
    "timeliness": "It provides a specific upcoming hearing date."
  },
  "opportunities": [
    {
      "type": "meeting",
      "date": "YYYY-MM-DD",
      "time": null,
      "location": null,
      "url": null,
      "description": "Public hearing (details in notice).",
      "evidence": [{"quote": "NOTICE OF PUBLIC HEARING"}]
    }
  ],
  "confidence": 0.5
}
Use this only as a style guide; always base outputs on the provided text.

DIMENSIONS:
- For each of the following six dimensions, output a score from 0.0 to 1.0:
  - comprehensibility
  - orientation
  - representation
  - agency
  - relevance
  - timeliness
- For each dimension, provide a short evidence-based justification sentence. If unclear, leave justification blank and score low.

OPPORTUNITIES:
- Identify any explicit civic participation opportunities (e.g., meetings, public comment periods, deadlines, applications, etc.).
- For each, include:
  - type (one of: meeting, public_comment, deadline, application, other)
  - date (YYYY-MM-DD) if present, else null
  - time (HH:MM) if present, else null
  - location if present, else null
  - url if present, else null
  - description (short)
  - evidence (array of supporting quotes)
If the article does not contain any explicit participation opportunity, return an empty array.
DO NOT invent opportunities. Only include if explicitly mentioned.

---

EVIDENCE RULES
Every extracted item MUST include evidence:
- Verbatim quote from the article
- As short as possible
- No paraphrasing
Each evidence item MUST include keys: quote, start, end.
If offsets are unknown or unreliable, set start and end to null.

---

CONFIDENCE SCORING
This rule applies ONLY to extracted list items (people, organizations, locations, keywords, issue_areas, opportunities).
For each extracted list item:
- Confidence must be between 0.0 and 1.0
- Be conservative
- If confidence < 0.55, OMIT that list item entirely
Also provide:
- analysis.confidence (how confident you are in the civic relevance analysis)
- enrichment.confidence (how confident you are in the enrichment lists)
- overall payload confidence
Do NOT set analysis.confidence to 0.0 when the article contains substantive civic/process language; use a low but non-zero value instead (e.g., 0.2–0.4) if uncertain.

---

OUTPUT FORMAT (STRICT)
Return ONLY valid JSON matching the schema.
No markdown. No commentary. No extra keys.
Return empty arrays when nothing applies.

ARTICLE TEXT
{$cleanedText}
PROMPT;
    }

    /**
     * @param  array<int, string>  $issueAreaSlugs
     */
    private function schema(array $issueAreaSlugs): Schema
    {
        $evidenceSchema = new ObjectSchema(
            name: 'evidence_item',
            description: 'A direct quote from the article text with optional character offsets.',
            properties: [
                new StringSchema('quote', 'Exact quote from the article text.'),
                new NumberSchema('start', 'Start offset of the quote in the text.', true),
                new NumberSchema('end', 'End offset of the quote in the text.', true),
            ],
            // OpenAI structured outputs requires requiredFields include ALL property keys.
            requiredFields: ['quote', 'start', 'end'],
            allowAdditionalProperties: false
        );

        $peopleSchema = new ObjectSchema(
            name: 'person',
            description: 'A person mentioned in the article.',
            properties: [
                new StringSchema('name', 'Full name.'),
                new StringSchema('role', 'Role or title if stated.', true),
                new NumberSchema('confidence', 'Confidence from 0 to 1.', false, null, 1, null, 0),
                new ArraySchema('evidence', 'Supporting quotes.', $evidenceSchema),
            ],
            requiredFields: ['name', 'role', 'confidence', 'evidence'],
            allowAdditionalProperties: false
        );

        $organizationSchema = new ObjectSchema(
            name: 'organization',
            description: 'An organization mentioned in the article.',
            properties: [
                new StringSchema('name', 'Organization name.'),
                new EnumSchema(
                    'type_guess',
                    'Best-fit type.',
                    ['government', 'news_media', 'nonprofit', 'business', 'school', 'other']
                ),
                new NumberSchema('confidence', 'Confidence from 0 to 1.', false, null, 1, null, 0),
                new ArraySchema('evidence', 'Supporting quotes.', $evidenceSchema),
            ],
            requiredFields: ['name', 'type_guess', 'confidence', 'evidence'],
            allowAdditionalProperties: false
        );

        $locationSchema = new ObjectSchema(
            name: 'location',
            description: 'A location mentioned in the article.',
            properties: [
                new StringSchema('name', 'Location name.'),
                new StringSchema('address', 'Address if present.', true),
                new NumberSchema('confidence', 'Confidence from 0 to 1.', false, null, 1, null, 0),
                new ArraySchema('evidence', 'Supporting quotes.', $evidenceSchema),
            ],
            requiredFields: ['name', 'address', 'confidence', 'evidence'],
            allowAdditionalProperties: false
        );

        $keywordSchema = new ObjectSchema(
            name: 'keyword',
            description: 'A topical keyword or short phrase.',
            properties: [
                new StringSchema('keyword', 'Keyword or short phrase.'),
                new NumberSchema('confidence', 'Confidence from 0 to 1.', false, null, 1, null, 0),
                new ArraySchema('evidence', 'Supporting quotes.', $evidenceSchema),
            ],
            requiredFields: ['keyword', 'confidence', 'evidence'],
            allowAdditionalProperties: false
        );

        $issueAreaSchema = new ObjectSchema(
            name: 'issue_area',
            description: 'Issue area slug from the allowed list.',
            properties: [
                new StringSchema('slug', 'Issue area slug.'),
                new NumberSchema('confidence', 'Confidence from 0 to 1.', false, null, 1, null, 0),
                new ArraySchema('evidence', 'Supporting quotes.', $evidenceSchema),
            ],
            requiredFields: ['slug', 'confidence', 'evidence'],
            allowAdditionalProperties: false
        );

        if ($issueAreaSlugs !== []) {
            $issueAreaSchema = new ObjectSchema(
                name: 'issue_area',
                description: 'Issue area slug from the allowed list.',
                properties: [
                    new EnumSchema('slug', 'Issue area slug.', $issueAreaSlugs),
                    new NumberSchema('confidence', 'Confidence from 0 to 1.', false, null, 1, null, 0),
                    new ArraySchema('evidence', 'Supporting quotes.', $evidenceSchema),
                ],
                requiredFields: ['slug', 'confidence', 'evidence'],
                allowAdditionalProperties: false
            );
        }

        // --- Civic Relevance Analysis SCHEMA ---
        $analysisDimensionsSchema = new ObjectSchema(
            name: 'dimensions',
            description: 'Six civic relevance dimension scores.',
            properties: [
                new NumberSchema('comprehensibility', 'Score 0..1', false, null, 1, null, 0),
                new NumberSchema('orientation', 'Score 0..1', false, null, 1, null, 0),
                new NumberSchema('representation', 'Score 0..1', false, null, 1, null, 0),
                new NumberSchema('agency', 'Score 0..1', false, null, 1, null, 0),
                new NumberSchema('relevance', 'Score 0..1', false, null, 1, null, 0),
                new NumberSchema('timeliness', 'Score 0..1', false, null, 1, null, 0),
            ],
            requiredFields: [
                'comprehensibility',
                'orientation',
                'representation',
                'agency',
                'relevance',
                'timeliness',
            ],
            allowAdditionalProperties: false
        );

        $analysisJustificationsSchema = new ObjectSchema(
            name: 'justifications',
            description: 'Justification sentences for each dimension.',
            properties: [
                new StringSchema('comprehensibility', 'Justification for comprehensibility.'),
                new StringSchema('orientation', 'Justification for orientation.'),
                new StringSchema('representation', 'Justification for representation.'),
                new StringSchema('agency', 'Justification for agency.'),
                new StringSchema('relevance', 'Justification for relevance.'),
                new StringSchema('timeliness', 'Justification for timeliness.'),
            ],
            requiredFields: [
                'comprehensibility',
                'orientation',
                'representation',
                'agency',
                'relevance',
                'timeliness',
            ],
            allowAdditionalProperties: false
        );

        $opportunitySchema = new ObjectSchema(
            name: 'opportunity',
            description: 'Civic participation opportunity.',
            properties: [
                new EnumSchema('type', 'Opportunity type.', ['meeting', 'public_comment', 'deadline', 'application', 'other']),
                new StringSchema('date', 'YYYY-MM-DD if present.', true),
                new StringSchema('time', 'HH:MM if present.', true),
                new StringSchema('location', 'Location if present.', true),
                new StringSchema('url', 'URL if present.', true),
                new StringSchema('description', 'Short description.'),
                new ArraySchema('evidence', 'Supporting quotes.', $evidenceSchema),
            ],
            requiredFields: [
                'type',
                'date',
                'time',
                'location',
                'url',
                'description',
                'evidence',
            ],
            allowAdditionalProperties: false
        );

        $analysisSchema = new ObjectSchema(
            name: 'analysis',
            description: 'Civic relevance analysis for this article.',
            properties: [
                $analysisDimensionsSchema,
                $analysisJustificationsSchema,
                new ArraySchema('opportunities', 'Participation opportunities.', $opportunitySchema),
                new NumberSchema('confidence', 'Confidence from 0 to 1.', false, null, 1, null, 0),
            ],
            requiredFields: ['dimensions', 'justifications', 'opportunities', 'confidence'],
            allowAdditionalProperties: false
        );

        $enrichmentSchema = new ObjectSchema(
            name: 'enrichment',
            description: 'Structured enrichment extraction response.',
            properties: [
                new ArraySchema('people', 'People mentioned in the article.', $peopleSchema),
                new ArraySchema('organizations', 'Organizations mentioned in the article.', $organizationSchema),
                new ArraySchema('locations', 'Locations mentioned in the article.', $locationSchema),
                new ArraySchema('keywords', 'Keywords mentioned in the article.', $keywordSchema),
                new ArraySchema('issue_areas', 'Issue areas relevant to the article.', $issueAreaSchema),
                new NumberSchema('confidence', 'Overall enrichment confidence from 0 to 1.', false, null, 1, null, 0),
            ],
            requiredFields: ['people', 'organizations', 'locations', 'keywords', 'issue_areas', 'confidence'],
            allowAdditionalProperties: false
        );

        return new ObjectSchema(
            name: 'intelligence_payload',
            description: 'Structured enrichment and civic relevance analysis response.',
            properties: [
                $analysisSchema,
                $enrichmentSchema,
                new NumberSchema('confidence', 'Overall confidence from 0 to 1.', false, null, 1, null, 0),
            ],
            requiredFields: ['analysis', 'enrichment', 'confidence'],
            allowAdditionalProperties: false
        );
    }

    /**
     * @param  array<int, string>  $issueAreaSlugs
     * @return array{
     *   analysis: array{
     *     dimensions: array{comprehensibility: float, orientation: float, representation: float, agency: float, relevance: float, timeliness: float},
     *     justifications: array{comprehensibility: string, orientation: string, representation: string, agency: string, relevance: string, timeliness: string},
     *     opportunities: array<int, array{type: string, date: string|null, time: string|null, location: string|null, url: string|null, description: string, evidence: array<int, array{quote: string, start?: int, end?: int}>}>,
     *     confidence: float
     *   },
     *   enrichment: array{
     *     people: array<int, array{name: string, role: string|null, confidence: float, evidence: array<int, array{quote: string, start?: int, end?: int}>>>,
     *     organizations: array<int, array{name: string, type_guess: string, confidence: float, evidence: array<int, array{quote: string, start?: int, end?: int}>>>,
     *     locations: array<int, array{name: string, address: string|null, confidence: float, evidence: array<int, array{quote: string, start?: int, end?: int}>>>,
     *     keywords: array<int, array{keyword: string, confidence: float, evidence: array<int, array{quote: string, start?: int, end?: int}>>>,
     *     issue_areas: array<int, array{slug: string, confidence: float, evidence: array<int, array{quote: string, start?: int, end?: int}>>>,
     *     confidence: float
     *   },
     *   confidence: float
     * }
     */
    private function normalizePayload(?array $structured, array $issueAreaSlugs): array
    {
        $structured = is_array($structured) ? $structured : [];
        $analysis = Arr::get($structured, 'analysis', []);
        $enrichment = Arr::get($structured, 'enrichment', []);

        return [
            'analysis' => $this->normalizeAnalysis($analysis),
            'enrichment' => [
                'people' => $this->normalizePeople(Arr::get($enrichment, 'people', [])),
                'organizations' => $this->normalizeOrganizations(Arr::get($enrichment, 'organizations', [])),
                'locations' => $this->normalizeLocations(Arr::get($enrichment, 'locations', [])),
                'keywords' => $this->normalizeKeywords(Arr::get($enrichment, 'keywords', [])),
                'issue_areas' => $this->normalizeIssueAreas(Arr::get($enrichment, 'issue_areas', []), $issueAreaSlugs),
                'confidence' => $this->clampConfidence(Arr::get($enrichment, 'confidence', 0.0)),
            ],
            'confidence' => $this->clampConfidence(Arr::get($structured, 'confidence', 0.0)),
        ];
    }

    /**
     * @return array{
     *   dimensions: array{comprehensibility: float, orientation: float, representation: float, agency: float, relevance: float, timeliness: float},
     *   justifications: array{comprehensibility: string, orientation: string, representation: string, agency: string, relevance: string, timeliness: string},
     *   opportunities: array<int, array{type: string, date: string|null, time: string|null, location: string|null, url: string|null, description: string, evidence: array<int, array{quote: string, start?: int, end?: int}>}>,
     *   confidence: float
     * }
     */
    private function normalizeAnalysis(array $analysis): array
    {
        $dimKeys = ['comprehensibility', 'orientation', 'representation', 'agency', 'relevance', 'timeliness'];
        $dimensions = Arr::get($analysis, 'dimensions', []);
        $justifications = Arr::get($analysis, 'justifications', []);
        $opps = Arr::get($analysis, 'opportunities', []);
        $confidence = $this->clampConfidence(Arr::get($analysis, 'confidence', 0.0));

        $normDimensions = [];
        foreach ($dimKeys as $k) {
            $normDimensions[$k] = $this->clampConfidence($dimensions[$k] ?? 0.0);
        }
        $normJustifications = [];
        foreach ($dimKeys as $k) {
            $normJustifications[$k] = $this->stringValue($justifications[$k] ?? null) ?? '';
        }
        $normOpportunities = $this->normalizeList($opps, function ($item) {
            $type = $this->stringValue($item['type'] ?? null);
            if ($type === null) {
                return null;
            }
            $type = strtolower($type);

            return [
                'type' => $type,
                'date' => $this->stringValue($item['date'] ?? null),
                'time' => $this->stringValue($item['time'] ?? null),
                'location' => $this->stringValue($item['location'] ?? null),
                'url' => $this->stringValue($item['url'] ?? null),
                'description' => $this->stringValue($item['description'] ?? null) ?? '',
                'evidence' => $this->normalizeEvidence($item['evidence'] ?? []),
            ];
        });

        return [
            'dimensions' => $normDimensions,
            'justifications' => $normJustifications,
            'opportunities' => $normOpportunities,
            'confidence' => $confidence,
        ];
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array{name: string, role: string|null, confidence: float, evidence: array<int, array{quote: string, start?: int, end?: int}>>>
     */
    private function normalizePeople(array $items): array
    {
        return $this->normalizeList($items, function (array $item): ?array {
            $name = $this->stringValue($item['name'] ?? null);

            if ($name === null) {
                return null;
            }

            return [
                'name' => $name,
                'role' => $this->stringValue($item['role'] ?? null),
                'confidence' => $this->clampConfidence($item['confidence'] ?? 0.0),
                'evidence' => $this->normalizeEvidence($item['evidence'] ?? []),
            ];
        });
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array{name: string, type_guess: string, confidence: float, evidence: array<int, array{quote: string, start?: int, end?: int}>>>
     */
    private function normalizeOrganizations(array $items): array
    {
        $allowedTypes = ['government', 'news_media', 'nonprofit', 'business', 'school', 'other'];

        return $this->normalizeList($items, function (array $item) use ($allowedTypes): ?array {
            $name = $this->stringValue($item['name'] ?? null);

            if ($name === null) {
                return null;
            }

            $typeGuess = $this->stringValue($item['type_guess'] ?? null);
            if ($typeGuess !== null) {
                $typeGuess = strtolower($typeGuess);
            }
            if ($typeGuess === null || ! in_array($typeGuess, $allowedTypes, true)) {
                $typeGuess = 'other';
            }

            return [
                'name' => $name,
                'type_guess' => $typeGuess,
                'confidence' => $this->clampConfidence($item['confidence'] ?? 0.0),
                'evidence' => $this->normalizeEvidence($item['evidence'] ?? []),
            ];
        });
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array{name: string, address: string|null, confidence: float, evidence: array<int, array{quote: string, start?: int, end?: int}>>>
     */
    private function normalizeLocations(array $items): array
    {
        return $this->normalizeList($items, function (array $item): ?array {
            $name = $this->stringValue($item['name'] ?? null);

            if ($name === null) {
                return null;
            }

            return [
                'name' => $name,
                'address' => $this->stringValue($item['address'] ?? null),
                'confidence' => $this->clampConfidence($item['confidence'] ?? 0.0),
                'evidence' => $this->normalizeEvidence($item['evidence'] ?? []),
            ];
        });
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array{keyword: string, confidence: float, evidence: array<int, array{quote: string, start?: int, end?: int}>>>
     */
    private function normalizeKeywords(array $items): array
    {
        return $this->normalizeList($items, function (array $item): ?array {
            $keyword = $this->stringValue($item['keyword'] ?? null);

            if ($keyword === null) {
                return null;
            }

            return [
                'keyword' => $keyword,
                'confidence' => $this->clampConfidence($item['confidence'] ?? 0.0),
                'evidence' => $this->normalizeEvidence($item['evidence'] ?? []),
            ];
        });
    }

    /**
     * @param  array<int, mixed>  $items
     * @param  array<int, string>  $issueAreaSlugs
     * @return array<int, array{slug: string, confidence: float, evidence: array<int, array{quote: string, start?: int, end?: int}>>>
     */
    private function normalizeIssueAreas(array $items, array $issueAreaSlugs): array
    {
        $allowed = array_map('strtolower', $issueAreaSlugs);

        return $this->normalizeList($items, function (array $item) use ($allowed): ?array {
            $slug = $this->stringValue($item['slug'] ?? null);

            if ($slug === null) {
                return null;
            }
            $slug = strtolower($slug);
            if ($allowed !== [] && ! in_array($slug, $allowed, true)) {
                return null;
            }

            return [
                'slug' => $slug,
                'confidence' => $this->clampConfidence($item['confidence'] ?? 0.0),
                'evidence' => $this->normalizeEvidence($item['evidence'] ?? []),
            ];
        });
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array{quote: string, start?: int, end?: int}>
     */
    private function normalizeEvidence(array $items): array
    {
        return $this->normalizeList($items, function (array $item): ?array {
            $quote = $this->stringValue($item['quote'] ?? null);

            if ($quote === null) {
                return null;
            }

            $entry = ['quote' => $quote];

            $start = $this->numberValue($item['start'] ?? null);
            if ($start !== null) {
                $entry['start'] = $start;
            }

            $end = $this->numberValue($item['end'] ?? null);
            if ($end !== null) {
                $entry['end'] = $end;
            }

            return $entry;
        });
    }

    /**
     * @template T of array
     *
     * @param  array<int, mixed>  $items
     * @param  callable(array<string, mixed>): T|null  $callback
     * @return array<int, T>
     */
    private function normalizeList(array $items, callable $callback): array
    {
        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $value = $callback($item);

            if ($value !== null) {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function numberValue(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (int) round((float) $value);
    }

    private function clampConfidence(mixed $value): float
    {
        $confidence = is_numeric($value) ? (float) $value : 0.0;

        return max(0.0, min(1.0, $confidence));
    }
}
