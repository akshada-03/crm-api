<?php

namespace App\Http\Requests;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\UserRole;
use App\Http\Requests\Concerns\LeadEnumMessages;
use App\Models\Lead;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreLeadRequest extends FormRequest
{
    use LeadEnumMessages;

    public function authorize(): Response
    {
        return Gate::inspect('create', Lead::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'company' => ['nullable', 'string', 'max:255'],
            'source' => ['required', Rule::enum(LeadSource::class)],
            'status' => ['sometimes', Rule::enum(LeadStatus::class), Rule::notIn([LeadStatus::Won, LeadStatus::Lost])],
            'expected_value' => ['required', 'numeric', 'decimal:0,2', 'between:0,9999999999.99'],
            'assigned_to' => [
                'bail',
                'nullable',
                function (string $attribute, mixed $value, Closure $fail) {
                    $ability = $this->assignAbility();

                    if ($ability->denied()) {
                        $fail($ability->message());
                    }
                },
                'integer',
                Rule::exists('users', 'id')->where('role', UserRole::Rep),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->leadEnumMessages(),
            'status.not_in' => 'A new lead cannot start as won or lost.',
            'expected_value.decimal' => 'The expected value must have at most 2 decimal places.',
            'assigned_to.exists' => 'The assigned to user must be an existing rep.',
        ];
    }

    /**
     * The attributes of the new lead. This is the one place a rep's lead is assigned to them:
     * a user who may not assign leads always owns what they create. The assigned_to rule has
     * already rejected any value such a user sent, so nothing they asked for is overridden.
     *
     * @return array<string, mixed>
     */
    public function leadAttributes(): array
    {
        if ($this->assignAbility()->allowed()) {
            return $this->validated();
        }

        return [...$this->validated(), 'assigned_to' => $this->user()->id];
    }

    /**
     * Setting assigned_to on create is an assignment, so it takes the same policy ability as
     * the assign endpoint. The lead doesn't exist yet, so the policy gets an unsaved one.
     */
    private function assignAbility(): Response
    {
        return Gate::inspect('assign', new Lead);
    }
}
