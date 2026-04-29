<?php

namespace App\Support\Auth;

final readonly class GoogleOauthUser
{
    public function __construct(
        public string $id,
        public ?string $email,
        public ?string $name,
        public ?string $avatar,
    ) {}
}
