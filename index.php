<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$name = cfg()['app_name'];
$pay = payment_required();
$blocked = service_blocked();
$price = (int)cfg()['price'];
$cur = (string)cfg()['currency'];

$st = db()->prepare("SELECT created_at, species, zone, summary, status FROM diagnostics WHERE sid = ? ORDER BY id DESC LIMIT 10");
$st->execute([sid()]);
$history = $st->fetchAll(PDO::FETCH_ASSOC);
$labels = ['done' => 'Terminé', 'pending' => 'Paiement en attente', 'paid' => 'En cours', 'running' => 'En cours', 'failed' => 'Échec', 'error' => 'Erreur'];
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($name) ?> – Diagnostic du bétail</title>
<style>
  :root { --g:#2e7d32; --bg:#f4f7f2; --c:#fff; --t:#1c2a1f; --m:#5f6f63; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:system-ui,sans-serif; background:var(--bg); color:var(--t); }
  header { background:var(--g); color:#fff; padding:16px; text-align:center; }
  header .sub { color:#d7ecd8; font-size:.9rem; }
  main { max-width:640px; margin:0 auto; padding:16px; }
  .card { background:var(--c); border-radius:12px; padding:16px; margin-bottom:16px; box-shadow:0 1px 4px #0001; }
  label { display:block; margin:12px 0 4px; font-weight:600; }
  select,input[type=text],input[type=tel],textarea,input[type=file] { width:100%; padding:10px; border:1px solid #ccd5cc; border-radius:8px; font:inherit; }
  textarea { min-height:90px; }
  button { width:100%; margin-top:16px; padding:14px; border:0; border-radius:8px; background:var(--g); color:#fff; font-size:1rem; font-weight:700; }
  button:disabled { opacity:.6; }
  #preview { max-width:100%; border-radius:8px; margin-top:8px; display:none; }
  #result,#wait { display:none; }
  .err { color:#b3261e; } .muted { color:var(--m); font-size:.9rem; }
  .price { background:#e8f3e8; border-radius:8px; padding:10px; margin-top:12px; font-weight:600; }
  pre { white-space:pre-wrap; background:#f1f5f0; padding:10px; border-radius:8px; font-size:.85rem; }
  table { width:100%; border-collapse:collapse; font-size:.9rem; } td { padding:6px 4px; border-bottom:1px solid #e3e9e2; }
</style>
</head>
<body>
<header><h1 style="margin:0;font-size:1.3rem">🐄 <?= e($name) ?></h1><div class="sub">Diagnostic de santé animale par photo</div></header>
<main>
<?php if ($blocked): ?>
  <div class="card err">Le service est momentanément indisponible. Réessaie plus tard.</div>
<?php else: ?>
  <form id="f" class="card" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <label>Espèce</label>
    <select name="species">
      <option value="bovin">Bovin</option><option value="porcin">Porcin</option><option value="ovin">Ovin</option>
      <option value="caprin">Caprin</option><option value="volaille">Volaille</option><option value="autre">Autre</option>
    </select>
    <label>Photo de l'animal</label>
    <input type="file" name="image" id="image" accept="image/*" capture="environment">
    <img id="preview" alt="Aperçu">
    <label>Symptômes observés (optionnel)</label>
    <textarea name="symptoms" placeholder="Ex : boiterie, fièvre, bave abondante, perte d'appétit…"></textarea>
    <label>Zone / village (optionnel)</label>
    <input type="text" name="zone" maxlength="80">
<?php if ($pay): ?>
    <label>Numéro Mobile Money (M-Pesa, Airtel Money, Orange Money)</label>
    <input type="tel" name="phone" placeholder="0812345678" inputmode="numeric" required>
    <div class="price">Prix du diagnostic : <?= number_format($price, 0, ',', ' ') ?> <?= e($cur) ?></div>
<?php endif; ?>
    <button id="btn" type="submit"><?= $pay ? 'Payer et analyser' : 'Analyser' ?></button>
  </form>
<?php endif; ?>

  <div id="wait" class="card">
    <h3 style="margin-top:0" id="waitTitle">Paiement en attente…</h3>
    <p id="waitMsg">Valide le paiement sur ton téléphone (message USSD). Ne ferme pas cette page.</p>
    <p class="muted">Référence : <b id="waitRef"></b></p>
  </div>

  <div id="result" class="card">
    <h2 style="margin-top:0">Résultat</h2>
    <p id="summary" style="font-size:1.1rem;font-weight:700"></p>
    <pre id="raw"></pre>
    <p class="muted">⚠️ Ce résultat est indicatif et ne remplace pas l'avis d'un vétérinaire. En cas de doute, isole l'animal et contacte un vétérinaire.</p>
  </div>
  <p id="error" class="err"></p>

<?php if ($history): ?>
  <div class="card"><h3 style="margin-top:0">Mes derniers diagnostics</h3>
    <table><?php foreach ($history as $h): ?>
      <tr><td><?= e(substr($h['created_at'], 0, 16)) ?></td><td><?= e($h['species']) ?><?= $h['zone'] ? ' · ' . e($h['zone']) : '' ?></td><td><?= e($h['status'] === 'done' ? $h['summary'] : ($labels[$h['status']] ?? $h['status'])) ?></td></tr>
    <?php endforeach; ?></table>
  </div>
<?php endif; ?>
</main>
<script>
const f = document.getElementById('f');
const $ = id => document.getElementById(id);
const sleep = ms => new Promise(r => setTimeout(r, ms));
function store(k, v) { try { v === null ? localStorage.removeItem(k) : localStorage.setItem(k, v); } catch (e) {} }
function load(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }

if (f) {
  $('image').addEventListener('change', ev => {
    const file = ev.target.files[0], prev = $('preview');
    if (file) { prev.src = URL.createObjectURL(file); prev.style.display = 'block'; } else prev.style.display = 'none';
  });
}

async function poll(ref) {
  $('waitRef').textContent = ref;
  for (let i = 0; i < 120; i++) {            // ~6 minutes
    const r = await fetch('status.php?ref=' + encodeURIComponent(ref));
    const d = await r.json();
    if (!r.ok) throw new Error(d.error || 'Erreur');
    if (d.status === 'done') return d;
    if (d.status === 'failed' || d.status === 'error') throw new Error(d.error || 'Échec');
    if (d.status === 'paid' || d.status === 'running') $('waitTitle').textContent = 'Paiement reçu, analyse en cours…';
    await sleep(3000);
  }
  throw new Error('Délai dépassé. Garde la référence ' + ref + ' et recharge la page.');
}

async function follow(ref) {
  $('error').textContent = ''; $('result').style.display = 'none';
  $('wait').style.display = 'block'; $('waitTitle').textContent = 'Paiement en attente…';
  store('veto_ref', ref);
  try {
    const d = await poll(ref);
    $('summary').textContent = d.summary;
    $('raw').textContent = JSON.stringify(d.result, null, 2);
    $('result').style.display = 'block';
  } catch (e) { $('error').textContent = e.message; }
  $('wait').style.display = 'none';
  store('veto_ref', null);
}

if (f) f.addEventListener('submit', async ev => {
  ev.preventDefault();
  const btn = $('btn'), label = btn.textContent;
  $('error').textContent = ''; btn.disabled = true; btn.textContent = 'Envoi…';
  try {
    const r = await fetch('analyze.php', { method: 'POST', body: new FormData(f) });
    const d = await r.json();
    if (!r.ok) throw new Error(d.error || 'Erreur');
    await follow(d.ref);
  } catch (e) { $('error').textContent = e.message; }
  btn.disabled = false; btn.textContent = label;
});

const pending = load('veto_ref');
if (pending) follow(pending);
</script>
</body>
</html>
