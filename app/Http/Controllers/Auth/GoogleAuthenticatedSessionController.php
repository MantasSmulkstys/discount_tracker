<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ResolveGoogleUser;
use App\Http\Controllers\Controller;
use App\Support\Auth\GoogleOauthUser;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class GoogleAuthenticatedSessionController extends Controller
{
    public function redirect(): RedirectResponse
    {
        $this->ensureGoogleIsConfigured();

        return Socialite::driver('google')->redirect();
    }

    public function callback(ResolveGoogleUser $resolveGoogleUser): RedirectResponse
    {
        $this->ensureGoogleIsConfigured();

        try {
            $socialiteUser = Socialite::driver('google')->user();

            $user = $resolveGoogleUser->handle(new GoogleOauthUser(
                id: $socialiteUser->getId(),
                email: $socialiteUser->getEmail(),
                name: $socialiteUser->getName(),
                avatar: $socialiteUser->getAvatar(),
            ));

            Auth::login($user, true);
            request()->session()->regenerate();

            $home = $user->isAdmin() ? route('admin.reports') : route('home');

            return redirect()->intended($home);
        } catch (AuthenticationException $exception) {
            return to_route('login')->with('oauthError', $exception->getMessage());
        } catch (Throwable $exception) {
            Log::warning('Google authentication failed.', [
                'message' => $exception->getMessage(),
            ]);

            return to_route('login')->with('oauthError', __('Nepavyko prisijungti su Google. Bandykite dar kartą.'));
        }
    }

    /**
     * When Google credentials are missing from the environment, don't leak a 500 — act as if the route isn't registered.
     */
    private function ensureGoogleIsConfigured(): void
    {
        abort_if(
            blank(config('services.google.client_id')) || blank(config('services.google.client_secret')),
            Response::HTTP_NOT_FOUND,
        );
    }
}
