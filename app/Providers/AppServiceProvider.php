<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Maps\PlacesApiClient;
use App\Services\Maps\RoutesApiClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RoutesApiClient::class, fn (): RoutesApiClient => new RoutesApiClient(
            apiKey: config('services.google_maps.server_key') ?: config('services.google_maps.key'),
        ));

        $this->app->singleton(PlacesApiClient::class, fn (): PlacesApiClient => new PlacesApiClient(
            apiKey: config('services.google_maps.server_key') ?: config('services.google_maps.key'),
        ));
    }

    public function boot(): void
    {
        $this->configureDefaults();
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        Gate::define('access-admin', fn (User $user): bool => $user->isAdmin());

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
