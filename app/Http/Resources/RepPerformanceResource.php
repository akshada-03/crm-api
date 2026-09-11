<?php

namespace App\Http\Resources;

use App\Enums\LeadStatus;
use App\Models\User;
use App\Queries\RepPerformanceReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A rep together with the totals RepPerformanceReport selected for them.
 *
 * @mixin User
 */
class RepPerformanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'rep' => [
                'id' => $this->id,
                'name' => $this->name,
            ],
            'total_leads' => $this->total_leads,
            'status_counts' => collect(LeadStatus::cases())
                ->mapWithKeys(fn (LeadStatus $status) => [
                    $status->value => $this->resource->getAttribute(RepPerformanceReport::statusCountColumn($status)),
                ])
                ->all(),
            'total_expected_value' => $this->total_expected_value,
            'won_expected_value' => $this->won_expected_value,
            'activity_count' => $this->activity_count,
        ];
    }
}
