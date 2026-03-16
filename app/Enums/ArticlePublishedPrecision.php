<?php

namespace App\Enums;

enum ArticlePublishedPrecision: string
{
    case Date = 'date';
    case DateTime = 'datetime';

    public function includesTime(): bool
    {
        return $this === self::DateTime;
    }
}
