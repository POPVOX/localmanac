<?php

namespace App\Services\Extraction;

final class EvidencePackResult
{
    /**
     * @param  array{
     *   date_like_count: int,
     *   time_like_count: int,
     *   has_url: bool,
     *   has_email: bool,
     *   has_phone: bool,
     *   heading_like_count: int,
     *   bullet_like_count: int
     * }  $signalsFull
     * @param  array{
     *   date_like_count: int,
     *   time_like_count: int,
     *   has_url: bool,
     *   has_email: bool,
     *   has_phone: bool,
     *   heading_like_count: int,
     *   bullet_like_count: int
     * }  $signalsPack
     * @param  array<int, array{
     *   type: string,
     *   start: int,
     *   end: int,
     *   score?: float,
     *   why?: string
     * }>  $slices
     */
    public function __construct(
        public readonly string $packText,
        public readonly int $originalLength,
        public readonly int $packLength,
        public readonly array $signalsFull,
        public readonly array $signalsPack,
        public readonly bool $rebuildUsed,
        public readonly array $slices,
    ) {}
}
