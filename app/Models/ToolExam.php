<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ToolExam extends Model
{
    protected $fillable = [
        'uid',
        'content_hash',
        'tool_id',
        'exam_id',
        'tool_category_id',
        'public_slug',
        'is_active',
        'sort_order',
        'popular_rank',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'popular_rank' => 'integer',
    ];

    public function tool(): BelongsTo
    {
        return $this->belongsTo(Tool::class);
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ToolCategory::class, 'tool_category_id');
    }

    public function data(): HasOne
    {
        return $this->hasOne(ToolExamData::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ToolExamDocument::class)->orderBy('sort_order');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ToolExamTranslation::class);
    }

    public function translate(?string $locale = null): ?ToolExamTranslation
    {
        $locale ??= app()->getLocale();

        return $this->translations->firstWhere('locale', $locale)
            ?? $this->translations->firstWhere('locale', config('app.fallback_locale'))
            ?? $this->translations->first();
    }

    public function contentTranslations(): HasMany
    {
        return $this->hasMany(ToolExamContentTranslation::class);
    }

    /**
     * The EAV content rows for $locale, flattened to [field_key =>
     * field_value] — falls back field-by-field to fallback_locale for any
     * key not yet translated, rather than an all-or-nothing swap to a
     * different language.
     *
     * @return array<string, ?string>
     */
    public function content(?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $fallback = config('app.fallback_locale');

        $byLocale = $this->contentTranslations->groupBy('locale');

        $base = $byLocale->get($fallback, collect())->pluck('field_value', 'field_key');
        $requested = $byLocale->get($locale, collect())->pluck('field_value', 'field_key');

        return $base->merge($requested)->all();
    }
}
