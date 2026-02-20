<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ProviderFailedOver;
use Laravel\Ai\Events\ToolInvoked;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('access-admin', fn (User $user): bool => $user->isSuperAdmin());
        Gate::define('manage-raw-scraper-config', fn (User $user): bool => $user->isSuperAdmin());

        RateLimiter::for('ask', function (Request $request) {
            return Limit::perMinute((int) config('chat.rate_limit_per_minute', 30))
                ->by($request->ip());
        });

        Event::listen(AgentFailedOver::class, function (AgentFailedOver $event): void {
            Log::warning('AI agent provider failover triggered.', [
                'agent' => get_class($event->agent),
                'provider' => $event->provider->name(),
                'driver' => $event->provider->driver(),
                'model' => $event->model,
                'exception' => $event->exception::class,
                'message' => $event->exception->getMessage(),
            ]);
        });

        Event::listen(ProviderFailedOver::class, function (ProviderFailedOver $event): void {
            Log::warning('AI provider failover triggered.', [
                'provider' => $event->provider->name(),
                'driver' => $event->provider->driver(),
                'model' => $event->model,
                'exception' => $event->exception::class,
                'message' => $event->exception->getMessage(),
            ]);
        });

        Event::listen(InvokingTool::class, function (InvokingTool $event): void {
            Log::info('AI tool invocation started.', [
                'invocation_id' => $event->invocationId,
                'tool_invocation_id' => $event->toolInvocationId,
                'agent' => get_class($event->agent),
                'tool' => get_class($event->tool),
                'arguments' => $event->arguments,
            ]);
        });

        Event::listen(ToolInvoked::class, function (ToolInvoked $event): void {
            Log::info('AI tool invocation completed.', [
                'invocation_id' => $event->invocationId,
                'tool_invocation_id' => $event->toolInvocationId,
                'agent' => get_class($event->agent),
                'tool' => get_class($event->tool),
            ]);
        });
    }
}
