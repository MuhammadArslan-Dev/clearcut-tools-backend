<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One translatable string per row (EAV), keyed by an app-level field_key
 * (e.g. "faqs.0.question", "official_body") — see the migration's docblock
 * for why this isn't a jsonb blob per locale.
 */
class ToolExamContentTranslation extends Model
{
    protected $fillable = [
        'tool_exam_id',
        'locale',
        'field_key',
        'field_value',
    ];

    public function toolExam(): BelongsTo
    {
        return $this->belongsTo(ToolExam::class);
    }
}
