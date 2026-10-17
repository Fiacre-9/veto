<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
    session_start();
}

function cfg(): array {
    static $c = null;
    if ($c === null) $c = require __DIR__ . '/config.php';
    return $c;
}

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function now(): string { return gmdate('Y-m-d H:i:s'); }
function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
function sid(): string { return hash('sha256', session_id()); }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function json_out(array $d, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

function base_url(): string {
    $b = rtrim((string)cfg()['base_url'], '/');
    if ($b !== '') return $b;
    $https = !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/* ---------- Mode de fonctionnement ---------- */
function payment_required(): bool { return cfg()['flexpay_token'] !== ''; }
function demo_mode(): bool { return cfg()['rapidapi_key'] === ''; }
/** Un vrai diagnostic sans paiement configuré est bloqué (sinon la clé RapidAPI serait consommée gratuitement). */
function service_blocked(): bool { return !payment_required() && !demo_mode() && !cfg()['allow_free']; }

/* ---------- Base de données ---------- */
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dir = __DIR__ . '/storage';
    if (!is_dir($dir . '/uploads')) mkdir($dir . '/uploads', 0750, true);
    $pdo = new PDO('sqlite:' . $dir . '/history.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('CREATE TABLE IF NOT EXISTS diagnostics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ref TEXT UNIQUE NOT NULL,
        sid TEXT NOT NULL,
        ip TEXT NOT NULL,
        status TEXT NOT NULL,            -- pending | paid | running | done | failed | error
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        paid_at TEXT,
        phone TEXT,
        amount INTEGER NOT NULL DEFAULT 0,
        currency TEXT,
        order_number TEXT,
        species TEXT, zone TEXT, symptoms TEXT,
        image_path TEXT, image_mime TEXT,
        attempts INTEGER NOT NULL DEFAULT 0,
        result_json TEXT, summary TEXT, error TEXT
    )');
    return $pdo;
}

function over_limit(): bool {
    $st = db()->prepare("SELECT COUNT(*) FROM diagnostics WHERE ip = ? AND created_at >= ? AND status != 'failed'");
    $st->execute([client_ip(), gmdate('Y-m-d 00:00:00')]);
    return (int)$st->fetchColumn() >= (int)cfg()['daily_limit_per_ip'];
}

function get_order(string $ref): ?array {
    $st = db()->prepare('SELECT * FROM diagnostics WHERE ref = ?');
    $st->execute([$ref]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function set_order(string $ref, array $fields): void {
    $sets = []; $vals = [];
    foreach ($fields as $k => $v) { $sets[] = $k . ' = ?'; $vals[] = $v; }   // clés internes uniquement
    $sets[] = 'updated_at = ?'; $vals[] = now(); $vals[] = $ref;
    db()->prepare('UPDATE diagnostics SET ' . implode(', ', $sets) . ' WHERE ref = ?')->execute($vals);
}

function drop_image(array $o): void {
    if (!empty($o['image_path'])) {
        $f = __DIR__ . '/storage/uploads/' . basename($o['image_path']);
        if (is_file($f)) @unlink($f);
    }
}

function normalize_phone(string $p): ?string {
    $d = preg_replace('/\D/', '', $p);
    if (preg_match('/^243\d{9}$/', $d)) return $d;
    if (preg_match('/^0\d{9}$/', $d)) return '243' . substr($d, 1);
    if (preg_match('/^\d{9}$/', $d)) return '243' . $d;
    return null;
}

/* ---------- FlexPaie ---------- */
function flexpay_bearer(): string {
    $t = (string)cfg()['flexpay_token'];
    return str_starts_with($t, 'Bearer ') ? $t : 'Bearer ' . $t;
}

function flexpay_pay_mobile(string $phone, string $ref, int $amount, string $currency): array {
    $ch = curl_init((string)cfg()['flexpay_mobile_url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: ' . flexpay_bearer()],
        CURLOPT_POSTFIELDS => json_encode([
            'merchant'    => cfg()['flexpay_merchant'],
            'type'        => '1',
            'phone'       => $phone,
            'reference'   => $ref,
            'amount'      => (string)$amount,
            'currency'    => $currency,
            'callbackUrl' => base_url() . '/callback.php',
        ]),
    ]);
    $txt = curl_exec($ch);
    curl_close($ch);
    $j = json_decode((string)$txt, true);
    return is_array($j) ? $j : ['code' => '1', 'message' => 'Réponse invalide du service de paiement.'];
}

/** Vérifie auprès de FlexPaie : 'paid' | 'failed' | 'pending'. Jamais sur simple confiance au client. */
function flexpay_check(string $orderNumber): string {
    $ch = curl_init(rtrim((string)cfg()['flexpay_check_url'], '/') . '/' . rawurlencode($orderNumber));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Authorization: ' . flexpay_bearer()],
    ]);
    $txt = curl_exec($ch);
    curl_close($ch);
    $j = json_decode((string)$txt, true);
    if (!is_array($j) || (string)($j['code'] ?? '') !== '0') return 'pending';
    $s = (string)($j['transaction']['status'] ?? '');
    if ($s === '0') return 'paid';
    if ($s === '1') return 'failed';
    return 'pending';
}

