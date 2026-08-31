<?php

namespace App\Livewire\Admin\Cities;

use App\Models\City;
use App\Models\CityAccessCode;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class AccessCodes extends Component
{
    use WithPagination;

    public City $city;

    public string $label = '';

    public string $plainTextCode = '';

    public string $description = '';

    public ?string $expiresAt = null;

    public ?string $createdCodePlainText = null;

    public ?string $createdCodeLabel = null;

    public function createCode(): void
    {
        $payload = $this->validate([
            'label' => [
                'required',
                'string',
                'max:255',
                Rule::unique('city_access_codes', 'label')
                    ->where('city_id', $this->city->getKey()),
            ],
            'plainTextCode' => ['required', 'string', 'min:8', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'expiresAt' => ['nullable', 'date', 'after:now'],
        ]);

        $plainTextCode = trim($payload['plainTextCode']);

        if ($this->plainTextCodeExists($plainTextCode)) {
            throw ValidationException::withMessages([
                'plainTextCode' => __('That code is already in use. Choose a unique code so campaign attribution stays unambiguous.'),
            ]);
        }

        try {
            $expiresAt = filled($payload['expiresAt'])
                ? CarbonImmutable::parse($payload['expiresAt'], $this->city->timezone)->utc()
                : null;

            CityAccessCode::query()->create([
                'city_id' => $this->city->getKey(),
                'label' => trim($payload['label']),
                'description' => trim((string) $payload['description']) ?: null,
                'code_hash' => Hash::make($plainTextCode),
                'lookup_digest' => CityAccessCode::lookupDigest($plainTextCode),
                'is_active' => true,
                'expires_at' => $expiresAt,
            ]);

            $this->createdCodePlainText = $plainTextCode;
            $this->createdCodeLabel = trim($payload['label']);
            $this->reset('label', 'plainTextCode', 'description', 'expiresAt');
            $this->resetValidation();
            $this->dispatchToast(__('Access code created'), __('The code is active and ready to share.'));
        } catch (Throwable $exception) {
            report($exception);
            $this->dispatchToast(__('Code creation failed'), __('We could not create this access code.'), 'danger');
        }
    }

    public function toggleCode(int $codeId): void
    {
        try {
            $code = $this->city->accessCodes()->findOrFail($codeId);
            $code->update(['is_active' => ! $code->is_active]);

            $this->dispatchToast(
                $code->is_active ? __('Access code activated') : __('Access code paused'),
                $code->is_active
                    ? __('Members can use this code again.')
                    : __('This code can no longer grant new access.'),
            );
        } catch (ModelNotFoundException $exception) {
            report($exception);
            $this->dispatchToast(__('Access code not found'), __('Refresh the page and try again.'), 'danger');
        } catch (Throwable $exception) {
            report($exception);
            $this->dispatchToast(__('Update failed'), __('We could not update this access code.'), 'danger');
        }
    }

    public function clearCreatedCode(): void
    {
        $this->createdCodePlainText = null;
        $this->createdCodeLabel = null;
    }

    public function render(): View
    {
        $codes = $this->city->accessCodes()
            ->withCount('redemptions')
            ->orderByDesc('created_at')
            ->get();

        $redemptions = $this->city->accessCodeRedemptions()
            ->with(['accessCode', 'user'])
            ->orderByDesc('redeemed_at')
            ->paginate(20);

        return view('livewire.admin.cities.access-codes', [
            'codes' => $codes,
            'redemptions' => $redemptions,
            'memberCount' => $this->city->users()->count(),
            'timezone' => $this->city->timezone ?: config('app.timezone', 'UTC'),
        ])->layout('layouts.admin', [
            'title' => __('Access Codes · :city', ['city' => $this->city->name]),
        ]);
    }

    private function plainTextCodeExists(string $plainTextCode): bool
    {
        if (CityAccessCode::plainTextCodeExists($plainTextCode)) {
            return true;
        }

        return City::query()
            ->whereNotNull('chat_access_code_hash')
            ->get()
            ->contains(fn (City $city): bool => $city->matchesLegacyChatAccessCode($plainTextCode));
    }

    private function dispatchToast(string $heading, string $message, string $variant = 'success'): void
    {
        $this->dispatch('toast', heading: $heading, message: $message, variant: $variant);
    }
}
