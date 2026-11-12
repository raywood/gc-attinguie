<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Entity\AppBranding;
use App\Entity\AppBrandingAudit;
use App\Controller\PublicBrandingController;

function gcAssert(bool $test): void { if (!$test) throw new RuntimeException('Test identité visuelle échoué.'); }

$brand = new AppBranding();
$payload = PublicBrandingController::payload($brand);
gcAssert($payload['appName'] === 'GC-ATTINGUIÉ');
gcAssert($payload['logoUrl'] === '/gc-attinguie-logo.png');
gcAssert($payload['version'] === 1);
$old = $brand->snapshot();
$brand->setContent('G-AKOUPE', 'Commune d’Akoupé', 'La plateforme de gestion des commerces de la Commune d’Akoupé.');
$brand->touch(1);
gcAssert($brand->getVersion() === 2);
gcAssert($brand->getAppName() === 'G-AKOUPE');
gcAssert($brand->getOrganizationName() === 'Commune d’Akoupé');
new AppBrandingAudit(1, 'branding_text_updated', $old, $brand->snapshot());
$brand->setLogoFilename('logo-' . str_repeat('a', 32) . '.webp');
gcAssert(PublicBrandingController::payload($brand)['logoUrl'] === '/api/public/branding/logo?v=2');
print "OK : identité modifiable, versionnée et retour API cohérent.\n";
