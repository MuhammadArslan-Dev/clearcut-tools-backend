<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToolExamDocument extends Model
{
    /** Document types the tools frontend knows how to render. */
    public const DOC_TYPES = ['photo', 'signature', 'left_thumb', 'right_thumb', 'handwritten_declaration'];

    public const MODES = ['upload', 'live_capture'];

    public const VERIFICATIONS = ['unverified', 'partly_verified', 'official_verified'];

    protected $fillable = [
        'uid',
        'content_hash',
        'tool_exam_id',
        'doc_type',
        'sort_order',
        'mode',
        'width_px',
        'height_px',
        'min_kb',
        'max_kb',
        'format',
        'is_required',
        'verification',
        'source_url',
        'verified_on',
        'note',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'width_px' => 'integer',
        'height_px' => 'integer',
        'min_kb' => 'integer',
        'max_kb' => 'integer',
        'is_required' => 'boolean',
        'is_active' => 'boolean',
        'verified_on' => 'date',
    ];

    public function toolExam(): BelongsTo
    {
        return $this->belongsTo(ToolExam::class);
    }
}
