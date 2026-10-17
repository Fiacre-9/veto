<?php
declare(strict_types=1);
// Appelé par FlexPaie après le paiement. Il ne fait que déclencher une VÉRIFICATION auprès de FlexPaie :
// aucune donnée reçue ici n'est crue sur parole.
require __DIR__ . '/lib.php';

$b = array_merge($_GET, $_POST);
$ref = (string)($b['reference'] ?? '');
if ($ref === '' && !empty($b['orderNumber'])) {
    $st = db()->prepare('SELECT ref FROM diagnostics WHERE order_number = ?');
    $st->execute([(string)$b['orderNumber']]);
    $ref = (string)($st->fetchColumn() ?: '');
}
if ($ref !== '' && get_order($ref)) refresh_order($ref);
json_out(['ok' => true]);
