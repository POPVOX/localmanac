<?php

namespace App\Services\Analysis;

final class ScoreDimensions
{
    public const COMPREHENSIBILITY = 'comprehensibility';

    public const ORIENTATION = 'orientation';

    public const REPRESENTATION = 'representation';

    public const AGENCY = 'agency';

    public const RELEVANCE = 'relevance';

    public const TIMELINESS = 'timeliness';

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return [
            self::COMPREHENSIBILITY,
            self::ORIENTATION,
            self::REPRESENTATION,
            self::AGENCY,
            self::RELEVANCE,
            self::TIMELINESS,
        ];
    }
}
