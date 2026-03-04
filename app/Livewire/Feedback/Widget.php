<?php

namespace App\Livewire\Feedback;

use App\Enums\SiteFeedbackType;
use App\Models\City;
use App\Models\SiteFeedback;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Throwable;

class Widget extends Component
{
    public string $type = '';

    public string $message = '';

    public bool $submitted = false;

    public string $pageUrl = '';

    public ?string $routeName = null;

    public ?int $cityId = null;

    public function mount(): void
    {
        $this->pageUrl = request()->fullUrl();
        $this->routeName = request()->route()?->getName();
        $this->cityId = $this->resolveCityId();
    }

    public function submit(): void
    {
        $this->resetErrorBag('feedback');

        try {
            $payload = $this->validate($this->rules());

            $userId = auth()->id();

            if (! $userId) {
                $this->addError('feedback', __('You must be logged in to submit feedback.'));

                return;
            }

            SiteFeedback::query()->create([
                'user_id' => $userId,
                'type' => $payload['type'],
                'message' => trim($payload['message']),
                'page_url' => $this->pageUrl,
                'route_name' => $this->routeName,
                'city_id' => $this->cityId,
            ]);

            $this->submitted = true;
            $this->type = '';
            $this->message = '';
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            $this->addError('feedback', __('We could not submit your feedback. Please try again.'));
        }
    }

    public function submitAnother(): void
    {
        $this->submitted = false;
        $this->type = '';
        $this->message = '';
        $this->resetErrorBag();
    }

    public function render(): View
    {
        return view('livewire.feedback.widget', [
            'feedbackTypes' => SiteFeedbackType::cases(),
        ]);
    }

    protected function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(SiteFeedbackType::values())],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ];
    }

    private function resolveCityId(): ?int
    {
        $cityId = request()->integer('city_id');

        if ($cityId <= 0) {
            return null;
        }

        return City::query()->whereKey($cityId)->value('id');
    }
}
