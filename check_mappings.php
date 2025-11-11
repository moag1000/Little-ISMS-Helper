<?php
// Quick check to see how many requirements have iso_controls

require __DIR__ . '/vendor/autoload.php';

$kernel = new \App\Kernel('dev', true);
$kernel->boot();
$container = $kernel->getContainer();

$em = $container->get('doctrine')->getManager();
$reqRepo = $em->getRepository(\App\Entity\ComplianceRequirement::class);

$allReqs = $reqRepo->findAll();

echo "Total requirements: " . count($allReqs) . "\n\n";

$byFramework = [];
$withIsoControls = [];
$withoutIsoControls = [];
$exampleWithout = [];

foreach ($allReqs as $req) {
    $framework = $req->getFramework()->getCode();
    
    if (!isset($byFramework[$framework])) {
        $byFramework[$framework] = 0;
        $withIsoControls[$framework] = 0;
        $withoutIsoControls[$framework] = 0;
        $exampleWithout[$framework] = null;
    }
    
    $byFramework[$framework]++;
    
    $dsm = $req->getDataSourceMapping();
    if (!empty($dsm['iso_controls'])) {
        $withIsoControls[$framework]++;
    } else {
        $withoutIsoControls[$framework]++;
        if (!$exampleWithout[$framework]) {
            $exampleWithout[$framework] = $req->getRequirementId() . ': ' . substr($req->getName(), 0, 50);
        }
    }
}

foreach ($byFramework as $fw => $count) {
    echo "$fw:\n";
    echo "  Total: $count\n";
    echo "  With iso_controls: {$withIsoControls[$fw]}\n";
    echo "  Without iso_controls: {$withoutIsoControls[$fw]}\n";
    echo "  Coverage: " . round(($withIsoControls[$fw] / $count) * 100, 1) . "%\n";
    if ($exampleWithout[$fw]) {
        echo "  Example without: {$exampleWithout[$fw]}\n";
    }
    echo "\n";
}
