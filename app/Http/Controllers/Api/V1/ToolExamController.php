<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\ToolExamResource;
use App\Models\Tool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ToolExamController extends Controller
{
    protected const EAGER = ['exam.translations', 'category.translations', 'translations'];

    /**
     * GET /api/v1/tools/{tool}/exams
     *
     * Only active rows are returned by default; pass ?filter[is_active]=0
     * (or =1) to override.
     * Filter: ?filter[is_active]=1
     *         ?filter[category]=teaching-exams-tet-tgt-pgt  (tool_categories.category_slug)
     *         ?filter[exam]=htet                            (exams.exam_slug)
     *         ?filter[search]=teacher                        (exam short_name/full_name, current locale)
     * Sort:   ?sort=sort_order or ?sort=-sort_order (default: sort_order)
     */
    public function index(Request $request, Tool $tool)
    {
        $builder = QueryBuilder::for($tool->toolExams())
            ->allowedFilters([
                AllowedFilter::exact('is_active'),
                AllowedFilter::callback('category', fn (Builder $query, $value) => $query->whereHas(
                    'category',
                    fn (Builder $q) => $q->where('category_slug', $value),
                )),
                AllowedFilter::callback('exam', fn (Builder $query, $value) => $query->whereHas(
                    'exam',
                    fn (Builder $q) => $q->where('exam_slug', $value),
                )),
                AllowedFilter::callback('search', fn (Builder $query, $value) => $query->whereHas(
                    'exam.translations',
                    fn (Builder $q) => $q->where('locale', app()->getLocale())
                        ->where(fn (Builder $q2) => $q2
                            ->where('short_name', 'like', "%{$value}%")
                            ->orWhere('full_name', 'like', "%{$value}%")),
                )),
            ])
            ->allowedSorts(['sort_order', 'public_slug'])
            ->defaultSort('sort_order')
            ->with(self::EAGER);

        // Inactive rows (is_active = false in the sheet) are hidden unless
        // the caller explicitly asks with ?filter[is_active]=...; without
        // this the static tools site kept building pages for rows switched
        // off in the sheet.
        if (! $request->has('filter.is_active')) {
            $builder->where('is_active', true);
        }

        return ApiResponse::success(ToolExamResource::collection($builder->get()));
    }

    /**
     * GET /api/v1/tools/{tool}/exams/{publicSlug}
     */
    public function show(Tool $tool, string $publicSlug)
    {
        $toolExam = $tool->toolExams()
            ->where('public_slug', $publicSlug)
            ->where('is_active', true)
            ->with([...self::EAGER, 'data', 'contentTranslations', 'documents'])
            ->first();

        if (! $toolExam) {
            return ApiResponse::notFound(
                "No exam found for tool '{$tool->tool_slug}' with slug '{$publicSlug}'.",
            );
        }

        return ApiResponse::success(ToolExamResource::make($toolExam));
    }
}
