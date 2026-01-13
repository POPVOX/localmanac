<?php

namespace App\Livewire\Admin\Cities;

use App\Models\City;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Throwable;

class Form extends Component
{
    public ?City $city = null;

    public string $name = '';

    public string $slug = '';

    public bool $slugManuallySet = false;

    public function mount(?City $city = null): void
    {
        $this->city = $city;

        if ($city) {
            $this->name = $city->name;
            $this->slug = $city->slug;
            $this->slugManuallySet = true;
        }
    }

    public function updatedName(string $value): void
    {
        if (! $this->slugManuallySet) {
            $this->slug = Str::slug($value);
        }
    }

    public function updatedSlug(): void
    {
        $this->slugManuallySet = true;
    }

    public function save(): RedirectResponse|Redirector|null
    {
        try {
            $payload = $this->validate($this->rules());
            $payload['slug'] = Str::slug($payload['slug']);

            $isUpdating = $this->city !== null;

            if ($this->city) {
                $this->city->update($payload);
            } else {
                $this->city = City::create($payload);
            }

            return redirect()->route('admin.cities.index')->with('toast', [
                'heading' => $isUpdating ? __('City updated') : __('City created'),
                'message' => __('Your changes have been saved.'),
                'variant' => 'success',
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            $this->dispatchToast(__('City save failed'), __('We could not save the city.'), 'danger');

            return null;
        }
    }

    public function render(): View
    {
        return view('livewire.admin.cities.form', [
            'title' => $this->city ? __('Edit City') : __('Create City'),
        ])->layout('layouts.admin', [
            'title' => $this->city ? __('Edit City') : __('Create City'),
        ]);
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('cities', 'slug')->ignore($this->city?->id),
            ],
        ];
    }

    private function dispatchToast(string $heading, string $message, string $variant = 'success'): void
    {
        $this->dispatch('toast', heading: $heading, message: $message, variant: $variant);
    }
}
