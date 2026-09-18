<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamTranslation extends Model
{
    protected $fillable = [
        'exam_id',
        'locale',
        'short_name',
        'full_name',
        'conducting_body',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }
}
