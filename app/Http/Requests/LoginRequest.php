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
     * Resolve the user for the submitted credentials. An unknown email and a wrong password
     * fail with the same message and cost the same one password hash, so neither the response
     * nor its timing reveals which emails exist.
     *
     * @throws ValidationException
     */
    public function authenticatedUser(): User
    {
        $user = User::query()->where('email', $this->validated('email'))->first();
        $password = $this->validated('password');

        if (! $user) {
            // Hashing costs as much as the Hash::check() a known email pays for below.
            Hash::make($password);

            throw $this->invalidCredentials();
        }

        if (! Hash::check($password, $user->password)) {
            throw $this->invalidCredentials();
        }

        return $user;
    }

    private function invalidCredentials(): ValidationException
    {
        return ValidationException::withMessages([
            'email' => 'The provided credentials are incorrect.',
        ]);
    }
}
