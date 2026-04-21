<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UpdateAccountRequest;
use App\Http\Resources\User\AuthenticatedUserResource;
use App\Services\AccountService;
use Illuminate\Http\JsonResponse;

class AccountController extends Controller
{
    public function update(UpdateAccountRequest $request, AccountService $accountService): JsonResponse
    {
        $user = $accountService->updateAccount($request->user(), $request->validated());

        return response()->json(
            AuthenticatedUserResource::make($user)->resolve()
        );
    }
}
