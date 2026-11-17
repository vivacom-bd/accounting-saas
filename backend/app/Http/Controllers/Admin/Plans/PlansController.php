<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Plans;

use App\Actions\Admin\Plans\ArchivePlan;
use App\Actions\Admin\Plans\CreatePlan;
use App\Actions\Admin\Plans\PublishPlanVersion;
use App\Http\Controllers\Controller;
use App\Http\Support\RequestAdminActor;
use App\Models\Billing\Plan;
use App\Queries\Admin\ListPlans;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Plans and their versions (plan Task 9). Routes are gated by `admin.can:plans.manage`. */
final class PlansController extends Controller
{
    public function index(ListPlans $plans): JsonResponse
    {
        return response()->json(['data' => $plans()]);
    }

    public function store(Request $request, CreatePlan $create, ListPlans $plans): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,31}$/'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:1000'],
            ...self::versionRules(),
        ]);

        $plan = $create(
            RequestAdminActor::from($request), $data['code'], $data['name'], $data['description'] ?? null, (int) ($data['sort'] ?? 0),
            $request->array('prices'), $request->array('limits'), $request->array('entitlements'),
            (int) $data['trial_days'], self::date($data['effective_from'] ?? null),
        );

        return response()->json(['data' => $this->one($plan, $plans)], 201);
    }

    public function publish(Request $request, string $plan, PublishPlanVersion $publish): JsonResponse
    {
        $data = $request->validate(self::versionRules());
        $model = Plan::query()->findOrFail($plan);

        $version = $publish(
            RequestAdminActor::from($request), $model, $request->array('prices'), $request->array('limits'), $request->array('entitlements'),
            (int) $data['trial_days'], self::date($data['effective_from'] ?? null),
        );

        return response()->json(['data' => ListPlans::version($version)], 201);
    }

    public function archive(Request $request, string $plan, ArchivePlan $archive, ListPlans $plans): JsonResponse
    {
        $archived = $archive(RequestAdminActor::from($request), Plan::query()->findOrFail($plan));

        return response()->json(['data' => $this->one($archived, $plans)]);
    }

    /** @return array<string, list<string>> */
    private static function versionRules(): array
    {
        // Shapes and numbers are checked in the Domain (strict ints, exact key sets); only presence is checked here.
        return [
            'prices' => ['required', 'array'],
            'limits' => ['required', 'array'],
            'entitlements' => ['required', 'array'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:365'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) ? new DateTimeImmutable($value.' 00:00:00', new \DateTimeZone('UTC')) : null;
    }

    /** @return array<string, mixed> */
    private function one(Plan $plan, ListPlans $plans): array
    {
        foreach ($plans() as $row) {
            if ($row['id'] === $plan->id) {
                return $row;
            }
        }

        return ListPlans::plan($plan);
    }
}
