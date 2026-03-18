<?php

use App\Services\Articles\MeetingSummaryFallback;

it('replaces a generic council recap explainer with specific meeting items', function () {
    $text = implode("\n\n", [
        'Yesterday at the City Council meeting, the Council heard the following items:',
        '* Consent Agenda with the exception of item 6 – Approved 7/0',
        '* Board of Bids and Contracts – Approved 7/0',
        '* Petitions for Public Improvements – Approved 7/0',
        'The video may be found here.',
    ]);

    $result = (new MeetingSummaryFallback)->narrative(
        title: 'March 10 City Council Meeting recap',
        cleanedText: $text,
        whatsHappening: 'During the March 10 City Council meeting, various items were discussed. The Council focused on important local issues affecting Wichita residents.',
        whyItMatters: 'Understanding the outcomes of these meetings helps residents stay informed about community decisions and local governance.',
    );

    expect($result['whats_happening'])
        ->toContain('Consent Agenda with the exception of item 6')
        ->toContain('Board of Bids and Contracts')
        ->not->toContain('various items were discussed')
        ->and($result['why_it_matters'])->toBeNull();
});

it('derives a concrete why-it-matters line when meeting notes mention public input', function () {
    $text = implode("\n\n", [
        'District 2 Advisory Board Meeting Summary Notes',
        'Documenter name: Jeanette Harding',
        'Council member Becky Tuttle began the meeting at 6:00 p.m.',
        'Katie Eddy from the Parks and Recreation department presented on the Imagine ICT! Master Plan process.',
        'There is an additional online survey available that closes this Friday where they hope to have 150 more completed.',
        'Also online is an interactive map where you can drop and pin and leave a comment.',
        'There is a survey available online for the board and members of the public to submit their thoughts on economic mobility in Wichita.',
        'The next meeting is on Apr. 13.',
    ]);

    $result = (new MeetingSummaryFallback)->narrative(
        title: 'District 2 Advisory Board — Notes',
        cleanedText: $text,
        whatsHappening: 'The District 2 Advisory Board met to discuss community issues and opportunities.',
        whyItMatters: 'Residents should be aware of these discussions as they directly impact local initiatives and community engagement.',
    );

    expect($result['whats_happening'])
        ->toContain('Imagine ICT! Master Plan process')
        ->and($result['why_it_matters'])->toContain('public survey')
        ->and($result['why_it_matters'])->toContain('interactive map');
});

it('keeps strong explainer narrative unchanged', function () {
    $text = 'Board members reviewed a zoning case, heard staff reports, and opened a public survey.';

    $result = (new MeetingSummaryFallback)->narrative(
        title: 'District 2 Advisory Board — Notes',
        cleanedText: $text,
        whatsHappening: 'Board members reviewed a zoning case, heard staff reports, and opened a public survey for residents.',
        whyItMatters: 'Residents can respond through the survey before the next meeting.',
    );

    expect($result['whats_happening'])
        ->toBe('Board members reviewed a zoning case, heard staff reports, and opened a public survey for residents.')
        ->and($result['why_it_matters'])->toBe('Residents can respond through the survey before the next meeting.');
});

it('replaces teaser explainers on rss council recap pages', function () {
    $text = implode("\n\n", [
        'News Flash',
        'March 10 City Council Meeting recap',
        'Yesterday at the City Council meeting, the Council heard the following items:',
        'Consent Agenda with the exception of item 6 – Approved 7/0',
        'Consent Agenda item 6, Contract Documents for Main Water Treatment Plant Conversion to Emergency Use – Approved 6/1 (No-Johnston)',
        'Board of Bids and Contracts – Approved 7/0',
        'Petitions for Public Improvements – Approved 7/0',
        'Public Hearings Considering an Amendment to a Tax Increment Financing Project Plan 3A – Approved 7/0',
        'The video may be found here and the agenda report may be found here.',
        'Related News',
    ]);

    $result = (new MeetingSummaryFallback)->narrative(
        title: 'March 10 City Council Meeting recap',
        cleanedText: $text,
        whatsHappening: 'Yesterday at the City Council meeting, the Council heard the following items:',
        whyItMatters: null,
    );

    expect($result['whats_happening'])
        ->toContain('Consent Agenda with the exception of item 6')
        ->toContain('Board of Bids and Contracts')
        ->not->toContain('heard the following items')
        ->and($result['why_it_matters'])->toBeNull();
});
