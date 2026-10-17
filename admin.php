<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$pw = (string)cfg()['admin_password'];
if ($pw === '') { http_response_code(403); exit('Page admin désactivée (définis admin_password dans config.local.php).'); }

if (isset($_GET['logout'])) { unset($_SESSION['admin']); header('Location: admin.php'); exit; }

$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ok = hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))
       && hash_equals(hash('sha256', $pw), hash('sha256', (string)($_POST['password'] ?? '')));
    if ($ok) { session_regenerate_id(true); $_SESSION['admin'] = true; header('Location: admin.php'); exit; }
    $loginError = 'Mot de passe incorrect.';
    usleep(500000);
}
?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin – <?= e(cfg()['app_name']) ?></title>
<style>
  body{font-family:system-ui,sans-serif;background:#f4f7f2;margin:0;padding:16px;color:#1c2a1f}
  .box{max-width:960px;margin:0 auto;background:#fff;border-radius:12px;padding:16px;box-shadow:0 1px 4px #0001}
  .kpi{display:flex;gap:12px;flex-wrap:wrap;margin:12px 0}.kpi div{background:#e8f3e8;border-radius:8px;padding:10px 14px}
  table{width:100%;border-collapse:collapse;font-size:.85rem}td,th{padding:6px 4px;border-bottom:1px solid #e3e9e2;text-align:left}
  input,button{padding:10px;font:inherit}button{background:#2e7d32;color:#fff;border:0;border-radius:8px}
  .scroll{overflow-x:auto}
</style></head><body><div class="box">
<?php if (empty($_SESSION['admin'])): ?>
  <h2>Connexion admin</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="password" name="password" placeholder="Mot de passe" required autofocus>
    <button>Entrer</button>
    <?php if ($loginError): ?><p style="color:#b3261e"><?= e($loginError) ?></p><?php endif; ?>
  </form>
<?php else:
  $pdo = db();
  $count = fn(string $w) => (int)$pdo->query("SELECT COUNT(*) FROM diagnostics WHERE $w")->fetchColumn();
  $rev = (int)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM diagnostics WHERE paid_at IS NOT NULL")->fetchColumn();
  $today = (int)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM diagnostics WHERE paid_at >= '" . gmdate('Y-m-d 00:00:00') . "'")->fetchColumn();
  $rows = $pdo->query('SELECT created_at, ref, phone, species, zone, amount, currency, status, summary FROM diagnostics ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
?>
  <h2>Admin – <?= e(cfg()['app_name']) ?> <a href="?logout=1" style="font-size:.8rem">Déconnexion</a></h2>
  <div class="kpi">
    <div>Recettes totales<br><b><?= number_format($rev, 0, ',', ' ') ?> <?= e((string)cfg()['currency']) ?></b></div>
    <div>Aujourd'hui<br><b><?= number_format($today, 0, ',', ' ') ?> <?= e((string)cfg()['currency']) ?></b></div>
    <div>Diagnostics terminés<br><b><?= $count("status='done'") ?></b></div>
    <div>Paiements en attente<br><b><?= $count("status='pending'") ?></b></div>
    <div>Payés mais en erreur<br><b><?= $count("status='error'") ?></b></div>
  </div>
  <p style="font-size:.85rem">Les lignes « error » sont des clients qui ont payé sans recevoir de résultat : à rembourser ou à traiter à la main (référence + téléphone ci-dessous).</p>
  <div class="scroll"><table>
    <tr><th>Date (UTC)</th><th>Réf.</th><th>Téléphone</th><th>Espèce / zone</th><th>Montant</th><th>Statut</th><th>Résultat</th></tr>
    <?php foreach ($rows as $r): ?>
    <tr><td><?= e(substr($r['created_at'], 0, 16)) ?></td><td><?= e($r['ref']) ?></td><td><?= e($r['phone']) ?></td>
        <td><?= e($r['species']) ?><?= $r['zone'] ? ' · ' . e($r['zone']) : '' ?></td>
        <td><?= (int)$r['amount'] ?> <?= e($r['currency']) ?></td><td><?= e($r['status']) ?></td><td><?= e($r['summary']) ?></td></tr>
    <?php endforeach; ?>
  </table></div>
<?php endif; ?>
</div></body></html>
