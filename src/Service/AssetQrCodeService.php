<?php

namespace App\Service;

use App\Entity\Asset;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Service for generating QR codes for Asset identification
 *
 * Use cases:
 * - Physical asset labels for inventory management
 * - Quick asset identification during audits
 * - Mobile access to asset details
 */
class AssetQrCodeService
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Generate a QR code for an asset that links to its detail page
     *
     * @param Asset $asset The asset to generate QR code for
     * @param string $locale The locale for the URL (de/en)
     * @param int $size QR code size in pixels
     * @param string $format Output format: 'png' or 'svg'
     * @return string Base64 encoded image data (for PNG) or SVG string
     */
    public function generateQrCode(
        Asset $asset,
        string $locale = 'de',
        int $size = 200,
        string $format = 'png'
    ): string {
        $url = $this->urlGenerator->generate(
            'app_asset_show',
            ['id' => $asset->getId(), '_locale' => $locale],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $writer = $format === 'svg' ? new SvgWriter() : new PngWriter();

        $result = new Builder(
            writer: $writer,
            writerOptions: [],
            data: $url,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $size,
            margin: 10,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        )->build();

        if ($format === 'svg') {
            return $result->getString();
        }

        return base64_encode($result->getString());
    }

    /**
     * Generate QR code as data URI for embedding in HTML
     */
    public function generateQrCodeDataUri(
        Asset $asset,
        string $locale = 'de',
        int $size = 200
    ): string {
        $base64 = $this->generateQrCode($asset, $locale, $size, 'png');
        return 'data:image/png;base64,' . $base64;
    }

    /**
     * Generate label data for a single asset
     *
     * @return array{
     *     asset: Asset,
     *     qrCode: string,
     *     url: string
     * }
     */
    public function generateLabelData(Asset $asset, string $locale = 'de'): array
    {
        return [
            'asset' => $asset,
            'qrCode' => $this->generateQrCodeDataUri($asset, $locale, 150),
            'url' => $this->urlGenerator->generate(
                'app_asset_show',
                ['id' => $asset->getId(), '_locale' => $locale],
                UrlGeneratorInterface::ABSOLUTE_URL
            ),
        ];
    }

    /**
     * Generate label data for multiple assets (for label sheets)
     *
     * @param Asset[] $assets
     * @return array<array{asset: Asset, qrCode: string, url: string}>
     */
    public function generateBulkLabelData(array $assets, string $locale = 'de'): array
    {
        $labels = [];
        foreach ($assets as $asset) {
            $labels[] = $this->generateLabelData($asset, $locale);
        }
        return $labels;
    }
}
