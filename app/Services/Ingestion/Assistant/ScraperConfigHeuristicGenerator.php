<?php

namespace App\Services\Ingestion\Assistant;

use Symfony\Component\DomCrawler\Crawler;

class ScraperConfigHeuristicGenerator
{
    /**
     * @return array{profile: string, config: array<string, mixed>, warnings: array<int, string>, confidence: float}
     */
    public function generate(string $type, string $sourceUrl, string $html): array
    {
        $normalizedType = mb_strtolower(trim($type));

        if ($normalizedType === 'rss') {
            return [
                'profile' => 'rss',
                'config' => [
                    'feed_url' => $sourceUrl,
                    'lang' => 'en',
                    'max_items' => 50,
                ],
                'warnings' => [],
                'confidence' => 0.95,
            ];
        }

        if ($normalizedType !== 'html') {
            return [
                'profile' => 'generic_listing',
                'config' => [
                    'profile' => 'generic_listing',
                    'list' => [
                        'link_selector' => 'article a',
                        'link_attr' => 'href',
                        'max_links' => 25,
                    ],
                    'article' => [
                        'content_selector' => 'article',
                        'remove_selectors' => ['script', 'style', 'nav', 'footer'],
                    ],
                    'best_effort' => true,
                ],
                'warnings' => [
                    'Unsupported scraper type for assistant heuristics. Generated a generic HTML listing profile.',
                ],
                'confidence' => 0.35,
            ];
        }

        return $this->generateHtmlConfig($sourceUrl, $html);
    }

    /**
     * @return array{profile: string, config: array<string, mixed>, warnings: array<int, string>, confidence: float}
     */
    private function generateHtmlConfig(string $sourceUrl, string $html): array
    {
        if ($this->looksLikeArchiveProfile($sourceUrl, $html)) {
            return [
                'profile' => 'wichita_archive_pdf_list',
                'config' => [
                    'profile' => 'wichita_archive_pdf_list',
                    'list' => [
                        'href_contains' => 'Archive.aspx?ADID=',
                        'max_links' => 50,
                    ],
                    'pdf' => [
                        'extract' => true,
                    ],
                ],
                'warnings' => [],
                'confidence' => 0.97,
            ];
        }

        if ($this->looksLikeDocumentersProfile($html)) {
            return [
                'profile' => 'wichitadocumenters',
                'config' => [
                    'profile' => 'wichitadocumenters',
                    'list' => [
                        'link_selector' => 'a[href*="docs.google.com"]',
                        'link_attr' => 'href',
                        'max_links' => 25,
                    ],
                ],
                'warnings' => [],
                'confidence' => 0.92,
            ];
        }

        return $this->generateGenericListingConfig($sourceUrl, $html);
    }

    private function looksLikeArchiveProfile(string $sourceUrl, string $html): bool
    {
        $sourceLower = mb_strtolower($sourceUrl);
        $htmlLower = mb_strtolower($html);

        if (str_contains($sourceLower, 'archive.aspx?amid=')) {
            return true;
        }

        if (str_contains($htmlLower, 'archive.aspx?adid=')) {
            return true;
        }

        return str_contains($htmlLower, 'summary="archive details"');
    }

    private function looksLikeDocumentersProfile(string $html): bool
    {
        return str_contains(mb_strtolower($html), 'docs.google.com');
    }

    /**
     * @return array{profile: string, config: array<string, mixed>, warnings: array<int, string>, confidence: float}
     */
    private function generateGenericListingConfig(string $sourceUrl, string $html): array
    {
        $crawler = new Crawler($html, $sourceUrl);

        $linkSelectorCandidates = [
            'article a',
            'main article a',
            '.entry a',
            '.post a',
            'h2 a',
            'h3 a',
            'main a',
            'a[href] ',
        ];

        $linkSelector = $this->firstSelectorWithCount($crawler, $linkSelectorCandidates, 2) ?? 'article a';
        $contentSelector = $this->firstSelectorWithCount($crawler, [
            'article',
            'main',
            '.entry-content',
            '.article-content',
            '#content',
            '.content',
        ], 1) ?? 'article';

        $warnings = [];
        $confidence = 0.72;

        if ($linkSelector === 'article a') {
            $warnings[] = 'Link selector confidence is moderate. Verify links in preview results.';
            $confidence = 0.64;
        }

        if ($contentSelector === 'article') {
            $warnings[] = 'Article content selector used default `article`.';
            $confidence = min($confidence, 0.61);
        }

        return [
            'profile' => 'generic_listing',
            'config' => [
                'profile' => 'generic_listing',
                'list' => [
                    'link_selector' => trim($linkSelector),
                    'link_attr' => 'href',
                    'max_links' => 25,
                ],
                'article' => [
                    'content_selector' => trim($contentSelector),
                    'remove_selectors' => ['script', 'style', 'nav', 'footer'],
                ],
                'best_effort' => true,
            ],
            'warnings' => $warnings,
            'confidence' => $confidence,
        ];
    }

    /**
     * @param  list<string>  $selectors
     */
    private function firstSelectorWithCount(Crawler $crawler, array $selectors, int $minimumCount): ?string
    {
        foreach ($selectors as $selector) {
            $trimmed = trim($selector);

            if ($trimmed === '') {
                continue;
            }

            try {
                $count = $crawler->filter($trimmed)->count();
            } catch (\Throwable) {
                continue;
            }

            if ($count >= $minimumCount) {
                return $trimmed;
            }
        }

        return null;
    }
}
