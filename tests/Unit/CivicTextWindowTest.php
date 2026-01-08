<?php

use App\Services\Extraction\CivicTextWindow;

it('builds a deterministic window that preserves the opening and caps length', function () {
    $opening = str_repeat('Opening sentence. ', 60);
    $filler = str_repeat('Background context about the town. ', 80);
    $civicBlock = 'Public hearing scheduled for March 15, 2030 at 6:00 PM. Location: City Hall, 100 Main St. Agenda includes zoning ordinance 2025-12.';
    $civicBlockTwo = 'Residents may submit comments by April 1, 2030. Visit https://example.com/agenda for details.';
    $tail = str_repeat('More updates about parks and programs. ', 80);

    $text = $opening."\n\n".$filler."\n\n".$civicBlock."\n\n".$civicBlockTwo."\n\n".$tail;

    $window = CivicTextWindow::build($text);

    expect($window)->toStartWith(mb_substr($text, 0, 600));
    expect(mb_strlen($window))->toBeLessThanOrEqual(3000);
    expect(CivicTextWindow::build($text))->toBe($window);
});
