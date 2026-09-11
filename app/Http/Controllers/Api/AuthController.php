<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\LoginResource;

class AuthController extends Controller
{
    public function login(LoginRequest $request): LoginResource
    {
        $user = $request->authenticatedUser();

        return new LoginResource($user, $user->createToken('api')->plainTextToken);
    }
}
