<?php

namespace App\Queries;

use App\Enums\LeadStatus;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Per-rep lead and activity totals in one SQL query.
 *
 * Leads and activities are aggregated in separate subqueries that are then joined to users:
 * joining both tables directly would repeat every lead once per activity and inflate the sums.
 * Both subqueries only aggregate the reps User::visibleTo() returns, so a rep's request groups
 * their own rows instead of the whole table, without a role check outside the scope.
 */
class RepPerformanceReport
{
    private const MONEY_COLUMNS = ['total_expected_value', 'won_expected_value'];

    /**
     * @return Collection<int, User>
     */
    public function forViewer(User $viewer): Collection
    {
        $countColumns = ['total_leads', ...$this->statusCountColumns()];

        $query = User::visibleTo($viewer)
            ->select(['users.id', 'users.name'])
            ->leftJoinSub($this->leadTotals($viewer), 'lead_totals', 'lead_totals.assigned_to', '=', 'users.id')
            ->leftJoinSub($this->activityTotals($viewer), 'activity_totals', 'activity_totals.user_id', '=', 'users.id');

        // A rep without leads or activities has no row in the subqueries, so their totals are null.
        foreach ([...$countColumns, ...self::MONEY_COLUMNS] as $column) {
            $query->selectRaw("coalesce(lead_totals.{$column}, 0) as {$column}");
        }

        return $query
            ->selectRaw('coalesce(activity_totals.activity_count, 0) as activity_count')
            ->withCasts([
                ...array_fill_keys([...$countColumns, 'activity_count'], 'integer'),
                ...array_fill_keys(self::MONEY_COLUMNS, 'decimal:2'),
            ])
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->get();
    }

    /**
     * The alias of the per-status lead count, e.g. "won_count". Built from the enum only.
     */
    public static function statusCountColumn(LeadStatus $status): string
    {
        return $status->value.'_count';
    }

    /**
     * Unassigned leads drop out here: NULL is never IN the list of rep ids.
     *
     * @return Builder<Lead>
     */
    private function leadTotals(User $viewer): Builder
    {
        $query = Lead::query()
            ->select('assigned_to')
            ->selectRaw('count(*) as total_leads');

        foreach (LeadStatus::cases() as $status) {
            $query->selectRaw('sum(case when status = ? then 1 else 0 end) as '.self::statusCountColumn($status), [$status->value]);
        }

        return $query
            ->selectRaw('sum(expected_value) as total_expected_value')
            ->selectRaw('sum(case when status = ? then expected_value else 0 end) as won_expected_value', [LeadStatus::Won->value])
            ->whereIn('assigned_to', $this->visibleRepIds($viewer))
            ->groupBy('assigned_to');
    }

    /**
     * Counts the activities each rep logged, on any lead.
     *
     * @return Builder<Activity>
     */
    private function activityTotals(User $viewer): Builder
    {
        return Activity::query()
            ->select('user_id')
            ->selectRaw('count(*) as activity_count')
            ->whereIn('user_id', $this->visibleRepIds($viewer))
            ->groupBy('user_id');
    }

    /**
     * @return Builder<User>
     */
    private function visibleRepIds(User $viewer): Builder
    {
        return User::visibleTo($viewer)->select('users.id');
    }

    /**
     * @return list<string>
     */
    private function statusCountColumns(): array
    {
        return array_map(self::statusCountColumn(...), LeadStatus::cases());
    }
}
