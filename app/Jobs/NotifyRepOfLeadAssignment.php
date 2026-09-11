<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\WithoutRelations;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The payload holds only the two model keys; the worker reloads fresh rows. The rep is passed
 * explicitly, so a later reassignment can't redirect this notification to someone else.
 * If the lead or rep is deleted before the job runs, it is dropped rather than retried.
 */
#[DeleteWhenMissingModels]
#[WithoutRelations]
class NotifyRepOfLeadAssignment implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    /**
     * Seconds to wait before the second and third attempts.
     *
     * @var list<int>
     */
    public array $backoff = [10, 60];

    public function __construct(
        public User $rep,
        public Lead $lead,
    ) {}

    public function handle(): void
    {
        Log::info("Rep {$this->rep->id} notified about lead {$this->lead->id}");
    }

    public function failed(Throwable $exception): void
    {
        Log::error("Rep {$this->rep->id} could not be notified about lead {$this->lead->id}", [
            'exception' => $exception,
        ]);
    }
}
