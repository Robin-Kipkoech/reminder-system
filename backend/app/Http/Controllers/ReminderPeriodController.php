<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\NormalizesBooleanFilters;
use App\Http\Requests\StoreReminderPeriodRequest;
use App\Http\Requests\UpdateReminderPeriodRequest;
use App\Http\Resources\ReminderPeriodResource;
use App\Models\ReminderPeriod;
use App\Services\ReminderPeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReminderPeriodController extends Controller
{
    use NormalizesBooleanFilters;

    public function __construct(private readonly ReminderPeriodService $periods) {}

    public function index(Request $request)
    {
        $this->normalizeBooleanFilters($request, ['is_active']);

        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', Rule::in(ReminderPeriodService::SORTABLE)],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
        ]);

        return ReminderPeriodResource::collection($this->periods->paginate($filters));
    }

    public function options(Request $request): JsonResponse
    {
        $validated = $request->validate(['search' => ['sometimes', 'string', 'max:255']]);

        $periods = $this->periods->options($validated['search'] ?? null)
            ->map(static fn (ReminderPeriod $period): array => [
                'id' => $period->id,
                'label' => $period->label,
                'offset_days' => $period->offset_days,
            ]);

        return response()->json(['data' => $periods]);
    }

    public function store(StoreReminderPeriodRequest $request): JsonResponse
    {
        $period = $this->periods->create($request->user(), $request->validated(), $request->ip());

        return ReminderPeriodResource::make($period)->response()->setStatusCode(201);
    }

    public function show(int $reminderPeriod): ReminderPeriodResource
    {
        return ReminderPeriodResource::make(ReminderPeriod::findOrFail($reminderPeriod));
    }

    public function update(UpdateReminderPeriodRequest $request, int $reminderPeriod): ReminderPeriodResource
    {
        $target = ReminderPeriod::findOrFail($reminderPeriod);

        return ReminderPeriodResource::make($this->periods->update($request->user(), $target, $request->validated(), $request->ip()));
    }

    public function activate(Request $request, int $reminderPeriod): JsonResponse
    {
        return $this->setActive($request, $reminderPeriod, true);
    }

    public function deactivate(Request $request, int $reminderPeriod): JsonResponse
    {
        return $this->setActive($request, $reminderPeriod, false);
    }

    public function destroy(Request $request, int $reminderPeriod): JsonResponse
    {
        $target = ReminderPeriod::findOrFail($reminderPeriod);
        $this->periods->delete($request->user(), $target, $request->ip());

        return response()->json(['message' => 'Reminder period deleted.']);
    }

    private function setActive(Request $request, int $reminderPeriod, bool $active): JsonResponse
    {
        $target = ReminderPeriod::findOrFail($reminderPeriod);
        $period = $this->periods->setActive($request->user(), $target, $active, $request->ip());

        return response()->json([
            'data' => ReminderPeriodResource::make($period)->resolve($request),
            'message' => $active ? 'Reminder period activated.' : 'Reminder period deactivated.',
        ]);
    }
}
