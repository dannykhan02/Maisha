<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\Password;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    // POST /api/register
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name'          => $validated['name'],
            'email'         => $validated['email'],
            'password'      => bcrypt($validated['password']), // ✅ was missing bcrypt()
            'auth_provider' => 'email',
        ]);

        $token = $user->createToken('maisha-web')->plainTextToken;

        return response()->json([
            'message'   => 'Account created successfully',
            'user'      => $user,
            'token'     => $token,
            'onboarded' => false,
        ], 201);
    }

    // POST /api/login
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        if (!Auth::attempt($validated)) {
            return response()->json(['message' => 'Invalid email or password'], 401);
        }

        $user = Auth::user();
        $user->tokens()->delete();
        $token = $user->createToken('maisha-web')->plainTextToken;

        return response()->json([
            'message'   => 'Login successful',
            'user'      => $user,
            'token'     => $token,
            'onboarded' => (bool) $user->onboarded,
        ]);
    }

    // POST /api/logout
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out successfully']);
    }

    // GET /api/auth/google
    public function googleRedirect()
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    // GET /api/auth/google/callback
    public function googleCallback()
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Exception $e) {
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:5173');
            return redirect($frontendUrl . '/login?error=google_failed');
        }

        $user = User::updateOrCreate(
            ['google_id' => $googleUser->getId()],
            [
                'name'               => $googleUser->getName(),
                'email'              => $googleUser->getEmail(),
                'avatar'             => $googleUser->getAvatar(),
                'auth_provider'      => 'google',
                'password'           => null,
                'email_verified_at'  => now(),
            ]
        );

        $user->tokens()->delete();
        $token = $user->createToken('maisha-google')->plainTextToken;

        $redirectPath = $user->onboarded ? '/dashboard' : '/onboarding';
        $frontendUrl  = env('FRONTEND_URL', 'http://localhost:5173');

        return redirect($frontendUrl . $redirectPath . '?token=' . $token);
    }

    // POST /api/forgot-password
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json(['message' => 'Reset link sent to your email'], 200);
        }

        return response()->json(['message' => 'Unable to send reset link'], 400);
    }

    // POST /api/reset-password
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token'    => 'required',
            'email'    => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password'       => bcrypt($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Password reset successfully'], 200);
        }

        return response()->json(['message' => 'Invalid token or email'], 400);
    }
}

