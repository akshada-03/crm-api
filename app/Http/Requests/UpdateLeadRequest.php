<?php

namespace App\Http\Requests;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Http\Requests\Concerns\LeadEnumMessages;
use App\Models\Lead;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateLeadRequest extends FormRequest
{
    use LeadEnumMessages;

    public function authorize(): Response
    {
        return Gate::inspect('update', $this->lead());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255'],
            'phone' => ['sometimes', 'required', 'string', 'max:30'],
            'company' => ['sometimes', 'nullable', 'string', 'max:255'],
            'source' => ['sometimes', Rule::enum(LeadSource::class)],
            'status' => ['sometimes', Rule::enum(LeadStatus::class)],
            'expected_value' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'between:0,9999999999.99'],
            'assigned_to' => ['missing'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->leadEnumMessages(),
            'expected_value.decimal' => 'The expected value must have at most 2 decimal places.',
            'assigned_to.missing' => 'Leads are assigned through POST /api/leads/{lead}/assign.',
        ];
    }

    /**
     * A lead can be closed as won or lost only once someone has worked it. The check is skipped
     * when status already failed its own rules: then there is no valid status to judge, and the
     * input may not even be a string.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('status') || ! $this->enum('status', LeadStatus::class)?->isClosed()) {
                    return;
                }

                if (! $this->lead()->hasActivities()) {
                    $validator->errors()->add(
                        'status',
                        'A lead can only be marked won or lost after at least one activity has been logged.',
                    );
                }
            },
        ];
    }

    private function lead(): Lead
    {
        return $this->route('lead');
    }
}
