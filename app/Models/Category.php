<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $table = 'categories';

    public $timestamps = false;

    protected $fillable = ['name_lt', 'slug'];

    /**
     * @return HasMany<Subcategory, self>
     */
    public function subcategories(): HasMany
    {
        return $this->hasMany(Subcategory::class, 'category_id');
    }
}
