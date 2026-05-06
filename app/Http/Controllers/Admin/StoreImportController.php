<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StoreImportController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'config' => ['required', 'file', 'max:512'],
        ]);

        $payload = $this->decodeConfig($request->file('config')->getRealPath());

        $slug = Str::slug((string) ($payload['store'] ?? ''));
        if ($slug === '') {
            throw ValidationException::withMessages([
                'config' => 'Konfigūracijoje trūksta `store` lauko (parduotuvės slug\'as).',
            ]);
        }

        $displayName = (string) ($payload['name'] ?? $payload['store'] ?? $slug);

        $configsDir = rtrim((string) config('scraper.path'), '/\\').DIRECTORY_SEPARATOR.'configs';
        if (! File::isDirectory($configsDir)) {
            File::makeDirectory($configsDir, recursive: true);
        }

        $targetPath = $configsDir.DIRECTORY_SEPARATOR.$slug.'.json';
        File::put(
            $targetPath,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                ."\n"
        );

        Store::updateOrCreate(
            ['slug' => $slug],
            [
                'name' => mb_strtolower($displayName),
                'website_url' => $payload['url'] ?? null,
                'is_active' => true,
                'logo_path' => "/store-logos/$slug.png",
            ],
        );

        return redirect()
            ->route('admin.scrape.index')
            ->with('success', "Parduotuvė \"{$displayName}\" importuota. Spauskite kortelę, kad pradėtumėte skenavimą.");
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeConfig(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw ValidationException::withMessages([
                'config' => 'Nepavyko nuskaityti įkelto failo.',
            ]);
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ValidationException::withMessages([
                'config' => 'Failas nėra galiojantis JSON: '.$e->getMessage(),
            ]);
        }

        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'config' => 'JSON šaknis turi būti objektas.',
            ]);
        }

        return $decoded;
    }
}
