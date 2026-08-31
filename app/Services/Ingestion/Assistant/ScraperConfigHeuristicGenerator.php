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
                'profile' => 'civicplus_archive_pdf_list',
                'config' => [
                    'profile' => 'civicplus_archive_pdf_list',
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
                'profile' => 'documenters',
                'config' => [
                    'profile' => 'documenters',
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

    private function looksLikeWixBlogProfile(string $html): bool
    {
        $htmlLower = mb_strtolower($html);

        return str_contains($htmlLower, 'wix.com website builder')
            || str_contains($htmlLower, 'wixstatic.com');
    }

    /**
     * @return array{profile: string, config: array<string, mixed>, warnings: array<int, string>, confidence: float}
     */
    private function generateGenericListingConfig(string $sourceUrl, string $html): array
    {
        $crawler = new Crawler($html, $sourceUrl);
        $htmlLower = mb_strtolower($html);

        if (str_contains($htmlLower, 'fusion-app')) {
            $arcLinkSelector = $this->firstSelectorWithCount($crawler, [
                '.flex-feature-feed h4.headline a[href^="/20"]',
                '.flex-feature-feed .headlines a[href^="/20"]',
                '.flex-feature-feed h4 a[href^="/20"]',
                'h4.headline a[href^="/20"]',
            ], 2);

            if ($arcLinkSelector !== null) {
                return [
                    'profile' => 'generic_listing',
                    'config' => [
                        'profile' => 'generic_listing',
                        'list' => [
                            'link_selector' => trim($arcLinkSelector),
                            'link_attr' => 'href',
                            'max_links' => 25,
                        ],
                        'article' => [
                            'content_selector' => '.article-body',
                            'remove_selectors' => ['script', 'style', 'nav', 'footer'],
                        ],
                        'best_effort' => true,
                    ],
                    'warnings' => [],
                    'confidence' => 0.86,
                ];
            }
        }

        if ($this->looksLikeWixBlogProfile($html)) {
            $wixLinkSelector = $this->firstSelectorWithCount($crawler, [
                'main a[href*="/post/"]',
                'a[href*="/post/"]',
                'main a[href*="/blog/post/"]',
                'a[href*="/blog/post/"]',
            ], 2);

            if ($wixLinkSelector !== null) {
                return [
                    'profile' => 'generic_listing',
                    'config' => [
                        'profile' => 'generic_listing',
                        'list' => [
                            'link_selector' => trim($wixLinkSelector),
                            'link_attr' => 'href',
                            'max_links' => 25,
                            'max_pages' => 5,
                            'pagination_selector' => 'a[href*="/page/"]',
                            'pagination_attr' => 'href',
                        ],
                        'article' => [
                            'content_selector' => 'main',
                            'remove_selectors' => ['script', 'style', 'nav', 'footer'],
                        ],
                        'best_effort' => true,
                    ],
                    'warnings' => [],
                    'confidence' => 0.84,
                ];
            }
        }

        $linkSelectorCandidates = [
            '.entry-title a',
            'h2.entry-title a',
            'main a[href*="/post/"]',
            'a[href*="/post/"]',
            '.post-title a',
            '.article-title a',
            'article h2 a',
            'article h3 a',
            'article a',
            'main article a',
            '.entry a',
            '.post a',
            'h4.headline a',
            '.headlines a',
            'h4 a',
            'h2 a',
            'h3 a',
            'main a',
            'a[href] ',
        ];

        $linkSelector = $this->firstSelectorWithCount($crawler, $linkSelectorCandidates, 2) ?? 'article a';
        $contentSelector = $this->firstSelectorWithCount($crawler, [
            '.entry-content',
            '.article-body',
            '.article-content',
            'main article',
            'article',
            'main',
            '#content',
            '.content',
        ], 1) ?? 'article';

        $warnings = [];
        $confidence = 0.72;
        $broadLinkSelectors = ['article a', 'main article a', 'h2 a', 'h3 a', 'h4 a', 'main a', 'a[href]'];
        $genericContentSelectors = ['article', 'main', '#content', '.content'];

        if (in_array($linkSelector, $broadLinkSelectors, true)) {
            $warnings[] = 'Link selector confidence is moderate. Verify links in preview results.';
            $confidence = 0.64;
        }

        if (in_array($contentSelector, $genericContentSelectors, true)) {
            $warnings[] = 'Article content selector confidence is moderate. Verify extracted body text in preview.';
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
