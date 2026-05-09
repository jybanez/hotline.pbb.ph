<?php

namespace App\Domain\Teams\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourceType extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'name',
        'unit_label',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ResourceTypeCategory::class, 'category_id');
    }
}
