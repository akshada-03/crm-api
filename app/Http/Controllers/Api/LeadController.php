<?php

namespace App\Http\Controllers\Api;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListLeadsRequest;
use App\Http\Requests\StoreLeadRequest;
use App\Http\Requests\UpdateLeadRequest;
use App\Http\Resources\LeadResource;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class LeadController extends Controller
{
    public function index(ListLeadsRequest $request): AnonymousResourceCollection
    {
        $leads = Lead::visibleTo($request->user())
            ->when($request->statusFilter(), fn (Builder $query, LeadStatus $status) => $query->where('status', $status))
            ->when($request->sourceFilter(), fn (Builder $query, LeadSource $source) => $query->where('source', $source))
            ->when($request->assigneeId(), fn (Builder $query, int $userId) => $query->where('assigned_to', $userId))
            ->when($request->onlyUnassigned(), fn (Builder $query) => $query->unassigned())
            ->when($request->searchTerm(), fn (Builder $query, string $term) => $query->search($term))
            ->sortedBy($request->sortColumn(), $request->sortDirection())
            ->with('assignedRep')
            ->paginate($request->perPage())
            ->withQueryString();

        return LeadResource::collection($leads);
    }

    public function store(StoreLeadRequest $request): JsonResponse
    {
        $lead = Lead::create($request->leadAttributes())->load('assignedRep');

        return (new LeadResource($lead))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Lead $lead): LeadResource
    {
        Gate::authorize('view', $lead);

        $lead->load([
            'assignedRep',
            'activities' => fn (HasMany $query) => $query->with('user')->latest('occurred_at')->latest('id'),
        ]);

        return new LeadResource($lead);
    }

    public function update(UpdateLeadRequest $request, Lead $lead): LeadResource
    {
        $lead->update($request->validated());

        return new LeadResource($lead->load('assignedRep'));
    }
}
