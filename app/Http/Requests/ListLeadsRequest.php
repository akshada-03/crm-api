<?php

namespace App\Http\Requests;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Lead;
use BackedEnum;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class ListLeadsRequest extends FormRequest
{
    /**
     * The assigned_to value that asks for leads nobody is assigned to.
     */
    private const UNASSIGNED = 'unassigned';

    public function authorize(): Response
    {
        return Gate::inspect('viewAny', Lead::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(LeadStatus::class)],
            'source' => ['nullable', Rule::enum(LeadSource::class)],
            'assigned_to' => [
                'bail',
                'nullable',
                Rule::when($this->input('assigned_to') !== self::UNASSIGNED, ['integer', 'exists:users,id']),
            ],
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['created_at', 'expected_value'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ];
    }

    /**
     * Rule::enum() failures are keyed by the rule's class name, not by "enum".
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.'.Enum::class => 'The status must be one of: '.$this->valuesOf(LeadStatus::class).'.',
            'source.'.Enum::class => 'The source must be one of: '.$this->valuesOf(LeadSource::class).'.',
            'assigned_to.integer' => 'The assigned to filter must be a user id or "'.self::UNASSIGNED.'".',
            'assigned_to.exists' => 'The selected assigned to user does not exist.',
            'sort.in' => 'The sort field must be one of: :values.',
            'direction.in' => 'The direction must be one of: :values.',
        ];
    }

    public function statusFilter(): ?LeadStatus
    {
        return $this->safe()->enum('status', LeadStatus::class);
    }

    public function sourceFilter(): ?LeadSource
    {
        return $this->safe()->enum('source', LeadSource::class);
    }

    public function assigneeId(): ?int
    {
        $assignee = $this->validated('assigned_to');

        return $assignee === null || $assignee === self::UNASSIGNED ? null : (int) $assignee;
    }

    public function onlyUnassigned(): bool
    {
        return $this->validated('assigned_to') === self::UNASSIGNED;
    }

    public function searchTerm(): ?string
    {
        return $this->validated('search');
    }

    public function sortColumn(): string
    {
        return $this->validated('sort') ?? 'created_at';
    }

    public function sortDirection(): string
    {
        return $this->validated('direction') ?? 'desc';
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 15);
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     */
    private function valuesOf(string $enum): string
    {
        return implode(', ', array_column($enum::cases(), 'value'));
    }
}
