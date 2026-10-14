<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

class ContactLinks
{
    private const PHONE_PATTERN = '/(?<![\pL\d])(?:\+?1[ .-]?)?(?:\(\d{3}\)[ .-]?|\d{3}[ .-])\d{3}[ .-]\d{4}(?:\s*(?:ext\.?|x)\s*\d{1,6})?(?!\d)/iu';

    private const ADDRESS_PATTERN = '/\b\d{1,6}\s+(?:[A-Z0-9][A-Za-z0-9.\x27-]*\s+){1,6}(?i:Street|St|Avenue|Ave|Road|Rd|Boulevard|Blvd|Drive|Dr|Lane|Ln|Court|Ct|Circle|Cir|Way|Terrace|Ter|Place|Pl|Parkway|Pkwy|Highway|Hwy)\b/u';

    /** @return array<int, array{label: string, url: string}> */
    public static function phones(string $text): array
    {
        preg_match_all(self::PHONE_PATTERN, html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5), $matches);
        $phones = [];
        foreach ($matches[0] as $label) {
            $parts = preg_split('/\s*(?:ext\.?|x)\s*/i', $label);
            $digits = preg_replace('/\D/', '', $parts[0]);
            $number = strlen($digits) === 10 ? '+1'.$digits : '+'.$digits;
            $url = 'tel:'.$number.(isset($parts[1]) ? ';ext='.$parts[1] : '');
            $phones[$url] = ['label' => $label, 'url' => $url];
        }

        return array_values($phones);
    }

    /** Escape source text before adding links; never render source HTML. */
    public static function html(?string $text): HtmlString
    {
        $text ??= '';
        preg_match_all(self::PHONE_PATTERN, $text, $phoneMatches, PREG_OFFSET_CAPTURE);
        preg_match_all(self::ADDRESS_PATTERN, $text, $addressMatches, PREG_OFFSET_CAPTURE);
        $matches = array_merge($phoneMatches[0], $addressMatches[0]);
        usort($matches, fn (array $a, array $b): int => $a[1] <=> $b[1]);
        $html = '';
        $offset = 0;
        foreach ($matches as [$label, $position]) {
            if ($position < $offset) {
                continue;
            }
            $phone = self::phones($label)[0] ?? null;
            $html .= e(substr($text, $offset, $position - $offset));
            $url = $phone['url'] ?? self::mapUrl($label);
            $attributes = $phone ? '' : ' target="_blank" rel="noopener noreferrer"';
            $html .= '<a class="underline underline-offset-2" href="'.e($url).'"'.$attributes.'>'.e($label).'</a>';
            $offset = $position + strlen($label);
        }

        return new HtmlString($html.e(substr($text, $offset)));
    }

    public static function mapUrl(string $place): string
    {
        return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode(trim($place));
    }

    public static function markdownLabel(string $label): string
    {
        return str_replace(["\r", "\n"], ' ', preg_replace('/([\\\\`*_{}\[\]<>])/u', '\\\\$1', $label) ?? $label);
    }
}
