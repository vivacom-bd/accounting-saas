<?php

declare(strict_types=1);

namespace App\Services\EInvoicing;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * The QR code of an accepted document's authority payload, as an SVG data URI for the PDF (plan 3c.27). Uses the QR library that
 * Fortify already installs for two-factor set-up; no package was added for this.
 */
final class EInvoiceQr
{
    public static function dataUri(string $payload): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(160, 0), new SvgImageBackEnd)))->writeString($payload);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
