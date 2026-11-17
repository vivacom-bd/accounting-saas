<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Support;

use App\Actions\Admin\Support\DeleteAnnouncement;
use App\Actions\Admin\Support\UpsertAnnouncement;
use App\Domain\Platform\LifecycleState;
use App\Http\Controllers\Controller;
use App\Http\Support\RequestAdminActor;
use App\Queries\Admin\Support\Announcements;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Staff announcements (plan Task 15). Routes are gated by `admin.can:announcements.manage`. */
final class AnnouncementsController extends Controller
{
    public function index(Announcements $announcements): JsonResponse
    {
        return response()->json(['data' => $announcements->all()]);
    }

    public function store(Request $request, UpsertAnnouncement $upsert): JsonResponse
    {
        return response()->json(['data' => Announcements::present($upsert->handle(RequestAdminActor::from($request), null, $this->input($request)))], 201);
    }

    public function update(Request $request, UpsertAnnouncement $upsert, string $id): JsonResponse
    {
        return response()->json(['data' => Announcements::present($upsert->handle(RequestAdminActor::from($request), $id, $this->input($request)))]);
    }

    public function destroy(Request $request, DeleteAnnouncement $delete, string $id): Response
    {
        $delete->handle(RequestAdminActor::from($request), $id);

        return response()->noContent();
    }

    /** @return array{title: string, body: string, kind: string, audience: array<string, list<string>>, starts_at: DateTimeImmutable, ends_at: DateTimeImmutable|null} */
    private function input(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:4000'],
            'kind' => ['required', Rule::in(['info', 'warning', 'maintenance'])],
            'audience' => ['nullable', 'array:plans,lifecycles,countries'],
            'audience.plans' => ['nullable', 'array', 'max:20'],
            'audience.plans.*' => ['string', 'max:32'],
            'audience.lifecycles' => ['nullable', 'array', 'max:7'],
            'audience.lifecycles.*' => ['string', Rule::enum(LifecycleState::class)],
            'audience.countries' => ['nullable', 'array', 'max:60'],
            'audience.countries.*' => ['string', 'regex:/^[A-Z]{2}$/'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);
        $audience = [];
        foreach (['plans', 'lifecycles', 'countries'] as $key) {
            $audience[$key] = array_values(array_unique(array_map(strval(...), (array) ($data['audience'][$key] ?? []))));
        }

        return [
            'title' => trim($data['title']), 'body' => trim($data['body']), 'kind' => $data['kind'], 'audience' => $audience,
            'starts_at' => new DateTimeImmutable($data['starts_at']),
            'ends_at' => ($data['ends_at'] ?? null) === null ? null : new DateTimeImmutable($data['ends_at']),
        ];
    }
}
