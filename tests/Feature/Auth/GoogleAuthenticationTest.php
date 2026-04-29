<?php

use App\Actions\Auth\ResolveGoogleUser;
use App\Enums\UserRole;
use App\Models\User;
use App\Support\Auth\GoogleOauthUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Laravel\Socialite\Facades\Socialite;

beforeEach(function () {
    Auth::logout();

    config()->set('services.google', [
        'client_id' => 'test-google-client-id',
        'client_secret' => 'test-google-client-secret',
        'redirect' => 'http://localhost/auth/google/callback',
    ]);
});

test('guests can be redirected to google', function () {
    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('redirect')
        ->once()
        ->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));

    Socialite::shouldReceive('driver')
        ->once()
        ->with('google')
        ->andReturn($provider);

    $response = $this->get(route('auth.google.redirect'));

    $response->assertRedirect('https://accounts.google.com/o/oauth2/auth');
});

test('google callback creates a verified user with the user role', function () {
    $socialiteUser = Mockery::mock(SocialiteUserContract::class);
    $socialiteUser->shouldReceive('getId')->andReturn('google-user-1');
    $socialiteUser->shouldReceive('getEmail')->andReturn('google-user@example.com');
    $socialiteUser->shouldReceive('getName')->andReturn('Google User');
    $socialiteUser->shouldReceive('getAvatar')->andReturn('https://example.com/avatar.png');

    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->once()->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')
        ->once()
        ->with('google')
        ->andReturn($provider);

    $response = $this->get(route('auth.google.callback'));

    $user = User::query()->where('email', 'google-user@example.com')->firstOrFail();

    expect($user->google_id)->toBe('google-user-1');
    expect($user->role)->toBe(UserRole::User);
    expect($user->email_verified_at)->not->toBeNull();
    $response->assertRedirect(route('home'));
});

test('google routes return 404 when credentials are not configured', function () {
    config()->set('services.google', [
        'client_id' => null,
        'client_secret' => null,
        'redirect' => null,
    ]);

    $this->get(route('auth.google.redirect'))->assertNotFound();
    $this->get(route('auth.google.callback'))->assertNotFound();
});

test('google users are linked to an existing account by email', function () {
    $email = 'existing-user-'.Str::lower(Str::random(8)).'@example.com';
    $googleId = 'google-user-'.Str::lower(Str::random(8));

    $user = User::factory()->create([
        'email' => $email,
        'email_verified_at' => null,
        'google_id' => null,
    ]);

    $resolvedUser = app(ResolveGoogleUser::class)->handle(new GoogleOauthUser(
        id: $googleId,
        email: $email,
        name: 'Existing User',
        avatar: 'https://example.com/avatar-2.png',
    ));

    $user->refresh();

    expect($resolvedUser->is($user))->toBeTrue();
    expect($user->google_id)->toBe($googleId);
    expect($user->google_avatar)->toBe('https://example.com/avatar-2.png');
    expect($user->email_verified_at)->not->toBeNull();
});

test('google callback redirects back to login when google does not return an email', function () {
    $socialiteUser = Mockery::mock(SocialiteUserContract::class);
    $socialiteUser->shouldReceive('getId')->andReturn('google-user-3');
    $socialiteUser->shouldReceive('getEmail')->andReturn(null);
    $socialiteUser->shouldReceive('getName')->andReturn('No Email User');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);

    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->once()->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')
        ->once()
        ->with('google')
        ->andReturn($provider);

    $response = $this->get(route('auth.google.callback'));

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('oauthError');
    expect(User::query()->where('google_id', 'google-user-3')->exists())->toBeFalse();
});
