# VétoElevage – Diagnostic du bétail (PHP + RapidAPI + FlexPaie)

Page : https://veto.mireb.online

## Fonctionnement
1. L'éleveur choisit l'espèce, envoie une photo, décrit les symptômes et saisit son numéro Mobile Money.
2. Le serveur lance le paiement FlexPaie (push USSD sur le téléphone).
3. La page vérifie le paiement **auprès de FlexPaie** (jamais sur la parole du navigateur), puis appelle l'API RapidAPI **une seule fois** et affiche le résultat.
4. Si le diagnostic échoue après paiement : 3 tentatives, puis statut « error » visible dans `admin.php` (client à rembourser ou à traiter à la main).

## Configuration (fichier `config.local.php` sur le serveur, jamais sur GitHub)
Modèle : `config.local.php.example`. À renseigner :
- `rapidapi_key`, `rapidapi_host`, `endpoint` : onglet *Code Snippets (PHP cURL)* de l'API sur RapidAPI. Si l'API attend du JSON sans image : `send_as` = `json`. Noms des champs : `image_field`, `symptoms_field`, `species_field`.
- `flexpay_token` : même token FlexPaie que la machine WiFi. `flexpay_merchant` = `MARCO_SERVICE`.
- `price` / `currency` : prix d'un diagnostic (500 CDF par défaut).
- `admin_password` : active `admin.php` (recettes, paiements en attente, erreurs).

## Modes
| flexpay_token | rapidapi_key | Comportement |
|---|---|---|
| vide | vide | **Démo gratuite** (résultat fictif) |
| rempli | vide | Paiement réel + résultat fictif (test du paiement) |
| rempli | rempli | **Production** |
| vide | rempli | **Bloqué** (évite de consommer RapidAPI gratuitement), sauf `allow_free` = true |

## Sécurité
- Régénère la clé RapidAPI qui a été collée dans le chat.
- `storage/` (base SQLite et images) est protégé par `.htaccess` ; l'image est supprimée après le diagnostic.
- Limite : 20 diagnostics par IP et par jour (`daily_limit_per_ip`).
- Chaque client ne peut lire que ses propres diagnostics.

## Limites actuelles
- Paiement Mobile Money uniquement (pas de carte bancaire).
- Pas de remboursement automatique.
