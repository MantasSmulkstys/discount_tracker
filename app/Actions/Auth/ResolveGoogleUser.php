<?php

namespace App\Actions\Auth;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Auth\GoogleOauthUser;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ResolveGoogleUser
{
    /**
     * @throws AuthenticationException
     */
    public function handle(GoogleOauthUser $googleUser): User
    {
        if (blank($googleUser->email)) {
            throw new AuthenticationException('Google did not return an email address for this account.');
        }

        return DB::transaction(function () use ($googleUser): User {
            $user = User::query()
                ->where('google_id', $googleUser->id)
                ->orWhere('email', $googleUser->email)
                ->lockForUpdate()
                ->first();

            if ($user === null) {
                return User::query()->create([
                    'name' => $this->resolveName($googleUser),
                    'email' => $googleUser->email,
                    'google_id' => $googleUser->id,
                    'google_avatar' => $googleUser->avatar,
                    'email_verified_at' => now(),
                    'password' => Hash::make(Str::random(40)),
                    'role' => UserRole::User,
                ]);
            }

            if ($user->google_id !== null && $user->google_id !== $googleUser->id) {
                throw new AuthenticationException('This email address is already linked to a different Google account.');
            }

            $user->forceFill([
                'name' => $user->name ?: $this->resolveName($googleUser),
                'google_id' => $googleUser->id,
                'google_avatar' => $googleUser->avatar,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            return $user;
        });
    }

    private function resolveName(GoogleOauthUser $googleUser): string
    {
        if (filled($googleUser->name)) {
            return $googleUser->name;
        }

        return Str::before($googleUser->email ?? 'Google User', '@');
    }
}
