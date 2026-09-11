<?php

namespace App\Models;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    /**
     * Mirrors the column default so a freshly created lead has a status in memory.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => LeadStatus::New->value,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'company',
        'source',
        'status',
        'expected_value',
        'assigned_to',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => LeadSource::class,
            'status' => LeadStatus::class,
            'expected_value' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedRep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /**
     * Managers see every lead; anyone else sees only the leads assigned to them.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $viewer): void
    {
        if ($viewer->isManager()) {
            return;
        }

        $query->whereBelongsTo($viewer, 'assignedRep');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function unassigned(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('assigned_to'));
    }

    /**
     * Case-insensitive substring match on name, email or company. The ORs sit in their own
     * group so they can't widen other constraints such as visibleTo(), and LIKE wildcards
     * in the term are escaped so "%" and "_" match literally.
     *
     * "!" is the escape character because it works unchanged on MySQL and SQLite: MySQL reads
     * '\' as an unterminated string literal, and SQLite rejects '\\' as more than one character.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function search(Builder $query, string $term): void
    {
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';

        $query->where(function (Builder $query) use ($pattern) {
            foreach (['name', 'email', 'company'] as $column) {
                $query->orWhereRaw($query->qualifyColumn($column)." like ? escape '!'", [$pattern]);
            }
        });
    }

    /**
     * Order by the given column, then by id so rows with equal values keep a stable order across pages.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function sortedBy(Builder $query, string $column, string $direction): void
    {
        $query->orderBy($query->qualifyColumn($column), $direction)
            ->orderBy($query->qualifyColumn('id'), $direction);
    }
}
