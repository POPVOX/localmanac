<?php

namespace App\Services\Extraction;

use App\Models\Article;
use App\Models\IssueArea;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
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
     *   process_timeline: array{
     *     items: array<int, array{key: string, label: string, date: string|null, status: string, badge_text: string|null, note: string|null, evidence: array<int, array{quote: string, start?: int, end?: int}>>>,
     *     current_key: string|null
     *   },
     *   explainer: array{
     *     whats_happening: string|null,
     *     why_it_matters: string|null,
     *     key_details: array<int, string>|null,
     *     what_to_watch: array<int, string>|null,
     *     evidence: array<string, array<int, array{quote: string, start?: int, end?: int}>>|null
     *   },
     *   confidence: float
     * }
     *
     * The explainer field is produced by the LLM and has the same shape as normalizeExplainer() returns:
     *   - whats_happening: string|null
     *   - why_it_matters: string|null
     *   - key_details: array<int, string>|null
     *   - what_to_watch: array<int, string>|null
     *   - evidence: array<string, array<int, array{quote: string, start?: int, end?: int}>>|null
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
        $originalLength = mb_strlen($cleanedText);

        if ($originalLength < $minChars) {
            return $this->emptyPayload();
        }

        $maxChars = (int) config('enrichment.max_text_chars', 18000);
        if ($originalLength > $maxChars) {
            $cleanedText = mb_substr($cleanedText, 0, $maxChars);
        }

        $evidencePack = (new EvidencePackBuilder)->build($cleanedText);
        $packText = $evidencePack->packText;

        Log::debug('Evidence pack built.', [
            'original_length' => $evidencePack->originalLength,
            'pack_length' => $evidencePack->packLength,
            'rebuild_used' => $evidencePack->rebuildUsed,
            'signals_full' => $evidencePack->signalsFull,
            'signals_pack' => $evidencePack->signalsPack,
        ]);

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
                ->withSchema($this->civicSchema($issueAreaSlugs))
                ->withPrompt($this->civicPrompt($article, $packText, $issueAreaSlugs))
                ->withClientOptions([
                    'timeout' => (int) config('enrichment.http_timeout', 120),
                ])
                ->withClientRetry(
                    (int) config('enrichment.http_retries', 2),
                    (int) config('enrichment.http_retry_sleep_ms', 250)
                )
                ->asStructured();

            Log::debug('Civic enrichment call completed.', [
                'article_id' => $article->id,
            ]);

            $civicPayload = $this->normalizeCivicPayload($response->structured);
        } catch (\Throwable $e) {
            report($e);

            Log::debug('Civic enrichment call failed.', [
                'article_id' => $article->id,
            ]);

            return $this->emptyPayload();
        }

        $payload = [
            'analysis' => $civicPayload['analysis'],
            'enrichment' => $this->normalizeEnrichmentPayload(null, $issueAreaSlugs)['enrichment'],
            'process_timeline' => $civicPayload['process_timeline'],
            'explainer' => $this->emptyExplainer(), // will be replaced by explainer call
            'confidence' => $civicPayload['confidence'],
        ];

        try {
            $response = Prism::structured()
                ->using(
                    config('enrichment.provider', 'openai'),
                    config('enrichment.model', 'gpt-4o-mini')
                )
                ->withSchema($this->enrichmentSchema($issueAreaSlugs))
                ->withPrompt($this->enrichmentPrompt($article, $packText, $issueAreaSlugs))
                ->withClientOptions([
                    'timeout' => (int) config('enrichment.http_timeout', 120),
                ])
                ->withClientRetry(
                    (int) config('enrichment.http_retries', 2),
                    (int) config('enrichment.http_retry_sleep_ms', 250)
                )
                ->asStructured();

            Log::debug('Entity enrichment call completed.', [
                'article_id' => $article->id,
            ]);

            $enrichmentPayload = $this->normalizeEnrichmentPayload($response->structured, $issueAreaSlugs);
        } catch (\Throwable $e) {
            report($e);

            Log::debug('Entity enrichment call failed.', [
                'article_id' => $article->id,
            ]);

            $enrichmentPayload = $this->normalizeEnrichmentPayload(null, $issueAreaSlugs);
        }

        $payload['enrichment'] = $enrichmentPayload['enrichment'];
        $payload['confidence'] = $this->mergeOverallConfidence(
            $civicPayload['confidence'],
            $enrichmentPayload['confidence']
        );

        // Explainer pass (demo-style "What's happening" / "Why it matters")
        try {
            $response = Prism::structured()
                ->using(
                    config('enrichment.provider', 'openai'),
                    config('enrichment.model', 'gpt-4o-mini')
                )
                ->withSchema($this->explainerSchema())
                ->withPrompt($this->explainerPrompt($article, $packText))
                ->withClientOptions([
                    'timeout' => (int) config('enrichment.http_timeout', 120),
                ])
                ->withClientRetry(
                    (int) config('enrichment.http_retries', 2),
                    (int) config('enrichment.http_retry_sleep_ms', 250)
                )
                ->asStructured();

            Log::debug('Explainer enrichment call completed.', [
                'article_id' => $article->id,
            ]);

            $payload['explainer'] = $this->normalizeExplainer($response->structured['explainer'] ?? null);
        } catch (\Throwable $e) {
            report($e);

            Log::debug('Explainer enrichment call failed.', [
                'article_id' => $article->id,
            ]);

            // Keep empty explainer
            $payload['explainer'] = $this->emptyExplainer();
        }

        return $payload;
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
     *   process_timeline: array{
     *     items: array<int, array{key: string, label: string, date: string|null, status: string, badge_text: string|null, note: string|null, evidence: array<int, array{quote: string, start?: int, end?: int}>>>,
     *     current_key: string|null
     *   },
     *   explainer: array{
     *     whats_happening: string|null,
     *     why_it_matters: string|null,
     *     key_details: array<int, string>|null,
     *     what_to_watch: array<int, string>|null,
     *     evidence: array<string, array<int, array{quote: string, start?: int, end?: int}>>|null
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
            'process_timeline' => [
                'items' => [],
                'current_key' => null,
            ],
            'explainer' => $this->emptyExplainer(),
            'confidence' => 0.0,
        ];
    }

    /**
     * @return array{
     *   whats_happening: null,
     *   why_it_matters: null,
     *   key_details: null,
     *   what_to_watch: null,
     *   evidence: null
     * }
     *
     * @note Always returns empty/null explainer; not produced by LLM.
     */
    private function emptyExplainer(): array
    {
        return [
            'whats_happening' => null,
            'why_it_matters' => null,
            'key_details' => null,
            'what_to_watch' => null,
            'evidence' => null,
        ];
    }

    /**
     * @param  array<int, string>  $issueAreaSlugs
     */
    private function civicPrompt(Article $article, string $packText, array $issueAreaSlugs): string
    {
        $cityName = $article->city?->name ?? 'Unknown';
        $title = $article->title ?? 'Untitled';
        $organization = $article->scraper?->organization?->name ?? 'Unknown';

        return <<<PROMPT
You are an information extraction system for a civic intelligence platform.
Be precise, conservative, and evidence-driven. Do not invent facts.

ARTICLE CONTEXT
City: {$cityName}
Article title: {$title}
Source organization: {$organization}

TASKS
1) CIVIC RELEVANCE ANALYSIS
Provide scores from 0.0 to 1.0 for: comprehensibility, orientation, representation, agency, relevance, timeliness.
Provide a short evidence-based justification sentence per dimension. If unclear, leave justification blank and score low.
Scoring anchors: 0.0 not present, 0.3 weak, 0.6 clear, 0.9 strong with specifics.
If the text contains civic/process language or dates, you must not return 0.0 for all dimensions.

