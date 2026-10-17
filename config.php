<?php
// Valeurs par défaut. Les vrais secrets vont dans config.local.php (voir README), jamais ici.
$config = [
    // --- Diagnostic (RapidAPI) : à vérifier dans l'onglet "Code Snippets" de l'API ---
    'rapidapi_key'   => getenv('RAPIDAPI_KEY') ?: '',
    'rapidapi_host'  => 'livestock-disease-detection-api.p.rapidapi.com',
    'endpoint'       => '/detect',
    'send_as'        => 'multipart',     // 'multipart' (image) ou 'json' (symptômes seuls)
    'image_field'    => 'image',
    'symptoms_field' => 'symptoms',
    'species_field'  => 'species',

    // --- Paiement FlexPaie (Mobile Money) ---
    'flexpay_merchant'   => '',            // ton code marchand FlexPaie (dans config.local.php)
    'flexpay_token'      => getenv('FLEXPAY_TOKEN') ?: '',   // si vide : pas de paiement
    'flexpay_mobile_url' => 'https://backend.flexpay.cd/api/rest/v1/paymentService',
    'flexpay_check_url'  => 'https://apicheck.flexpaie.com/api/rest/v1/check/',
    'price'              => 500,         // prix d'un diagnostic (à ajuster)
    'currency'           => 'CDF',
    'base_url'           => '',          // ex : https://veto.mireb.online (sinon déduit de la requête)

    // --- Divers ---
    'allow_free'         => false,       // true = autoriser un vrai diagnostic SANS paiement (déconseillé)
    'admin_password'     => '',          // vide = page admin.php désactivée
    'max_upload_mb'      => 5,
    'daily_limit_per_ip' => 20,
    'app_name'           => 'VétoElevage',
];

if (is_file(__DIR__ . '/config.local.php')) {
    $config = array_merge($config, require __DIR__ . '/config.local.php');
}
return $config;
