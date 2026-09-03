<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

final class TwoFactorAuthenticationService
{
    private const RECOVERY_CODE_COUNT = 8;

    public function __construct(private readonly Google2FA $google2fa) {}

    /**
     * Genera un secreto nuevo (todavia no confirmado) y lo guarda en el
     * usuario. El secreto no se considera activo hasta que confirm() reciba
     * un codigo TOTP valido generado a partir de el.
     */
    public function generateSecret(User $user): string
    {
        $secret = $this->google2fa->generateSecretKey();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return $secret;
    }

    /**
     * SVG del codigo QR para escanear con la app autenticadora. Se genera en
     * el servidor (bacon/bacon-qr-code, sin llamar a un servicio externo de
     * terceros) para no depender de una imagen remota ni romper el CSP.
     */
    public function qrCodeSvg(User $user, string $secret): string
    {
        $qrCodeUrl = $this->google2fa->getQRCodeUrl(
            config('app.name', 'UDG Sentinel'),
            (string) $user->email,
            $secret,
        );

        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd,
        );

        return (new Writer($renderer))->writeString($qrCodeUrl);
    }

    public function verifyCode(User $user, string $code): bool
    {
        if ($user->two_factor_secret === null) {
            return false;
        }

        $timestamp = $this->google2fa->verifyKeyNewer(
            $user->two_factor_secret,
            preg_replace('/\s+/', '', $code) ?? '',
            $user->two_factor_last_verified_timestamp ?? null,
        );

        if ($timestamp === false) {
            return false;
        }

        $user->forceFill(['two_factor_last_verified_timestamp' => $timestamp])->save();

        return true;
    }

    /**
     * Confirma el secreto pendiente con un primer codigo TOTP valido, activa
     * 2FA y genera los codigos de recuperacion (se devuelven UNA sola vez en
     * claro; a partir de aqui solo se guardan cifrados).
     *
     * @return array<int, string>
     */
    public function confirm(User $user, string $code): array
    {
        abort_unless($this->verifyCode($user, $code), 422, 'El código no es válido.');

        $recoveryCodes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $recoveryCodes,
        ])->save();

        return $recoveryCodes;
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    /**
     * @return array<int, string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->generateRecoveryCodes();

        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return $codes;
    }

    public function useRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];
        $normalized = strtoupper(trim($code));

        if (! in_array($normalized, $codes, true)) {
            return false;
        }

        $user->forceFill([
            'two_factor_recovery_codes' => array_values(array_diff($codes, [$normalized])),
        ])->save();

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function generateRecoveryCodes(): array
    {
        return collect(range(1, self::RECOVERY_CODE_COUNT))
            ->map(static fn (): string => strtoupper(Str::random(4).'-'.Str::random(4)))
            ->all();
    }
}
