<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tool extends Model
{
    // tool_name/description live in tool_translations (one row per
    // locale) — this table only holds what's identical across every
    // language.
    protected $fillable = [
        'tool_slug',
        'status',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function categories(): HasMany
    {
        return $this->hasMany(ToolCategory::class);
    }

    public function toolExams(): HasMany
    {
        return $this->hasMany(ToolExam::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ToolTranslation::class);
    }

    /**
     * The translation for $locale (default: current app locale), falling
     * back to the app's fallback_locale, then to whichever translation
     * happens to exist — so a caller never has to null-check just because
     * one language is still missing.
     */
    public function translate(?string $locale = null): ?ToolTranslation
    {
        $locale ??= app()->getLocale();

        return $this->translations->firstWhere('locale', $locale)
            ?? $this->translations->firstWhere('locale', config('app.fallback_locale'))
            ?? $this->translations->first();
    }
}
