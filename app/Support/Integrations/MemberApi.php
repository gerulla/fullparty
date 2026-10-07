<?php

namespace App\Support\Integrations;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Response negotiation only; member workflows remain shared with the website. */
final class MemberApi
{
    public static function active(?Request $request = null): bool
    {
        return ($request ?? request())->attributes->has('integration_member');
    }

    public static function success(array $data = [], int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, ...$data], $status);
    }

    public static function validationError(string $field, string $message): void
    {
        if (self::active()) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    public static function flashData(array $data): JsonResponse|RedirectResponse
    {
        return self::active() ? response()->json($data) : back()->with('flash_data', $data);
    }

    public static function errors(array $errors): RedirectResponse
    {
        if (self::active()) {
            throw ValidationException::withMessages($errors);
        }

        return back()->withErrors($errors);
    }

    public static function saved(string $message): JsonResponse|RedirectResponse
    {
        return self::active() ? self::success() : back()->with('success', $message);
    }
}
