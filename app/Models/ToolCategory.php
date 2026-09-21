<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ToolCategory extends Model
{
    // label/description live in tool_category_translations; icon isn't
    // text so it stays here, identical across every locale.
    protected $fillable = [
        'uid',
        'content_hash',
        'tool_id',
        'category_slug',
        'icon',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function tool(): BelongsTo
    {
        return $this->belongsTo(Tool::class);
    }

    public function toolExams(): HasMany
    {
        return $this->hasMany(ToolExam::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ToolCategoryTranslation::class);
    }

    public function translate(?string $locale = null): ?ToolCategoryTranslation
    {
        $locale ??= app()->getLocale();

        return $this->translations->firstWhere('locale', $locale)
            ?? $this->translations->firstWhere('locale', config('app.fallback_locale'))
            ?? $this->translations->first();
    }
}
