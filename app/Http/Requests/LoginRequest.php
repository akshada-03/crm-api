<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Resolve the user for the submitted credentials. An unknown email and a wrong
     * password fail with the same message so the response doesn't reveal which emails exist.
     *
     * @throws ValidationException
     */
    public function authenticatedUser(): User
    {
        $user = User::query()->where('email', $this->validated('email'))->first();

        if (! $user || ! Hash::check($this->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'The provided credentials are incorrect.',
            ]);
        }

        return $user;
    }
}
