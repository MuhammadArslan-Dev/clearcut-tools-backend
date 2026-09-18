<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ToolExamResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $translation = $this->translate();

        return [
            'id' => $this->id,
            'public_slug' => $this->public_slug,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'exam' => ExamResource::make($this->whenLoaded('exam')),
            'category' => ToolCategoryResource::make($this->whenLoaded('category')),
            'seo_title' => $translation?->seo_title,
            'seo_description' => $translation?->seo_description,
            'is_placeholder' => $translation?->is_placeholder ?? false,

            // Only the show endpoint pays for these — they're the heavy
            // part of a tool_exam (structural spec blob + however many
            // translated content rows), not needed to render a list.
            $this->mergeWhen($request->routeIs('*.exams.show'), fn () => [
                'data' => $this->whenLoaded('data', fn () => $this->data->data_json),
                'content' => $this->content(),
            ]),
        ];
    }
}
