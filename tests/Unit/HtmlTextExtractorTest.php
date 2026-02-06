<?php

use App\Services\Chat\HtmlTextExtractor;

it('extracts block text as paragraphs', function () {
    $html = <<<'HTML'
    <html>
        <head><title>FAQ</title></head>
        <body>
            <main>
                <h2>Scooters</h2>
                <p>First paragraph about scooters.</p>
                <p>As of July 2019, the fines are $91.50 per citation.</p>
                <ul>
                    <li>Item one</li>
                    <li>Item two</li>
                </ul>
            </main>
        </body>
    </html>
    HTML;

    $extractor = new HtmlTextExtractor;
    $result = $extractor->extract($html, 'https://example.com');

    expect($result['text'])->toContain('Scooters')
        ->toContain('fines are $91.50')
        ->toContain("\n\n");
});

it('extracts accordion content as text when present', function () {
    $html = <<<'HTML'
    <html>
        <body>
            <main>
                <p>General information.</p>
            </main>
            <button aria-controls="faq-1">How much are the fines for scooter violations?</button>
            <div id="faq-1">
                <p>As of July 2019, the fines are $91.50 per citation.</p>
            </div>
        </body>
    </html>
    HTML;

    $extractor = new HtmlTextExtractor;
    $result = $extractor->extract($html, 'https://example.com');

    expect($result['text'])->toContain('General information.');
});

it('ignores json-ld faq payloads when extracting text', function () {
    $html = <<<'HTML'
    <html>
        <head>
            <script type="application/ld+json">
            {
                "@context": "https://schema.org",
                "@type": "FAQPage",
                "mainEntity": [
                    {
                        "@type": "Question",
                        "name": "What is being done to control rabies?",
                        "acceptedAnswer": {
                            "@type": "Answer",
                            "text": "Pets should be vaccinated against rabies."
                        }
                    }
                ]
            }
            </script>
        </head>
        <body></body>
    </html>
    HTML;

    $extractor = new HtmlTextExtractor;
    $result = $extractor->extract($html, 'https://example.com');

    expect($result['text'])->not->toContain('vaccinated');
});

it('extracts content links and uses aria labels', function () {
    $html = <<<'HTML'
    <html>
        <body>
            <nav>
                <a href="/calendar">Calendar</a>
            </nav>
            <main>
                <a href="/landfill/brooks" aria-label="Brooks Landfill Fees">
                    <span class="icon"></span>
                </a>
            </main>
        </body>
    </html>
    HTML;

    $extractor = new HtmlTextExtractor;
    $result = $extractor->extract($html, 'https://example.com');

    expect($result['content_links'])->toHaveCount(1)
        ->and($result['content_links'][0]['href'])->toBe('/landfill/brooks')
        ->and($result['content_links'][0]['text'])->toContain('Brooks Landfill');
});

it('prefers module content when extracting text and links', function () {
    $html = <<<'HTML'
    <html>
        <body>
            <nav>
                <a href="/calendar">Calendar</a>
            </nav>
            <div id="moduleContent">
                <div class="pageContent">
                    <p>Recycling &amp; Trash</p>
                    <a href="/712/Brooks-Landfill">Brooks C&amp;D Landfill</a>
                </div>
            </div>
        </body>
    </html>
    HTML;

    $extractor = new HtmlTextExtractor;
    $result = $extractor->extract($html, 'https://example.com');

    expect($result['text'])->toContain('Recycling')
        ->and($result['content_links'])->toHaveCount(1)
        ->and($result['content_links'][0]['href'])->toBe('/712/Brooks-Landfill');
});

it('extracts table rows as text', function () {
    $html = <<<'HTML'
    <html>
        <body>
            <main>
                <table>
                    <thead>
                        <tr><th>Material</th><th>Fee</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Secured load</td><td>$44.85 per ton</td></tr>
                    </tbody>
                </table>
            </main>
        </body>
    </html>
    HTML;

    $extractor = new HtmlTextExtractor;
    $result = $extractor->extract($html, 'https://example.com');

    expect($result['text'])->toContain('Material | Fee')
        ->and($result['text'])->toContain('Secured load | $44.85 per ton');
});
