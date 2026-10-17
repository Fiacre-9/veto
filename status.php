<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$ref = (string)($_GET['ref'] ?? '');
$o = $ref !== '' ? get_order($ref) : null;
// un client ne peut lire que ses propres commandes
if (!$o || !hash_equals($o['sid'], sid())) json_out(['error' => 'Commande introuvable'], 404);

$o = refresh_order($ref);
$out = ['ref' => $o['ref'], 'status' => $o['status']];
if ($o['status'] === 'done') {
    $out['summary'] = $o['summary'];
    $out['result']  = json_decode((string)$o['result_json'], true);
}
if (in_array($o['status'], ['failed', 'error'], true)) $out['error'] = $o['error'];
json_out($out);
