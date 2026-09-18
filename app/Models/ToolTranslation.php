<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToolTranslation extends Model
{
    protected $fillable = [
        'tool_id',
        'locale',
        'tool_name',
        'description',
    ];

    public function tool(): BelongsTo
    {
        return $this->belongsTo(Tool::class);
    }
}
