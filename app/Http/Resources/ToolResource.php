<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ToolResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $translation = $this->translate();

        return [
            'id' => $this->id,
            'tool_slug' => $this->tool_slug,
            'tool_name' => $translation?->tool_name,
            'description' => $translation?->description,
            'status' => $this->status,
            'sort_order' => $this->sort_order,
        ];
    }
}
