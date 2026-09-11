<?php

namespace App\Http\Requests;

use App\Enums\ActivityType;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreActivityRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('logActivity', $this->route('lead'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ActivityType::class)],
            'body' => ['required', 'string', 'max:5000'],
            'occurred_at' => ['bail', 'nullable', 'date', 'before_or_equal:now'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.'.Enum::class => 'The type must be one of: '.implode(', ', array_column(ActivityType::cases(), 'value')).'.',
            'occurred_at.before_or_equal' => 'The occurred at date cannot be in the future.',
        ];
    }

    /**
     * The attributes of the new activity. The author is always the authenticated user, never
     * the input. occurred_at is converted to the app timezone because Eloquent stores a date's
     * wall-clock time and drops its offset: "10:00+02:00" would otherwise be saved as 10:00 UTC.
     *
     * @return array<string, mixed>
     */
    public function activityAttributes(): array
    {
        return [
            ...$this->safe()->only(['type', 'body']),
            'occurred_at' => $this->safe()->date('occurred_at')?->setTimezone(config('app.timezone')) ?? now(),
            'user_id' => $this->user()->id,
        ];
    }
}
