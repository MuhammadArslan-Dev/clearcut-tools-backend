<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\ToolCategoryResource;
use App\Models\Tool;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ToolCategoryController extends Controller
{
    /**
     * GET /api/v1/tools/{tool}/categories
     *
     * Filter: ?filter[is_active]=1
     * Sort:   ?sort=sort_order or ?sort=-sort_order (default: sort_order)
     */
    public function index(Tool $tool)
    {
        $categories = QueryBuilder::for($tool->categories())
            ->allowedFilters([AllowedFilter::exact('is_active')])
            ->allowedSorts(['sort_order', 'category_slug'])
            ->defaultSort('sort_order')
            ->with('translations')
            ->get();

        return ApiResponse::success(ToolCategoryResource::collection($categories));
    }
}
