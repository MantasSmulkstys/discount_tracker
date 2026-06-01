<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SavedCart;
use App\Models\User;

class SavedCartPolicy
{
    public function view(User $user, SavedCart $savedCart): bool
    {
        return $savedCart->user_id === $user->id;
    }

    public function update(User $user, SavedCart $savedCart): bool
    {
        return $savedCart->user_id === $user->id;
    }

    public function delete(User $user, SavedCart $savedCart): bool
    {
        return $savedCart->user_id === $user->id;
    }
}
