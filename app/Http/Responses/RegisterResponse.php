<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;

/**
 * After registration, send vendors to the signature-enrollment step (the
 * second registration step) instead of straight to the dashboard. The
 * EnsureSignatureEnrolled middleware keeps them there until it is completed.
 */
class RegisterResponse implements RegisterResponseContract
{
    public function toResponse($request): RedirectResponse|JsonResponse
    {
        return $request->wantsJson()
            ? new JsonResponse('', 201)
            : redirect()->route('signature.create');
    }
}
