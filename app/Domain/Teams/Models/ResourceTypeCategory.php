<?php

namespace App\Domain\Teams\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResourceTypeCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'sort_order',
    ];

    public function resourceTypes(): HasMany
    {
        return $this->hasMany(ResourceType::class, 'category_id');
    }
}
