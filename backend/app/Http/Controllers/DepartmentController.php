<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\NormalizesBooleanFilters;
use App\Http\Requests\StoreDepartmentRequest;
use App\Http\Requests\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use App\Services\DepartmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    use NormalizesBooleanFilters;

    public function __construct(private readonly DepartmentService $departments) {}

    public function index(Request $request)
    {
        $this->normalizeBooleanFilters($request, ['is_active', 'with_trashed']);

        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'with_trashed' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', Rule::in(DepartmentService::SORTABLE)],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
        ]);

        $includeTrashed = ($filters['with_trashed'] ?? false) && $request->user()->role === 'admin';

        return DepartmentResource::collection($this->departments->paginate($filters, $includeTrashed));
    }

    public function options(Request $request): JsonResponse
    {
        $validated = $request->validate(['search' => ['sometimes', 'string', 'max:255']]);

        $departments = $this->departments->options($validated['search'] ?? null)
            ->map(static fn (Department $department): array => [
                'id' => $department->id,
                'name' => $department->name,
            ]);

        return response()->json(['data' => $departments]);
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $department = $this->departments->create($request->user(), $request->validated(), $request->ip());

        return DepartmentResource::make($department)->response()->setStatusCode(201);
    }

    public function show(int $department): DepartmentResource
    {
        return DepartmentResource::make(Department::withTrashed()->findOrFail($department));
    }

    public function update(UpdateDepartmentRequest $request, int $department): DepartmentResource
    {
        $target = Department::withTrashed()->findOrFail($department);

        return DepartmentResource::make($this->departments->update($request->user(), $target, $request->validated(), $request->ip()));
    }

    public function activate(Request $request, int $department): JsonResponse
    {
        return $this->setActive($request, $department, true);
    }

    public function deactivate(Request $request, int $department): JsonResponse
    {
        return $this->setActive($request, $department, false);
    }

    public function destroy(Request $request, int $department): JsonResponse
    {
        $target = Department::withTrashed()->findOrFail($department);
        $usage = $this->departments->delete($request->user(), $target, $request->ip());

        return response()->json([
            'message' => 'Department deleted.',
            'meta' => $usage,
        ]);
    }

    public function restore(Request $request, int $department): DepartmentResource
    {
        $target = Department::onlyTrashed()->findOrFail($department);

        return DepartmentResource::make($this->departments->restore($request->user(), $target, $request->ip()));
    }

    private function setActive(Request $request, int $department, bool $active): JsonResponse
    {
        $target = Department::withTrashed()->findOrFail($department);
        $usage = $this->departments->setActive($request->user(), $target, $active, $request->ip());

        return response()->json([
            'data' => DepartmentResource::make($target->refresh())->resolve($request),
            'message' => $active ? 'Department activated.' : 'Department deactivated.',
            'meta' => $usage,
        ]);
    }
}
