<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exam extends Model
{
    // short_name/full_name/conducting_body live in exam_translations.
    protected $fillable = [
        'uid',
        'content_hash',
        'exam_slug',
    ];

    public function toolExams(): HasMany
    {
        return $this->hasMany(ToolExam::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ExamTranslation::class);
    }

    public function translate(?string $locale = null): ?ExamTranslation
    {
        $locale ??= app()->getLocale();

        return $this->translations->firstWhere('locale', $locale)
            ?? $this->translations->firstWhere('locale', config('app.fallback_locale'))
            ?? $this->translations->first();
    }
}
