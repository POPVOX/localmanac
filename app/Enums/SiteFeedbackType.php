<?php

namespace App\Enums;

enum SiteFeedbackType: string
{
    case Like = 'like';
    case Dislike = 'dislike';
    case Trouble = 'trouble';
    case Suggestion = 'suggestion';

    public function label(): string
    {
        return match ($this) {
            self::Like => 'Like',
            self::Dislike => 'Dislike',
            self::Trouble => 'Trouble',
            self::Suggestion => 'Suggestion',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $type): string => $type->value,
            self::cases(),
        );
    }
}
