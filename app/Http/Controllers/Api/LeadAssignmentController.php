<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignLeadRequest;
use App\Http\Resources\LeadResource;
use App\Models\Lead;

class LeadAssignmentController extends Controller
{
    public function __invoke(AssignLeadRequest $request, Lead $lead): LeadResource
    {
        $lead->update($request->validated());

        return new LeadResource($lead->load('assignedRep'));
    }
}