2) OPPORTUNITIES
Extract explicit civic participation opportunities.
Each includes: type (meeting|public_comment|deadline|application|other), date (YYYY-MM-DD or null), time (HH:MM or null), location (or null), url (or null), description, evidence.
Return an empty array if none. Do not invent.

3) PROCESS TIMELINE
Return process_timeline with items and current_key.
Each item includes: key (snake_case), label, date (YYYY-MM-DD or null), status (completed|current|upcoming|unknown), badge_text (or null), note (or null), evidence.
Only include explicitly mentioned steps. Do not infer.

EVIDENCE RULES
Every extracted list item must include evidence quotes with: quote, start, end. Use null for unknown offsets.

OUTPUT FORMAT (STRICT)
Return ONLY valid JSON matching the schema. No markdown or extra keys.

ARTICLE TEXT
{$packText}
PROMPT;
    }

    private function explainerPrompt(Article $article, string $packText): string
    {
        $cityName = $article->city?->name ?? 'Unknown';
        $title = $article->title ?? 'Untitled';
        $organization = $article->scraper?->organization?->name ?? 'Unknown';

        return <<<PROMPT
You are an explainer writer for a civic intelligence platform.
Be accurate, plain-spoken, and helpful. Do not invent facts.
Use only what is in the ARTICLE TEXT.

ARTICLE CONTEXT
City: {$cityName}
Article title: {$title}
Source organization: {$organization}

TASK
Produce a concise, demo-style explainer with:
- whats_happening: 2–4 sentences summarizing the situation in plain English.
- why_it_matters: 1–3 sentences explaining why a resident should care.
- key_details: up to 5 bullet-style strings (dates, times, locations, dollar amounts, deadlines) ONLY if explicitly present.
- what_to_watch: up to 3 short strings about next known steps ONLY if explicitly present.
- evidence: optional map with evidence quotes for whats_happening and why_it_matters.

STYLE
- No jargon unless the text uses it; if needed, explain it briefly.
- Be specific with dates/times/places when present.
- If details are missing, omit that list item instead of guessing.

EVIDENCE RULES
If you include evidence, provide exact quotes. Offsets are optional.

OUTPUT FORMAT (STRICT)
Return ONLY valid JSON matching the schema. No markdown or extra keys.

ARTICLE TEXT
{$packText}
PROMPT;
    }

    private function explainerSchema(): Schema
    {
        $evidenceSchema = $this->evidenceSchema();

        $explainerEvidenceMapSchema = new ObjectSchema(
            name: 'explainer_evidence',
            description: 'Evidence quotes for explainer sections.',
            properties: [
                new ArraySchema('whats_happening', 'Evidence quotes for whats_happening.', $evidenceSchema),
                new ArraySchema('why_it_matters', 'Evidence quotes for why_it_matters.', $evidenceSchema),
            ],
            requiredFields: ['whats_happening', 'why_it_matters'],
            allowAdditionalProperties: false
        );

        $explainerSchema = new ObjectSchema(
            name: 'explainer',
            description: 'Demo-style explainer content.',
            properties: [
                new StringSchema('whats_happening', 'Plain-English summary.', true),
                new StringSchema('why_it_matters', 'Why a resident should care.', true),
                new ArraySchema('key_details', 'Up to 5 key details.', new StringSchema('item', 'A key detail.')),
                new ArraySchema('what_to_watch', 'Up to 3 things to watch.', new StringSchema('item', 'A watch item.')),
                new ObjectSchema(
                    name: 'evidence',
                    description: 'Optional evidence map.',
                    properties: [
                        new ArraySchema('whats_happening', 'Evidence quotes for whats_happening.', $evidenceSchema),
                        new ArraySchema('why_it_matters', 'Evidence quotes for why_it_matters.', $evidenceSchema),
                    ],
                    requiredFields: ['whats_happening', 'why_it_matters'],
                    allowAdditionalProperties: false
                ),
            ],
            requiredFields: ['whats_happening', 'why_it_matters', 'key_details', 'what_to_watch', 'evidence'],
            allowAdditionalProperties: false
        );

        return new ObjectSchema(
            name: 'explainer_payload',
            description: 'Structured explainer response.',
            properties: [
                $explainerSchema,
            ],
            requiredFields: ['explainer'],
            allowAdditionalProperties: false
        );
    }

    /**
     * @param  array<int, string>  $issueAreaSlugs
     */
    private function enrichmentPrompt(Article $article, string $packText, array $issueAreaSlugs): string
    {
        $issueAreas = $issueAreaSlugs === []
            ? 'None. Return an empty issue_areas array.'
            : implode(', ', $issueAreaSlugs);

        $cityName = $article->city?->name ?? 'Unknown';
        $title = $article->title ?? 'Untitled';
        $organization = $article->scraper?->organization?->name ?? 'Unknown';

        return <<<PROMPT
You are an information extraction system for a civic intelligence platform.
Be precise, conservative, and evidence-driven. Do not invent facts.

ARTICLE CONTEXT
City: {$cityName}
Article title: {$title}
Source organization: {$organization}
Allowed issue areas (slugs): {$issueAreas}

TASKS
1) PEOPLE
Extract explicitly named individuals. Include role only if stated. Evidence required.

