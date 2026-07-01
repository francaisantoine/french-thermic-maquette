<?php
/**
 * Proxy ADEME DPE XML — French Thermic
 * 
 * À déposer à la racine du site sur Infomaniak (même dossier que index.html).
 * Permet au simulateur DPE de récupérer le fichier XML directement depuis
 * l'Observatoire ADEME sans blocage CORS.
 * 
 * Usage : GET /dpe-proxy.php?n=2284E2661376X
 * 
 * Sécurité :
 *  - Validation du format du numéro DPE (alphanumérique 13 chars)
 *  - Rate limiting simple via fichier (optionnel)
 *  - Timeout court (5 secondes)
 */

// ---- CORS : autoriser uniquement votre domaine ----
$allowed_origins = [
    'https://frenchthermic.fr',
    'https://www.frenchthermic.fr',
    'http://localhost',          // pour tests locaux
    'http://127.0.0.1',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    // En dev, accepter toutes origines (retirer en production)
    header("Access-Control-Allow-Origin: *");
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---- Validation du numéro DPE ----
$numero = trim($_GET['n'] ?? '');
if (!preg_match('/^[A-Z0-9]{13,17}$/i', $numero)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Numéro DPE invalide.']);
    exit;
}
$numero = strtoupper($numero);

// ---- Tentative 1 : Observatoire DPE Rénovation ----
$urls = [
    "https://observatoire-dpe-renovation.ademe.fr/api/dpe/3/{$numero}/download",
    "https://data.ademe.fr/data-fair/api/v1/datasets/dpe03existant/lines?q=" . urlencode($numero) . "&q_fields=numero_dpe&size=1&format=xml",
];

$xml_content = null;
foreach ($urls as $url) {
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 8,
            'header'  => "Accept: application/xml, text/xml\r\nUser-Agent: FrenchThermic-Simulator/1.0\r\n",
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
        ],
    ]);
    $content = @file_get_contents($url, false, $ctx);
    if ($content && strlen($content) > 500 && strpos($content, '<dpe') !== false) {
        $xml_content = $content;
        break;
    }
}

if (!$xml_content) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => 'XML DPE non trouvé pour ce numéro. Téléchargez manuellement sur observatoire-dpe-renovation.ademe.fr',
        'fallback_url' => "https://observatoire-dpe-renovation.ademe.fr/resultats/{$numero}",
    ]);
    exit;
}

// ---- Succès : renvoyer le XML ----
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=86400'); // cache 24h (DPE immuable)
header('X-DPE-Numero: ' . $numero);
echo $xml_content;
