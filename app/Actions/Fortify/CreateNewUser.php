<?php

namespace App\Actions\Fortify;

use App\Models\City;
use App\Models\CityAccessCode;
use App\Models\User;
use App\Services\Access\CityAccessGrantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
            'city_code' => ['nullable', 'string', 'max:100'],
        ]);

        $cityCode = trim((string) ($input['city_code'] ?? ''));
        $canLookUpCityCode = $cityCode !== '' && mb_strlen($cityCode) <= 100;
        $accessCode = $canLookUpCityCode ? CityAccessCode::findMatchingAvailable($cityCode) : null;
        $city = $accessCode?->city
            ?? ($canLookUpCityCode ? City::findByLegacyChatAccessCode($cityCode) : null);

        $validator->after(function ($validator) use ($cityCode, $city): void {
            if ($cityCode !== '' && ! $city) {
                $validator->errors()->add('city_code', __('That city access code is not valid.'));
            }
        });

        $validator->validate();

        return DB::transaction(function () use ($input, $city, $accessCode): User {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            if ($city) {
                app(CityAccessGrantService::class)->grant($user, $city, $accessCode);
            }

            return $user;
        });
    }
}
