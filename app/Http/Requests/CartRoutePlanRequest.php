<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CartRoutePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'origin' => ['required', 'array'],
            'origin.lat' => ['required', 'numeric', 'between:-90,90'],
            'origin.lng' => ['required', 'numeric', 'between:-180,180'],
            'chains' => ['required', 'array', 'min:1', 'max:10'],
            'chains.*' => ['required', 'string', 'max:64'],
            'mode' => ['required', 'string', 'in:walking,driving'],
        ];
    }

    /**
     * @return array{lat: float, lng: float}
     */
    public function origin(): array
    {
        /** @var array{lat: float|int|string, lng: float|int|string} $origin */
        $origin = $this->validated('origin');

        return [
            'lat' => (float) $origin['lat'],
            'lng' => (float) $origin['lng'],
        ];
    }

    /**
     * @return list<string>
     */
    public function chains(): array
    {
        /** @var list<string> $chains */
        $chains = array_values(array_unique($this->validated('chains')));

        return $chains;
    }

    /**
     * @return 'WALK'|'DRIVE'
     */
    public function travelMode(): string
    {
        return $this->validated('mode') === 'walking' ? 'WALK' : 'DRIVE';
    }
}
