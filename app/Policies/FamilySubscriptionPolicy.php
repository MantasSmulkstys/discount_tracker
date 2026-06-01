<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FamilySubscription;
use App\Models\User;

class FamilySubscriptionPolicy
{
    public function view(User $user, FamilySubscription $subscription): bool
    {
        return $subscription->user_id === $user->id;
    }

    public function delete(User $user, FamilySubscription $subscription): bool
    {
        return $subscription->user_id === $user->id;
    }
}