2) ORGANIZATIONS
Extract explicitly named organizations. type_guess must be one of: government|news_media|nonprofit|business|school|other.

3) LOCATIONS
Extract explicitly named locations. Include address only if verbatim.

4) KEYWORDS
Extract civic/process-focused keywords. Lowercase, dedupe, max 15.

5) ISSUE AREAS
Choose ONLY from the allowed issue areas list. Omit if not confident.

EVIDENCE RULES
Every extracted item must include evidence quotes with: quote, start, end. Use null for unknown offsets.

CONFIDENCE RULE
For each list item, set confidence 0..1. If confidence < 0.55, omit the item.
Also provide enrichment.confidence and overall confidence.

OUTPUT FORMAT (STRICT)
Return ONLY valid JSON matching the schema. No markdown or extra keys.

ARTICLE TEXT
{$packText}
PROMPT;
    }

    /**
     * @param  array<int, string>  $issueAreaSlugs
     */
    private function civicSchema(array $issueAreaSlugs): Schema
    {
        $evidenceSchema = $this->evidenceSchema();

        return new ObjectSchema(
            name: 'civic_payload',
            description: 'Structured civic relevance analysis response.',
            properties: [
                $this->analysisSchema($evidenceSchema),
                $this->processTimelineSchema($evidenceSchema),
                new NumberSchema('confidence', 'Overall confidence from 0 to 1.', false, null, 1, null, 0),
            ],
            requiredFields: ['analysis', 'process_timeline', 'confidence'],
            allowAdditionalProperties: false
        );
    }

    /**
     * @param  array<int, string>  $issueAreaSlugs
     */
    private function enrichmentSchema(array $issueAreaSlugs): Schema
    {
        $evidenceSchema = $this->evidenceSchema();

        return new ObjectSchema(
            name: 'enrichment_payload',
            description: 'Structured entity enrichment response.',
            properties: [
                $this->enrichmentPayloadSchema($evidenceSchema, $issueAreaSlugs),
                new NumberSchema('confidence', 'Overall confidence from 0 to 1.', false, null, 1, null, 0),
            ],
            requiredFields: ['enrichment', 'confidence'],
            allowAdditionalProperties: false
        );
    }

    private function evidenceSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'evidence_item',
            description: 'A direct quote from the article text with optional character offsets.',
            properties: [
                new StringSchema('quote', 'Exact quote from the article text.'),
                new NumberSchema('start', 'Start offset of the quote in the text.', true),
                new NumberSchema('end', 'End offset of the quote in the text.', true),
            ],
            requiredFields: ['quote', 'start', 'end'],
            allowAdditionalProperties: false
        );
    }

    private function analysisSchema(ObjectSchema $evidenceSchema): ObjectSchema
    {
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

        return new ObjectSchema(
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
    }

    private function processTimelineSchema(ObjectSchema $evidenceSchema): ObjectSchema
    {
        $processTimelineItemSchema = new ObjectSchema(
            name: 'process_timeline_item',
            description: 'A step in the civic process timeline.',
            properties: [
                new StringSchema('key', 'Stable key for this step.'),
                new StringSchema('label', 'Short label for this step.'),
                new StringSchema('date', 'YYYY-MM-DD if present.', true),
                new EnumSchema('status', 'Status of this step.', ['completed', 'current', 'upcoming', 'unknown']),
                new StringSchema('badge_text', 'Short badge text if present.', true),
                new StringSchema('note', 'Optional short detail for this step.', true),
                new ArraySchema('evidence', 'Supporting quotes.', $evidenceSchema),
            ],
            requiredFields: [
                'key',
                'label',
                'date',
                'status',
                'badge_text',
                'note',
                'evidence',
            ],
            allowAdditionalProperties: false
        );

        return new ObjectSchema(
            name: 'process_timeline',
            description: 'Timeline for where we are in the process.',
            properties: [
                new ArraySchema('items', 'Timeline items.', $processTimelineItemSchema),
                new StringSchema('current_key', 'Key of the current step.', true),
            ],
            requiredFields: ['items', 'current_key'],
            allowAdditionalProperties: false
        );
    }

    /**
     * @param  array<int, string>  $issueAreaSlugs
     */
    private function enrichmentPayloadSchema(ObjectSchema $evidenceSchema, array $issueAreaSlugs): ObjectSchema
    {
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

        return new ObjectSchema(
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
     *   process_timeline: array{
     *     items: array<int, array{key: string, label: string, date: string|null, status: string, badge_text: string|null, note: string|null, evidence: array<int, array{quote: string, start?: int, end?: int}>>>,
     *     current_key: string|null
     *   },
     *   explainer: array{
     *     whats_happening: null,
     *     why_it_matters: null,
     *     key_details: null,
     *     what_to_watch: null,
     *     evidence: null
     *   },
     *   confidence: float
     * }
     *
     * @note The explainer field is always empty/null and is not produced by the LLM call.
     */
    private function normalizeCivicPayload(?array $structured): array
    {
        $structured = is_array($structured) ? $structured : [];
        $analysis = Arr::get($structured, 'analysis', []);

        return [
            'analysis' => $this->normalizeAnalysis($analysis),
            'process_timeline' => $this->normalizeProcessTimeline(Arr::get($structured, 'process_timeline', [])),
            'confidence' => $this->clampConfidence(Arr::get($structured, 'confidence', 0.0)),
        ];
    }

    /**
     * @param  array<int, string>  $issueAreaSlugs
     */
    private function normalizeEnrichmentPayload(?array $structured, array $issueAreaSlugs): array
    {
        $structured = is_array($structured) ? $structured : [];
        $enrichment = Arr::get($structured, 'enrichment', []);

        return [
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
     *   items: array<int, array{
     *     key: string,
     *     label: string,
     *     date: string|null,
     *     status: string,
     *     badge_text: string|null,
     *     note: string|null,
     *     evidence: array<int, array{quote: string, start?: int, end?: int}>
     *   }>,
     *   current_key: string|null
     * }
     */
    private function normalizeProcessTimeline(mixed $timeline): array
    {
        if (! is_array($timeline)) {
            return [
                'items' => [],
                'current_key' => null,
            ];
        }

        $items = Arr::get($timeline, 'items', []);
        if (! is_array($items)) {
            $items = [];
        }
        $currentKey = $this->stringValue($timeline['current_key'] ?? null);
        $allowedStatuses = ['completed', 'current', 'upcoming', 'unknown'];

        $normalized = $this->normalizeList($items, function (array $item) use ($allowedStatuses): ?array {
            $key = $this->stringValue($item['key'] ?? null);
            $label = $this->stringValue($item['label'] ?? null);

            if ($key === null || $label === null) {
                return null;
            }

            $status = $this->stringValue($item['status'] ?? null);
            if ($status !== null) {
                $status = strtolower($status);
            }

            if ($status === null || ! in_array($status, $allowedStatuses, true)) {
                $status = 'unknown';
            }

            return [
                'key' => $key,
                'label' => $label,
                'date' => $this->stringValue($item['date'] ?? null),
                'status' => $status,
                'badge_text' => $this->stringValue($item['badge_text'] ?? null),
                'note' => $this->stringValue($item['note'] ?? null),
                'evidence' => $this->normalizeEvidence($item['evidence'] ?? []),
            ];
        });

        return [
            'items' => $normalized,
            'current_key' => $currentKey,
        ];
    }

    /**
     * @return array{
     *   whats_happening: string|null,
     *   why_it_matters: string|null,
     *   key_details: array<int, string>|null,
     *   what_to_watch: array<int, string>|null,
     *   evidence: array<string, array<int, array{quote: string, start?: int, end?: int}>>|null
     * }
     */
    private function normalizeExplainer(mixed $explainer): array
    {
        if (! is_array($explainer)) {
            return $this->emptyExplainer();
        }

        return [
            'whats_happening' => $this->stringValue($explainer['whats_happening'] ?? null),
            'why_it_matters' => $this->stringValue($explainer['why_it_matters'] ?? null),
            'key_details' => $this->normalizeStringList($explainer['key_details'] ?? null),
            'what_to_watch' => $this->normalizeStringList($explainer['what_to_watch'] ?? null),
            'evidence' => $this->normalizeEvidenceMap($explainer['evidence'] ?? null),
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
            $allowed = ['meeting', 'public_comment', 'deadline', 'application', 'other'];
            if (! in_array($type, $allowed, true)) {
                return null;
            }

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
     * @return array<int, string>|null
     */
    private function normalizeStringList(mixed $items): ?array
    {
        if (! is_array($items)) {
            return null;
        }

        $normalized = [];

        foreach ($items as $item) {
            $value = $this->stringValue($item);

            if ($value !== null) {
                $normalized[] = $value;
            }
        }

        if ($normalized === []) {
            return null;
        }

        return array_slice($normalized, 0, 5);
    }

    /**
     * @return array<string, array<int, array{quote: string, start?: int, end?: int}>>|null
     */
    private function normalizeEvidenceMap(mixed $evidence): ?array
    {
        if (! is_array($evidence)) {
            return null;
        }

        $normalized = [];
        $sections = ['whats_happening', 'why_it_matters'];

        foreach ($sections as $section) {
            $items = $evidence[$section] ?? null;

            if (! is_array($items)) {
                continue;
            }

            $list = $this->normalizeEvidence($items);

            if ($list !== []) {
                $normalized[$section] = $list;
            }
        }

        return $normalized === [] ? null : $normalized;
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

    private function mergeOverallConfidence(float $civicConfidence, float $enrichmentConfidence): float
    {
        if ($civicConfidence > 0.0) {
            return $civicConfidence;
        }

        return $enrichmentConfidence;
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
