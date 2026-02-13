<?php

namespace App\Support;

class Utf8Sanitizer
{
    public static function sanitize(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $sanitized = $value;

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $sanitized);

            if (is_string($converted)) {
                $sanitized = $converted;
            }
        }

        if (! mb_check_encoding($sanitized, 'UTF-8')) {
            $converted = mb_convert_encoding($sanitized, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');

            if (is_string($converted)) {
                $sanitized = $converted;
            }
        }

        $sanitized = str_replace("\0", '', $sanitized);

        return $sanitized;
    }
}
