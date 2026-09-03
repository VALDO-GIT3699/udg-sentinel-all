<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class TwoFactorAuthenticationController extends Controller
{
    public function __construct(private readonly TwoFactorAuthenticationService $twoFactor) {}

    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Account/Security', [
            'twoFactorEnabled' => $user->hasVerifiedTwoFactor(),
            'recoveryCodesRemaining' => $user->hasVerifiedTwoFactor()
                ? count($user->two_factor_recovery_codes ?? [])
                : null,
            // El correo de incidente critico solo se manda a administradores
            // -mostrar la preferencia a alguien que nunca lo recibiria
            // confundiria mas de lo que ayuda.
            'isAdmin' => $user->can('monitoring.manage_users'),
            'notifyOnCriticalIncidents' => (bool) $user->notify_on_critical_incidents,
        ]);
    }

    public function updateNotificationPreference(Request $request): JsonResponse
    {
        $validated = $request->validate(['enabled' => ['required', 'boolean']]);

        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['notify_on_critical_incidents' => (bool) $validated['enabled']])->save();

        return response()->json(['message' => 'Preferencia de notificaciones actualizada.']);
    }

    public function enable(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $secret = $this->twoFactor->generateSecret($user);

        return response()->json([
            'secret' => $secret,
            'qr_code_svg' => $this->twoFactor->qrCodeSvg($user, $secret),
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $recoveryCodes = $this->twoFactor->confirm($user, $validated['code']);

        activity()->causedBy($user)->performedOn($user)
            ->log('Activó la verificación en dos pasos de su cuenta');

        return response()->json(['recovery_codes' => $recoveryCodes]);
    }

    public function disable(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $this->twoFactor->disable($user);

        activity()->causedBy($user)->performedOn($user)
            ->log('Desactivó la verificación en dos pasos de su cuenta');

        return response()->json(['message' => 'Verificación en dos pasos desactivada.']);
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        /** @var User $user */
        $user = $request->user();

        abort_unless($user->hasVerifiedTwoFactor(), 422);

        $codes = $this->twoFactor->regenerateRecoveryCodes($user);

        activity()->causedBy($user)->performedOn($user)
            ->log('Regeneró sus códigos de recuperación de dos pasos');

        return response()->json(['recovery_codes' => $codes]);
    }

    // ── Reto de segundo factor durante el login ──────────────────

    public function showChallenge(Request $request)
    {
        if (! $request->session()->has('2fa.user_id')) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    public function verifyChallenge(Request $request): RedirectResponse
    {
        $userId = $request->session()->get('2fa.user_id');

        if ($userId === null) {
            return redirect()->route('login');
        }

        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = User::query()->find($userId);

        if (! $user instanceof User) {
            $request->session()->forget('2fa.user_id');

            return redirect()->route('login');
        }

        $code = trim($validated['code']);
        $verified = $this->twoFactor->verifyCode($user, $code) || $this->twoFactor->useRecoveryCode($user, $code);

        if (! $verified) {
            return back()->withErrors(['code' => 'El código no es válido o ya expiró.']);
        }

        $remember = (bool) $request->session()->pull('2fa.remember', false);
        $request->session()->forget('2fa.user_id');

        Auth::login($user, $remember);
        $request->session()->regenerate();
        $request->session()->put('auth.profile', $user->can('monitoring.manage_settings') ? 'admin' : 'mortal');

        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended('/monitoring/dashboard');
    }
}
