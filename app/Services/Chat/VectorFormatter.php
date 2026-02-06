<?php

namespace App\Services\Chat;

class VectorFormatter
{
    /**
     * @param  array<int, float>  $vector
     */
    public function toSql(array $vector): string
    {
        $values = array_map(
            fn (float $value) => rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.'),
            $vector
        );

        return '['.implode(',', $values).']';
    }
}
