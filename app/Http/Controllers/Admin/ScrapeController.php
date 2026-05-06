<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\NormalizePendingJob;
use App\Jobs\ScrapeStoreJob;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ScrapeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Scrape', [
            'stores' => $this->stores(),
        ]);
    }

    public function runStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'store' => ['required', 'string', 'exists:stores,slug'],
        ]);

        $store = Store::where('slug', $validated['store'])->firstOrFail();

        Bus::chain([
            new ScrapeStoreJob($store->id),
            new NormalizePendingJob,
        ])->dispatch();

        return back()->with('success', "Paleistas \"{$store->name}\" skenavimas.");
    }

    public function normalize(): RedirectResponse
    {
        NormalizePendingJob::dispatch();

        return back()->with('success', 'Paleista AI normalizacija.');
    }

    public function runAll(): RedirectResponse
    {
        $stores = Store::where('is_active', true)->orderBy('name')->get();

        if ($stores->isEmpty()) {
            return back()->with('error', 'Nėra aktyvių parduotuvių.');
        }

        $chain = $stores
            ->map(fn (Store $s): ScrapeStoreJob => new ScrapeStoreJob($s->id))
            ->all();
        $chain[] = new NormalizePendingJob;

        Bus::chain($chain)->dispatch();

        return back()->with('success', 'Paleistas pilnas procesas (visos parduotuvės).');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function stores(): array
    {
        return Store::where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Store $s): array => [
                'id' => $s->id,
                'slug' => $s->slug,
                'name' => Str::title($s->name),
                'logoPath' => $s->logo_path,
                'scrapeCron' => $s->scrape_cron,
            ])
            ->all();
    }
}
