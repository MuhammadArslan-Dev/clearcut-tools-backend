<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\ToolResource;
use App\Models\Tool;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ToolController extends Controller
{
    /**
     * GET /api/v1/tools
     *
     * Filter: ?filter[status]=active
     * Sort:   ?sort=sort_order or ?sort=-sort_order (default: sort_order)
     */
    public function index()
    {
        $tools = QueryBuilder::for(Tool::class)
            ->allowedFilters([AllowedFilter::exact('status')])
            ->allowedSorts(['sort_order', 'tool_slug'])
            ->defaultSort('sort_order')
            ->with('translations')
            ->get();

        return ApiResponse::success(ToolResource::collection($tools));
    }

    /**
     * GET /api/v1/tools/{tool}
     */
    public function show(Tool $tool)
    {
        $tool->load('translations');

        return ApiResponse::success(ToolResource::make($tool));
    }
}
