<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AssignLeadRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('assign', $this->route('lead'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'assigned_to' => ['bail', 'required', 'integer', Rule::exists('users', 'id')->where('role', UserRole::Rep)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'assigned_to.exists' => 'The selected user is not a sales rep.',
        ];
    }
}
