<?php

declare(strict_types=1);

namespace App\Actions\Admin\Auth;

use App\Domain\Shared\DomainRuleViolation;
use App\Models\Admin\AdminUser;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminRequestContext;
use App\Support\Admin\AdminTransaction;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * Starts TOTP enrolment for a staff member who passed the password step but has never confirmed 2FA. Issues a new
 * secret each time until it is confirmed; a confirmed secret is never replaced here.
 */
final class EnrolAdminTwoFactor
{
    public function __construct(
        private readonly TwoFactorAuthenticationProvider $totp,
        private readonly AdminAuditWriter $audit,
    ) {}

    /** @return array{secret: string, otpauth_url: string, qr_svg: string} */
    public function __invoke(AdminUser $admin, AdminRequestContext $context): array
    {
        if ($admin->two_factor_confirmed_at !== null) {
            throw DomainRuleViolation::single('two_factor_already_confirmed', 'Two-factor authentication is already set up.');
        }

        $secret = $this->totp->generateSecretKey();
        AdminTransaction::run(function () use ($admin, $secret, $context): void {
            $admin->forceFill(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => null])->save();
            $this->audit->record($context->actorFor($admin), 'auth.two_factor_enrol_started', null, 'admin_user', $admin->id);
        });

        $url = $this->totp->qrCodeUrl(config('app.name').' Admin', $admin->email, $secret);

        return ['secret' => $secret, 'otpauth_url' => $url, 'qr_svg' => self::svg($url)];
    }

    private static function svg(string $url): string
    {
        $svg = (new Writer(new ImageRenderer(
            new RendererStyle(192, 0, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(45, 55, 72))),
            new SvgImageBackEnd,
        )))->writeString($url);

        // Drop the XML declaration so the markup can be inlined.
        return trim(substr($svg, (int) strpos($svg, "\n") + 1));
    }
}
