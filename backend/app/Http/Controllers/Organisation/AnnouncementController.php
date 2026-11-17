<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organisation;

use App\Http\Controllers\Controller;
use App\Queries\Organisation\ActiveAnnouncements;
use Illuminate\Http\JsonResponse;

/** The platform announcements that apply to the current organisation; any signed-in member may read them. */
final class AnnouncementController extends Controller
{
    /**
     * Active announcements for this organisation.
     *
     * @response array{data: list<array{id: string, title: string, body: string, kind: string, starts_at: string, ends_at: string|null}>}
     */
    public function index(ActiveAnnouncements $announcements): JsonResponse
    {
        return response()->json(['data' => $announcements()]);
    }
}
