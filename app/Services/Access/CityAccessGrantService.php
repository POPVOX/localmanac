<?php

namespace App\Services\Access;

use App\Models\City;
use App\Models\CityAccessCode;
use App\Models\CityAccessCodeRedemption;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CityAccessGrantService
{
    public function grant(User $user, City $city, ?CityAccessCode $accessCode = null): void
    {
        if ($accessCode && ((int) $accessCode->city_id !== (int) $city->getKey() || ! $accessCode->isAvailable())) {
            throw new InvalidArgumentException('The access code cannot grant access to this city.');
        }

        DB::transaction(function () use ($user, $city, $accessCode): void {
            $user->cities()->syncWithoutDetaching([$city->getKey()]);

            if (! $accessCode) {
                return;
            }

            CityAccessCodeRedemption::query()->firstOrCreate(
                [
                    'city_id' => $city->getKey(),
                    'user_id' => $user->getKey(),
                ],
                [
                    'city_access_code_id' => $accessCode->getKey(),
                    'redeemed_at' => now(),
                ],
            );

            $accessCode->forceFill(['last_redeemed_at' => now()])->save();
        });
    }
}
