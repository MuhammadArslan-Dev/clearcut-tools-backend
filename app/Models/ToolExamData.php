<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToolExamData extends Model
{
    // seo_title/seo_description/is_placeholder moved to
    // ToolExamTranslation (per-locale). data_json stays here — it's
    // structural, non-text config (dimensions, size limits, formats) that
    // is identical across every language; see ToolExamContentTranslation
    // for actual translatable prose.
    protected $fillable = [
        'uid',
        'content_hash',
        'tool_exam_id',
        'data_json',
    ];

    protected $casts = [
        'data_json' => 'array',
    ];

    public function toolExam(): BelongsTo
    {
        return $this->belongsTo(ToolExam::class);
    }
}
