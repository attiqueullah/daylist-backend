<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Services\Access;
use App\Services\SmartListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SmartListController extends Controller
{
    public function __construct(private SmartListService $smartLists, private Access $access) {}

    public function tasks(Request $request, string $name): AnonymousResourceCollection
    {
        abort_unless(in_array($name, SmartListService::NAMES, true), 404, 'Unknown smart list.');
        $user = $request->user();

        return TaskResource::collection(
            $this->smartLists->tasks($user, $name, $this->access->today($user, $request->query('today')))
        );
    }

    public function counts(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => $this->smartLists->counts($user, $this->access->today($user, $request->query('today'))),
        ]);
    }

    public function suggestions(Request $request): JsonResponse
    {
        $user = $request->user();
        $groups = $this->smartLists->suggestions($user, $this->access->today($user, $request->query('today')));

        return response()->json(['data' => array_map(
            fn ($tasks) => TaskResource::collection($tasks)->resolve($request),
            $groups,
        )]);
    }
}
