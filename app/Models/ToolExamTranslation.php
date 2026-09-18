<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToolExamTranslation extends Model
{
    protected $fillable = [
        'tool_exam_id',
        'locale',
        'seo_title',
        'seo_description',
        'is_placeholder',
    ];

    protected $casts = [
        'is_placeholder' => 'boolean',
    ];

    public function toolExam(): BelongsTo
    {
        return $this->belongsTo(ToolExam::class);
    }
}
