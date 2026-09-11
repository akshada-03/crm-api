<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreActivityRequest;
use App\Http\Resources\ActivityResource;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class LeadActivityController extends Controller
{
    public function __invoke(StoreActivityRequest $request, Lead $lead): JsonResponse
    {
        $activity = $lead->activities()->create($request->activityAttributes())->load('user');

        return (new ActivityResource($activity))->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
