<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToolCategoryTranslation extends Model
{
    protected $fillable = [
        'tool_category_id',
        'locale',
        'label',
        'description',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ToolCategory::class, 'tool_category_id');
    }
}
