<?php

namespace App\Services\Chat;

use Symfony\Component\DomCrawler\Crawler;

class HtmlTextExtractor
{
    /**
     * @return array{
     *     title: string|null,
     *     canonical_url: string|null,
     *     text: string,
     *     link_count: int,
     *     content_links: array<int, array{href: string, text: string}>,
     *     links: array<int, array{href: string, text: string}>,
     * }
     */
    public function extract(string $html, string $baseUrl): array
    {
        $crawler = new Crawler($html, $baseUrl);

        $title = null;
        if ($crawler->filter('title')->count() > 0) {
            $title = trim($crawler->filter('title')->first()->text());
        }

        $canonicalUrl = null;
        if ($crawler->filter('link[rel="canonical"]')->count() > 0) {
            $canonicalHref = trim($crawler->filter('link[rel="canonical"]')->first()->attr('href') ?? '');

            if ($canonicalHref !== '') {
                $canonicalUrl = $canonicalHref;
            }
        }

        $links = $this->extractLinks($crawler);
        $contentLinks = $this->extractContentLinks($crawler);
        $text = $this->extractMainText($crawler);

        return [
            'title' => $title,
            'canonical_url' => $canonicalUrl,
            'text' => $text,
            'link_count' => count($links),
            'content_links' => $contentLinks,
            'links' => $links,
        ];
    }

    /**
     * @return array<int, array{href: string, text: string}>
     */
    private function extractLinks(Crawler $crawler): array
    {
        $links = [];

        foreach ($crawler->filter('a[href]') as $node) {
            $href = trim($node->getAttribute('href'));
            $text = trim($node->textContent ?? '');

            if ($text === '') {
                $text = trim((string) $node->getAttribute('aria-label'));
            }

            if ($text === '') {
                $text = trim((string) $node->getAttribute('title'));
            }

            if ($text === '') {
                $text = trim((string) $node->getAttribute('data-title'));
            }

            if ($href === '') {
                continue;
            }

            $links[] = [
                'href' => $href,
                'text' => $text === '' ? $href : $text,
            ];
        }

        return $links;
    }

    /**
     * @return array<int, array{href: string, text: string}>
     */
    private function extractContentLinks(Crawler $crawler): array
    {
        $selectors = [
            '#moduleContent',
            '[data-cpRole="mainContentContainer"]',
            '#page',
            '.moduleContentNew',
            'main',
            '[role="main"]',
            'article',
            '#content',
            '.content',
            '.page-content',
            '.main-content',
        ];

        foreach ($selectors as $selector) {
            if ($crawler->filter($selector)->count() === 0) {
                continue;
            }

            $links = $this->extractLinks($crawler->filter($selector));

            if ($links !== []) {
                return $links;
            }
        }

        return [];
    }

    private function extractMainText(Crawler $crawler): string
    {
        $selectors = [
            '#moduleContent',
            '[data-cpRole="mainContentContainer"]',
            '#page',
            '.moduleContentNew',
            'main',
            '[role="main"]',
            'article',
            '#content',
            '.content',
            '.page-content',
            '.main-content',
        ];

        foreach ($selectors as $selector) {
            if ($crawler->filter($selector)->count() === 0) {
                continue;
            }

            $text = $this->extractParagraphText($crawler->filter($selector));

            if ($text !== '') {
                return $text;
            }
        }

        $fallback = $crawler->filter('body')->count() > 0
            ? $crawler->filter('body')
            : $crawler;

        $text = $this->extractParagraphText($fallback);

        if ($text !== '') {
            return $text;
        }

        $rawText = $fallback->text('', true);

        return $this->normalizeWhitespace($rawText);
    }

    private function extractParagraphText(Crawler $crawler): string
    {
        $paragraphs = [];
        $selector = 'h1, h2, h3, h4, h5, h6, p, li, dt, dd, blockquote, summary';
        $tableText = $this->extractTableText($crawler);

        foreach ($tableText as $rowText) {
            $paragraphs[] = $rowText;
        }

        if ($crawler->filter($selector)->count() === 0) {
            if ($tableText !== []) {
                return trim(implode("\n\n", $tableText));
            }

            $text = $crawler->text('', true);

            return $this->normalizeWhitespace($text);
        }

        foreach ($crawler->filter($selector) as $node) {
            $text = trim($node->textContent ?? '');

            if ($text === '') {
                continue;
            }

            $paragraphs[] = $this->normalizeWhitespace($text);
        }

        return trim(implode("\n\n", $paragraphs));
    }

    /**
     * @return array<int, string>
     */
    private function extractTableText(Crawler $crawler): array
    {
        $rows = [];

        foreach ($crawler->filter('table') as $tableNode) {
            $table = new Crawler($tableNode);

            foreach ($table->filter('tr') as $rowNode) {
                $row = new Crawler($rowNode);
                $cells = [];

                foreach ($row->filter('th, td') as $cellNode) {
                    $cellText = $this->normalizeWhitespace(trim($cellNode->textContent ?? ''));

                    if ($cellText === '') {
                        continue;
                    }

                    $cells[] = $cellText;
                }

                if ($cells === []) {
                    continue;
                }

                $rows[] = implode(' | ', $cells);
            }
        }

        return array_values(array_unique($rows));
    }

    private function normalizeWhitespace(string $text): string
    {
        $text = preg_replace("/\r\n?/", "\n", $text) ?? '';
        $text = preg_replace('/[ \\t]+/', ' ', $text) ?? '';
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? '';

        return trim($text);
    }
}