/* ---------- RapidAPI ---------- */
/** Retourne [httpCode, array|null, erreur|null]. */
function call_livestock_api(string $species, string $symptoms, ?string $imagePath, ?string $imageMime): array {
    $c = cfg();
    if ($c['rapidapi_key'] === '') {
        return [200, [
            'demo' => true,
            'disease' => 'Exemple : fièvre aphteuse (résultat fictif)',
            'confidence' => 0.82,
            'advice' => 'Mode démo : configure la clé RapidAPI pour de vrais diagnostics.',
        ], null];
    }

    $url = 'https://' . $c['rapidapi_host'] . $c['endpoint'];
    $headers = [
        'X-RapidAPI-Key: ' . $c['rapidapi_key'],
        'X-RapidAPI-Host: ' . $c['rapidapi_host'],
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 60]);

    if ($c['send_as'] === 'multipart' && $imagePath) {
        $fields = [
            $c['image_field']   => new CURLFile($imagePath, $imageMime ?: 'image/jpeg', 'photo.jpg'),
            $c['species_field'] => $species,
        ];
        if ($symptoms !== '') $fields[$c['symptoms_field']] = $symptoms;
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    } else {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            $c['species_field']  => $species,
            $c['symptoms_field'] => $symptoms,
        ]));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch) ?: null;
    curl_close($ch);

    $json = $body ? json_decode((string)$body, true) : null;
    return [$code, is_array($json) ? $json : ['raw' => $body], $err];
}

function run_diagnosis(array $o): void {
    $img = null;
    if (!empty($o['image_path'])) {
        $f = __DIR__ . '/storage/uploads/' . basename($o['image_path']);
        if (is_file($f)) $img = $f;
    }
    [$code, $result, $err] = call_livestock_api((string)$o['species'], (string)$o['symptoms'], $img, $o['image_mime']);

    if ($err || $code >= 400 || $code === 0) {
        $attempts = (int)$o['attempts'] + 1;
        if ($attempts >= 3) {
            set_order($o['ref'], ['status' => 'error', 'attempts' => $attempts,
                'error' => 'Le diagnostic a échoué après paiement. Contacte le support avec la référence ' . $o['ref'] . '.']);
        } else {
            set_order($o['ref'], ['status' => 'paid', 'attempts' => $attempts, 'error' => 'Nouvelle tentative…']);
        }
        return;
    }

    $summary = $result['disease'] ?? $result['diagnosis'] ?? $result['prediction'] ?? $result['result'] ?? 'Résultat reçu';
    if (is_array($summary)) $summary = json_encode($summary, JSON_UNESCAPED_UNICODE);
    set_order($o['ref'], [
        'status' => 'done',
        'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE),
        'summary' => mb_substr((string)$summary, 0, 200),
        'error' => null,
    ]);
    drop_image($o);
}

/**
 * Fait avancer la commande : vérifie le paiement chez FlexPaie, puis lance le diagnostic une seule fois.
 * Appelée par status.php (sondage du client) ET callback.php (appel de FlexPaie).
 */
function refresh_order(string $ref): ?array {
    $o = get_order($ref);
    if (!$o) return null;

    if ($o['status'] === 'pending' && $o['order_number']) {
        $s = flexpay_check($o['order_number']);
        if ($s === 'paid') {
            set_order($ref, ['status' => 'paid', 'paid_at' => now()]);
        } elseif ($s === 'failed') {
            set_order($ref, ['status' => 'failed', 'error' => 'Paiement refusé ou annulé.']);
            drop_image($o);
        } elseif (time() - strtotime($o['created_at'] . ' UTC') > 1800) {
            set_order($ref, ['status' => 'failed', 'error' => 'Délai de paiement dépassé.']);
            drop_image($o);
        }
        $o = get_order($ref);
    }

    // verrou périmé (plantage pendant l'appel API)
    if ($o['status'] === 'running' && time() - strtotime($o['updated_at'] . ' UTC') > 180) {
        set_order($ref, ['status' => 'paid']);
        $o = get_order($ref);
    }

    if ($o['status'] === 'paid') {
        $st = db()->prepare("UPDATE diagnostics SET status = 'running', updated_at = ? WHERE ref = ? AND status = 'paid'");
        $st->execute([now(), $ref]);
        if ($st->rowCount() === 1) run_diagnosis(get_order($ref));
        $o = get_order($ref);
    }
    return $o;
}
