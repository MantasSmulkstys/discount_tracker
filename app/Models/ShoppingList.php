<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShoppingList extends Model
{
    protected $guarded = [];

    protected $casts = [
        'items' => 'array',
        'store_ids' => 'array',
        'subtotal' => 'float',
        'original_total' => 'float',
        'savings' => 'float',
    ];

    /**
     * @return BelongsTo<User, self>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
