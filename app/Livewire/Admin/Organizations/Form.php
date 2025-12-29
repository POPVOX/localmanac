<?php

namespace App\Livewire\Admin\Organizations;

use App\Models\City;
use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Throwable;

class Form extends Component
{
    private const TYPES = [
        'government',
        'news_media',
        'nonprofit',
        'business',
        'school',
        'other',
    ];

    public ?Organization $organization = null;

    public ?int $cityId = null;

    public string $name = '';

    public string $slug = '';

    public string $type = 'government';

    public bool $shouldSyncSlugWithName = true;

    public ?string $website = null;

    public ?string $description = null;

    public function mount(?Organization $organization = null): void
    {
        $this->organization = $organization;

        if ($organization) {
            $this->cityId = $organization->city_id;
            $this->name = $organization->name;
            $this->slug = $organization->slug;
            $this->type = $organization->type;
            $this->website = $organization->website;
            $this->description = $organization->description;
        } else {
            $this->cityId = $this->cityId ?? City::query()->orderBy('name')->value('id');
        }
    }

    public function updatedName(string $value): void
    {
        if (! $this->shouldSyncSlugWithName) {
            return;
        }

        $this->slug = Str::slug($value);
    }

    public function updatedSlug(string $value): void
    {
        $this->shouldSyncSlugWithName = $value === '';
    }

    public function save(): RedirectResponse|Redirector|null
    {
        $this->slug = Str::slug($this->slug);

        try {
            $payload = $this->validate($this->rules());
            $payload['city_id'] = (int) $payload['cityId'];
            unset($payload['cityId']);

            $isUpdating = $this->organization !== null;

            if ($this->organization) {
                $this->organization->update($payload);
            } else {
                $this->organization = Organization::create($payload);
            }

            return redirect()->route('admin.organizations.index')->with('toast', [
                'message' => $isUpdating ? __('Organization updated') : __('Organization created'),
                'variant' => 'success',
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            if ($this->isConstraintViolation($exception, '23505', 'organizations_city_id_slug_unique')) {
                throw ValidationException::withMessages([
                    'slug' => __('An organization with this slug already exists for the selected city.'),
                ]);
            }

            if ($this->isConstraintViolation($exception, '23503', 'organizations_city_id_foreign')) {
                throw ValidationException::withMessages([
                    'cityId' => __('The selected city is invalid.'),
                ]);
            }

            if ($this->isConstraintViolation($exception, '23514', 'organizations_type_check')) {
                throw ValidationException::withMessages([
                    'type' => __('The selected type is invalid.'),
                ]);
            }

            report($exception);

            $this->dispatchToast(__('Unable to save organization'), 'danger');

            return null;
        } catch (Throwable $exception) {
            report($exception);

            $this->dispatchToast(__('Unable to save organization'), 'danger');

            return null;
        }
    }

    public function render(): View
    {
        $cities = City::query()
            ->orderBy('name')
            ->get();

        return view('livewire.admin.organizations.form', [
            'cities' => $cities,
            'types' => self::TYPES,
            'title' => $this->organization ? __('Edit Organization') : __('Create Organization'),
        ])->layout('layouts.admin', [
            'title' => $this->organization ? __('Edit Organization') : __('Create Organization'),
        ]);
    }

    protected function rules(): array
    {
        return [
            'cityId' => ['required', 'integer', 'exists:cities,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('organizations', 'slug')
                    ->where(fn ($query) => $query->where('city_id', $this->cityId))
                    ->ignore($this->organization?->id),
            ],
            'type' => ['required', Rule::in(self::TYPES)],
            'website' => ['nullable', 'url', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }

    private function dispatchToast(string $message, string $variant = 'success'): void
    {
        $this->dispatch('toast', message: $message, variant: $variant);
    }

    private function isConstraintViolation(QueryException $exception, string $sqlState, string $constraint): bool
    {
        $state = $exception->errorInfo[0] ?? null;

        if ($state !== $sqlState) {
            return false;
        }

        return str_contains($exception->getMessage(), $constraint);
    }
}
