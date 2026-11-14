<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\InvalidIdToken;
use App\Services\Auth\SocialLogin;
use App\Services\ListService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private ListService $lists, private SocialLogin $social) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', PasswordRule::min(8)],
            'device_name' => ['required', 'string', 'max:100'],
            'timezone' => ['sometimes', 'timezone:all'],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                'password' => $data['password'],
                'timezone' => $data['timezone'] ?? 'UTC',
            ]);
            $this->lists->createDefaultList($user);

            return $user;
        });

        return $this->tokenResponse($request, $user, $data['device_name'], 201, ['is_new' => true]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $user = User::where('email', Str::lower($data['email']))->first();
        if (! $user || $user->password === null || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        return $this->tokenResponse($request, $user, $data['device_name']);
    }

    public function apple(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identity_token' => ['required', 'string'],
            'name' => ['nullable', 'string', 'max:120'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        return $this->socialSignIn($request, 'apple', $data['identity_token'], $data['name'] ?? null, $data['device_name']);
    }

    public function google(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_token' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        return $this->socialSignIn($request, 'google', $data['id_token'], null, $data['device_name']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        // Same answer whether or not the email exists, so accounts can't be probed.
        Password::sendResetLink(['email' => Str::lower($request->input('email'))]);

        return response()->json(['message' => 'If that email has an account, a reset link is on its way.'], 202);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', PasswordRule::min(8)],
        ]);

        $status = Password::reset(
            ['email' => Str::lower($data['email']), 'token' => $data['token'], 'password' => $data['password']],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();
                $user->tokens()->delete();
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => __($status)]);
        }

        return response()->json(['message' => 'Password updated. Sign in again on your devices.']);
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }

    private function socialSignIn(Request $request, string $provider, string $token, ?string $name, string $deviceName): JsonResponse
    {
        try {
            $claims = SocialLogin::verifier($provider)->verify($token);
        } catch (InvalidIdToken $e) {
            throw ValidationException::withMessages([$provider === 'apple' ? 'identity_token' : 'id_token' => $e->getMessage()]);
        }

        [$user, $isNew] = $this->social->resolve($provider, $claims, $name);

        return $this->tokenResponse($request, $user, $deviceName, $isNew ? 201 : 200, ['is_new' => $isNew]);
    }

    private function tokenResponse(Request $request, User $user, string $deviceName, int $status = 200, array $extra = []): JsonResponse
    {
        $token = $user->createToken($deviceName)->plainTextToken;
        $request->setUserResolver(fn () => $user);

        return response()->json(['data' => array_merge([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => (new UserResource($user))->resolve($request),
        ], $extra)], $status);
    }
}
