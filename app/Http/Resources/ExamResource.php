<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $translation = $this->translate();

        return [
            'id' => $this->id,
            'exam_slug' => $this->exam_slug,
            'short_name' => $translation?->short_name,
            'full_name' => $translation?->full_name,
            'conducting_body' => $translation?->conducting_body,
        ];
    }
}
