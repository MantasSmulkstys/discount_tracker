<?php

namespace App\Http\Middleware;

use App\Models\FamilySubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'subscribedFamilies' => $this->subscribedFamilies($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * @return array<int, int> family_id => subscription_id
     */
    private function subscribedFamilies(Request $request): array
    {
        $user = $request->user();
        if ($user === null) {
            return [];
        }

        if (! Schema::hasTable('family_subscriptions')) {
            return [];
        }

        $rows = FamilySubscription::query()
            ->where('user_id', $user->id)
            ->whereNotNull('family_id')
            ->get(['id', 'family_id']);

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->family_id] = (int) $row->id;
        }

        return $map;
    }
}
