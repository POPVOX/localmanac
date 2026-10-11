<?php

use App\Services\Chat\HtmlTextExtractor;

it('keeps application URLs beside their labels without changing crawl links', function () {
    $html = '<body><nav><a href="/unrelated">Unrelated menu</a></nav><main>'
        .'<p>Download the <a href="docs/designation.pdf">Designation Application Form</a>.</p>'
        .'<p>For a separate tax benefit, use <a href="/tax.pdf">Tax Exemption Application</a>.</p>'
        .'<p><a href="#top">Top</a> <a href="javascript:alert(1)">Toggle</a> '
        .'<a href="mailto:help@example.gov">Email us</a></p>'
        .'<p><a href="https://example.gov/permits">https://example.gov/permits</a></p>'
        .'</main></body>';

    $result = (new HtmlTextExtractor)->extract($html, 'https://example.gov/planning/landmarks.html');

    expect($result['text'])
        ->toContain('Designation Application Form (https://example.gov/planning/docs/designation.pdf)')
        ->toContain('Tax Exemption Application (https://example.gov/tax.pdf)')
        ->not->toContain('javascript:', 'mailto:', '(#top)', '/unrelated')
        ->and(substr_count($result['text'], 'https://example.gov/permits'))->toBe(1)
        ->and($result['content_links'][0])->toBe(['href' => 'docs/designation.pdf', 'text' => 'Designation Application Form']);
});

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

it('recognizes legacy municipal content wrappers without following the header menu', function (string $wrapper) {
    $html = '<html><body><nav><a href="/shopping">Shopping</a></nav>'
        .'<div '.$wrapper.'><h2>Public notices</h2><a href="/agenda.pdf">Council agenda</a>'
        .'<section class="sidebarMainLinks"><a href="/contact">Contact us</a></section>'
        .'<div class="footer"><a href="/social">Social media</a></div></div>'
        .'<footer><a href="/privacy">Privacy</a></footer></body></html>';

    $result = (new HtmlTextExtractor)->extract($html, 'https://example.gov');

    expect($result['content_links'])->toBe([['href' => '/agenda.pdf', 'text' => 'Council agenda']])
        ->and($result['text'])->toContain('Public notices')
        ->not->toContain('Shopping')->not->toContain('Privacy')->not->toContain('Contact us')->not->toContain('Social media')
        ->and($result['links'])->toHaveCount(5);
})->with(['class="mainContent"', 'id="main"']);

it('uses content outside navigation when a page has no recognized content wrapper', function () {
    $result = (new HtmlTextExtractor)->extract(
        '<body><nav><a href="/menu">Menu</a></nav><div><a href="/permit">Permit application</a></div></body>',
        'https://example.gov'
    );

    expect($result['content_links'])->toBe([['href' => '/permit', 'text' => 'Permit application']]);
});

it('does not fall back to unrelated links when the main article has no links', function () {
    $result = (new HtmlTextExtractor)->extract(
        '<body><main><p>Residents may apply at city hall.</p></main><aside><a href="/sale">Advertisement</a></aside></body>',
        'https://example.gov'
    );

    expect($result['content_links'])->toBeEmpty();
});

it('excludes FAQ tab navigation while preserving answers inside an accordion tablist', function () {
    $html = '<html><body><main><h1>City services</h1>'
        .'<div role="tablist"><ul><li><a role="tab" href="#waste">WASTE NAVIGATION ONLY</a></li><li><a role="tab" href="#historic">HISTORIC NAVIGATION ONLY</a></li></ul></div>'
        .'<div class="TA-tab-content TA-accordionMode" role="tablist">'
        .'<div class="accordionTabHead"><a role="tab" href="#waste">WASTE NAVIGATION ONLY</a></div>'
        .'<div role="tabpanel" id="waste"><p>Household hazardous waste collection is held in April and October.</p></div>'
        .'<div role="tabpanel" id="historic"><p>Contact the <a href="/planning">Planning Department</a> for historic designation.</p></div>'
        .'</div></main></body></html>';
    $result = app(\App\Services\Chat\HtmlTextExtractor::class)->extract($html, 'https://example.gov/faq');
    expect($result['text'])->toContain('Household hazardous waste collection is held in April and October.')
        ->toContain('Planning Department (https://example.gov/planning)')
        ->not->toContain('NAVIGATION ONLY');
});
