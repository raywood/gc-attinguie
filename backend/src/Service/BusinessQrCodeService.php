<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Business;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class BusinessQrCodeService
{
    public function __construct(
        #[Autowire('%env(BUSINESS_VERIFY_BASE_URL)%')]
        private readonly string $baseUrl,
    ) {}

    public function verificationUrl(Business $business): string
    {
        $url = rtrim(trim($this->baseUrl), '/');
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['host']) || !in_array($parsed['scheme'] ?? '', ['https', 'http'], true)) {
            throw new \LogicException('BUSINESS_VERIFY_BASE_URL invalide.');
        }
        $isLoopback = in_array(strtolower((string) $parsed['host']), ['localhost', '127.0.0.1', '::1'], true);
        if ($parsed['scheme'] !== 'https' && !$isLoopback) {
            throw new \LogicException('HTTPS requis pour les QR Codes hors développement local.');
        }
        if (isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['query']) || isset($parsed['fragment'])) {
            throw new \LogicException('L’URL de vérification ne peut pas contenir d’identifiants, de requête ou de fragment.');
        }
        return $url . '/verification/c/' . $business->getQrPublicToken();
    }

    public function png(Business $business): string
    {
        return (new PngWriter())->write(new QrCode(data: $this->verificationUrl($business), size: 700, margin: 35))->getString();
    }

    public function svg(Business $business): string
    {
        return (new SvgWriter())->write(new QrCode(data: $this->verificationUrl($business), size: 700, margin: 35))->getString();
    }

    public function dataUri(Business $business): string
    {
        return 'data:image/png;base64,' . base64_encode($this->png($business));
    }

    public function reference(Business $business): string
    {
        return sprintf('GC-ATT-%06d', $business->getId());
    }
}
