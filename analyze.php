<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Méthode non autorisée'], 405);
if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) json_out(['error' => 'Session expirée, recharge la page.'], 403);
if (service_blocked()) json_out(['error' => 'Service momentanément indisponible (paiement non configuré).'], 503);
if (over_limit()) json_out(['error' => 'Limite journalière atteinte. Réessaie demain.'], 429);

$allowed  = ['bovin', 'porcin', 'ovin', 'caprin', 'volaille', 'autre'];
$species  = in_array($_POST['species'] ?? '', $allowed, true) ? $_POST['species'] : 'autre';
$zone     = mb_substr(trim((string)($_POST['zone'] ?? '')), 0, 80);
$symptoms = mb_substr(trim((string)($_POST['symptoms'] ?? '')), 0, 1000);

$pay   = payment_required();
$phone = null;
if ($pay) {
    $phone = normalize_phone((string)($_POST['phone'] ?? ''));
    if (!$phone) json_out(['error' => 'Numéro Mobile Money invalide. Exemple : 0812345678'], 422);
}

$ref = 'VET' . strtoupper(base_convert((string)time(), 10, 36)) . strtoupper(bin2hex(random_bytes(3)));

$imgFile = $imgMime = null;
if (!empty($_FILES['image']['tmp_name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $f = $_FILES['image'];
    if ($f['size'] > cfg()['max_upload_mb'] * 1048576) json_out(['error' => 'Image trop lourde.'], 413);
    $imgMime = mime_content_type($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$imgMime] ?? null;
    if (!$ext) json_out(['error' => 'Format image non supporté (JPG, PNG, WebP).'], 415);
    $imgFile = $ref . '.' . $ext;
    db(); // crée storage/uploads si besoin
    if (!move_uploaded_file($f['tmp_name'], __DIR__ . '/storage/uploads/' . $imgFile)) json_out(['error' => 'Impossible d\'enregistrer l\'image.'], 500);
}
if (!$imgFile && $symptoms === '') json_out(['error' => 'Ajoute une photo ou décris les symptômes.'], 422);

$amount   = $pay ? (int)cfg()['price'] : 0;
$currency = (string)cfg()['currency'];

db()->prepare('INSERT INTO diagnostics (ref, sid, ip, status, created_at, updated_at, phone, amount, currency, species, zone, symptoms, image_path, image_mime)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute([$ref, sid(), client_ip(), $pay ? 'pending' : 'paid', now(), now(), $phone, $amount, $currency, $species, $zone, $symptoms, $imgFile, $imgMime]);

if ($pay) {
    $r = flexpay_pay_mobile($phone, $ref, $amount, $currency);
    if ((string)($r['code'] ?? '1') !== '0' || empty($r['orderNumber'])) {
        $o = get_order($ref);
        set_order($ref, ['status' => 'failed', 'error' => (string)($r['message'] ?? 'Paiement refusé')]);
        drop_image($o);
        json_out(['error' => 'Le paiement n\'a pas pu être lancé : ' . ($r['message'] ?? 'refusé') . '.'], 502);
    }
    set_order($ref, ['order_number' => (string)$r['orderNumber']]);
    json_out(['ok' => true, 'ref' => $ref, 'status' => 'pending', 'amount' => $amount, 'currency' => $currency]);
}

// Mode gratuit (démo) : diagnostic immédiat
$o = refresh_order($ref);
json_out(['ok' => true, 'ref' => $ref, 'status' => $o['status']]);
