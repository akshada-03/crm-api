<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class LeadPolicy
{
    /**
     * Anyone may list leads; Lead::visibleTo() narrows the results for reps.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Lead $lead): Response
    {
        return $this->managerOrAssignedRep($user, $lead);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Lead $lead): Response
    {
        return $this->managerOrAssignedRep($user, $lead);
    }

    public function assign(User $user, Lead $lead): Response
    {
        return $user->isManager()
            ? Response::allow()
            : Response::deny('Only managers can assign leads.');
    }

    public function logActivity(User $user, Lead $lead): Response
    {
        return $this->managerOrAssignedRep($user, $lead);
    }

    private function managerOrAssignedRep(User $user, Lead $lead): Response
    {
        return $user->isManager() || $lead->assignedRep()->is($user)
            ? Response::allow()
            : Response::deny('This lead is not assigned to you.');
    }
}
