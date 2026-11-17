<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(Request $request): UserResource|JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            $email = $this->loginEmail($request);
            $this->recordLoginEvent($request, $email, $this->userIdForEmail($email), false);
            throw new ValidationException($validator);
        }

        $credentials = $validator->validated();

        if (! Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
            $this->recordLoginEvent($request, $credentials['email'], $this->userIdForEmail($credentials['email']), false);
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        $user = $request->user('web');

        if ($user->status !== 'active') {
            $this->recordLoginEvent($request, $user->email, $user->id, false);
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json([
                'code' => 'USER_SUSPENDED',
                'message' => 'Your account is suspended. Contact an administrator.',
            ], 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $this->recordLoginEvent($request, $user->email, $user->id, true);

        return UserResource::make($user->refresh());
    }

    public function me(Request $request): UserResource
    {
        return UserResource::make($request->user());
    }

    public function updateProfile(Request $request): UserResource
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);
        $user = $request->user();
        $old = ['name' => $user->name, 'phone' => $user->phone];

        $user->fill($validated)->save();
        $this->auditChange($request, $user, 'profile-updated', $old, [
            'name' => $user->name,
            'phone' => $user->phone,
        ]);

        return UserResource::make($user->refresh());
    }

    public function preferences(Request $request): JsonResponse
    {
        return response()->json($this->preferenceValues($request->user()));
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'notify_email' => ['required', 'boolean'],
            'notify_push' => ['required', 'boolean'],
            'digest_time' => ['nullable', 'date_format:H:i'],
        ]);
        $user = $request->user();
        $old = $this->preferenceValues($user);

        $user->fill($validated)->save();
        $new = $this->preferenceValues($user->refresh());
        $this->auditChange($request, $user, 'preferences-updated', $old, $new);

        return response()->json($new);
    }

    public function changePassword(Request $request): UserResource
    {
        $user = $request->user();

        // The first-time flow (must_change_password) already verified the current
        // password at login, so it only needs the new one. Later changes require it.
        $requiresCurrent = ! $user->must_change_password;

        $validated = $request->validate([
            'current_password' => [$requiresCurrent ? 'required' : 'sometimes', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($requiresCurrent && ! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
        ])->save();

        return UserResource::make($user->refresh());
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Signed out.']);
    }

    private function loginEmail(Request $request): string
    {
        $email = $request->input('email');

        return is_string($email) ? substr($email, 0, 255) : '';
    }

    private function userIdForEmail(string $email): ?int
    {
        if ($email === '') {
            return null;
        }

        return User::withTrashed()->where('email', $email)->value('id');
    }

    private function recordLoginEvent(Request $request, string $email, ?int $userId, bool $successful): void
    {
        DB::table('login_events')->insert([
            'user_id' => $userId,
            'email' => $email,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'successful' => $successful,
            'created_at' => now(),
        ]);
    }

    private function preferenceValues($user): array
    {
        return [
            'notify_email' => (bool) $user->notify_email,
            'notify_push' => (bool) $user->notify_push,
            'digest_time' => $user->digest_time === null ? null : substr((string) $user->digest_time, 0, 5),
        ];
    }

    private function auditChange(Request $request, $user, string $action, array $old, array $new): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $user->getKey(),
            'action' => $action,
            'entity_type' => 'user',
            'entity_id' => $user->getKey(),
            'old_values' => json_encode($old, JSON_THROW_ON_ERROR),
            'new_values' => json_encode($new, JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }
}
