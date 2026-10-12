<?php
/* ==== Chargement du fichier .env (secrets) ====
   Aucune dépendance externe (pas de Composer) : on lit .env à la main et on
   pose chaque valeur dans $_ENV / getenv(), pour que ça marche pareil en
   local et une fois hébergé (Hostinger ou autre). Le fichier .env doit être
   placé à côté de index.php et ne doit jamais être commité sur Git. */
function orzeta_charger_env($chemin) {
    if (!is_readable($chemin)) { return; }
    foreach (file($chemin, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ligne) {
        $ligne = trim($ligne);
        if ($ligne === '' || $ligne[0] === '#' || strpos($ligne, '=') === false) { continue; }
        list($cle, $valeur) = explode('=', $ligne, 2);
        $cle = trim($cle);
        $valeur = trim($valeur);
        // retire les guillemets englobants ("Site Orzeta" -> Site Orzeta)
        if (strlen($valeur) >= 2 && (
            ($valeur[0] === '"' && substr($valeur, -1) === '"') ||
            ($valeur[0] === "'" && substr($valeur, -1) === "'")
        )) {
            $valeur = substr($valeur, 1, -1);
        }
        if ($cle === '') { continue; }
        $GLOBALS['ORZETA_ENV'][$cle] = $valeur;
        $_ENV[$cle] = $valeur;
        if (function_exists('putenv')) { @putenv($cle . '=' . $valeur); }
    }
}

/** Lit une valeur du .env. Marche même si l'hébergeur (ex : InfinityFree) désactive putenv()/getenv(). */
if (!function_exists('orzeta_env')) {
    function orzeta_env($cle) {
        if (isset($GLOBALS['ORZETA_ENV'][$cle])) { return $GLOBALS['ORZETA_ENV'][$cle]; }
        if (isset($_ENV[$cle])) { return $_ENV[$cle]; }
        $v = function_exists('getenv') ? @getenv($cle) : false;
        return $v === false ? false : $v;
    }
}
orzeta_charger_env(__DIR__ . '/.env');
// Stockage des messages du formulaire (base SQLite dans data/)
if (is_readable(__DIR__ . '/stockage.php')) { require_once __DIR__ . '/stockage.php'; }

/* ==== Traitement du formulaire de contact (envoi d'email réel via SMTP) ====
   Fonctionne pareil en local et en hébergement (Hostinger), car l'envoi passe
   directement par le serveur SMTP indiqué ci-dessous, sans dépendre d'un
   serveur mail installé sur la machine (contrairement à mail()).
   Toutes les valeurs sensibles viennent maintenant du fichier .env. */
$CONTACT_TO_EMAIL   = orzeta_env('CONTACT_TO_EMAIL') ?: 'aminelahmer1999@gmail.com'; // destinataire par défaut (en production : CONTACT_TO_EMAIL=contact@orzeta.com dans .env)
$SMTP_HOST          = orzeta_env('SMTP_HOST') ?: 'smtp.gmail.com';
$SMTP_PORT          = (int) (orzeta_env('SMTP_PORT') ?: 587); // STARTTLS
$SMTP_USER          = orzeta_env('SMTP_USER') ?: '';
$SMTP_PASS          = orzeta_env('SMTP_PASS') ?: ''; // mot de passe d'application Gmail (16 caractères, sans espaces)
$SMTP_FROM_NAME     = orzeta_env('SMTP_FROM_NAME') ?: 'Site Orzeta';
// Méthode d'envoi : "smtp" (Gmail ou autre serveur, idéal en local) ou "mail"
// (fonction mail() de l'hébergeur — recommandé chez OVH, sans boîte mail à créer).
$MAIL_METHOD        = strtolower(orzeta_env('MAIL_METHOD') ?: 'smtp');
// Adresse d'expéditeur utilisée avec "mail" : doit être sur le domaine du site (ex : noreply@orzeta.com).
$MAIL_FROM          = orzeta_env('MAIL_FROM') ?: $SMTP_USER;

/**
 * Envoi via la fonction mail() de l'hébergeur (OVH, Hostinger...).
 * Chez OVH, l'adresse From doit appartenir au domaine rattaché à l'hébergement.
 */
function phpSendMail($fromEmail, $fromName, $toEmail, $subject, $body, $replyTo) {
    if (!function_exists('mail')) {
        return ['success' => false, 'error' => "La fonction mail() n'est pas disponible sur ce serveur."];
    }
    $proprete = function ($v) { return str_replace(["\r", "\n"], '', $v); };
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedName    = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $headers  = 'From: ' . $encodedName . ' <' . $proprete($fromEmail) . ">\r\n";
    $headers .= 'Reply-To: ' . $proprete($replyTo) . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit";
    $ok = @mail($proprete($toEmail), $encodedSubject, $body, $headers, '-f' . $proprete($fromEmail));
    return $ok ? ['success' => true, 'error' => '']
               : ['success' => false, 'error' => "mail() a refusé l'envoi (vérifie que MAIL_FROM est bien une adresse @ton-domaine)."];
}

/**
 * Envoie un email via SMTP (STARTTLS + AUTH LOGIN), sans dépendance externe.
 * Retourne ['success' => bool, 'error' => string].
 */
function smtpSendMail($host, $port, $user, $pass, $fromEmail, $fromName, $toEmail, $subject, $body, $replyTo) {
    // 1er essai : le port configuré (587 = STARTTLS, 465 = SSL direct).
    // Si la connexion sécurisée échoue sur 587 (antivirus, réseau, pare-feu...),
    // on retente automatiquement en SSL direct sur le port 465.
    $essais = [[(int) $port, ((int) $port === 465) ? 'ssl' : 'starttls']];
    if ((int) $port !== 465) { $essais[] = [465, 'ssl']; }

    $erreurs = [];
    foreach ($essais as $essai) {
        list($p, $mode) = $essai;
        $res = smtpTentative($host, $p, $mode, $user, $pass, $fromEmail, $fromName, $toEmail, $subject, $body, $replyTo);
        if ($res['success']) { return $res; }
        $erreurs[] = "[port $p / $mode] " . $res['error'];
        // Mauvais identifiants ou message refusé : inutile de retenter sur un autre port.
        if ($res['etape'] !== 'connexion' && $res['etape'] !== 'tls') { break; }
    }
    return ['success' => false, 'error' => implode(' | ', $erreurs)];
}

function smtpTentative($host, $port, $mode, $user, $pass, $fromEmail, $fromName, $toEmail, $subject, $body, $replyTo) {
    $readResponse = function ($socket) {
        $data = '';
        while (($line = fgets($socket, 515)) !== false) {
            $data .= $line;
            // Une réponse SMTP se termine par une ligne "CODE ESPACE ..." (pas de tiret après le code).
            if (isset($line[3]) && $line[3] === ' ') { break; }
        }
        return $data;
    };
    $echec = function ($etape, $msg, $socket = null) {
        if ($socket) { @fclose($socket); }
        return ['success' => false, 'etape' => $etape, 'error' => $msg];
    };

    // Options TLS : vérification du certificat activée (sécurité).
    // Si PHP ne trouve pas les certificats racines (fréquent sous Windows),
    // on peut indiquer le fichier cacert.pem via SMTP_CAFILE dans .env.
    $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true];
    // Dépannage LOCAL uniquement : SMTP_VERIFY_SSL=false désactive la vérification du certificat
    // (utile si un antivirus intercepte la connexion sous Windows). Ignoré sur un vrai serveur.
    $enLocal = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1', '::1'], true);
    if ($enLocal && strtolower((string) orzeta_env('SMTP_VERIFY_SSL')) === 'false') {
        $ssl['verify_peer'] = false;
        $ssl['verify_peer_name'] = false;
    }
    $cafile = orzeta_env('SMTP_CAFILE');
    if (!$cafile) {
        $infoCafile = ' [SMTP_CAFILE non défini dans .env]';
    } elseif (is_readable($cafile)) {
        $ssl['cafile'] = $cafile;
        $infoCafile = " [cacert.pem bien chargé depuis $cafile -> la cause est donc probablement l'antivirus]";
    } else {
        $infoCafile = " [SMTP_CAFILE=$cafile INTROUVABLE : vérifie le chemin et le nom exact du fichier]";
    }
    $contexte = stream_context_create(['ssl' => $ssl]);

    // Capture le vrai message d'erreur OpenSSL (sinon il est perdu).
    $dernierAvertissement = '';
    set_error_handler(function ($no, $str) use (&$dernierAvertissement) { $dernierAvertissement = $str; return true; });

    $errno = 0; $errstr = '';
    $prefixe = ($mode === 'ssl') ? 'ssl' : 'tcp';
    $socket = stream_socket_client("$prefixe://$host:$port", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $contexte);
    if (!$socket) {
        restore_error_handler();
        $detail = trim($errstr . ' ' . $dernierAvertissement);
        return $echec(($mode === 'ssl' && stripos($detail, 'SSL') !== false) ? 'tls' : 'connexion',
            "Connexion SMTP impossible ($detail).");
    }
    stream_set_timeout($socket, 15);

    $banniere = $readResponse($socket);
    if (strpos($banniere, '220') !== 0) {
        restore_error_handler();
        return $echec('connexion', 'Bannière SMTP inattendue : ' . trim($banniere), $socket);
    }

    fwrite($socket, "EHLO orzeta-site\r\n");
    $readResponse($socket);

    if ($mode === 'starttls') {
        fwrite($socket, "STARTTLS\r\n");
        $rep = $readResponse($socket);
        if (strpos($rep, '220') !== 0) {
            restore_error_handler();
            return $echec('tls', 'Le serveur a refusé STARTTLS : ' . trim($rep), $socket);
        }
        $methodes = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) { $methodes |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT; }
        if (!stream_socket_enable_crypto($socket, true, $methodes)) {
            restore_error_handler();
            $detail = $dernierAvertissement;
            while ($e = openssl_error_string()) { $detail .= ' ' . $e; }
            $conseil = (stripos($detail, 'certificate verify failed') !== false)
                ? ' -> PHP ne trouve pas les certificats racines (cacert.pem) OU un antivirus intercepte la connexion.'
                : '';
            return $echec('tls', "Impossible d'établir une connexion sécurisée (TLS). Détail : " . trim($detail) . $conseil . $infoCafile, $socket);
        }
        fwrite($socket, "EHLO orzeta-site\r\n");
        $readResponse($socket);
    }
    restore_error_handler();

    fwrite($socket, "AUTH LOGIN\r\n");
    $readResponse($socket);
    fwrite($socket, base64_encode($user) . "\r\n");
    $readResponse($socket);
    fwrite($socket, base64_encode($pass) . "\r\n");
    $authResp = $readResponse($socket);
    if (strpos($authResp, '235') !== 0) {
        return $echec('auth', "Authentification SMTP refusée. Vérifie l'adresse et le mot de passe d'application.", $socket);
    }

    fwrite($socket, "MAIL FROM:<$fromEmail>\r\n");
    $readResponse($socket);

    fwrite($socket, "RCPT TO:<$toEmail>\r\n");
    $rcptResp = $readResponse($socket);
    if (strpos($rcptResp, '250') !== 0 && strpos($rcptResp, '251') !== 0) {
        return $echec('envoi', "Le serveur SMTP a refusé le destinataire.", $socket);
    }

    fwrite($socket, "DATA\r\n");
    $readResponse($socket);

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers  = "From: $fromName <$fromEmail>\r\n";
    $headers .= "To: <$toEmail>\r\n";
    $headers .= "Reply-To: " . str_replace(["\r", "\n"], '', $replyTo) . "\r\n";
    $headers .= "Subject: $encodedSubject\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "\r\n";

    // Doubler les points en début de ligne (échappement SMTP standard).
    $escapedBody = preg_replace('/^\./m', '..', $body);

    fwrite($socket, $headers . $escapedBody . "\r\n.\r\n");
    $dataResp = $readResponse($socket);

    fwrite($socket, "QUIT\r\n");
    $readResponse($socket);
    fclose($socket);

    if (strpos($dataResp, '250') !== 0) {
        return ['success' => false, 'etape' => 'envoi', 'error' => "Le serveur mail a refusé le message."];
    }
    return ['success' => true, 'etape' => 'ok', 'error' => ''];
}

// Longueur d'un texte en caractères (fonctionne même si l'extension mbstring est absente)
function orzeta_len($t) { return function_exists('mb_strlen') ? mb_strlen($t, 'UTF-8') : (int) preg_match_all('/./us', $t); }

$contactSuccess = false;
$contactError = '';
$isAjaxContact = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['orzeta_contact'])) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $TYPES_CONTACT = ['particulier' => 'Particulier', 'distributeur' => 'Distributeur / importateur', 'restaurant' => 'Restaurant & hôtel', 'epicerie' => 'Épicerie fine', 'cadeaux' => "Cadeaux d'entreprise", 'autre' => 'Autre'];
    $typeContact = $TYPES_CONTACT[$_POST['type_contact'] ?? ''] ?? '';
    $typeAutre = trim(str_replace(["\r", "\n"], ' ', $_POST['type_contact_autre'] ?? ''));
    if (($_POST['type_contact'] ?? '') === 'autre') {
        if ($typeAutre === '' || orzeta_len($typeAutre) > 60) { $typeContact = ''; }
        else { $typeContact = 'Autre : ' . $typeAutre; }
    }
    $honeypot = trim($_POST['website'] ?? ''); // champ piège anti-spam, doit rester vide

    if ($honeypot !== '') {
        // Probable robot : on affiche un succès factice sans envoyer d'email.
        $contactSuccess = true;
    } elseif ($typeContact === '' || $name === '' || $email === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
              || orzeta_len($name) > 80 || orzeta_len($subject) > 120 || orzeta_len($message) < 10 || orzeta_len($message) > 2000) {
        $contactError = "Merci de remplir tous les champs obligatoires avec une adresse email valide.";
    } else {
        $mailSubject = 'Nouveau message du site Orzeta [' . $typeContact . ']' . ($subject !== '' ? ' — ' . $subject : '');
        $body = "Type de contact : $typeContact\n"
              . "Nom : $name\n"
              . "Email : $email\n"
              . "Sujet : " . ($subject !== '' ? $subject : '(non précisé)') . "\n\n"
              . "Message :\n$message\n";

        // 1) on enregistre d'abord le message dans la base (il est gardé même si le mail échoue)
        $idMessage = function_exists('orzeta_enregistrer_message')
            ? orzeta_enregistrer_message($typeContact, $name, $email, $subject, $message) : 0;

        // 2) puis on envoie le mail de notification
        if ($MAIL_METHOD === 'mail') {
            $result = phpSendMail($MAIL_FROM, $SMTP_FROM_NAME, $CONTACT_TO_EMAIL, $mailSubject, $body, $email);
        } else {
            $result = smtpSendMail(
                $SMTP_HOST, $SMTP_PORT, $SMTP_USER, $SMTP_PASS,
                $SMTP_USER, $SMTP_FROM_NAME, $CONTACT_TO_EMAIL,
                $mailSubject, $body, $email
            );
            // Filet de sécurité en ligne : si le SMTP est refusé par l'hébergeur,
            // on retente avec la fonction mail() du serveur (expéditeur = adresse du domaine).
            $surServeur = !in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1', '::1'], true);
            if (!$result['success'] && $surServeur && function_exists('mail')) {
                error_log('Orzeta : SMTP en échec (' . $result['error'] . '), nouvel essai avec mail().');
                $result = phpSendMail($MAIL_FROM ?: $SMTP_USER, $SMTP_FROM_NAME, $CONTACT_TO_EMAIL, $mailSubject, $body, $email);
            }
        }

        if ($idMessage) { orzeta_marquer_mail_envoye($idMessage, $result['success']); }
        if (!$result['success']) {
            error_log('Orzeta contact form SMTP error: ' . $result['error']);
        }
        // Succès si le message est parti par mail OU s'il est bien enregistré dans la base
        if ($result['success'] || $idMessage) {
            $contactSuccess = true;
        } else {
            $contactError = "Une erreur est survenue lors de l'envoi. Merci de réessayer, ou de nous contacter directement par email.";
        }
    }

    if ($isAjaxContact) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => $contactSuccess, 'error' => $contactError]);
        exit;
    }
}

/* ==== Référencement (SEO) : titre, description et adresse canonique par page ====
   Chaque page (?p=...) reçoit son propre titre et sa propre description côté serveur,
   pour que Google les voie directement. SITE_URL peut être défini dans .env. */
$SITE_URL = rtrim(orzeta_env('SITE_URL') ?: 'https://orzeta.com', '/');
$SEO_PAGES = [
    ''         => ["Orzeta — Huile d'Olive Vierge Extra de Tunisie", "Orzeta, huile d'olive vierge extra née en Tunisie, sous le soleil méditerranéen. Une origine, trois expressions — Sun, Grove et Organic — extraites à froid."],
    'produits' => ["Nos huiles d'olive vierge extra — Orzeta", "Une origine, trois expressions : Sun, Grove et Organic. Huiles d'olive vierge extra tunisiennes, extraction à froid, du format 250 mL au 1 L."],
    'sun'      => ["Sun Signature — Huile d'olive vierge extra | Orzeta", "Sun Signature, l'essentiel du quotidien : une huile d'olive vierge extra douce et équilibrée, aux notes fruitées et florales. Extraction à froid."],
    'grove'    => ["Grove Signature — Huile d'olive vierge extra | Orzeta", "Grove Signature, l'essentiel à table : une huile d'olive vierge extra plus intense, au profil structuré et à l'amertume délicate. Extraction à froid."],
    'organic'  => ["Organic Signature — Huile d'olive vierge extra bio | Orzeta", "Organic Signature, l'essentiel bio : huile d'olive vierge extra certifiée biologique, pure et équilibrée. Acidité ≤ 0,3 %, extraction à froid."],
    'recettes' => ["Recettes à l'huile d'olive — Orzeta", "Recettes méditerranéennes et tunisiennes à l'huile d'olive vierge extra Orzeta : salades, poissons, viandes et entrées, pas à pas."],
    'histoire' => ["Notre histoire — Orzeta, huile d'olive de Tunisie", "L'histoire d'Orzeta : une huile d'olive née au cœur de la Tunisie, portée par un savoir-faire oléicole transmis à travers les générations."],
    'contact'  => ["Contact — Orzeta", "Contactez Orzeta : questions sur nos huiles d'olive, demandes de partenariat pour distributeurs, restaurants et épiceries fines."],
];
$SEO_RECETTES = [
    'slata-tounsia' => 'Slata Tounsia', 'daurade' => 'Daurade grillée à la sfaxienne',
    'fenouil-orange' => 'Salade de fenouil, orange et olives', 'poulpe' => 'Poulpe grillé, citron et persil',
    'bruschetta' => 'Bruschetta tomate & basilic', 'agneau' => "Gigot d'agneau rôti aux herbes",
    'caprese' => "Caprese à l'huile d'olive", 'houmous' => "Houmous crémeux à l'huile d'olive",
    'tartare-saumon' => "Tartare de saumon, citron et huile d'olive",
];
$seoPage = isset($_GET['p']) && isset($SEO_PAGES[$_GET['p']]) ? $_GET['p'] : '';
list($SEO_TITLE, $SEO_DESC) = $SEO_PAGES[$seoPage];
$SEO_CANONICAL = $SITE_URL . '/' . ($seoPage !== '' ? '?p=' . $seoPage : '');
if ($seoPage === 'recettes' && isset($_GET['r']) && isset($SEO_RECETTES[$_GET['r']])) {
    $SEO_TITLE = $SEO_RECETTES[$_GET['r']] . " — Recette à l'huile d'olive | Orzeta";
    $SEO_DESC  = 'Recette ' . $SEO_RECETTES[$_GET['r']] . " à l'huile d'olive vierge extra Orzeta : ingrédients et préparation pas à pas.";
    $SEO_CANONICAL .= '&r=' . $_GET['r'];
}
function seo($v) { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/><title><?= seo($SEO_TITLE) ?></title><meta content="<?= seo($SEO_DESC) ?>" name="description"/><meta content="index, follow" name="robots"/><link href="<?= seo($SEO_CANONICAL) ?>" rel="canonical"/><meta content="<?= seo($SEO_TITLE) ?>" property="og:title"/><meta content="<?= seo($SEO_DESC) ?>" property="og:description"/><meta content="<?= seo($SEO_CANONICAL) ?>" property="og:url"/><meta content="Orzeta" property="og:site_name"/><meta content="fr_FR" property="og:locale"/><meta content="website" property="og:type"/><meta content="<?= seo($SITE_URL) ?>/assets/images/hero-bg.png" property="og:image"/><meta content="summary_large_image" name="twitter:card"/><link href="assets/images/logo-orzeta.png" rel="icon" type="image/png"/><link href="https://fonts.googleapis.com" rel="preconnect"/><link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/><link href="https://fonts.googleapis.com/css2?family=Literata:ital,opsz,wght@0,7..72,400..700;1,7..72,400..700&amp;family=Nunito+Sans:ital,opsz,wght@0,6..12,300..800;1,6..12,300..800&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;icon_names=add,agriculture,arrow_back,arrow_forward,call,chat,chevron_left,chevron_right,close,eco,error,expand_more,favorite,group,history_edu,home,lightbulb,local_fire_department,local_shipping,location_on,mail,menu,more_horiz,nutrition,open_in_new,person,redeem,remove,restaurant,restaurant_menu,room_service,schedule,send,signal_cellular_alt,storefront,verified,water_drop,workspace_premium,zoom_in&amp;display=block" rel="stylesheet"/><style>@layer base{html,body{margin:0;padding:0;}body{overscroll-behavior:none;}main>:first-child{margin-top:0!important;}main>:last-child{margin-bottom:0!important;}}::-webkit-scrollbar{display:none;}
html{scroll-behavior:smooth;}
/* Reveal au scroll */
.reveal{opacity:0;transform:scale(.96);transition:opacity .9s cubic-bezier(.22,.61,.36,1), transform .9s cubic-bezier(.22,.61,.36,1);}
.reveal.active{opacity:1;transform:scale(1);}
/* Zoom doux sur les images des cartes au survol */
.rounded-2xl img, .rounded-xl img{transition:transform .6s cubic-bezier(.22,.61,.36,1);}
.rounded-2xl:hover img, .rounded-xl:hover img{transform:scale(1.07);}
/* Slider Expertise & Fabrication : effet Ken Burns + entrée animée */
@keyframes expertiseEnter{0%{opacity:0;transform:translateY(30px) scale(.96);}100%{opacity:1;transform:translateY(0) scale(1);}}
.expertise-enter{animation:expertiseEnter .9s cubic-bezier(.22,.61,.36,1) both;}
@keyframes expertiseFadeUp{0%{opacity:0;transform:translateY(16px);}100%{opacity:1;transform:translateY(0);}}
.expertise-fade-up{animation:expertiseFadeUp .8s cubic-bezier(.22,.61,.36,1) both;}
/* Animations d'ouverture des pages produit (SUN / Grove / Organic) et de changement de contenance */
@keyframes imgSwapPop{0%{opacity:.35;transform:scale(.92);}100%{opacity:1;transform:scale(1);}}
.img-swap-pop{animation:imgSwapPop .4s cubic-bezier(.22,.61,.36,1);}
/* Jauges du profil de dégustation */
.taste-track{position:relative;height:6px;border-radius:9999px;background:var(--taste-track-bg,#e4e0d8);overflow:hidden;}
.taste-fill{position:absolute;top:0;left:0;height:100%;border-radius:9999px;background:#705c30;transform-origin:left;transform:scaleX(0);transition:transform 1s cubic-bezier(.22,.61,.36,1);}
.taste-fill.active{transform:scaleX(1);}
/* Lightbox luxury zoom sur les photos produit */
#image-lightbox{background:rgba(26,22,15,0);backdrop-filter:blur(0px);transition:background .45s cubic-bezier(.22,.61,.36,1),backdrop-filter .45s cubic-bezier(.22,.61,.36,1);}
#image-lightbox.lightbox-active{background:rgba(26,22,15,.86);backdrop-filter:blur(6px);}
#image-lightbox #lightbox-img{opacity:0;transform:scale(.85);transition:opacity .5s cubic-bezier(.22,.61,.36,1),transform .55s cubic-bezier(.22,.61,.36,1);}
#image-lightbox.lightbox-active #lightbox-img{opacity:1;transform:scale(1);}
#image-lightbox #lightbox-close{opacity:0;transform:translateY(-10px);transition:opacity .4s cubic-bezier(.22,.61,.36,1) .15s,transform .4s cubic-bezier(.22,.61,.36,1) .15s;}
#image-lightbox.lightbox-active #lightbox-close{opacity:1;transform:translateY(0);}
/* Loupe de zoom (filtre magnifier) au survol des photos produit */
.img-magnifier-lens{position:absolute;top:0;left:0;width:170px;height:170px;border-radius:50%;border:3px solid rgba(255,255,255,.95);box-shadow:0 10px 30px rgba(26,22,15,.35),inset 0 0 0 1px rgba(112,92,48,.2);background-color:#faf6f0;background-repeat:no-repeat;pointer-events:none;opacity:0;transform:scale(.85);transition:opacity .2s ease,transform .2s ease;z-index:20;}
.img-magnifier-lens.active{opacity:1;transform:scale(1);}
@media (hover:none){.img-magnifier-lens{display:none !important;}}
/* Flottement des halos décoratifs du hero */
@keyframes floaty{0%,100%{transform:translate(0,0);}50%{transform:translate(0,-18px);}}
/* Flottement doux et luxueux pour les bouteilles produit */
@keyframes bottleFloat{0%,100%{transform:translateY(0);}50%{transform:translateY(-10px);}}
.bottle-float{animation:bottleFloat 5s ease-in-out infinite;}
/* Zoom cinématographique lent pour les bannières plein écran de la page Histoire */
@keyframes kenBurnsSlow{0%{transform:scale(1);}100%{transform:scale(1.08);}}
.ken-burns-slow{animation:kenBurnsSlow 18s ease-in-out infinite alternate;}
.float-blob{animation:floaty 7s ease-in-out infinite;}
.float-blob-slow{animation:floaty 10s ease-in-out infinite;animation-delay:-3s;}
/* Apparition en fondu du hero au chargement */
@keyframes fadeInUp{from{opacity:0;transform:translateY(20px);}to{opacity:1;transform:translateY(0);}}
.hero-in{opacity:0;animation:fadeInUp .8s ease-out forwards;}
.hero-in-1{animation-delay:.05s;}
.hero-in-2{animation-delay:.2s;}
.hero-in-3{animation-delay:.35s;}
.hero-in-4{animation-delay:.5s;}
/* Header qui se resserre légèrement au scroll */
header{transition:box-shadow .3s ease, background-color .4s ease, backdrop-filter .4s ease;}
header.scrolled{box-shadow:0 4px 24px rgba(46,50,48,0.10);}
/* Header transparent en pleine page sur le hero, redevient blanc/opaque au scroll */
header.hdr-transparent{background-color:transparent!important;backdrop-filter:none!important;-webkit-backdrop-filter:none!important;box-shadow:none!important;}
header.hdr-transparent::before{content:'';position:absolute;inset:0;z-index:-1;background:linear-gradient(180deg,rgba(16,28,20,.42) 0%,rgba(16,28,20,.18) 60%,rgba(16,28,20,0) 100%);pointer-events:none;}
header.hdr-transparent .hdr-link{color:#ffffff;text-shadow:0 1px 6px rgba(0,0,0,.35);}
header.hdr-transparent .hdr-link:hover{color:#ffffff;opacity:.8;}
/* Diapositives "Nos Recettes" / "Notre Histoire" du carrousel d'accueil : menu 100% transparent (le contraste vient désormais directement de la photo) */
header.hdr-transparent #sidebar-toggle-btn:hover{background-color:rgba(255,255,255,.15);}
.hdr-link{transition:color .35s ease, opacity .35s ease, background-color .25s ease;}
/* Boutons avec un léger rebond au survol */
a.inline-flex{transition:transform .25s ease, background-color .25s ease, color .25s ease;}
a.inline-flex:hover{transform:translateY(-2px);}
/* Reveal en cascade pour les grilles de cartes */
.reveal-item{opacity:0;transform:translateY(26px) scale(.97);transition:opacity .6s cubic-bezier(.22,.61,.36,1), transform .6s cubic-bezier(.22,.61,.36,1);}
.reveal-item.active{opacity:1;transform:translateY(0) scale(1);}
/* Lignes du comparatif de gammes : apparition en cascade + léger surlignage au survol */
.compare-row{opacity:0;transform:translateX(-12px);transition:opacity .5s cubic-bezier(.22,.61,.36,1), transform .5s cubic-bezier(.22,.61,.36,1), background-color .2s ease;border-radius:0.6rem;margin:0 -0.4rem;padding:0.3rem 0.4rem;}
.compare-row.active{opacity:1;transform:translateX(0);}
.compare-card:hover .compare-row:hover{background-color:var(--compare-row-hover,#f5f1ea);}
/* Effet brillance qui balaye les cartes produit au survol */
.shine-card{position:relative;}
.shine-card::after{content:'';position:absolute;top:0;left:-75%;width:40%;height:100%;background:linear-gradient(115deg, transparent, rgba(255,255,255,.55), transparent);transform:skewX(-20deg);transition:left .8s ease;pointer-events:none;z-index:5;}
.shine-card:hover::after{left:125%;}
/* Contrôle de saisie du formulaire de contact */
.champ-contact.champ-erreur{box-shadow:0 0 0 2px #b83230 !important;background-color:#fff7f6 !important;}
.champ-contact.champ-ok{box-shadow:0 0 0 1.5px rgba(74,124,89,.55);}
@keyframes champSecousse{0%,100%{transform:translateX(0)}20%,60%{transform:translateX(-6px)}40%,80%{transform:translateX(6px)}}
.champ-secousse{animation:champSecousse .4s ease;}
#contact-type.groupe-erreur .type-contact-chip > span{box-shadow:0 0 0 1.5px #b83230;}
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;}
/* Accordéons "Déclaration nutritionnelle" / "Conditionnements disponibles" des pages produit */
.accordion-panel{max-height:600px;opacity:1;overflow:hidden;transition:max-height .5s cubic-bezier(.22,.61,.36,1), opacity .35s ease;}
.accordion-panel.collapsed{max-height:0;opacity:0;}
/* Sélecteur de contenance sous forme de bouteilles (vide/pleine) dans "Conditionnements disponibles" */
.size-icon-btn{color:#c4c8bc;opacity:.7;transition:color .35s ease, opacity .35s ease, transform .25s ease;}
.size-icon-btn:hover{opacity:1;transform:translateY(-2px);}
.size-icon-btn.active{color:#4a7c59;opacity:1;}
.size-icon-btn .bottle-liquid{transform:scaleY(.08);transform-origin:bottom;transition:transform .6s cubic-bezier(.22,.61,.36,1);}
.size-icon-btn.active .bottle-liquid{transform:scaleY(1);}
/* Cartes cliquables (produits, recettes) : curseur main garanti */
button[onclick]{cursor:pointer;}
/* Halo doré sur le logo du header : actif seulement sur la page Nos Produits */
#header-logo-img.logo-glow{filter:drop-shadow(0 1px 3px rgba(0,0,0,.22)) drop-shadow(0 0 14px rgba(196,166,106,.55));}
/* Empilement : la barre de filtres doit toujours passer au-dessus de la grille */
.recettes-barre-filtres{position:relative;z-index:40;}
#recettes-grille{position:relative;z-index:0;}
/* Menus déroulants des filtres de recettes : ouverture fluide, toujours au-dessus des cartes */
.filtre-panel{position:absolute;left:50%;top:100%;margin-top:.35rem;z-index:60;opacity:0;visibility:hidden;transform:translate(-50%,-10px) scale(.97);transform-origin:top center;transition:opacity .28s ease, transform .28s cubic-bezier(.22,.61,.36,1), visibility .28s;}
.filtre-panel.ouvert{opacity:1;visibility:visible;transform:translate(-50%,0) scale(1);}
.filtre-groupe .material-symbols-outlined{will-change:transform;}
/* Filtrage des cartes recettes : disparition/apparition en fondu */
.recette-card{transition:opacity .35s ease, transform .4s cubic-bezier(.22,.61,.36,1), box-shadow .5s ease;}
.recette-card.carte-masquee{opacity:0;transform:scale(.95);pointer-events:none;}
/* Pulsation douce sur le bouton principal du hero */
@keyframes ctaPulse{0%,100%{box-shadow:0 0 0 0 rgba(74,124,89,.45);}50%{box-shadow:0 0 0 12px rgba(74,124,89,0);}}
.cta-pulse{animation:ctaPulse 2.4s ease-out infinite;}
/* Micro-interaction : léger "pop" au survol/clic sur les boutons d'action */
.btn-pop{transition:transform .25s cubic-bezier(.34,1.56,.64,1), background-color .25s ease, color .25s ease, box-shadow .25s ease;}
.btn-pop:hover{transform:translateY(-2px) scale(1.03);}
.btn-pop:active{transform:translateY(0) scale(.96);}
/* Carrousel hero plein écran (3 visuels), inspiré de carapelli.fr */
#hero-media{position:absolute;inset:0;}
.hero-slide{position:absolute;inset:0;background-size:cover;background-position:center;opacity:0;transition:opacity 1s ease;}
.hero-slide.active{opacity:1;}
.hero-slide-content{display:none;}
.hero-slide-content.active{display:block;animation:heroContentIn .7s cubic-bezier(.22,.61,.36,1);}
@keyframes heroContentIn{from{opacity:0;transform:translateY(16px);}to{opacity:1;transform:translateY(0);}}
.hero-arrow{position:absolute;top:50%;transform:translateY(-50%);width:48px;height:48px;border-radius:9999px;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.18);color:#fff;backdrop-filter:blur(4px);transition:background-color .25s ease,transform .25s ease;z-index:20;}
.hero-arrow:hover{background:rgba(255,255,255,.32);transform:translateY(-50%) scale(1.05);}
.hero-arrow-left{left:1.25rem;}
.hero-arrow-right{right:1.25rem;}
@media (min-width:1024px){.hero-arrow-left{left:2.5rem;}.hero-arrow-right{right:2.5rem;}}
.hero-dots{position:absolute;left:0;right:0;bottom:1.75rem;display:flex;align-items:center;justify-content:center;gap:0.6rem;z-index:20;}
.hero-dot{width:9px;height:9px;border-radius:9999px;background:rgba(255,255,255,.5);transition:background-color .25s ease,transform .25s ease;}
.hero-dot.active{background:#ffffff;transform:scale(1.25);}
/* Petit rebond continu sur l'icône du badge hero */
@keyframes softBounce{0%,100%{transform:translateY(0);}50%{transform:translateY(-3px);}}
.badge-bounce{animation:softBounce 2s ease-in-out infinite;}
/* Mobile : centrage des photos produit (page Nos Produits + fiches SUN / Grove / Organic) */
@media (max-width: 767px){
  #detail-produits button > div:first-child > img{width:100% !important;height:88% !important;object-fit:contain;object-position:center bottom;margin-left:auto;margin-right:auto;}
  #sun-detail-img,#grove-detail-img,#organic-detail-img{width:100% !important;max-width:100%;object-fit:contain;object-position:center;}
  #gamme img{object-position:center;}
}

/* ==== Version arabe (droite à gauche) ==== */
html[lang="ar"] body, html[lang="ar"] .font-body, html[lang="ar"] .font-label { font-family: 'Noto Sans Arabic', 'Nunito Sans', sans-serif; }
html[lang="ar"] .font-headline, html[lang="ar"] .font-display, html[lang="ar"] h1, html[lang="ar"] h2, html[lang="ar"] h3 { font-family: 'Amiri', 'Literata', serif; }
html[lang="ar"] .material-symbols-outlined { font-family: 'Material Symbols Outlined' !important; }
html[lang="ar"] [class*="tracking-"] { letter-spacing: 0 !important; }
html[lang="ar"] .italic, html[lang="ar"] em { font-style: normal; }
html[lang="ar"] p, html[lang="ar"] li { line-height: 1.9; }
.lang-btn[lang="ar"] { font-family: 'Noto Sans Arabic', 'Nunito Sans', sans-serif; font-size: 1.05em; }
html[dir="rtl"] .text-left { text-align: right; }
html[dir="rtl"] .text-right { text-align: left; }
html[dir="rtl"] .pl-4 { padding-left: 0; padding-right: 1rem; }
html[dir="rtl"] .pl-7 { padding-left: 0; padding-right: 1.75rem; }
html[dir="rtl"] .pr-2 { padding-right: 0; padding-left: .5rem; }
html[dir="rtl"] .ml-0\.5 { margin-left: 0; margin-right: .125rem; }
html[dir="rtl"] .ml-auto { margin-left: 0; margin-right: auto; }
html[dir="rtl"] .border-l { border-left-width: 0; border-right-width: 1px; }
html[dir="rtl"] .first\:border-l-0:first-child { border-right-width: 0; }
html[dir="rtl"] [class*="space-x-"] > :not([hidden]) ~ :not([hidden]) { --tw-space-x-reverse: 1; }
html[dir="rtl"] .group:hover .group-hover\:translate-x-1 { --tw-translate-x: -0.25rem; }
html[dir="rtl"] .icone-rtl { transform: scaleX(-1); }
html[dir="rtl"] .bg-gradient-to-r { background-image: linear-gradient(to left, var(--tw-gradient-stops)); }
html[dir="rtl"] .group:hover .icone-rtl.group-hover\:translate-x-1 { transform: scaleX(-1) translateX(0.25rem); }
html[dir="rtl"] input[type="email"], html[dir="rtl"] input[type="tel"] { direction: ltr; text-align: right; }
html[dir="rtl"] a[href^="https://wa.me"], html[dir="rtl"] a[href^="tel:"] { direction: ltr; }

/* Profil aromatique (gouttes) */
.profil-aromatique{margin-top:1.1rem;padding-top:1rem;border-top:1px solid rgba(112,92,48,.18);}
.profil-echelle{margin-top:.7rem;display:flex;align-items:center;gap:.9rem;}
.profil-borne{font-family:'Nunito Sans',sans-serif;font-size:.68rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#4a4e4a;}
html[dir="rtl"] .profil-borne{letter-spacing:0;font-size:.8rem;}
.profil-gouttes{display:inline-flex;gap:.3rem;}
.profil-gouttes svg{width:22px;height:22px;display:block;transition:transform .3s ease;}
.profil-aromatique:hover .profil-gouttes svg{transform:translateY(-2px);}

/* Filtres recettes : compteur par option, options sans résultat grisées */
.filtre-option{display:flex;align-items:center;justify-content:space-between;gap:.75rem;}
.filtre-compte{font-size:.7rem;font-weight:700;min-width:1.4rem;padding:.05rem .4rem;border-radius:9999px;background:rgba(74,124,89,.1);color:#4a7c59;text-align:center;}
.filtre-option.filtre-vide{opacity:.4;cursor:not-allowed;}
.filtre-option.filtre-vide:hover{background:transparent!important;}
html[dir="rtl"] .filtre-option{text-align:right;}

/* Choix de la bouteille (Marasca / Dorica) */
.bouteille-onglets{display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin-bottom:1rem;}
.bouteille-onglet{display:flex;align-items:center;gap:.7rem;padding:.7rem .8rem;border-radius:1rem;background:#faf6f0;color:#4a4e4a;box-shadow:inset 0 0 0 1px rgba(112,92,48,.18);text-align:start;transition:box-shadow .25s ease,color .25s ease,background .25s ease;}
.bouteille-onglet:hover{box-shadow:inset 0 0 0 1px rgba(74,124,89,.55);}
.bouteille-onglet.actif{background:#fff;color:#2e3230;box-shadow:inset 0 0 0 2px #4a7c59,0 6px 16px -10px rgba(46,50,48,.35);}
.bouteille-onglet svg{width:17px;height:44px;flex-shrink:0;color:#2e3230;}
.bouteille-texte{display:flex;flex-direction:column;min-width:0;}
.bouteille-nom{font-family:'Nunito Sans',sans-serif;font-size:.78rem;font-weight:800;letter-spacing:.16em;text-transform:uppercase;}
.bouteille-tailles{margin-top:.15rem;font-size:.68rem;color:#4a4e4a;opacity:.85;}
html[dir="rtl"] .bouteille-tailles{text-align:right;}
@media (max-width:380px){.bouteille-tailles{font-size:.6rem;}}

/* Devise sur la bannière de Notre Histoire */
.histoire-devise{position:absolute;z-index:10;top:1.75rem;inset-inline-end:max(1.5rem,calc((100vw - 80rem) / 2 + 3rem));text-align:end;pointer-events:none;}
.devise-trait{display:block;width:3rem;height:2px;border-radius:2px;margin-inline-start:auto;margin-bottom:1rem;background:#F2D68E;}
.devise-l1,.devise-l2{font-family:'Literata',serif;font-style:italic;line-height:1.15;white-space:nowrap;text-shadow:0 1px 3px rgba(0,0,0,.5),0 6px 30px rgba(0,0,0,.6);}
.devise-l1{color:#fff;font-weight:400;font-size:1.35rem;}
.devise-l2{color:#F2D68E;font-weight:600;font-size:1.75rem;}
html[dir="rtl"] .devise-l1,html[dir="rtl"] .devise-l2{font-family:'Amiri',serif;font-style:normal;line-height:1.45;}
@media (min-width:768px){.histoire-devise{top:2.5rem;}.devise-l1{font-size:1.75rem;}.devise-l2{font-size:2.35rem;}}
/* Grand écran : en bas à droite, face au titre, sur la partie sombre de la photo */
@media (min-width:1024px){.histoire-devise{top:auto;bottom:3.5rem;}.devise-l1{font-size:2.1rem;}.devise-l2{font-size:2.9rem;}}

html[dir="rtl"] .rang-fr{display:none;}

.carte-produit-accueil:focus-visible{outline:2px solid #4a7c59;outline-offset:6px;border-radius:1.75rem;}
.carte-produit-accueil:hover h3{color:#4a7c59;}
.carte-produit-accueil h3{transition:color .3s ease;}

/* Header compact au scroll : moins haut, logo plus petit */
#site-header > div{transition:height .4s ease;}
#header-logo-img{transition:height .4s ease, filter .4s ease;}
header.compact > div{height:122px;}
header.compact #header-logo-img{height:82px;}
/* Hero : le texte s'efface doucement quand on descend */
.hero-texte{will-change:opacity,transform;}

/* ==== Hero vidéo ==== */
.hero-video-section{background:#1c1a14;}
.hero-video-cadre{position:relative;width:100%;height:100vh;height:100svh;min-height:560px;max-height:1000px;overflow:hidden;background:#1c1a14;}
.hero-video{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:0;transition:opacity .6s ease;}
.hero-video.lecture{opacity:1;}
.hero-voile{position:absolute;inset:0;pointer-events:none;background:
  linear-gradient(180deg,rgba(14,12,8,.72) 0%,rgba(14,12,8,.30) 22%,rgba(14,12,8,0) 42%),
  linear-gradient(0deg,rgba(14,12,8,.78) 0%,rgba(14,12,8,.35) 32%,rgba(14,12,8,0) 60%),
  linear-gradient(90deg,rgba(14,12,8,.45) 0%,rgba(14,12,8,0) 55%);}
html[dir="rtl"] .hero-voile{background:
  linear-gradient(180deg,rgba(14,12,8,.72) 0%,rgba(14,12,8,.30) 22%,rgba(14,12,8,0) 42%),
  linear-gradient(0deg,rgba(14,12,8,.78) 0%,rgba(14,12,8,.35) 32%,rgba(14,12,8,0) 60%),
  linear-gradient(270deg,rgba(14,12,8,.45) 0%,rgba(14,12,8,0) 55%);}
.hero-contenu{position:absolute;inset:0;display:flex;align-items:flex-end;padding:0 1.5rem 6.5rem;}
@media (min-width:768px){.hero-contenu{padding:0 3rem 7.5rem;}}
@media (min-width:1024px){.hero-contenu{align-items:center;padding:9rem 3rem 0;}}
.hero-texte{width:100%;max-width:80rem;margin:0 auto;}
.hero-texte > *{max-width:40rem;}
.hero-surtitre{font-family:'Nunito Sans',sans-serif;font-size:.75rem;font-weight:700;letter-spacing:.28em;text-transform:uppercase;color:#e9d6a4;margin-bottom:1.1rem;text-shadow:0 1px 8px rgba(0,0,0,.4);}
.hero-titre{color:#fff;font-weight:700;font-size:2.6rem;line-height:1.05;letter-spacing:-.015em;text-shadow:0 2px 24px rgba(0,0,0,.35);}
@media (min-width:640px){.hero-titre{font-size:3.6rem;}}
@media (min-width:1024px){.hero-titre{font-size:4.6rem;}}
.hero-sous-titre{margin-top:1.25rem;color:rgba(255,255,255,.88);font-size:1rem;line-height:1.65;max-width:32rem!important;text-shadow:0 1px 10px rgba(0,0,0,.4);}
@media (min-width:640px){.hero-sous-titre{font-size:1.1rem;}}
.hero-actions{margin-top:2rem;display:flex;flex-wrap:wrap;align-items:center;gap:.75rem;}

.hero-btn{display:inline-flex;align-items:center;justify-content:center;padding:.95rem 1.6rem;border-radius:9999px;font-family:'Nunito Sans',sans-serif;font-weight:700;font-size:.78rem;letter-spacing:.14em;text-transform:uppercase;}
.hero-btn-plein{background:#4a7c59;color:#fff;box-shadow:0 10px 30px -10px rgba(0,0,0,.5);}
.hero-btn-plein:hover{background:#3f6c4d;}
.hero-btn-contour{color:#fff;border:1px solid rgba(255,255,255,.65);background:rgba(255,255,255,.06);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);}
.hero-btn-contour:hover{background:rgba(255,255,255,.16);}
/* Mobile : un bouton principal compact + un lien élégant, sur une seule ligne */
@media (max-width:767px){
  .hero-contenu{padding-bottom:calc(3.25rem + env(safe-area-inset-bottom, 0px));}
  .hero-sous-titre{font-size:.95rem;line-height:1.6;}
  .hero-actions{margin-top:1.5rem;flex-wrap:wrap;gap:.6rem 1.3rem;}
  .hero-btn{padding:.85rem 1.25rem;font-size:.7rem;letter-spacing:.1em;white-space:nowrap;}
  .hero-btn-contour{position:relative;padding:.85rem 0;border:0;border-radius:0;background:none;backdrop-filter:none;-webkit-backdrop-filter:none;}
  .hero-btn-contour::before{content:'';position:absolute;left:0;right:1.15rem;bottom:.55rem;height:1px;background:rgba(233,214,164,.85);transform-origin:left;transition:transform .35s ease;}
  .hero-btn-contour::after{content:'\2192';margin-left:.5rem;font-size:.95rem;letter-spacing:0;color:#e9d6a4;transition:transform .3s ease;}
  .hero-btn-contour:hover{background:none;}
  .hero-btn-contour:active::after{transform:translateX(3px);}
  html[dir="rtl"] .hero-btn-contour::before{left:1.15rem;right:0;}
  html[dir="rtl"] .hero-btn-contour::after{content:'\2190';margin-left:0;margin-right:.5rem;}
}
html[dir="rtl"] @media (min-width:1024px){.hero-defiler{position:absolute;left:50%;bottom:1.6rem;transform:translateX(-50%);width:24px;height:38px;border-radius:9999px;border:1.5px solid rgba(255,255,255,.6);display:none;}
.hero-defiler span{position:absolute;left:50%;top:8px;width:3px;height:8px;margin-left:-1.5px;border-radius:3px;background:#fff;animation:heroDefiler 1.8s ease-in-out infinite;}
@keyframes heroDefiler{0%{opacity:0;transform:translateY(0);}30%{opacity:1;}100%{opacity:0;transform:translateY(12px);}}
@media (min-width:1024px){.hero-defiler{display:block;}}
@media (prefers-reduced-motion:reduce){.hero-defiler span{animation:none;}}
</style><link href="orzeta.css?v=1" rel="stylesheet"/><script type="application/ld+json"><?= json_encode([
  '@context' => 'https://schema.org',
  '@graph' => [
    ['@type' => 'Organization', '@id' => $SITE_URL . '/#organization', 'name' => 'Orzeta', 'url' => $SITE_URL . '/',
     'logo' => $SITE_URL . '/assets/images/logo-orzeta.png',
     'description' => "Marque tunisienne d'huiles d'olive vierge extra : Sun, Grove et Organic.",
     'address' => ['@type' => 'PostalAddress', 'streetAddress' => 'Route Saltania, km 4.5', 'addressLocality' => 'Sfax', 'addressCountry' => 'TN'],
     'contactPoint' => ['@type' => 'ContactPoint', 'contactType' => 'customer service', 'email' => 'contact@orzeta.com', 'availableLanguage' => ['French', 'English']]],
    ['@type' => 'WebSite', '@id' => $SITE_URL . '/#website', 'name' => 'Orzeta', 'url' => $SITE_URL . '/', 'inLanguage' => 'fr', 'publisher' => ['@id' => $SITE_URL . '/#organization']],
  ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script></head><body class="bg-surface font-body text-on-surface antialiased"><a class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-[999] focus:px-4 focus:py-2.5 focus:rounded-xl focus:bg-primary focus:text-on-primary focus:text-sm focus:font-bold focus:shadow-lg" href="#main-content">Aller au contenu principal</a><header class="fixed top-0 w-full z-50 bg-surface/90 backdrop-blur-xl shadow-[0_1px_8px_rgba(46,50,48,0.05)]" id="site-header"><div class="h-36 max-w-7xl mx-auto px-6 lg:px-12 flex items-center justify-between gap-6"><a class="flex items-center gap-3 shrink-0 focus:outline-none" data-path="accueil" href="#" onclick="showHomePage(); return false;"><img alt="Orzeta - Huile d'Olive Vierge Extra" class="h-24 w-auto transition-all duration-500" id="header-logo-img" src="assets/images/logo-orzeta.png" decoding="async" style="filter:drop-shadow(0 1px 3px rgba(0,0,0,.22));"/><span class="hidden xl:inline-block text-[11px] uppercase tracking-widest text-tertiary font-label font-bold px-2 py-0.5 rounded-full bg-secondary-fixed">Huile d'Olive</span></a><nav class="hidden lg:flex items-center gap-5 xl:gap-7" data-active-classes="text-primary font-bold"><a class="hdr-link text-xs xl:text-sm font-medium text-on-surface-variant hover:text-on-surface transition-colors whitespace-nowrap" data-path="gamme-de-produits" href="#" onclick="showDetailPage('produits'); return false;">Nos Produits</a><a class="hdr-link text-xs xl:text-sm font-medium text-on-surface-variant hover:text-on-surface transition-colors whitespace-nowrap" data-path="nos-recettes" href="#" onclick="showDetailPage('recettes'); return false;">Nos Recettes</a><a class="hdr-link text-xs xl:text-sm font-medium text-on-surface-variant hover:text-on-surface transition-colors whitespace-nowrap" data-path="notre-histoire" href="#" onclick="showDetailPage('histoire'); return false;">Notre Histoire</a></nav><div class="flex items-center gap-3 shrink-0"><div class="hidden sm:flex items-center text-xs font-bold text-on-surface-variant px-2.5 py-1 rounded-full bg-surface-container"><button class="lang-btn text-primary hover:text-on-surface transition-colors focus:outline-none" data-lang="fr" onclick="setSiteLanguage('fr')" type="button">FR</button><span class="mx-1 text-outline-variant">|</span><button class="lang-btn hover:text-on-surface transition-colors focus:outline-none" data-lang="en" onclick="setSiteLanguage('en')" type="button">EN</button><span class="mx-1 text-outline-variant">|</span><button class="lang-btn hover:text-on-surface transition-colors focus:outline-none" data-lang="ar" lang="ar" onclick="setSiteLanguage('ar')" type="button">ع</button></div><a class="btn-pop hidden sm:inline-flex items-center justify-center px-4 py-2 text-xs font-bold rounded-xl bg-primary text-on-primary hover:bg-primary-container hover:text-on-primary-container shadow-[0_4px_20px_rgba(46,50,48,0.06)]" data-path="contact" href="#" onclick="showDetailPage('contact'); return false;">Contact</a><button aria-label="Ouvrir le menu" class="hdr-link flex p-2 text-on-surface-variant hover:text-on-surface hover:bg-surface-container-high rounded-full transition-colors focus:outline-none" id="sidebar-toggle-btn" onclick="openSidebar()" type="button"><span class="material-symbols-outlined text-[22px]">menu</span></button></div></div></header><div class="fixed inset-0 bg-[rgba(16,28,20,0.55)] backdrop-blur-[2px] z-[60] opacity-0 pointer-events-none transition-opacity duration-300" id="sidebar-overlay" onclick="closeSidebar()"></div><aside class="fixed top-0 right-0 h-full w-[80%] max-w-[320px] bg-surface z-[70] shadow-2xl transform translate-x-full transition-transform duration-300 ease-out flex flex-col" id="mobile-sidebar"><div class="flex items-center justify-between px-5 h-20 border-b border-surface-container-highest shrink-0"><img alt="Orzeta - Huile d'Olive Vierge Extra" class="h-20 w-auto" src="assets/images/logo-orzeta.png" decoding="async"/><button aria-label="Fermer le menu" class="p-2 text-on-surface-variant hover:text-on-surface hover:bg-surface-container-high rounded-full transition-colors focus:outline-none" onclick="closeSidebar()" type="button"><span class="material-symbols-outlined text-[22px]">close</span></button></div><nav class="flex-1 overflow-y-auto px-3 py-5 flex flex-col gap-1"><a class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold text-on-surface hover:bg-surface-container-low transition-colors" href="#" onclick="closeSidebar(); showHomePage(); return false;"><span class="material-symbols-outlined text-lg text-primary">home</span>Accueil</a><a class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold text-on-surface hover:bg-surface-container-low transition-colors" href="#" onclick="closeSidebar(); showDetailPage('produits'); return false;"><span class="material-symbols-outlined text-lg text-primary">nutrition</span>Nos Produits</a><a class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold text-on-surface hover:bg-surface-container-low transition-colors" href="#" onclick="closeSidebar(); showDetailPage('recettes'); return false;"><span class="material-symbols-outlined text-lg text-primary">restaurant_menu</span>Nos Recettes</a><a class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-bold text-on-surface hover:bg-surface-container-low transition-colors" href="#" onclick="closeSidebar(); showDetailPage('histoire'); return false;"><span class="material-symbols-outlined text-lg text-primary">history_edu</span>Notre Histoire</a></nav><div class="p-4 border-t border-surface-container-highest shrink-0 space-y-3"><div class="flex items-center justify-center text-xs font-bold text-on-surface-variant px-2.5 py-1.5 rounded-full bg-surface-container w-fit mx-auto"><button class="lang-btn text-primary hover:text-on-surface transition-colors focus:outline-none px-1" data-lang="fr" onclick="setSiteLanguage('fr')" type="button">FR</button><span class="mx-1 text-outline-variant">|</span><button class="lang-btn hover:text-on-surface transition-colors focus:outline-none px-1" data-lang="en" onclick="setSiteLanguage('en')" type="button">EN</button><span class="mx-1 text-outline-variant">|</span><button class="lang-btn hover:text-on-surface transition-colors focus:outline-none px-1" data-lang="ar" lang="ar" onclick="setSiteLanguage('ar')" type="button">ع</button></div><a class="flex items-center justify-center px-4 py-3 text-xs font-bold rounded-xl bg-primary text-on-primary hover:bg-primary-container hover:text-on-primary-container transition-all" href="#" onclick="closeSidebar(); showDetailPage('contact'); return false;">Contact</a></div></aside><main class="w-full pt-36 bg-surface min-h-[calc(100vh-20rem)]" id="main-content"><div class="flex flex-col w-full" id="home-page">
<!-- 1. HERO — vidéo plein écran (version ordinateur 16:9 / version mobile 9:16) -->
<section class="relative w-full overflow-hidden -mt-36 hero-video-section" id="hero-section">
<div class="hero-video-cadre" id="hero-video-cadre">
<video aria-hidden="true" autoplay class="hero-video" disablepictureinpicture id="hero-video" loop muted playsinline preload="auto"><source media="(max-width: 767px)" src="assets/videos/orzeta-video-mobile-720p.mp4" type="video/mp4"/><source src="assets/videos/orzeta-video-web-1080p.mp4" type="video/mp4"/></video>
<div class="hero-voile" aria-hidden="true"></div>
<div class="hero-contenu">
<div class="hero-texte">
<p class="hero-surtitre hero-in hero-in-1">Huile d'olive vierge extra · Tunisie</p>
<h1 class="font-headline hero-titre hero-in hero-in-2">Née sous le soleil<br/>méditerranéen.</h1>
<p class="font-body hero-sous-titre hero-in hero-in-3">ORZETA naît en Tunisie, là où le soleil, la terre et des générations d'oléiculteurs façonnent des huiles au caractère méditerranéen singulier.</p>
<div class="hero-actions hero-in hero-in-4">
<a class="btn-pop hero-btn hero-btn-plein" href="#" onclick="showDetailPage('produits'); return false;">Découvrir la collection</a>
<a class="btn-pop hero-btn hero-btn-contour" href="#" onclick="showDetailPage('histoire'); return false;">Notre Histoire</a>
</div>
</div>
</div>

<a aria-label="Faire défiler vers le contenu" class="hero-defiler" href="#story" onclick="var s=document.getElementById('story'); if(s){ s.scrollIntoView({behavior:'smooth'}); } return false;"><span></span></a>
</div>
</section>
<!-- 1bis. THE ORZETA STORY — bandeau de marque façon carapelli.fr, inspiré de DRAFT_CAT_ORZETA.docx -->
<section class="w-full py-20 md:py-24 bg-primary" id="story">
<div class="max-w-7xl mx-auto px-6 lg:px-12">
<div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-16 items-center">
<div class="lg:col-span-5">
<span class="text-xs font-bold uppercase tracking-wider font-label text-on-primary/70 reveal-item">L'Histoire Orzeta</span>
<h2 class="mt-3 font-headline text-5xl sm:text-6xl font-bold text-on-primary tracking-tight reveal-item" style="transition-delay:.1s">ORZETA</h2>
<p class="mt-3 font-headline text-xl sm:text-2xl italic text-on-primary/90 leading-snug reveal-item" style="transition-delay:.2s">Authentique par son origine.<br/>Précise par son art.<br/>Distinctive par sa nature.</p>
</div>
<div class="lg:col-span-7">
<p class="text-on-primary font-body text-base sm:text-lg leading-relaxed reveal-item" style="transition-delay:.15s">Née au cœur de la Tunisie, ORZETA puise son identité dans une terre d’oliviers et un savoir-faire transmis à travers les générations.</p>
<p class="mt-4 text-on-primary/90 font-body leading-relaxed reveal-item" style="transition-delay:.25s">Portée par un savoir-faire oléicole tunisien, la marque conjugue authenticité, exigence et innovation. Chaque huile est élaborée avec une attention particulière portée à la qualité et à la constance.</p>
<p class="mt-4 text-on-primary/90 font-body leading-relaxed reveal-item" style="transition-delay:.35s">ORZETA transforme ainsi l’héritage tunisien en une expérience contemporaine.</p>
<p class="mt-6 font-headline text-lg sm:text-xl italic text-on-primary reveal-item" style="transition-delay:.45s">Une signature tunisienne pensée pour rayonner au-delà des frontières.</p>
</div>
</div>
</div>
</section>
<script>
(function() {
  var items = document.querySelectorAll('#story .reveal-item');
  if (!items.length) { return; }
  var obs = new IntersectionObserver(function(entries) {
    entries.forEach(function(entry) {
      if (entry.isIntersecting) {
        entry.target.classList.add('active');
        obs.unobserve(entry.target);
      }
    });
  }, { threshold: 0.15 });
  items.forEach(function(el) { obs.observe(el); });
})();
</script>
<!-- 2. NOTRE GAMME DE PRODUITS -->
<section class="w-full py-24 md:py-28 bg-surface overflow-hidden" id="gamme">
<div class="max-w-7xl mx-auto px-6 lg:px-12">
<div class="text-center max-w-2xl mx-auto reveal-item">
<span class="text-xs font-label uppercase tracking-[0.25em] text-tertiary font-bold">La Collection</span>
<h2 class="mt-3 text-3xl sm:text-4xl lg:text-5xl font-headline font-bold text-on-surface">Une origine. Trois expressions.</h2>
<div class="w-12 h-px bg-tertiary/50 mx-auto my-6"></div>
<p class="text-on-surface-variant text-sm sm:text-base font-body leading-relaxed">
            D'une même terre baignée de soleil naissent trois expressions distinctes, qui révèlent chacune une facette de notre héritage oléicole.
          </p>
</div>
<div class="mt-16 grid grid-cols-1 md:grid-cols-3 gap-8 lg:gap-10">
<div class="group relative flex flex-col reveal-item carte-produit-accueil cursor-pointer" role="link" tabindex="0" aria-label="Sun Signature" onclick="if(!event.target.closest('a')){showDetailPage('sun');}" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();showDetailPage('sun');}" style="transition-delay:0.05s">
<div class="relative rounded-[1.75rem] overflow-hidden bg-gradient-to-b from-surface-container-low to-surface-container ring-1 ring-tertiary/15 shadow-sm group-hover:shadow-2xl transition-all duration-500 shine-card">

<div class="relative aspect-[3/4] overflow-hidden">
<div class="absolute inset-0 flex items-center justify-center pointer-events-none">
<div class="w-52 h-52 rounded-full bg-tertiary/15 blur-3xl transition-transform duration-700 group-hover:scale-125"></div>
</div>
<img alt="Sun Signature" class="relative z-10 w-full h-full object-contain p-8 bottle-float transition-transform duration-700 group-hover:scale-105" src="assets/images/sun-marasca-1l.webp" loading="lazy" decoding="async"/>
</div>
</div>
<div class="pt-7 text-center flex flex-col flex-1">
<span class="text-[10px] font-bold uppercase tracking-[0.25em] font-label text-tertiary">L'essentiel du quotidien</span>
<h3 class="mt-2 text-xl sm:text-2xl font-headline font-bold text-on-surface">Sun Signature</h3>
<div class="w-8 h-px bg-tertiary/40 mx-auto my-4"></div>
<p class="text-sm text-on-surface-variant font-body leading-relaxed flex-1 max-w-xs mx-auto">Une huile d'olive extra vierge douce et équilibrée, aux notes fruitées et florales. Polyvalente et expressive, elle apporte une touche méditerranéenne au quotidien.</p>
<div class="mt-5 flex flex-wrap items-center justify-center gap-1.5">
<span class="px-2.5 py-1 rounded-full bg-surface-container-low text-[10px] font-bold text-on-surface-variant font-label">250ml</span>
<span class="px-2.5 py-1 rounded-full bg-surface-container-low text-[10px] font-bold text-on-surface-variant font-label">500ml</span>
<span class="px-2.5 py-1 rounded-full bg-surface-container-low text-[10px] font-bold text-on-surface-variant font-label">750ml</span>
<span class="px-2.5 py-1 rounded-full bg-surface-container-low text-[10px] font-bold text-on-surface-variant font-label">1L</span>
</div>
<p class="mt-3 text-[10px] font-bold uppercase tracking-[0.18em] text-on-surface-variant/70 font-label">2 bouteilles : Marasca &amp; Dorica</p>
<a class="mt-6 inline-flex items-center justify-center gap-2 text-xs font-bold uppercase tracking-widest font-label text-primary hover:gap-3 transition-all" href="#" onclick="showDetailPage('sun'); return false;">Découvrir <span class="material-symbols-outlined text-base">arrow_forward</span></a>
</div>
</div>
<div class="group relative flex flex-col reveal-item carte-produit-accueil cursor-pointer" role="link" tabindex="0" aria-label="Grove Signature" onclick="if(!event.target.closest('a')){showDetailPage('grove');}" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();showDetailPage('grove');}" style="transition-delay:0.17s">
<div class="relative rounded-[1.75rem] overflow-hidden bg-gradient-to-b from-surface-container-low to-surface-container ring-1 ring-tertiary/15 shadow-sm group-hover:shadow-2xl transition-all duration-500 shine-card">

<div class="relative aspect-[3/4] overflow-hidden">
<div class="absolute inset-0 flex items-center justify-center pointer-events-none">
<div class="w-52 h-52 rounded-full bg-tertiary/15 blur-3xl transition-transform duration-700 group-hover:scale-125"></div>
</div>
<img alt="Grove Signature" class="relative z-10 w-full h-full object-contain p-8 bottle-float transition-transform duration-700 group-hover:scale-105" src="assets/images/grove-marasca-1l.webp" loading="lazy" decoding="async"/>
</div>
</div>
<div class="pt-7 text-center flex flex-col flex-1">
<span class="text-[10px] font-bold uppercase tracking-[0.25em] font-label text-tertiary">L'essentiel à table</span>
<h3 class="mt-2 text-xl sm:text-2xl font-headline font-bold text-on-surface">Grove Signature</h3>
<div class="w-8 h-px bg-tertiary/40 mx-auto my-4"></div>
<p class="text-sm text-on-surface-variant font-body leading-relaxed flex-1 max-w-xs mx-auto">Une huile d'olive extra vierge plus intense, au profil structuré et à l'amertume délicate. Elle enrichit les salades, les assaisonnements et les touches finales.</p>
<div class="mt-5 flex flex-wrap items-center justify-center gap-1.5">
<span class="px-2.5 py-1 rounded-full bg-surface-container-low text-[10px] font-bold text-on-surface-variant font-label">250ml</span>
<span class="px-2.5 py-1 rounded-full bg-surface-container-low text-[10px] font-bold text-on-surface-variant font-label">500ml</span>
<span class="px-2.5 py-1 rounded-full bg-surface-container-low text-[10px] font-bold text-on-surface-variant font-label">750ml</span>
<span class="px-2.5 py-1 rounded-full bg-surface-container-low text-[10px] font-bold text-on-surface-variant font-label">1L</span>
</div>
<p class="mt-3 text-[10px] font-bold uppercase tracking-[0.18em] text-on-surface-variant/70 font-label">2 bouteilles : Marasca &amp; Dorica</p>
<a class="mt-6 inline-flex items-center justify-center gap-2 text-xs font-bold uppercase tracking-widest font-label text-primary hover:gap-3 transition-all" href="#" onclick="showDetailPage('grove'); return false;">Découvrir <span class="material-symbols-outlined text-base">arrow_forward</span></a>
</div>
</div>
<div class="group relative flex flex-col reveal-item carte-produit-accueil cursor-pointer" role="link" tabindex="0" aria-label="Organic Signature" onclick="if(!event.target.closest('a')){showDetailPage('organic');}" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();showDetailPage('organic');}" style="transition-delay:0.29s">
<div class="relative rounded-[1.75rem] overflow-hidden bg-gradient-to-b from-surface-container-low to-surface-container ring-1 ring-tertiary/15 shadow-sm group-hover:shadow-2xl transition-all duration-500 shine-card">
<div class="relative aspect-[3/4] overflow-hidden">
<div class="absolute inset-0 flex items-center justify-center pointer-events-none">
<div class="w-52 h-52 rounded-full bg-primary/15 blur-3xl transition-transform duration-700 group-hover:scale-125"></div>
</div>
<img alt="Organic Signature" class="relative z-10 w-full h-full object-contain p-8 bottle-float transition-transform duration-700 group-hover:scale-105" src="assets/images/organic-marasca-1l.webp" loading="lazy" decoding="async"/>
</div>
</div>
<div class="pt-7 text-center flex flex-col flex-1">
<span class="text-[10px] font-bold uppercase tracking-[0.25em] font-label text-primary">L'essentiel bio</span>
<h3 class="mt-2 text-xl sm:text-2xl font-headline font-bold text-on-surface">Organic Signature</h3>
<div class="w-8 h-px bg-primary/40 mx-auto my-4"></div>
<p class="text-sm text-on-surface-variant font-body leading-relaxed flex-1 max-w-xs mx-auto">Une huile d'olive extra vierge certifiée biologique, pour ceux qui privilégient l'authenticité, la traçabilité et un approvisionnement responsable. Une expression pure et équilibrée.</p>
<div class="mt-5 flex flex-wrap items-center justify-center gap-1.5">
<span class="px-2.5 py-1 rounded-full bg-surface-container-low text-[10px] font-bold text-on-surface-variant font-label">250ml</span>
<span class="px-2.5 py-1 rounded-full bg-surface-container-low text-[10px] font-bold text-on-surface-variant font-label">500ml</span>
<span class="px-2.5 py-1 rounded-full bg-surface-container-low text-[10px] font-bold text-on-surface-variant font-label">750ml</span>
<span class="px-2.5 py-1 rounded-full bg-surface-container-low text-[10px] font-bold text-on-surface-variant font-label">1L</span>
</div>
<p class="mt-3 text-[10px] font-bold uppercase tracking-[0.18em] text-on-surface-variant/70 font-label">2 bouteilles : Marasca &amp; Dorica</p>
<a class="mt-6 inline-flex items-center justify-center gap-2 text-xs font-bold uppercase tracking-widest font-label text-primary hover:gap-3 transition-all" href="#" onclick="showDetailPage('organic'); return false;">Découvrir <span class="material-symbols-outlined text-base">arrow_forward</span></a>
</div>
</div>
</div>
<div class="mt-16 text-center reveal-item" style="transition-delay:.4s">
<a class="btn-pop inline-flex items-center justify-center gap-2 px-8 py-3.5 rounded-xl bg-primary text-on-primary font-bold text-xs uppercase tracking-widest hover:bg-primary-container hover:text-on-primary-container" href="#" onclick="showDetailPage('produits'); return false;">Voir toute la collection <span class="material-symbols-outlined text-sm">arrow_forward</span></a>
</div>
</div>
</section>
<!-- 2ter. NOS RECETTES — aperçu -->
<section class="w-full py-24 md:py-28 bg-surface-container-low overflow-hidden" id="recettes-apercu">
<div class="max-w-7xl mx-auto px-6 lg:px-12">
<div class="flex flex-col md:flex-row md:items-end md:justify-between gap-6 mb-14 reveal-item">
<div class="max-w-xl">
<span class="text-xs font-label uppercase tracking-[0.25em] text-tertiary font-bold">La cuisine Orzeta</span>
<h2 class="mt-3 text-3xl sm:text-4xl lg:text-5xl font-headline font-bold text-on-surface">Sublimez chaque plat</h2>
<p class="mt-5 text-on-surface-variant text-sm sm:text-base font-body leading-relaxed">Des recettes méditerranéennes pour tirer le meilleur de chaque huile. Des gestes simples, des produits justes.</p>
</div>
<a class="shrink-0 inline-flex items-center gap-2 text-xs font-bold uppercase tracking-widest font-label text-primary hover:gap-3 transition-all" href="#" onclick="showDetailPage('recettes'); return false;">Voir toutes les recettes <span class="material-symbols-outlined text-base">arrow_forward</span></a>
</div>
<div class="grid grid-cols-1 md:grid-cols-3 gap-7">
<button class="group text-left rounded-3xl overflow-hidden bg-surface ring-1 ring-surface-container-highest shadow-sm hover:shadow-2xl hover:-translate-y-1.5 transition-all duration-500 flex flex-col reveal-item" onclick="allerVersRecette('daurade')" style="transition-delay:0.05s" type="button">
<div class="relative aspect-[4/3] overflow-hidden shrink-0">
<img alt="Daurade grillée à la sfaxienne" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-[1.08]" src="assets/images/recette-sun-daurade.png" loading="lazy" decoding="async"/>
</div>
<div class="p-6 flex flex-col flex-1">
<span class="text-[10px] font-bold uppercase tracking-[0.18em] font-label text-tertiary">Sun Signature</span>
<h3 class="mt-1.5 text-lg font-headline font-bold text-on-surface leading-snug flex-1">Daurade grillée à la sfaxienne</h3>
<div class="mt-5 flex items-center justify-between">
<span class="text-xs text-on-surface-variant font-label flex items-center gap-1.5"><span class="material-symbols-outlined text-base">schedule</span><span>35 min</span> · <span>2 personnes</span></span>
<span class="text-xs font-bold text-primary font-label flex items-center gap-1 group-hover:gap-2 transition-all">Voir <span class="material-symbols-outlined text-sm">arrow_forward</span></span>
</div>
</div>
</button>
<button class="group text-left rounded-3xl overflow-hidden bg-surface ring-1 ring-surface-container-highest shadow-sm hover:shadow-2xl hover:-translate-y-1.5 transition-all duration-500 flex flex-col reveal-item" onclick="allerVersRecette('poulpe')" style="transition-delay:0.17s" type="button">
<div class="relative aspect-[4/3] overflow-hidden shrink-0">
<img alt="Poulpe grillé, citron et persil" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-[1.08]" src="assets/images/recette-grove-poulpe.png" loading="lazy" decoding="async"/>
</div>
<div class="p-6 flex flex-col flex-1">
<span class="text-[10px] font-bold uppercase tracking-[0.18em] font-label text-primary">Grove Signature</span>
<h3 class="mt-1.5 text-lg font-headline font-bold text-on-surface leading-snug flex-1">Poulpe grillé, citron et persil</h3>
<div class="mt-5 flex items-center justify-between">
<span class="text-xs text-on-surface-variant font-label flex items-center gap-1.5"><span class="material-symbols-outlined text-base">schedule</span><span>1 h 30</span> · <span>4 personnes</span></span>
<span class="text-xs font-bold text-primary font-label flex items-center gap-1 group-hover:gap-2 transition-all">Voir <span class="material-symbols-outlined text-sm">arrow_forward</span></span>
</div>
</div>
</button>
<button class="group text-left rounded-3xl overflow-hidden bg-surface ring-1 ring-surface-container-highest shadow-sm hover:shadow-2xl hover:-translate-y-1.5 transition-all duration-500 flex flex-col reveal-item" onclick="allerVersRecette('caprese')" style="transition-delay:0.29s" type="button">
<div class="relative aspect-[4/3] overflow-hidden shrink-0">
<img alt="Caprese à l'huile d'olive" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-[1.08]" src="assets/images/recette-organic-caprese.png" loading="lazy" decoding="async"/>
</div>
<div class="p-6 flex flex-col flex-1">
<span class="text-[10px] font-bold uppercase tracking-[0.18em] font-label text-primary">Organic Signature</span>
<h3 class="mt-1.5 text-lg font-headline font-bold text-on-surface leading-snug flex-1">Caprese à l'huile d'olive</h3>
<div class="mt-5 flex items-center justify-between">
<span class="text-xs text-on-surface-variant font-label flex items-center gap-1.5"><span class="material-symbols-outlined text-base">schedule</span><span>10 min</span> · <span>2 personnes</span></span>
<span class="text-xs font-bold text-primary font-label flex items-center gap-1 group-hover:gap-2 transition-all">Voir <span class="material-symbols-outlined text-sm">arrow_forward</span></span>
</div>
</div>
</button>
</div>
</div>
</section>
<!-- DEVENIR PARTENAIRE — section professionnels (B2B), version éditoriale -->
<section class="relative w-full py-20 md:py-24 bg-surface overflow-hidden" id="partenaires">
<svg aria-hidden="true" class="absolute -right-10 top-10 w-72 h-72 text-primary/10 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 200 200"><path d="M20 190 Q 70 130 130 90 Q 160 70 185 30"/><ellipse cx="70" cy="140" rx="22" ry="9" transform="rotate(-40 70 140)"/><ellipse cx="110" cy="105" rx="22" ry="9" transform="rotate(-35 110 105)"/><ellipse cx="150" cy="70" rx="20" ry="8" transform="rotate(-50 150 70)"/></svg>
<div class="relative max-w-7xl mx-auto px-6 lg:px-12">
<div class="grid grid-cols-1 lg:grid-cols-12 gap-12 lg:gap-20 items-stretch">
<div class="lg:col-span-6 reveal-item">
<div class="group relative h-full min-h-[320px] lg:min-h-[520px] rounded-[2rem] overflow-hidden shadow-[0_30px_60px_-20px_rgba(30,40,25,0.35)]">
<img alt="Huiles d'olive Orzeta chez un partenaire professionnel" class="absolute inset-0 w-full h-full object-cover object-[15%_center] transition-transform duration-[1600ms] ease-out group-hover:scale-105" src="assets/images/devenir-partenaire.png" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='assets/images/placeholder-photo.svg';"/>
<div aria-hidden="true" class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/10 to-transparent"></div>
<div class="absolute bottom-0 left-0 right-0 p-7 sm:p-9">
<p class="font-headline italic text-xl sm:text-2xl lg:text-3xl text-white leading-snug max-w-md">Une huile d’exception, pensée pour les tables et les rayons les plus exigeants.</p>
</div>
</div>
</div>
<div class="lg:col-span-6 flex flex-col justify-center">
<div class="flex items-center gap-3 reveal-item">
<span class="h-px w-10 bg-tertiary"></span>
<span class="text-xs font-label uppercase tracking-[0.3em] text-tertiary font-bold">Professionnels</span>
</div>
<h2 class="mt-5 text-4xl sm:text-5xl lg:text-6xl font-headline font-bold text-on-surface leading-[1.05] reveal-item" style="transition-delay:.1s">Devenir partenaire</h2>
<p class="mt-3 font-headline italic text-2xl sm:text-3xl text-primary reveal-item" style="transition-delay:.15s">Grandissons ensemble.</p>
<p class="mt-6 text-on-surface-variant text-sm sm:text-base font-body leading-relaxed max-w-xl reveal-item" style="transition-delay:.2s">Distributeurs, restaurateurs, épiceries fines : faites entrer une huile d’olive vierge extra tunisienne d’exception dans votre offre.</p>
<ul class="mt-8 border-t border-surface-container-highest">
<li class="group/item flex items-baseline gap-5 py-4 border-b border-surface-container-highest reveal-item" style="transition-delay:.25s"><span class="font-headline text-sm font-bold text-tertiary tabular-nums">01</span><div><h3 class="font-headline text-lg font-bold text-on-surface transition-colors group-hover/item:text-primary">Qualité constante</h3><p class="mt-1 text-sm text-on-surface-variant font-body leading-relaxed">Extraction à froid et acidité maîtrisée selon la gamme.</p></div></li>
<li class="group/item flex items-baseline gap-5 py-4 border-b border-surface-container-highest reveal-item" style="transition-delay:.3s"><span class="font-headline text-sm font-bold text-tertiary tabular-nums">02</span><div><h3 class="font-headline text-lg font-bold text-on-surface transition-colors group-hover/item:text-primary">Trois gammes</h3><p class="mt-1 text-sm text-on-surface-variant font-body leading-relaxed">3 huiles · 2 bouteilles (Marasca et Dorica) · de 250 mL à 1 L.</p></div></li>
<li class="group/item flex items-baseline gap-5 py-4 border-b border-surface-container-highest reveal-item" style="transition-delay:.35s"><span class="font-headline text-sm font-bold text-tertiary tabular-nums">03</span><div><h3 class="font-headline text-lg font-bold text-on-surface transition-colors group-hover/item:text-primary">Un savoir-faire tunisien</h3><p class="mt-1 text-sm text-on-surface-variant font-body leading-relaxed">Une expertise oléicole ancrée en Tunisie.</p></div></li>
<li class="group/item flex items-baseline gap-5 py-4 border-b border-surface-container-highest reveal-item" style="transition-delay:.4s"><span class="font-headline text-sm font-bold text-tertiary tabular-nums">04</span><div><h3 class="font-headline text-lg font-bold text-on-surface transition-colors group-hover/item:text-primary">Accompagnement dédié</h3><p class="mt-1 text-sm text-on-surface-variant font-body leading-relaxed">Échantillons, fiches techniques et supports de présentation.</p></div></li>
</ul>
<p class="mt-6 text-[11px] font-bold uppercase tracking-[0.2em] text-on-surface-variant font-label leading-loose reveal-item" style="transition-delay:.45s">Distributeurs · Restaurants &amp; hôtels · Épiceries fines · Cadeaux d’entreprise</p>
<div class="mt-6 text-center reveal-item" style="transition-delay:.5s">
<button class="group inline-flex items-center gap-3 pl-7 pr-2 py-2 rounded-full bg-primary text-on-primary text-xs font-bold uppercase tracking-widest font-label shadow-lg hover:shadow-2xl hover:-translate-y-0.5 transition-all" onclick="ouvrirDemandePartenariat()" type="button"><span>Devenir partenaire</span><span class="w-10 h-10 rounded-full bg-on-primary text-primary flex items-center justify-center transition-transform duration-300 group-hover:translate-x-1"><span class="material-symbols-outlined text-lg">arrow_forward</span></span></button>
</div>
</div>
</div>
</div>
</section>
<script>
(function() {
  var items = document.querySelectorAll('#partenaires .reveal-item');
  if (!items.length) { return; }
  var obs = new IntersectionObserver(function(entries) {
    entries.forEach(function(entry) {
      if (entry.isIntersecting) {
        entry.target.classList.add('active');
        obs.unobserve(entry.target);
      }
    });
  }, { threshold: 0.12 });
  items.forEach(function(el) { obs.observe(el); });
})();
// Ouvre la page Contact avec l'objet et un modèle de message pré-remplis
function ouvrirDemandePartenariat() {
  showDetailPage('contact');
  var objet = document.getElementById('contact-subject');
  var message = document.getElementById('contact-message');
  var objets = { fr: 'Demande de partenariat', en: 'Partnership request', ar: 'طلب شراكة' };
  if (objet) { objet.value = objets[siteLang] || objets.fr; }
  if (message) { setTimeout(function() { message.focus(); }, 400); }
}
// Depuis n'importe quelle page : revenir à l'accueil et descendre jusqu'à la section partenaires
function allerVersPartenaires() {
  showHomePage();
  var section = document.getElementById('partenaires');
  if (section) { setTimeout(function() { section.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 50); }
}
</script>
<script>
(function() {
  var items = document.querySelectorAll('#gamme .reveal-item, #recettes-apercu .reveal-item');
  if (!items.length) { return; }
  var obs = new IntersectionObserver(function(entries) {
    entries.forEach(function(entry) {
      if (entry.isIntersecting) {
        entry.target.classList.add('active');
        obs.unobserve(entry.target);
      }
    });
  }, { threshold: 0.12 });
  items.forEach(function(el) { obs.observe(el); });
})();
function allerVersRecette(id) {
  // on passe par la liste sans créer d'entrée d'historique intermédiaire :
  // le bouton Retour doit ramener à la page d'où l'on vient, pas à la liste
  NAV_SILENCIEUX = true;
  showDetailPage('recettes');
  NAV_SILENCIEUX = false;
  ouvrirRecette(id);
}
</script>
<script>
var ORGANIC_IMAGES = {
  '250ml': 'assets/images/organic-marasca-250ml.webp',
  '500ml': 'assets/images/organic-marasca-500ml.webp',
  '750ml': 'assets/images/organic-marasca-750ml.webp',
  '1l': 'assets/images/organic-marasca-1l.webp'
};
var ORGANIC_SIZE_SCALE = {
  '250ml': '100%',
  '500ml': '100%',
  '750ml': '100%',
  '1l': '100%'
};
function switchOrganicSize(size) {
  if (BOUTEILLE_ACTIVE.organic === 'dorica' && size === '1l') { return; }
  TAILLE_ACTIVE.organic = size;
  var img = document.getElementById('organic-detail-img');
  if (img && ORGANIC_IMAGES[size]) { img.src = imageBouteille('organic', size); }
  if (img && ORGANIC_SIZE_SCALE[size]) { img.style.height = ORGANIC_SIZE_SCALE[size]; }
  if (img) {
    img.classList.remove('img-swap-pop');
    void img.offsetWidth;
    img.classList.add('img-swap-pop');
  }
  document.querySelectorAll('.organic-size-btn').forEach(function(btn) {
    btn.classList.toggle('active', btn.getAttribute('data-size') === size);
  });
}
var SUN_IMAGES = {
  '250ml': 'assets/images/sun-marasca-250ml.webp',
  '500ml': 'assets/images/sun-marasca-500ml.webp',
  '750ml': 'assets/images/sun-marasca-750ml.webp',
  '1l': 'assets/images/sun-marasca-1l.webp'
};
var SUN_SIZE_SCALE = {
  '250ml': '100%',
  '500ml': '100%',
  '750ml': '100%',
  '1l': '100%'
};
function switchSunSize(size) {
  if (BOUTEILLE_ACTIVE.sun === 'dorica' && size === '1l') { return; }
  TAILLE_ACTIVE.sun = size;
  var img = document.getElementById('sun-detail-img');
  if (img && SUN_IMAGES[size]) { img.src = imageBouteille('sun', size); }
  if (img && SUN_SIZE_SCALE[size]) { img.style.height = SUN_SIZE_SCALE[size]; }
  if (img) {
    img.classList.remove('img-swap-pop');
    void img.offsetWidth;
    img.classList.add('img-swap-pop');
  }
  document.querySelectorAll('.sun-size-btn').forEach(function(btn) {
    btn.classList.toggle('active', btn.getAttribute('data-size') === size);
  });
}
var GROVE_IMAGES = {
  '250ml': 'assets/images/grove-marasca-250ml.webp',
  '500ml': 'assets/images/grove-marasca-500ml.webp',
  '750ml': 'assets/images/grove-marasca-750ml.webp',
  '1l': 'assets/images/grove-marasca-1l.webp'
};
var GROVE_SIZE_SCALE = {
  '250ml': '100%',
  '500ml': '100%',
  '750ml': '100%',
  '1l': '100%'
};
function switchGroveSize(size) {
  if (BOUTEILLE_ACTIVE.grove === 'dorica' && size === '1l') { return; }
  TAILLE_ACTIVE.grove = size;
  var img = document.getElementById('grove-detail-img');
  if (img && GROVE_IMAGES[size]) { img.src = imageBouteille('grove', size); }
  if (img && GROVE_SIZE_SCALE[size]) { img.style.height = GROVE_SIZE_SCALE[size]; }
  if (img) {
    img.classList.remove('img-swap-pop');
    void img.offsetWidth;
    img.classList.add('img-swap-pop');
  }
  document.querySelectorAll('.grove-size-btn').forEach(function(btn) {
    btn.classList.toggle('active', btn.getAttribute('data-size') === size);
  });
}

/* ===== Formes de bouteille : Marasca (250 ml à 1 L) et Dorica (250 à 750 ml) ===== */
var DORICA_IMAGES = {
  sun:     { '250ml': 'assets/images/SUN250-Photoroom - DORICA.png', '500ml': 'assets/images/SUN500-Photoroom - DORICA.png', '750ml': 'assets/images/SUN750-Photoroom - DORICA.png' },
  grove:   { '250ml': 'assets/images/GROVE250-Photoroom - DORICA.png', '500ml': 'assets/images/GROVE500-Photoroom - DORICA.png', '750ml': 'assets/images/GROVE750-Photoroom - DORICA.png' },
  organic: { '250ml': 'assets/images/ORGANIC250 - DORICA.png', '500ml': 'assets/images/ORGANIC500 - DORICA.png', '750ml': 'assets/images/ORGANIC750 - DORICA.png' }
};
var BOUTEILLE_ACTIVE = { sun: 'marasca', grove: 'marasca', organic: 'marasca' };
var TAILLE_ACTIVE = { sun: '1l', grove: '1l', organic: '1l' };
function imageBouteille(huile, taille) {
  if (BOUTEILLE_ACTIVE[huile] === 'dorica' && DORICA_IMAGES[huile][taille]) {
    return encodeURI(DORICA_IMAGES[huile][taille]);
  }
  var maps = { sun: SUN_IMAGES, grove: GROVE_IMAGES, organic: ORGANIC_IMAGES };
  return maps[huile][taille];
}
function choisirBouteille(huile, forme) {
  BOUTEILLE_ACTIVE[huile] = forme;
  document.querySelectorAll('.bouteille-onglet[data-huile="' + huile + '"]').forEach(function(b) {
    var actif = b.getAttribute('data-forme') === forme;
    b.classList.toggle('actif', actif);
    b.setAttribute('aria-pressed', actif ? 'true' : 'false');
  });
  // Dorica n'existe pas en 1 L
  var btn1l = document.querySelector('.' + huile + '-size-btn[data-size="1l"]');
  if (btn1l) { btn1l.classList.toggle('hidden', forme === 'dorica'); }
  var FORMES = {
    marasca: { corps: 'M9 5V12L2.7 15.3Q2 15.7 2 16.5V56.5A2 2 0 0 0 4 58.5H20A2 2 0 0 0 22 56.5V16.5Q22 15.7 21.3 15.3L15 12V5Z', bouchon: [8.5, 1, 7, 4] },
    dorica:  { corps: 'M9.6 4.5V10.5C9.6 14.2 2.4 14.6 2.4 21.2V51.8C2.4 56.4 5.6 58.7 12 58.7C18.4 58.7 21.6 56.4 21.6 51.8V21.2C21.6 14.6 14.4 14.2 14.4 10.5V4.5Z', bouchon: [9, 0.6, 6, 3.9] }
  };
  document.querySelectorAll('.' + huile + '-size-btn .taille-svg').forEach(function(svg) {
    svg.querySelectorAll('.forme-corps').forEach(function(p) { p.setAttribute('d', FORMES[forme].corps); });
    var b = svg.querySelector('.forme-bouchon'), c = FORMES[forme].bouchon;
    if (b) { b.setAttribute('x', c[0]); b.setAttribute('y', c[1]); b.setAttribute('width', c[2]); b.setAttribute('height', c[3]); }
  });
  var taille = TAILLE_ACTIVE[huile];
  if (forme === 'dorica' && taille === '1l') { taille = '750ml'; }
  var fn = { sun: switchSunSize, grove: switchGroveSize, organic: switchOrganicSize }[huile];
  fn(taille);
}
var OIL_LINES = ['sun', 'grove', 'organic', 'histoire', 'produits', 'contact', 'recettes'];
function playDetailEntrance(tab) {
  var root = document.getElementById('detail-' + tab);
  if (!root) { return; }
  root.querySelectorAll('.expertise-enter, .expertise-fade-up').forEach(function(el) {
    var cls = el.classList.contains('expertise-enter') ? 'expertise-enter' : 'expertise-fade-up';
    el.classList.remove(cls);
    void el.offsetWidth;
    el.classList.add(cls);
  });
  root.querySelectorAll('.taste-fill').forEach(function(el) {
    el.classList.remove('active');
    void el.offsetWidth;
    setTimeout(function() { el.classList.add('active'); }, 350);
  });
}
function toggleAccordion(panelId, iconId) {
  var panel = document.getElementById(panelId);
  var icon = document.getElementById(iconId);
  if (!panel) { return; }
  var collapsed = panel.classList.toggle('collapsed');
  if (icon) { icon.textContent = collapsed ? 'add' : 'remove'; }
}
function openImageLightbox(imgId) {
  var sourceImg = document.getElementById(imgId);
  var overlay = document.getElementById('image-lightbox');
  var lightboxImg = document.getElementById('lightbox-img');
  if (!sourceImg || !overlay || !lightboxImg) { return; }
  lightboxImg.src = sourceImg.src;
  lightboxImg.alt = sourceImg.alt;
  overlay.classList.remove('hidden');
  document.body.classList.add('overflow-hidden');
  void overlay.offsetWidth;
  overlay.classList.add('lightbox-active');
}
function closeImageLightbox() {
  var overlay = document.getElementById('image-lightbox');
  if (!overlay) { return; }
  overlay.classList.remove('lightbox-active');
  document.body.classList.remove('overflow-hidden');
  setTimeout(function() {
    overlay.classList.add('hidden');
    var lightboxImg = document.getElementById('lightbox-img');
    if (lightboxImg) { lightboxImg.src = ''; }
  }, 400);
}
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    var overlay = document.getElementById('image-lightbox');
    if (overlay && overlay.classList.contains('lightbox-active')) {
      closeImageLightbox();
    }
  }
});
var MAGNIFIER_ZOOM = 2.4;
function showImageMagnifier(lensId) {
  if (window.matchMedia && !window.matchMedia('(hover: hover)').matches) { return; }
  var lens = document.getElementById(lensId);
  if (!lens) { return; }
  lens.classList.add('active');
}
function hideImageMagnifier(lensId) {
  var lens = document.getElementById(lensId);
  if (!lens) { return; }
  lens.classList.remove('active');
}
function moveImageMagnifier(event, imgId, lensId) {
  if (window.matchMedia && !window.matchMedia('(hover: hover)').matches) { return; }
  var img = document.getElementById(imgId);
  var lens = document.getElementById(lensId);
  if (!img || !lens) { return; }
  var rect = img.getBoundingClientRect();
  var x = event.clientX - rect.left;
  var y = event.clientY - rect.top;
  if (x < 0 || y < 0 || x > rect.width || y > rect.height) {
    lens.classList.remove('active');
    return;
  }
  var lensSize = lens.offsetWidth;
  var lensX = Math.min(Math.max(x - lensSize / 2, -lensSize / 4), rect.width - lensSize * 0.75);
  var lensY = Math.min(Math.max(y - lensSize / 2, -lensSize / 4), rect.height - lensSize * 0.75);
  lens.style.left = lensX + 'px';
  lens.style.top = lensY + 'px';
  lens.style.backgroundImage = 'url(' + img.src + ')';
  lens.style.backgroundSize = (rect.width * MAGNIFIER_ZOOM) + 'px ' + (rect.height * MAGNIFIER_ZOOM) + 'px';
  var bgX = -(x * MAGNIFIER_ZOOM - lensSize / 2);
  var bgY = -(y * MAGNIFIER_ZOOM - lensSize / 2);
  lens.style.backgroundPosition = bgX + 'px ' + bgY + 'px';
}
var RECETTE_IDS = ['slata-tounsia','daurade','fenouil-orange','poulpe','bruschetta','agneau','caprese','houmous','tartare-saumon'];
function reinitVueRecettes() {
  if (typeof reinitFiltres === 'function') { reinitFiltres(); }
  document.querySelectorAll('#recettes-grille .recette-card').forEach(function(card) {
    card.classList.remove('hidden', 'carte-masquee');
    card.classList.add('expertise-enter');
  });
  RECETTE_IDS.forEach(function(id) {
    var f = document.getElementById('recette-' + id);
    if (f) { f.classList.add('hidden'); }
  });
  var liste = document.getElementById('recettes-liste');
  if (liste) { liste.classList.remove('hidden'); }
}
function rejouerAnimations(root) {
  if (!root) { return; }
  root.querySelectorAll('.expertise-enter, .expertise-fade-up').forEach(function(el) {
    var cls = el.classList.contains('expertise-enter') ? 'expertise-enter' : 'expertise-fade-up';
    el.classList.remove(cls);
    void el.offsetWidth;
    el.classList.add(cls);
  });
}
function ouvrirRecette(id) {
  var liste = document.getElementById('recettes-liste');
  if (liste) { liste.classList.add('hidden'); }
  RECETTE_IDS.forEach(function(r) {
    var f = document.getElementById('recette-' + r);
    if (f) { f.classList.toggle('hidden', r !== id); }
  });
  window.scrollTo({ top: 0, behavior: 'instant' });
  if (typeof updateHeaderTransparency === 'function') { updateHeaderTransparency(); }
  rejouerAnimations(document.getElementById('recette-' + id));
  majURL('p=recettes&r=' + id);
}
function retourRecettes() {
  reinitVueRecettes();
  majURL('p=recettes');
  window.scrollTo({ top: 0, behavior: 'instant' });
  if (typeof updateHeaderTransparency === 'function') { updateHeaderTransparency(); }
  rejouerAnimations(document.getElementById('recettes-liste'));
}
var FILTRES_LIBELLES = { gamme: 'Huile', categorie: 'Catégorie', temps: 'Temps', niveau: 'Niveau' };
var FILTRES_ACTIFS = { gamme: 'all', categorie: 'all', temps: 'all', niveau: 'all' };
function fermerFiltres(sauf) {
  Object.keys(FILTRES_LIBELLES).forEach(function(cle) {
    if (cle === sauf) { return; }
    var p = document.getElementById('panel-' + cle);
    var ca = document.getElementById('caret-' + cle);
    if (p) { p.classList.remove('ouvert'); }
    if (ca) { ca.style.transform = ''; }
  });
}
function ouvrirFiltre(cle) {
  var panel = document.getElementById('panel-' + cle);
  var caret = document.getElementById('caret-' + cle);
  if (!panel) { return; }
  var dejaOuvert = panel.classList.contains('ouvert');
  fermerFiltres(cle);
  panel.classList.toggle('ouvert', !dejaOuvert);
  if (caret) { caret.style.transform = dejaOuvert ? '' : 'rotate(180deg)'; }
}
function choisirFiltre(cle, valeur) {
  FILTRES_ACTIFS[cle] = valeur;
  var libelle = document.getElementById('libelle-' + cle);
  if (libelle) {
    if (valeur === 'all') {
      libelle.textContent = FILTRES_LIBELLES[cle];
      libelle.classList.remove('text-primary', 'font-bold');
    } else {
      var opt = document.querySelector('.filtre-option[data-groupe="' + cle + '"][data-valeur="' + valeur + '"]');
      libelle.textContent = opt ? (opt.firstChild ? opt.firstChild.textContent.trim() : opt.textContent.trim()) : FILTRES_LIBELLES[cle];
      libelle.classList.add('text-primary', 'font-bold');
    }
  }
  fermerFiltres(null);
  appliquerFiltres();
}
function appliquerFiltres() {
  var cartes = document.querySelectorAll('#recettes-grille .recette-card');
  cartes.forEach(function(card) {
    var mn = parseInt(card.getAttribute('data-minutes'), 10);
    if (!isNaN(mn)) { card.setAttribute('data-temps', mn < 30 ? 'court' : (mn <= 60 ? 'moyen' : 'long')); }
  });
  var visibles = 0;
  cartes.forEach(function(card) {
    // l'animation d'entrée fige opacity/transform : on la retire pour laisser jouer les transitions
    card.classList.remove('expertise-enter');
    var ok = (FILTRES_ACTIFS.gamme === 'all' || card.getAttribute('data-gamme') === FILTRES_ACTIFS.gamme)
          && (FILTRES_ACTIFS.categorie === 'all' || card.getAttribute('data-categorie') === FILTRES_ACTIFS.categorie)
          && (FILTRES_ACTIFS.temps === 'all' || card.getAttribute('data-temps') === FILTRES_ACTIFS.temps)
          && (FILTRES_ACTIFS.niveau === 'all' || card.getAttribute('data-niveau') === FILTRES_ACTIFS.niveau);
    if (ok) {
      visibles++;
      if (card.classList.contains('hidden')) {
        card.classList.remove('hidden');
        void card.offsetWidth;
      }
      card.classList.remove('carte-masquee');
    } else {
      card.classList.add('carte-masquee');
      setTimeout(function() {
        if (card.classList.contains('carte-masquee')) { card.classList.add('hidden'); }
      }, 360);
    }
  });
  var nb = document.getElementById('recettes-nombre');
  if (nb) { nb.textContent = visibles; }
  var vide = document.getElementById('recettes-vide');
  if (vide) { vide.classList.toggle('hidden', visibles > 0); }
  var actif = Object.keys(FILTRES_ACTIFS).some(function(k) { return FILTRES_ACTIFS[k] !== 'all'; });
  var reset = document.getElementById('recettes-reset');
  if (reset) { reset.classList.toggle('hidden', !actif); }
  majOptionsFiltres(cartes);
}
// Chaque option affiche combien de recettes elle donnerait (avec les autres filtres actifs) ;
// une option qui ne donnerait aucun résultat est grisée et non cliquable.
function majOptionsFiltres(cartes) {
  document.querySelectorAll('.filtre-option').forEach(function(opt) {
    var groupe = opt.getAttribute('data-groupe');
    var valeur = opt.getAttribute('data-valeur');
    var n = 0;
    cartes.forEach(function(card) {
      var ok = Object.keys(FILTRES_ACTIFS).every(function(cle) {
        var v = (cle === groupe) ? valeur : FILTRES_ACTIFS[cle];
        var attr = (cle === 'gamme') ? 'data-gamme' : 'data-' + cle;
        return v === 'all' || card.getAttribute(attr) === v;
      });
      if (ok) { n++; }
    });
    var badge = opt.querySelector('.filtre-compte');
    if (!badge) {
      badge = document.createElement('span');
      badge.className = 'filtre-compte';
      badge.setAttribute('aria-hidden', 'true');
      opt.appendChild(badge);
    }
    badge.textContent = n;
    var vide = n === 0 && valeur !== 'all';
    opt.disabled = vide;
    opt.classList.toggle('filtre-vide', vide);
  });
}
document.addEventListener('DOMContentLoaded', function() { majOptionsFiltres(document.querySelectorAll('#recettes-grille .recette-card')); });
function reinitFiltres() {
  Object.keys(FILTRES_ACTIFS).forEach(function(cle) { FILTRES_ACTIFS[cle] = 'all'; });
  Object.keys(FILTRES_LIBELLES).forEach(function(cle) {
    var l = document.getElementById('libelle-' + cle);
    if (l) { l.textContent = FILTRES_LIBELLES[cle]; l.classList.remove('text-primary', 'font-bold'); }
  });
  fermerFiltres(null);
  appliquerFiltres();
}
document.addEventListener('click', function(e) {
  if (!e.target.closest || !e.target.closest('.filtre-groupe')) { fermerFiltres(null); }
});
/* ===== Gestion des URL : une adresse par page (?p=...) ===== */
var NAV_SILENCIEUX = false;   // vrai pendant une restauration : on ne réécrit pas l'historique

function urlSupportee() {
  return !!(window.history && window.history.pushState);
}
function majURL(requete, remplacer) {
  if (NAV_SILENCIEUX || !urlSupportee()) { return; }
  var cible = window.location.pathname + (requete ? '?' + requete : '');
  var actuelle = window.location.pathname + window.location.search;
  if (cible === actuelle) { return; }
  if (remplacer) { history.replaceState(null, '', cible); }
  else { history.pushState(null, '', cible); }
}
function restaurerDepuisURL() {
  var params = {};
  window.location.search.replace(/^\?/, '').split('&').forEach(function(paire) {
    if (!paire) { return; }
    var kv = paire.split('=');
    params[decodeURIComponent(kv[0])] = decodeURIComponent(kv[1] || '');
  });
  var p = params.p || '';
  var r = params.r || '';
  NAV_SILENCIEUX = true;
  if (p && OIL_LINES.indexOf(p) !== -1) {
    showDetailPage(p);
    if (p === 'recettes' && r && RECETTE_IDS.indexOf(r) !== -1) { ouvrirRecette(r); }
  } else {
    showHomePage();
  }
  NAV_SILENCIEUX = false;
}
window.addEventListener('popstate', function() {
  NAV_SILENCIEUX = true;
  restaurerDepuisURL();
  NAV_SILENCIEUX = false;
});
document.addEventListener('DOMContentLoaded', function() { restaurerDepuisURL(); });

function showDetailPage(tab) {
  document.getElementById('home-page').classList.add('hidden');
  OIL_LINES.forEach(function(t) {
    document.getElementById('detail-' + t).classList.add('hidden');
  });
  document.getElementById('detail-' + tab).classList.remove('hidden');
  window.scrollTo({ top: 0, behavior: 'instant' });
  if (typeof updateHeaderTransparency === 'function') { updateHeaderTransparency(); }
  var logoHeader = document.getElementById('header-logo-img');
  if (logoHeader) { logoHeader.classList.toggle('logo-glow', tab === 'produits'); }
  if (tab === 'recettes') { reinitVueRecettes(); }
  if (tab === 'sun' || tab === 'grove' || tab === 'organic' || tab === 'produits' || tab === 'contact' || tab === 'recettes' || tab === 'histoire') {
    playDetailEntrance(tab);
  }
  majURL('p=' + tab);
}
function showHomePage() {
  OIL_LINES.forEach(function(t) {
    document.getElementById('detail-' + t).classList.add('hidden');
  });
  document.getElementById('home-page').classList.remove('hidden');
  majURL('');
  window.scrollTo({ top: 0, behavior: 'instant' });
  if (typeof updateHeaderTransparency === 'function') { updateHeaderTransparency(); }
  var logoHeaderAccueil = document.getElementById('header-logo-img');
  if (logoHeaderAccueil) { logoHeaderAccueil.classList.remove('logo-glow'); }
}

function openSidebar() {
  var sidebar = document.getElementById('mobile-sidebar');
  var overlay = document.getElementById('sidebar-overlay');
  if (!sidebar || !overlay) { return; }
  sidebar.classList.remove('translate-x-full');
  sidebar.classList.add('translate-x-0');
  overlay.classList.remove('opacity-0', 'pointer-events-none');
  overlay.classList.add('opacity-100', 'pointer-events-auto');
  document.body.style.overflow = 'hidden';
}
function closeSidebar() {
  var sidebar = document.getElementById('mobile-sidebar');
  var overlay = document.getElementById('sidebar-overlay');
  if (!sidebar || !overlay) { return; }
  sidebar.classList.add('translate-x-full');
  sidebar.classList.remove('translate-x-0');
  overlay.classList.add('opacity-0', 'pointer-events-none');
  overlay.classList.remove('opacity-100', 'pointer-events-auto');
  document.body.style.overflow = '';
}
function toggleSidebarGamme() {
  var panel = document.getElementById('sidebar-gamme-panel');
  var caret = document.getElementById('sidebar-gamme-caret');
  if (!panel) { return; }
  var isHidden = panel.classList.contains('hidden');
  if (isHidden) {
    panel.classList.remove('hidden');
    panel.classList.add('flex');
    if (caret) { caret.style.transform = 'rotate(180deg)'; }
  } else {
    panel.classList.add('hidden');
    panel.classList.remove('flex');
    if (caret) { caret.style.transform = 'rotate(0deg)'; }
  }
}
window.addEventListener('resize', function() {
  if (window.innerWidth >= 1024) { closeSidebar(); }
});

/* ==== Contrôle de saisie du formulaire de contact (même style que le site) ==== */
var CONTACT_MESSAGES = {
  fr: {
    nomVide: 'Merci d’indiquer votre nom.', nomCourt: 'Votre nom doit contenir au moins 2 caractères.', nomInvalide: 'Votre nom contient des caractères non autorisés.',
    emailVide: 'Merci d’indiquer votre adresse email.', emailInvalide: 'Cette adresse email ne semble pas valide (ex : vous@exemple.com).',
    sujetLong: 'Le sujet ne doit pas dépasser 120 caractères.',
    typeVide: 'Merci d’indiquer qui vous êtes.',
    autreVide: 'Merci de préciser votre activité.',
    messageVide: 'Merci d’écrire votre message.', messageCourt: 'Votre message est un peu court (10 caractères minimum).',
    corriger: 'Merci de corriger les champs indiqués en rouge.',
    envoi: 'Envoi en cours...', succes: 'Merci, votre message a bien été envoyé ! Nous vous répondrons rapidement.',
    erreur: 'Une erreur est survenue. Merci de réessayer ou de nous écrire directement à contact@orzeta.com.',
    reseau: "Impossible d'envoyer le message pour le moment. Merci de nous écrire directement à contact@orzeta.com."
  },
  en: {
    nomVide: 'Please enter your name.', nomCourt: 'Your name must contain at least 2 characters.', nomInvalide: 'Your name contains characters that are not allowed.',
    emailVide: 'Please enter your email address.', emailInvalide: 'This email address does not look valid (e.g. you@example.com).',
    sujetLong: 'The subject must not exceed 120 characters.',
    typeVide: 'Please tell us who you are.',
    autreVide: 'Please specify your activity.',
    messageVide: 'Please write your message.', messageCourt: 'Your message is a little short (10 characters minimum).',
    corriger: 'Please correct the fields highlighted in red.',
    envoi: 'Sending...', succes: 'Thank you, your message has been sent! We will get back to you shortly.',
    erreur: 'An error occurred. Please try again or write to us directly at contact@orzeta.com.',
    reseau: 'Unable to send the message right now. Please write to us directly at contact@orzeta.com.'
  },
  ar: {
    nomVide: 'يرجى إدخال اسمك.', nomCourt: 'يجب أن يحتوي اسمك على حرفين على الأقل.', nomInvalide: 'يحتوي اسمك على أحرف غير مسموح بها.',
    emailVide: 'يرجى إدخال بريدك الإلكتروني.', emailInvalide: 'يبدو أن هذا البريد الإلكتروني غير صالح (مثال: you@example.com).',
    sujetLong: 'يجب ألا يتجاوز الموضوع 120 حرفًا.',
    typeVide: 'يرجى اختيار صفتك.',
    autreVide: 'يرجى تحديد نشاطك.',
    messageVide: 'يرجى كتابة رسالتك.', messageCourt: 'رسالتك قصيرة بعض الشيء (10 أحرف على الأقل).',
    corriger: 'يرجى تصحيح الحقول المشار إليها باللون الأحمر.',
    envoi: 'جارٍ الإرسال...', succes: 'شكرًا، تم إرسال رسالتك بنجاح! سنردّ عليك قريبًا.',
    erreur: 'حدث خطأ. يرجى المحاولة مرة أخرى أو مراسلتنا مباشرة على contact@orzeta.com.',
    reseau: 'تعذّر إرسال الرسالة حاليًا. يرجى مراسلتنا مباشرة على contact@orzeta.com.'
  }
};
function contactTxt(cle) {
  var lang = (typeof siteLang !== 'undefined' && CONTACT_MESSAGES[siteLang]) ? siteLang : 'fr';
  return CONTACT_MESSAGES[lang][cle];
}
function verifierChampContact(id) {
  var el = document.getElementById(id);
  if (!el) { return ''; }
  var v = el.value.trim();
  if (id === 'contact-name') {
    if (!v) { return contactTxt('nomVide'); }
    if (v.length < 2) { return contactTxt('nomCourt'); }
    if (!/^[A-Za-zÀ-ÖØ-öø-ÿ؀-ۿ' .\-]+$/.test(v)) { return contactTxt('nomInvalide'); }
  } else if (id === 'contact-email') {
    if (!v) { return contactTxt('emailVide'); }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v)) { return contactTxt('emailInvalide'); }
  } else if (id === 'contact-subject') {
    if (v.length > 120) { return contactTxt('sujetLong'); }
  } else if (id === 'contact-message') {
    if (!v) { return contactTxt('messageVide'); }
    if (v.length < 10) { return contactTxt('messageCourt'); }
  }
  return '';
}
function afficherEtatChampContact(id, erreur) {
  var el = document.getElementById(id);
  var zone = document.getElementById(id + '-erreur');
  if (!el || !zone) { return; }
  var aEteTouche = el.getAttribute('data-touche') === '1';
  el.classList.remove('champ-erreur', 'champ-ok');
  if (erreur) {
    el.classList.add('champ-erreur');
    el.setAttribute('aria-invalid', 'true');
    zone.querySelector('.erreur-texte').textContent = erreur;
    zone.classList.remove('hidden');
  } else {
    el.removeAttribute('aria-invalid');
    zone.classList.add('hidden');
    if (aEteTouche && el.value.trim() !== '') { el.classList.add('champ-ok'); }
  }
}
function verifierTypeContact() {
  var coche = document.querySelector('#contact-form input[name="type_contact"]:checked');
  var zone = document.getElementById('contact-type-erreur');
  var groupe = document.getElementById('contact-type');
  if (!zone || !groupe) { return true; }
  if (!coche) {
    zone.querySelector('.erreur-texte').textContent = contactTxt('typeVide');
    zone.classList.remove('hidden');
    groupe.classList.add('groupe-erreur');
    return false;
  }
  zone.classList.add('hidden');
  groupe.classList.remove('groupe-erreur');
  if (coche.value === 'autre') {
    var champ = document.getElementById('contact-type-autre');
    if (champ) {
      champ.setAttribute('data-touche', '1');
      var err = champ.value.trim() === '' ? contactTxt('autreVide') : '';
      afficherEtatChampContact('contact-type-autre', err);
      if (err) { return false; }
    }
  }
  return true;
}
// Affiche la zone « Précisez » uniquement quand « Autre » est choisi
function majChampAutreContact() {
  var coche = document.querySelector('#contact-form input[name="type_contact"]:checked');
  var bloc = document.getElementById('contact-type-autre-bloc');
  var champ = document.getElementById('contact-type-autre');
  if (!bloc || !champ) { return; }
  if (coche && coche.value === 'autre') {
    bloc.classList.remove('hidden');
    setTimeout(function() { champ.focus(); }, 50);
  } else {
    bloc.classList.add('hidden');
    champ.value = '';
    champ.removeAttribute('data-touche');
    champ.classList.remove('champ-erreur', 'champ-ok');
    var z = document.getElementById('contact-type-autre-erreur'); if (z) { z.classList.add('hidden'); }
  }
}
function validerFormulaireContact() {
  var ids = ['contact-name', 'contact-email', 'contact-subject', 'contact-message'];
  var premierInvalide = null;
  if (!verifierTypeContact()) {
    var cocheT = document.querySelector('#contact-form input[name="type_contact"]:checked');
    premierInvalide = (cocheT && cocheT.value === 'autre') ? document.getElementById('contact-type-autre') : document.getElementById('contact-type');
  }
  ids.forEach(function(id) {
    var el = document.getElementById(id);
    if (el) { el.setAttribute('data-touche', '1'); }
    var erreur = verifierChampContact(id);
    afficherEtatChampContact(id, erreur);
    if (erreur && !premierInvalide) { premierInvalide = el; }
  });
  if (premierInvalide) {
    premierInvalide.focus();
    premierInvalide.classList.remove('champ-secousse');
    void premierInvalide.offsetWidth;
    premierInvalide.classList.add('champ-secousse');
  }
  return !premierInvalide;
}
function initControleContact() {
  var champAutre = document.getElementById('contact-type-autre');
  if (champAutre) {
    champAutre.addEventListener('input', function() {
      if (champAutre.getAttribute('data-touche') === '1') {
        afficherEtatChampContact('contact-type-autre', champAutre.value.trim() === '' ? contactTxt('autreVide') : '');
      }
    });
  }
  ['contact-name', 'contact-email', 'contact-subject', 'contact-message'].forEach(function(id) {
    var el = document.getElementById(id);
    if (!el) { return; }
    el.addEventListener('blur', function() {
      if (el.value.trim() !== '' || el.getAttribute('data-touche') === '1') {
        el.setAttribute('data-touche', '1');
        afficherEtatChampContact(id, verifierChampContact(id));
      }
    });
    el.addEventListener('input', function() {
      if (el.getAttribute('data-touche') === '1') { afficherEtatChampContact(id, verifierChampContact(id)); }
    });
  });
  var msg = document.getElementById('contact-message');
  var compteur = document.getElementById('contact-message-compteur');
  function majCompteur() {
    if (!msg || !compteur) { return; }
    var n = msg.value.length;
    compteur.textContent = n + ' / 2000';
    compteur.classList.toggle('text-error', n >= 1900);
  }
  if (msg) { msg.addEventListener('input', majCompteur); }
  document.querySelectorAll('#contact-form input[name="type_contact"]').forEach(function(r) {
    r.addEventListener('change', function() {
      majChampAutreContact();
      var zt = document.getElementById('contact-type-erreur'); if (zt) { zt.classList.add('hidden'); }
      var gt = document.getElementById('contact-type'); if (gt) { gt.classList.remove('groupe-erreur'); }
    });
  });
  var form = document.getElementById('contact-form');
  if (form) {
    form.addEventListener('reset', function() {
      setTimeout(function() {
        ['contact-name', 'contact-email', 'contact-subject', 'contact-message'].forEach(function(id) {
          var el = document.getElementById(id);
          if (el) { el.removeAttribute('data-touche'); el.classList.remove('champ-ok', 'champ-erreur'); }
          var zone = document.getElementById(id + '-erreur');
          if (zone) { zone.classList.add('hidden'); }
        });
        var zt = document.getElementById('contact-type-erreur'); if (zt) { zt.classList.add('hidden'); }
        majChampAutreContact();
        var gt = document.getElementById('contact-type'); if (gt) { gt.classList.remove('groupe-erreur'); }
        majCompteur();
      }, 0);
    });
  }
}
// le formulaire est plus bas dans la page : on attend qu'il soit chargé
if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', initControleContact); }
else { initControleContact(); }

function handleContactSubmit(event) {
  event.preventDefault();
  var form = document.getElementById('contact-form');
  var status = document.getElementById('contact-status');
  var submitBtn = form.querySelector('button[type="submit"]');
  var originalBtnHTML = submitBtn ? submitBtn.innerHTML : '';
  status.classList.remove('text-primary', 'text-error', 'font-semibold', 'text-on-surface-variant');
  if (!validerFormulaireContact()) {
    status.textContent = contactTxt('corriger');
    status.classList.add('text-error', 'font-semibold');
    return false;
  }
  status.textContent = contactTxt('envoi');
  if (submitBtn) {
    submitBtn.disabled = true;
    submitBtn.style.opacity = '.7';
  }
  fetch(window.location.pathname + window.location.search, {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: new FormData(form)
  })
    .then(function(res) { return res.json(); })
    .then(function(data) {
      if (data && data.success) {
        status.textContent = contactTxt('succes');
        status.classList.add('text-primary', 'font-semibold');
        form.reset();
      } else {
        status.textContent = (siteLang === 'fr' && data && data.error) ? data.error : contactTxt('erreur');
        status.classList.add('text-error', 'font-semibold');
      }
    })
    .catch(function() {
      status.textContent = contactTxt('reseau');
      status.classList.add('text-error', 'font-semibold');
    })
    .finally(function() {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.style.opacity = '';
        submitBtn.innerHTML = originalBtnHTML;
      }
    });
  return false;
}

// Animation "reveal" au scroll sur chaque section
document.querySelectorAll('main section').forEach(function(sec) {
  sec.classList.add('reveal');
});
var revealObserver = new IntersectionObserver(function(entries) {
  entries.forEach(function(entry) {
    if (entry.isIntersecting) {
      entry.target.classList.add('active');
      revealObserver.unobserve(entry.target);
    }
  });
}, { threshold: 0.12 });
document.querySelectorAll('.reveal').forEach(function(el) {
  revealObserver.observe(el);
});

// Header qui gagne une ombre plus marquée dès qu'on scrolle
// (un seul listener de scroll, throttlé via requestAnimationFrame pour rester fluide)
var siteHeader = document.getElementById('site-header');
var scrollTicking = false;
function updateHeaderTransparency() {
  if (!siteHeader) { return; }
  var homePage = document.getElementById('home-page');
  var hero = document.getElementById('hero-section');
  var isHome = homePage && !homePage.classList.contains('hidden');
  // Transparent uniquement tout en haut du hero ; dès qu'on descend, fond crème
  if (isHome && hero) {
    if (window.scrollY < 60) {
      siteHeader.classList.add('hdr-transparent');
      siteHeader.classList.remove('scrolled');
      return;
    }
  }
  siteHeader.classList.remove('hdr-transparent');
}
var heroTexte = document.querySelector('.hero-texte');
var reduireMouvement = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
function handleScrollFrame() {
  var y = window.scrollY;
  updateHeaderTransparency();
  if (siteHeader) { siteHeader.classList.toggle('compact', y > 60); }
  // Le texte du hero s'efface avant de passer sous le menu
  if (heroTexte && !reduireMouvement) {
    var h = window.innerHeight || 800;
    var p = Math.min(Math.max(y / (h * 0.45), 0), 1);
    heroTexte.style.opacity = (1 - p).toFixed(3);
    heroTexte.style.transform = p > 0 ? 'translateY(' + (-p * 40).toFixed(1) + 'px)' : '';
  }
  if (siteHeader && !siteHeader.classList.contains('hdr-transparent')) {
    if (y > 12) { siteHeader.classList.add('scrolled'); }
    else { siteHeader.classList.remove('scrolled'); }
  }
  scrollTicking = false;
}
window.addEventListener('scroll', function() {
  if (!scrollTicking) {
    requestAnimationFrame(handleScrollFrame);
    scrollTicking = true;
  }
}, { passive: true });

// Bannières des autres pages : préchargées discrètement 2,5 s après l'ouverture du site
function prechargerBannieres() {
  document.querySelectorAll('img[data-banniere="1"]').forEach(function(img) { img.loading = 'eager'; });
}
window.addEventListener('load', function() { setTimeout(prechargerBannieres, 2500); });

// Hero vidéo : lecture directe, bonne version selon l'écran, pause hors écran
(function() {
  var video = document.getElementById('hero-video');
  if (!video) { return; }
  var visible = true;
  var reduireAnimations = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  function afficher() { video.classList.add('lecture'); }
  video.addEventListener('loadeddata', afficher);
  video.addEventListener('playing', afficher);
  if (video.readyState >= 2) { afficher(); }
  function lire() {
    if (reduireAnimations || !visible || document.hidden) { return; }
    var p = video.play();
    if (p && typeof p.catch === 'function') { p.catch(function() {}); }
  }
  // "Réduire les animations" activé : on affiche la vidéo à l'arrêt (1re image)
  if (reduireAnimations) { video.removeAttribute('autoplay'); video.pause(); }
  // Rotation de l'écran / redimensionnement : le navigateur reprend la bonne version (mobile ou ordinateur)
  var ecranMobile = window.matchMedia('(max-width: 767px)');
  var minuterie = null;
  var surChangement = function() { clearTimeout(minuterie); minuterie = setTimeout(function() { video.load(); lire(); }, 300); };
  if (ecranMobile.addEventListener) { ecranMobile.addEventListener('change', surChangement); }
  else if (ecranMobile.addListener) { ecranMobile.addListener(surChangement); }
  // Pause quand la vidéo n'est plus visible (défilement, autre page, onglet masqué)
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(function(entrees) {
      visible = entrees[0].isIntersecting;
      if (visible) { lire(); } else { video.pause(); }
    }, { threshold: 0.05 }).observe(video);
  }
  document.addEventListener('visibilitychange', function() { if (document.hidden) { video.pause(); } else { lire(); } });
  lire();
})();
updateHeaderTransparency();
// Recalcul de sécurité : on revérifie la transparence du header pendant le chargement.
window.addEventListener('load', updateHeaderTransparency);
[50, 150, 350, 700, 1200].forEach(function(ms) { setTimeout(updateHeaderTransparency, ms); });
if ('ResizeObserver' in window) {
  var heroSectionEl = document.getElementById('hero-section');
  if (heroSectionEl) {
    new ResizeObserver(function() { updateHeaderTransparency(); }).observe(heroSectionEl);
  }
}

// Reveal en cascade : chaque carte d'une grille apparaît avec un léger décalage
document.querySelectorAll('main section .grid').forEach(function(grid) {
  Array.from(grid.children).forEach(function(child, i) {
    child.classList.add('reveal-item');
    child.style.transitionDelay = (i * 0.1) + 's';
  });
});
var itemObserver = new IntersectionObserver(function(entries) {
  entries.forEach(function(entry) {
    if (entry.isIntersecting) {
      entry.target.classList.add('active');
      itemObserver.unobserve(entry.target);
    }
  });
}, { threshold: 0.15 });
document.querySelectorAll('.reveal-item').forEach(function(el) {
  itemObserver.observe(el);
});
</script>
<!-- 2bis. IMAGE DE MARQUE — bandeau visuel plein écran -->
<section class="relative w-full h-[58vh] min-h-[380px] max-h-[620px] overflow-hidden" id="signature">
<div class="absolute inset-0">
<img alt="Huile d'olive Orzeta" class="w-full h-full object-cover ken-burns-slow" src="assets/images/degustation-hero.jpg" loading="lazy" decoding="async"/>
</div>
<div class="absolute inset-0 bg-gradient-to-r from-black/65 via-black/35 to-black/10"></div>
<div class="relative z-10 h-full max-w-7xl mx-auto px-6 lg:px-12 flex items-center">
<div class="max-w-xl reveal-item">
<span class="text-xs font-bold uppercase tracking-[0.3em] font-label text-white/80">La Signature Orzeta</span>
<h2 class="mt-4 font-headline text-3xl sm:text-4xl lg:text-5xl font-bold text-white leading-tight" style="text-shadow:0 2px 20px rgba(0,0,0,.45)">La nature l'a perfectionnée.<br/>Nous l'avons mise en bouteille.</h2>
<p class="mt-5 text-white/90 text-sm sm:text-base font-body" style="text-shadow:0 1px 8px rgba(0,0,0,.4)">Née en Tunisie. Faite pour le monde.</p>
</div>
</div>
</section>
<script>
(function() {
  var items = document.querySelectorAll('#signature .reveal-item');
  if (!items.length) { return; }
  var obs = new IntersectionObserver(function(entries) {
    entries.forEach(function(entry) {
      if (entry.isIntersecting) {
        entry.target.classList.add('active');
        obs.unobserve(entry.target);
      }
    });
  }, { threshold: 0.15 });
  items.forEach(function(el) { obs.observe(el); });
})();
</script>
<!-- TODO Orzeta : section "Recettes / Accords Mets & Huile" retirée temporairement (20/09/2026).
     Raison : traitement jugé pas assez professionnel (icône + dégradé au lieu de vraies photos,
     3 cartes statiques au lieu d'une bibliothèque filtrable avec URL par recette).
     Voir le plan de refonte complet + contenu déjà rédigé dans recettes-draft-futur.md,
     et le rapport de recherche concurrentielle "Sections recettes huile olive premium.md". -->
</script>
</div>
<!-- PAGE DÉTAIL : HUILE D'OLIVE EXTRA VIERGE -->
<div class="hidden w-full" id="detail-sun">
<section class="w-full py-16 bg-surface">
<div class="max-w-7xl mx-auto px-6 lg:px-12">
<div class="grid grid-cols-1 lg:grid-cols-12 gap-12">
<div class="lg:col-span-5">
<div class="relative rounded-2xl overflow-hidden bg-surface-container-low aspect-[3/4] flex items-center justify-center expertise-enter cursor-zoom-in group" onclick="openImageLightbox('sun-detail-img')" onmousemove="moveImageMagnifier(event, 'sun-detail-img', 'sun-magnifier-lens')" onmouseenter="moveImageMagnifier(event, 'sun-detail-img', 'sun-magnifier-lens'); showImageMagnifier('sun-magnifier-lens')" onmouseleave="hideImageMagnifier('sun-magnifier-lens')">
<img alt="Sun Signature - Huile d'Olive Vierge Extra" class="object-contain transition-all duration-300 group-hover:scale-[1.04]" id="sun-detail-img" src="assets/images/sun-marasca-1l.webp" style="height:100%;width:auto;" loading="lazy" decoding="async"/><div class="img-magnifier-lens" id="sun-magnifier-lens"></div>
<span class="absolute bottom-4 right-4 w-10 h-10 rounded-full bg-surface/90 backdrop-blur-sm shadow-md flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity duration-300"><span class="material-symbols-outlined text-primary text-lg">zoom_in</span></span>
</div>
<div class="mt-6 rounded-2xl overflow-hidden ring-1 ring-surface-container-highest expertise-fade-up" style="animation-delay:.25s">
<button class="w-full flex items-center justify-between gap-4 px-5 py-4 bg-primary text-on-primary font-bold text-xs uppercase tracking-widest font-label transition-colors hover:bg-primary-container hover:text-on-primary-container" onclick="toggleAccordion('sun-sizes-panel','sun-sizes-icon')" type="button"><span>Conditionnements disponibles</span><span class="material-symbols-outlined transition-transform duration-300" id="sun-sizes-icon">remove</span></button>
<div class="accordion-panel" id="sun-sizes-panel">
<div class="p-6 bg-surface-container-low">
<div class="bouteille-onglets" role="group" aria-label="Bouteille"><button class="bouteille-onglet actif" data-huile="sun" data-forme="marasca" aria-pressed="true" onclick="choisirBouteille('sun','marasca')" type="button"><svg viewBox="0 0 24 60" aria-hidden="true"><path d="M9 5V12L2.7 15.3Q2 15.7 2 16.5V56.5A2 2 0 0 0 4 58.5H20A2 2 0 0 0 22 56.5V16.5Q22 15.7 21.3 15.3L15 12V5Z" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><rect x="8.5" y="1" width="7" height="4" rx=".6" fill="none" stroke="currentColor" stroke-width="1.3"/><path d="M8.5 2.7H15.5" stroke="currentColor" stroke-width="1.1"/></svg><span class="bouteille-texte"><span class="bouteille-nom">Marasca</span><span class="bouteille-tailles" dir="ltr">250 ml · 500 ml · 750 ml · 1 L</span></span></button><button class="bouteille-onglet" data-huile="sun" data-forme="dorica" aria-pressed="false" onclick="choisirBouteille('sun','dorica')" type="button"><svg viewBox="0 0 24 60" aria-hidden="true"><path d="M9.6 4.5V10.5C9.6 14.2 2.4 14.6 2.4 21.2V51.8C2.4 56.4 5.6 58.7 12 58.7C18.4 58.7 21.6 56.4 21.6 51.8V21.2C21.6 14.6 14.4 14.2 14.4 10.5V4.5Z" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><rect x="9" y="0.6" width="6" height="3.9" rx=".6" fill="none" stroke="currentColor" stroke-width="1.3"/><path d="M9 2.2H15" stroke="currentColor" stroke-width="1.1"/></svg><span class="bouteille-texte"><span class="bouteille-nom">Dorica</span><span class="bouteille-tailles" dir="ltr">250 ml · 500 ml · 750 ml</span></span></button></div>
<p class="text-xs text-on-surface-variant font-body mb-5">Choisissez une bouteille, puis une contenance.</p>
<div class="flex flex-wrap items-end gap-8">
<button class="sun-size-btn size-icon-btn flex flex-col items-center gap-2" data-size="250ml" onclick="switchSunSize('250ml')" type="button"><svg class="taille-svg" height="36" viewBox="0 0 24 60" width="16" xmlns="http://www.w3.org/2000/svg"><defs><clipPath id="sun-clip-250"><path class="forme-corps" d="M9 5V12L2.7 15.3Q2 15.7 2 16.5V56.5A2 2 0 0 0 4 58.5H20A2 2 0 0 0 22 56.5V16.5Q22 15.7 21.3 15.3L15 12V5Z"></path></clipPath></defs><rect class="bottle-liquid" clip-path="url(#sun-clip-250)" fill="currentColor" height="60" width="24" x="0" y="0"></rect><path class="forme-corps" d="M9 5V12L2.7 15.3Q2 15.7 2 16.5V56.5A2 2 0 0 0 4 58.5H20A2 2 0 0 0 22 56.5V16.5Q22 15.7 21.3 15.3L15 12V5Z" fill="none" stroke="#9aa39a" stroke-width="1.6" stroke-linejoin="round"></path><rect class="forme-bouchon" x="8.5" y="1" width="7" height="4" rx=".6" fill="none" stroke="#9aa39a" stroke-width="1.4"></rect></svg><span class="text-xs font-bold font-label">250ml</span></button>
<button class="sun-size-btn size-icon-btn flex flex-col items-center gap-2" data-size="500ml" onclick="switchSunSize('500ml')" type="button"><svg class="taille-svg" height="46" viewBox="0 0 24 60" width="16" xmlns="http://www.w3.org/2000/svg"><defs><clipPath id="sun-clip-500"><path class="forme-corps" d="M9 5V12L2.7 15.3Q2 15.7 2 16.5V56.5A2 2 0 0 0 4 58.5H20A2 2 0 0 0 22 56.5V16.5Q22 15.7 21.3 15.3L15 12V5Z"></path></clipPath></defs><rect class="bottle-liquid" clip-path="url(#sun-clip-500)" fill="currentColor" height="60" width="24" x="0" y="0"></rect><path class="forme-corps" d="M9 5V12L2.7 15.3Q2 15.7 2 16.5V56.5A2 2 0 0 0 4 58.5H20A2 2 0 0 0 22 56.5V16.5Q22 15.7 21.3 15.3L15 12V5Z" fill="none" stroke="#9aa39a" stroke-width="1.6" stroke-linejoin="round"></path><rect class="forme-bouchon" x="8.5" y="1" width="7" height="4" rx=".6" fill="none" stroke="#9aa39a" stroke-width="1.4"></rect></svg><span class="text-xs font-bold font-label">500ml</span></button>
<button class="sun-size-btn size-icon-btn flex flex-col items-center gap-2" data-size="750ml" onclick="switchSunSize('750ml')" type="button"><svg class="taille-svg" height="56" viewBox="0 0 24 60" width="16" xmlns="http://www.w3.org/2000/svg"><defs><clipPath id="sun-clip-750"><path class="forme-corps" d="M9 5V12L2.7 15.3Q2 15.7 2 16.5V56.5A2 2 0 0 0 4 58.5H20A2 2 0 0 0 22 56.5V16.5Q22 15.7 21.3 15.3L15 12V5Z"></path></clipPath></defs><rect class="bottle-liquid" clip-path="url(#sun-clip-750)" fill="currentColor" height="60" width="24" x="0" y="0"></rect><path class="forme-corps" d="M9 5V12L2.7 15.3Q2 15.7 2 16.5V56.5A2 2 0 0 0 4 58.5H20A2 2 0 0 0 22 56.5V16.5Q22 15.7 21.3 15.3L15 12V5Z" fill="none" stroke="#9aa39a" stroke-width="1.6" stroke-linejoin="round"></path><rect class="forme-bouchon" x="8.5" y="1" width="7" height="4" rx=".6" fill="none" stroke="#9aa39a" stroke-width="1.4"></rect></svg><span class="text-xs font-bold font-label">750ml</span></button>
<button class="sun-size-btn size-icon-btn active flex flex-col items-center gap-2" data-size="1l" onclick="switchSunSize('1l')" type="button"><svg class="taille-svg" height="64" viewBox="0 0 24 60" width="16" xmlns="http://www.w3.org/2000/svg"><defs><clipPath id="sun-clip-1l"><path class="forme-corps" d="M9 5V12L2.7 15.3Q2 15.7 2 16.5V56.5A2 2 0 0 0 4 58.5H20A2 2 0 0 0 22 56.5V16.5Q22 15.7 21.3 15.3L15 12V5Z"></path></clipPath></defs><rect class="bottle-liquid" clip-path="url(#sun-clip-1l)" fill="currentColor" height="60" width="24" x="0" y="0"></rect><path class="forme-corps" d="M9 5V12L2.7 15.3Q2 15.7 2 16.5V56.5A2 2 0 0 0 4 58.5H20A2 2 0 0 0 22 56.5V16.5Q22 15.7 21.3 15.3L15 12V5Z" fill="none" stroke="#9aa39a" stroke-width="1.6" stroke-linejoin="round"></path><rect class="forme-bouchon" x="8.5" y="1" width="7" height="4" rx=".6" fill="none" stroke="#9aa39a" stroke-width="1.4"></rect></svg><span class="text-xs font-bold font-label">1L</span></button>
</div>
</div>
</div>
</div>
</div>
<div class="lg:col-span-7">
<span class="text-xs font-label uppercase tracking-widest text-tertiary font-bold expertise-fade-up" style="animation-delay:.1s">L'essentiel du quotidien</span>
<h1 class="text-3xl sm:text-4xl font-headline font-bold text-on-surface mt-2 expertise-fade-up" style="animation-delay:.15s">Sun Signature</h1>
<p class="text-on-surface-variant text-sm sm:text-base font-body mt-2 expertise-fade-up" style="animation-delay:.2s">Huile d'olive vierge extra — Acidité &lt; 0,8% — Extraction à froid</p>
<div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-8 expertise-fade-up" style="animation-delay:.3s">
<div class="rounded-xl bg-surface-container-low p-5 flex gap-4 transition-shadow hover:shadow-md">
<span class="material-symbols-outlined text-2xl text-primary shrink-0">water_drop</span>
<div><h3 class="text-xs font-bold uppercase tracking-wider text-on-surface font-label mb-2">En quelques mots</h3>
<p class="text-sm text-on-surface-variant font-body leading-relaxed">Une huile d'olive extra vierge douce et équilibrée, aux notes fruitées et florales. Polyvalente et expressive, elle apporte une touche méditerranéenne au quotidien.</p></div>
</div>
<div class="rounded-xl bg-surface-container-low p-5 flex gap-4 transition-shadow hover:shadow-md">
<span class="material-symbols-outlined text-2xl text-primary shrink-0">restaurant</span>
<div><h3 class="text-xs font-bold uppercase tracking-wider text-on-surface font-label mb-2">Idéal avec</h3>
<p class="text-sm text-on-surface-variant font-body leading-relaxed">Cuisine de tous les jours : salades composées, poissons grillés, légumes, pâtes, plats mijotés.</p></div>
</div>
</div>
<div class="mt-8 rounded-xl bg-surface-container-low p-5 expertise-fade-up" id="sun-taste-profile" style="animation-delay:.35s">
<h3 class="text-xs font-bold uppercase tracking-wider text-on-surface font-label mb-4">Le caractère</h3>
<p class="text-xs text-on-surface-variant font-body">Une huile douce et légère, la plus accessible de la gamme — celle qu'on utilise tous les jours, sans hésiter.</p>
<p class="text-xs italic text-tertiary font-body mt-3">« Une touche de lumière méditerranéenne dans chaque assiette. »</p>
<div class="profil-aromatique" data-niveau="3"><h3 class="text-xs font-bold uppercase tracking-wider text-on-surface font-label">Profil aromatique</h3><div class="profil-echelle"><span class="profil-borne">Doux</span><span class="profil-gouttes" role="img" aria-label="3 / 5"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.5C12 2.5 5 11 5 15.5a7 7 0 0 0 14 0C19 11 12 2.5 12 2.5z" fill="#8A9A2B"/></svg><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.5C12 2.5 5 11 5 15.5a7 7 0 0 0 14 0C19 11 12 2.5 12 2.5z" fill="#7F9128"/></svg><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.5C12 2.5 5 11 5 15.5a7 7 0 0 0 14 0C19 11 12 2.5 12 2.5z" fill="#748725"/></svg><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.5C12 2.5 5 11 5 15.5a7 7 0 0 0 14 0C19 11 12 2.5 12 2.5z" fill="none" stroke="#6A7D22" stroke-width="1.4"/></svg><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.5C12 2.5 5 11 5 15.5a7 7 0 0 0 14 0C19 11 12 2.5 12 2.5z" fill="none" stroke="#5F7320" stroke-width="1.4"/></svg></span><span class="profil-borne">Intense</span></div></div>
</div>
<div class="mt-6 rounded-2xl overflow-hidden ring-1 ring-surface-container-highest expertise-fade-up" style="animation-delay:.4s">
<button class="w-full flex items-center justify-between gap-4 px-5 py-4 bg-primary text-on-primary font-bold text-xs uppercase tracking-widest font-label transition-colors hover:bg-primary-container hover:text-on-primary-container" onclick="toggleAccordion('sun-nutrition-panel','sun-nutrition-icon')" type="button"><span>Déclaration nutritionnelle</span><span class="material-symbols-outlined transition-transform duration-300" id="sun-nutrition-icon">remove</span></button>
<div class="accordion-panel" id="sun-nutrition-panel">
<div class="p-5 bg-surface-container-low">
<p class="text-xs text-on-surface-variant font-body mb-3">Pour 100 mL</p>
<ul class="text-sm text-on-surface font-body divide-y divide-surface-container-highest">
<li class="flex justify-between gap-4 py-2"><span>Énergie</span><span class="font-bold text-right whitespace-nowrap">3389 kJ / 824 kcal</span></li>
<li class="flex justify-between gap-4 py-2"><span>Matières grasses</span><span class="font-bold text-right whitespace-nowrap">91,6 g</span></li>
<li class="flex justify-between gap-4 py-2 pl-4 text-on-surface-variant"><span>dont acides gras saturés</span><span class="font-bold text-right whitespace-nowrap">13,1 g</span></li>
<li class="flex justify-between gap-4 py-2"><span>Glucides</span><span class="font-bold text-right whitespace-nowrap">0 g</span></li>
<li class="flex justify-between gap-4 py-2 pl-4 text-on-surface-variant"><span>dont sucres</span><span class="font-bold text-right whitespace-nowrap">0 g</span></li>
<li class="flex justify-between gap-4 py-2"><span>Protéines</span><span class="font-bold text-right whitespace-nowrap">0 g</span></li>
<li class="flex justify-between gap-4 py-2"><span>Sel</span><span class="font-bold text-right whitespace-nowrap">0 g</span></li>
</ul>
</div>
</div>
</div>
</div>

</div>
</div>
</section>
<section class="w-full pb-4 bg-surface">
<div class="max-w-7xl mx-auto px-6 lg:px-12 pt-12 border-t border-surface-container-highest">
<div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4 mb-8 expertise-fade-up" style="animation-delay:.05s">
<div>
<span class="text-xs font-label uppercase tracking-[0.25em] text-tertiary font-bold">La cuisine Orzeta</span>
<h2 class="mt-2 text-xl sm:text-2xl font-headline font-bold text-on-surface">Nos recettes avec cette huile</h2>
</div>
<a class="shrink-0 inline-flex items-center gap-2 text-xs font-bold uppercase tracking-widest font-label text-primary hover:gap-3 transition-all" href="#" onclick="showDetailPage('recettes'); return false;">Voir toutes les recettes <span class="material-symbols-outlined text-base">arrow_forward</span></a>
</div>
<div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
<button class="group text-left rounded-2xl overflow-hidden bg-surface-container-low ring-1 ring-surface-container-highest hover:shadow-xl hover:-translate-y-1 transition-all duration-500 flex flex-col expertise-enter" onclick="allerVersRecette('slata-tounsia')" style="animation-delay:0.10s" type="button">
<div class="relative aspect-[4/3] overflow-hidden shrink-0">
<img alt="Slata Tounsia" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-[1.08]" src="assets/images/recette-sun-slata-tounsia.png" loading="lazy" decoding="async"/>
</div>
<div class="p-5 flex flex-col flex-1">
<h3 class="text-base font-headline font-bold text-on-surface leading-snug flex-1">Slata Tounsia</h3>
<div class="mt-4 flex items-center justify-between">
<span class="text-xs text-on-surface-variant font-label flex items-center gap-1.5"><span class="material-symbols-outlined text-base">schedule</span><span>30 min</span></span>
<span class="text-xs font-bold text-primary font-label flex items-center gap-1 group-hover:gap-2 transition-all">Voir <span class="material-symbols-outlined text-sm">arrow_forward</span></span>
</div>
</div>
</button>
<button class="group text-left rounded-2xl overflow-hidden bg-surface-container-low ring-1 ring-surface-container-highest hover:shadow-xl hover:-translate-y-1 transition-all duration-500 flex flex-col expertise-enter" onclick="allerVersRecette('daurade')" style="animation-delay:0.20s" type="button">
<div class="relative aspect-[4/3] overflow-hidden shrink-0">
<img alt="Daurade grillée à la sfaxienne" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-[1.08]" src="assets/images/recette-sun-daurade.png" loading="lazy" decoding="async"/>
</div>
<div class="p-5 flex flex-col flex-1">
<h3 class="text-base font-headline font-bold text-on-surface leading-snug flex-1">Daurade grillée à la sfaxienne</h3>
<div class="mt-4 flex items-center justify-between">
<span class="text-xs text-on-surface-variant font-label flex items-center gap-1.5"><span class="material-symbols-outlined text-base">schedule</span><span>35 min</span></span>
<span class="text-xs font-bold text-primary font-label flex items-center gap-1 group-hover:gap-2 transition-all">Voir <span class="material-symbols-outlined text-sm">arrow_forward</span></span>
</div>
</div>
</button>
<button class="group text-left rounded-2xl overflow-hidden bg-surface-container-low ring-1 ring-surface-container-highest hover:shadow-xl hover:-translate-y-1 transition-all duration-500 flex flex-col expertise-enter" onclick="allerVersRecette('fenouil-orange')" style="animation-delay:0.30s" type="button">
<div class="relative aspect-[4/3] overflow-hidden shrink-0">
<img alt="Salade de fenouil, orange et olives" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-[1.08]" src="assets/images/recette-sun-fenouil-orange.png" loading="lazy" decoding="async"/>
</div>
<div class="p-5 flex flex-col flex-1">
<h3 class="text-base font-headline font-bold text-on-surface leading-snug flex-1">Salade de fenouil, orange et olives</h3>
<div class="mt-4 flex items-center justify-between">
<span class="text-xs text-on-surface-variant font-label flex items-center gap-1.5"><span class="material-symbols-outlined text-base">schedule</span><span>15 min</span></span>
<span class="text-xs font-bold text-primary font-label flex items-center gap-1 group-hover:gap-2 transition-all">Voir <span class="material-symbols-outlined text-sm">arrow_forward</span></span>
</div>
</div>
</button>
</div>
</div>
</section>
<section class="w-full pb-16 md:pb-20 bg-surface">
<div class="max-w-7xl mx-auto px-6 lg:px-12 pt-12 border-t border-surface-container-highest">
<h2 class="text-xl sm:text-2xl font-headline font-bold text-on-surface mb-8">Produits associés</h2>
<div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
<a class="group flex items-center gap-5 p-4 rounded-2xl bg-surface-container-low hover:bg-surface-container transition-colors" href="#" onclick="showDetailPage('grove'); return false;">
<div class="relative w-20 h-20 shrink-0 rounded-xl bg-surface flex items-center justify-center overflow-hidden"><img alt="Grove Signature - Huile d'Olive Vierge Extra" class="w-full h-full object-contain p-2 transition-transform duration-500 group-hover:scale-110" src="assets/images/grove-gamme.webp" loading="lazy" decoding="async"/></div>
<div class="flex-1"><span class="text-[10px] font-bold uppercase tracking-[0.25em] font-label text-tertiary">Signature Orzeta</span><h3 class="text-base font-headline font-bold text-on-surface mt-1">Grove Signature</h3></div>
<span class="material-symbols-outlined text-on-surface-variant group-hover:text-primary group-hover:translate-x-1 transition-all">arrow_forward</span>
</a>
<a class="group flex items-center gap-5 p-4 rounded-2xl bg-surface-container-low hover:bg-surface-container transition-colors" href="#" onclick="showDetailPage('organic'); return false;">
<div class="relative w-20 h-20 shrink-0 rounded-xl bg-surface flex items-center justify-center overflow-hidden"><img alt="Organic Signature - Huile d'Olive Vierge Extra Biologique" class="w-full h-full object-contain p-2 transition-transform duration-500 group-hover:scale-110" src="assets/images/organic-gamme.webp" loading="lazy" decoding="async"/></div>
<div class="flex-1"><span class="text-[10px] font-bold uppercase tracking-[0.25em] font-label text-tertiary">Signature Orzeta</span><h3 class="text-base font-headline font-bold text-on-surface mt-1">Organic Signature</h3></div>
<span class="material-symbols-outlined text-on-surface-variant group-hover:text-primary group-hover:translate-x-1 transition-all">arrow_forward</span>
</a>
</div>
</div>
</section>
</div>
<!-- PAGE DÉTAIL : ORZETA GROVE -->
<div class="hidden w-full" id="detail-grove">
<section class="w-full py-16 bg-surface">
<div class="max-w-7xl mx-auto px-6 lg:px-12">
<div class="grid grid-cols-1 lg:grid-cols-12 gap-12">
<div class="lg:col-span-5">
<div class="relative rounded-2xl overflow-hidden bg-surface-container-low aspect-[3/4] flex items-center justify-center expertise-enter cursor-zoom-in group" onclick="openImageLightbox('grove-detail-img')" onmousemove="moveImageMagnifier(event, 'grove-detail-img', 'grove-magnifier-lens')" onmouseenter="moveImageMagnifier(event, 'grove-detail-img', 'grove-magnifier-lens'); showImageMagnifier('grove-magnifier-lens')" onmouseleave="hideImageMagnifier('grove-magnifier-lens')">
<img alt="Grove Signature - Huile d'Olive Vierge Extra" class="object-contain transition-all duration-300 group-hover:scale-[1.04]" id="grove-detail-img" src="assets/images/grove-marasca-1l.webp" style="height:100%;width:auto;" loading="lazy" decoding="async"/><div class="img-magnifier-lens" id="grove-magnifier-lens"></div>
<span class="absolute bottom-4 right-4 w-10 h-10 rounded-full bg-surface/90 backdrop-blur-sm shadow-md flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity duration-300"><span class="material-symbols-outlined text-primary text-lg">zoom_in</span></span>
</div>
<div class="mt-6 rounded-2xl overflow-hidden ring-1 ring-surface-container-highest expertise-fade-up" style="animation-delay:.25s">
<button class="w-full flex items-center justify-between gap-4 px-5 py-4 bg-primary text-on-primary font-bold text-xs uppercase tracking-widest font-label transition-colors hover:bg-primary-container hover:text-on-primary-container" onclick="toggleAccordion('grove-sizes-panel','grove-sizes-icon')" type="button"><span>Conditionnements disponibles</span><span class="material-symbols-outlined transition-transform duration-300" id="grove-sizes-icon">remove</span></button>
<div class="accordion-panel" id="grove-sizes-panel">
<div class="p-6 bg-surface-container-low">
<div class="bouteille-onglets" role="group" aria-label="Bouteille"><button class="bouteille-onglet actif" data-huile="grove" data-forme="marasca" aria-pressed="true" onclick="choisirBouteille('grove','marasca')" type="button"><svg viewBox="0 0 24 60" aria-hidden="true"><path d="M9 5V12L2.7 15.3Q2 15.7 2 16.5V56.5A2 2 0 0 0 4 58.5H20A2 2 0 0 0 22 56.5V16.5Q22 15.7 21.3 15.3L15 12V5Z" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><rect x="8.5" y="1" width="7" height="4" rx=".6" fill="none" stroke="currentColor" stroke-width="1.3"/><path d="M8.5 2.7H15.5" stroke="currentColor" stroke-width="1.1"/></svg><span class="bouteille-texte"><span class="bouteille-nom">Marasca</span><span class="bouteille-tailles" dir="ltr">250 ml · 500 ml · 750 ml · 1 L</span></span></button><button class="bouteille-onglet" data-huile="grove" data-forme="dorica" aria-pressed="false" onclick="choisirBouteille('grove','dorica')" type="button"><svg viewBox="0 0 24 60" aria-hidden="true"><path d="M9.6 4.5V10.5C9.6 14.2 2.4 14.6 2.4 21.2V51.8C2.4 56.4 5.6 58.7 12 58.7C18.4 58.7 21.6 56.4 21.6 51.8V21.2C21.6 14.6 14.4 14.2 14.4 10.5V4.5Z" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><rect x="9" y="0.6" width="6" height="3.9" rx=".6" fill="none" stroke="currentColor" stroke-width="1.3"/><path d="M9 2.2H15" stroke="currentColor" stroke-width="1.1"/></svg><span class="bouteille-texte"><span class="bouteille-nom">Dorica</span><span class="bouteille-tailles" dir="ltr">250 ml · 500 ml · 750 ml</span></span></button></div>
<p class="text-xs text-on-surface-variant font-body mb-5">Choisissez une bouteille, puis une contenance.</p>
<div class="flex flex-wrap items-end gap-8">
<button class="grove-size-btn size-icon-btn flex flex-col items-center gap-2" data-size="250ml" onclick="switchGroveSize('250ml')" type="button"><svg class="taille-svg" height="36" viewBox="0 0 24 60" width="16" xmlns="http://www.w3.org/2000/svg"><defs><clipPath id="grove-clip-250"><path class="forme-corps" d="M9 5V12L2.7 15.3Q2 15.7 2 16.5V56.5A2 2 0 0 0 4 58.5H20A2 2 0 0 0 22 56.5V16.5Q22 15.7 21.3 15.3L15 12V5Z"></path></clipPath></defs><rect class="bottle-liquid" clip-path="url(#grove-clip-250)" fill="currentColor" height="60" width="24" x="0" y="0"></rect><path class="forme-corps" d="M9 5V12L2.7 15.3Q2 15.7 2 16.5V56.5A2 2 0 0 0 4 58.5H20A2 2 0 0 0 22 56.5V16.5Q22 15.7 21.3 15.3L15 12V5Z" fill="none" stroke="#9aa39a" stroke-width="1.6" stroke-linejoin="round"></path><rect class="forme-bouchon" x="8.5" y="1" width="7" height="4" rx=".6" fill="none" stroke="#9aa39a" stroke-width="1.4"></rect></svg><span class="text-xs font-bold font-label">250ml</span></button>
<button class="grove-size-btn size-icon-btn flex flex-col items-center gap-2" data-size="500ml" onclick="switchGroveSize('500ml')" type="button"><svg class="taille-svg" height="46" viewBox="0 0 24 60" width="16" xmlns="http://www.w3.org/2000/svg"><defs><clipPath id="grove-clip-500"><path class="forme-corps" d="M9 5V12L2.7 15.3Q2 15.7 2 16.5V56.5A2 2 0 0 0 4 58.5H20A2 2 0 0 0 22 56.5V16.5Q22 15.7 21.3 15.3L15 12V5Z"></path></clipPath></defs><rect class="bottle-liquid" clip-path="url(#grove-clip-500)" fill="currentColor" height="60" width="24" x="0" y="0"></rect><path class="forme-corps" d="M9 5V12L2.7 15.3Q2 15.7 2 16.5V56.5A2 2 0 0 0 4 58.5H20A2 2 0 0 0 22 56.5V16.5Q22 15.7 21.3 15.3L15 12V5Z" fill="none" stroke="#9aa39a" stroke-width="1.6" stroke-linejoin="round"></path><rect class="forme-bouchon" x="8.5" y="1" width="7" height="4" rx=".6" fill="none" stroke="#9aa39a" stroke-width="1.4"></rect></svg><span class="text-xs font-bold font-label">500ml</span></button>
<button class="grove-size-btn size-icon-btn flex flex-col items-center gap-2" data-size="750ml" onclick="switchGroveSize('750ml')" type="button"><svg class="taille-svg" height="56" viewBox="0 0 24 60" width="16" xmlns="http://www.w3.org/2000/svg"><defs><clipPath id="grove-clip-750"><path class="forme-corps" d="M9 5V12L2.7 15.3Q2 15.7 2 16.5V56.5A2 2 0 0 0 4 58.5H20A2 2 0 0 0 22 56.5V16.5Q22 15.7 21.3 15.3L15 12V5Z"></path></clipPath></defs><rect class="bottle-liquid" clip-path="url(#grove-clip-750)" fill="currentColor" height="60" width="24" x="0" y="0"></rect><path class="forme-corps" d="M9 5V12L2.7 15.3Q2 15.7 2 16.5V56.5A2 2 0 0 0 4 58.5H20A2 2 0 0 0 22 56.5V16.5Q22 15.7 21.3 15.3L15 12V5Z" fill="none" stroke="#9aa39a" stroke-width="1.6" stroke-linejoin="round"></path><rect class="forme-bouchon" x="8.5" y="1" width="7" height="4" rx=".6" fill="none" stroke="#9aa39a" stroke-width="1.4"></rect></svg><span class="text-xs font-bold font-label">750ml</span></button>
<button class="grove-size-btn size-icon-btn active flex flex-col items-center gap-2" data-size="1l" onclick="switchGroveSize('1l')" type="button"><svg class="taille-svg" height="64" viewBox="0 0 24 60" width="16" xmlns="http://www.w3.org/2000/svg"><defs><clipPath id="grove-clip-1l"><path class="forme-corps" d="M9 5V12L2.7 15.3Q2 15.7 2 16.5V56.5A2 2 0 0 0 4 58.5H20A2 2 0 0 0 22 56.5V16.5Q22 15.7 21.3 15.3L15 12V5Z"></path></clipPath></defs><rect class="bottle-liquid" clip-path="url(#grove-clip-1l)" fill="currentColor" height="60" width="24" x="0" y="0"></rect><path class="forme-corps" d="M9 5V12L2.7 15.3Q2 15.7 2 16.5V56.5A2 2 0 0 0 4 58.5H20A2 2 0 0 0 22 56.5V16.5Q22 15.7 21.3 15.3L15 12V5Z" fill="none" stroke="#9aa39a" stroke-width="1.6" stroke-linejoin="round"></path><rect class="forme-bouchon" x="8.5" y="1" width="7" height="4" rx=".6" fill="none" stroke="#9aa39a" stroke-width="1.4"></rect></svg><span class="text-xs font-bold font-label">1L</span></button>
</div>
</div>
</div>
</div>
</div>
<div class="lg:col-span-7">
<span class="text-xs font-label uppercase tracking-widest text-tertiary font-bold expertise-fade-up" style="animation-delay:.1s">L'essentiel à table</span>
<h1 class="text-3xl sm:text-4xl font-headline font-bold text-on-surface mt-2 expertise-fade-up" style="animation-delay:.15s">Grove Signature</h1>
<p class="text-on-surface-variant text-sm sm:text-base font-body mt-2 expertise-fade-up" style="animation-delay:.2s">Huile d'olive vierge extra — Acidité &lt; 0,5% — Récolte de parcelles sélectionnées — Extraction à froid</p>
<div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-8 expertise-fade-up" style="animation-delay:.3s">
<div class="rounded-xl bg-surface-container-low p-5 flex gap-4 transition-shadow hover:shadow-md">
<span class="material-symbols-outlined text-2xl text-primary shrink-0">water_drop</span>
<div><h3 class="text-xs font-bold uppercase tracking-wider text-on-surface font-label mb-2">En quelques mots</h3>
<p class="text-sm text-on-surface-variant font-body leading-relaxed">Une huile d'olive extra vierge plus intense, au profil structuré et à l'amertume délicate. Elle enrichit les salades, les assaisonnements et les touches finales.</p></div>
</div>
<div class="rounded-xl bg-surface-container-low p-5 flex gap-4 transition-shadow hover:shadow-md">
<span class="material-symbols-outlined text-2xl text-primary shrink-0">restaurant</span>
<div><h3 class="text-xs font-bold uppercase tracking-wider text-on-surface font-label mb-2">Idéal avec</h3>
<p class="text-sm text-on-surface-variant font-body leading-relaxed">Salades, vinaigrettes et assaisonnements, légumes grillés, viandes rôties, pain rustique, touches finales.</p></div>
</div>
</div>
<div class="mt-8 rounded-xl bg-surface-container-low p-5 expertise-fade-up" id="grove-taste-profile" style="animation-delay:.35s">
<h3 class="text-xs font-bold uppercase tracking-wider text-on-surface font-label mb-4">Le caractère</h3>
<p class="text-xs text-on-surface-variant font-body">Une huile plus corsée, au caractère affirmé — pour celles et ceux qui aiment qu'une huile se remarque dans l'assiette.</p>
<p class="text-xs italic text-tertiary font-body mt-3">« La force du verger, capturée dans chaque goutte. »</p>
<div class="profil-aromatique" data-niveau="4"><h3 class="text-xs font-bold uppercase tracking-wider text-on-surface font-label">Profil aromatique</h3><div class="profil-echelle"><span class="profil-borne">Doux</span><span class="profil-gouttes" role="img" aria-label="4 / 5"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.5C12 2.5 5 11 5 15.5a7 7 0 0 0 14 0C19 11 12 2.5 12 2.5z" fill="#8A9A2B"/></svg><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.5C12 2.5 5 11 5 15.5a7 7 0 0 0 14 0C19 11 12 2.5 12 2.5z" fill="#7F9128"/></svg><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.5C12 2.5 5 11 5 15.5a7 7 0 0 0 14 0C19 11 12 2.5 12 2.5z" fill="#748725"/></svg><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.5C12 2.5 5 11 5 15.5a7 7 0 0 0 14 0C19 11 12 2.5 12 2.5z" fill="#6A7D22"/></svg><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.5C12 2.5 5 11 5 15.5a7 7 0 0 0 14 0C19 11 12 2.5 12 2.5z" fill="none" stroke="#5F7320" stroke-width="1.4"/></svg></span><span class="profil-borne">Intense</span></div></div>
</div>
<div class="mt-6 rounded-2xl overflow-hidden ring-1 ring-surface-container-highest expertise-fade-up" style="animation-delay:.4s">
<button class="w-full flex items-center justify-between gap-4 px-5 py-4 bg-primary text-on-primary font-bold text-xs uppercase tracking-widest font-label transition-colors hover:bg-primary-container hover:text-on-primary-container" onclick="toggleAccordion('grove-nutrition-panel','grove-nutrition-icon')" type="button"><span>Déclaration nutritionnelle</span><span class="material-symbols-outlined transition-transform duration-300" id="grove-nutrition-icon">remove</span></button>
<div class="accordion-panel" id="grove-nutrition-panel">
<div class="p-5 bg-surface-container-low">
<p class="text-xs text-on-surface-variant font-body mb-3">Pour 100 mL</p>
<ul class="text-sm text-on-surface font-body divide-y divide-surface-container-highest">
<li class="flex justify-between gap-4 py-2"><span>Énergie</span><span class="font-bold text-right whitespace-nowrap">3389 kJ / 824 kcal</span></li>
<li class="flex justify-between gap-4 py-2"><span>Matières grasses</span><span class="font-bold text-right whitespace-nowrap">91,6 g</span></li>
<li class="flex justify-between gap-4 py-2 pl-4 text-on-surface-variant"><span>dont acides gras saturés</span><span class="font-bold text-right whitespace-nowrap">13,1 g</span></li>
<li class="flex justify-between gap-4 py-2"><span>Glucides</span><span class="font-bold text-right whitespace-nowrap">0 g</span></li>
<li class="flex justify-between gap-4 py-2 pl-4 text-on-surface-variant"><span>dont sucres</span><span class="font-bold text-right whitespace-nowrap">0 g</span></li>
<li class="flex justify-between gap-4 py-2"><span>Protéines</span><span class="font-bold text-right whitespace-nowrap">0 g</span></li>
<li class="flex justify-between gap-4 py-2"><span>Sel</span><span class="font-bold text-right whitespace-nowrap">0 g</span></li>
</ul>
</div>
</div>
</div>
</div>

</div>
</div>
</section>
<section class="w-full pb-4 bg-surface">
<div class="max-w-7xl mx-auto px-6 lg:px-12 pt-12 border-t border-surface-container-highest">
<div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4 mb-8 expertise-fade-up" style="animation-delay:.05s">
<div>
<span class="text-xs font-label uppercase tracking-[0.25em] text-tertiary font-bold">La cuisine Orzeta</span>
<h2 class="mt-2 text-xl sm:text-2xl font-headline font-bold text-on-surface">Nos recettes avec cette huile</h2>
</div>
<a class="shrink-0 inline-flex items-center gap-2 text-xs font-bold uppercase tracking-widest font-label text-primary hover:gap-3 transition-all" href="#" onclick="showDetailPage('recettes'); return false;">Voir toutes les recettes <span class="material-symbols-outlined text-base">arrow_forward</span></a>
</div>
<div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
<button class="group text-left rounded-2xl overflow-hidden bg-surface-container-low ring-1 ring-surface-container-highest hover:shadow-xl hover:-translate-y-1 transition-all duration-500 flex flex-col expertise-enter" onclick="allerVersRecette('poulpe')" style="animation-delay:0.10s" type="button">
<div class="relative aspect-[4/3] overflow-hidden shrink-0">
<img alt="Poulpe grillé, citron et persil" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-[1.08]" src="assets/images/recette-grove-poulpe.png" loading="lazy" decoding="async"/>
</div>
<div class="p-5 flex flex-col flex-1">
<h3 class="text-base font-headline font-bold text-on-surface leading-snug flex-1">Poulpe grillé, citron et persil</h3>
<div class="mt-4 flex items-center justify-between">
<span class="text-xs text-on-surface-variant font-label flex items-center gap-1.5"><span class="material-symbols-outlined text-base">schedule</span><span>1 h 30</span></span>
<span class="text-xs font-bold text-primary font-label flex items-center gap-1 group-hover:gap-2 transition-all">Voir <span class="material-symbols-outlined text-sm">arrow_forward</span></span>
</div>
</div>
</button>
<button class="group text-left rounded-2xl overflow-hidden bg-surface-container-low ring-1 ring-surface-container-highest hover:shadow-xl hover:-translate-y-1 transition-all duration-500 flex flex-col expertise-enter" onclick="allerVersRecette('bruschetta')" style="animation-delay:0.20s" type="button">
<div class="relative aspect-[4/3] overflow-hidden shrink-0">
<img alt="Bruschetta tomate & basilic" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-[1.08]" src="assets/images/recette-grove-bruschetta.png" loading="lazy" decoding="async"/>
</div>
<div class="p-5 flex flex-col flex-1">
<h3 class="text-base font-headline font-bold text-on-surface leading-snug flex-1">Bruschetta tomate & basilic</h3>
<div class="mt-4 flex items-center justify-between">
<span class="text-xs text-on-surface-variant font-label flex items-center gap-1.5"><span class="material-symbols-outlined text-base">schedule</span><span>20 min</span></span>
<span class="text-xs font-bold text-primary font-label flex items-center gap-1 group-hover:gap-2 transition-all">Voir <span class="material-symbols-outlined text-sm">arrow_forward</span></span>
</div>
</div>
</button>
<button class="group text-left rounded-2xl overflow-hidden bg-surface-container-low ring-1 ring-surface-container-highest hover:shadow-xl hover:-translate-y-1 transition-all duration-500 flex flex-col expertise-enter" onclick="allerVersRecette('agneau')" style="animation-delay:0.30s" type="button">
<div class="relative aspect-[4/3] overflow-hidden shrink-0">
<img alt="Gigot d'agneau rôti aux herbes" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-[1.08]" src="assets/images/recette-grove-agneau.png" loading="lazy" decoding="async"/>
</div>
<div class="p-5 flex flex-col flex-1">
<h3 class="text-base font-headline font-bold text-on-surface leading-snug flex-1">Gigot d'agneau rôti aux herbes</h3>
<div class="mt-4 flex items-center justify-between">
<span class="text-xs text-on-surface-variant font-label flex items-center gap-1.5"><span class="material-symbols-outlined text-base">schedule</span><span>1 h 30</span></span>
<span class="text-xs font-bold text-primary font-label flex items-center gap-1 group-hover:gap-2 transition-all">Voir <span class="material-symbols-outlined text-sm">arrow_forward</span></span>
</div>
</div>
</button>
</div>
</div>
</section>
<section class="w-full pb-16 md:pb-20 bg-surface">
<div class="max-w-7xl mx-auto px-6 lg:px-12 pt-12 border-t border-surface-container-highest">
<h2 class="text-xl sm:text-2xl font-headline font-bold text-on-surface mb-8">Produits associés</h2>
<div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
<a class="group flex items-center gap-5 p-4 rounded-2xl bg-surface-container-low hover:bg-surface-container transition-colors" href="#" onclick="showDetailPage('sun'); return false;">
<div class="relative w-20 h-20 shrink-0 rounded-xl bg-surface flex items-center justify-center overflow-hidden"><img alt="Sun Signature - Huile d'Olive Vierge Extra" class="w-full h-full object-contain p-2 transition-transform duration-500 group-hover:scale-110" src="assets/images/sun-gamme.webp" loading="lazy" decoding="async"/></div>
<div class="flex-1"><span class="text-[10px] font-bold uppercase tracking-[0.25em] font-label text-tertiary">Signature Orzeta</span><h3 class="text-base font-headline font-bold text-on-surface mt-1">Sun Signature</h3></div>
<span class="material-symbols-outlined text-on-surface-variant group-hover:text-primary group-hover:translate-x-1 transition-all">arrow_forward</span>
</a>
<a class="group flex items-center gap-5 p-4 rounded-2xl bg-surface-container-low hover:bg-surface-container transition-colors" href="#" onclick="showDetailPage('organic'); return false;">
<div class="relative w-20 h-20 shrink-0 rounded-xl bg-surface flex items-center justify-center overflow-hidden"><img alt="Organic Signature - Huile d'Olive Vierge Extra Biologique" class="w-full h-full object-contain p-2 transition-transform duration-500 group-hover:scale-110" src="assets/images/organic-gamme.webp" loading="lazy" decoding="async"/></div>
<div class="flex-1"><span class="text-[10px] font-bold uppercase tracking-[0.25em] font-label text-tertiary">Signature Orzeta</span><h3 class="text-base font-headline font-bold text-on-surface mt-1">Organic Signature</h3></div>
<span class="material-symbols-outlined text-on-surface-variant group-hover:text-primary group-hover:translate-x-1 transition-all">arrow_forward</span>
</a>
</div>
</div>
</section>
</div>
<!-- PAGE DÉTAIL : ORZETA ORGANIC (données issues de la fiche technique) -->
<div class="hidden w-full" id="detail-organic">
<section class="w-full py-16 bg-surface">
<div class="max-w-7xl mx-auto px-6 lg:px-12">
<div class="grid grid-cols-1 lg:grid-cols-12 gap-12">
<div class="lg:col-span-5">
<div class="relative rounded-2xl overflow-hidden bg-surface-container-low aspect-[3/4] flex items-center justify-center expertise-enter cursor-zoom-in group" onclick="openImageLightbox('organic-detail-img')" onmousemove="moveImageMagnifier(event, 'organic-detail-img', 'organic-magnifier-lens')" onmouseenter="moveImageMagnifier(event, 'organic-detail-img', 'organic-magnifier-lens'); showImageMagnifier('organic-magnifier-lens')" onmouseleave="hideImageMagnifier('organic-magnifier-lens')">
<img alt="Organic Signature - Huile d'Olive Vierge Extra Biologique" class="object-contain transition-all duration-300 group-hover:scale-[1.04]" id="organic-detail-img" src="assets/images/organic-marasca-1l.webp" style="height:100%;width:auto;" loading="lazy" decoding="async"/><div class="img-magnifier-lens" id="organic-magnifier-lens"></div>
<span class="absolute bottom-4 right-4 w-10 h-10 rounded-full bg-surface/90 backdrop-blur-sm shadow-md flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity duration-300"><span class="material-symbols-outlined text-primary text-lg">zoom_in</span></span>
</div>
<div class="mt-6 rounded-2xl overflow-hidden ring-1 ring-surface-container-highest expertise-fade-up" style="animation-delay:.25s">
<button class="w-full flex items-center justify-between gap-4 px-5 py-4 bg-primary text-on-primary font-bold text-xs uppercase tracking-widest font-label transition-colors hover:bg-primary-container hover:text-on-primary-container" onclick="toggleAccordion('organic-sizes-panel','organic-sizes-icon')" type="button"><span>Conditionnements disponibles</span><span class="material-symbols-outlined transition-transform duration-300" id="organic-sizes-icon">remove</span></button>
<div class="accordion-panel" id="organic-sizes-panel">
<div class="p-6 bg-surface-container-low">
<div class="bouteille-onglets" role="group" aria-label="Bouteille"><button class="bouteille-onglet actif" data-huile="organic" data-forme="marasca" aria-pressed="true" onclick="choisirBouteille('organic','marasca')" type="button"><svg viewBox="0 0 24 60" aria-hidden="true"><path d="M9 5V12L2.7 15.3Q2 15.7 2 16.5V56.5A2 2 0 0 0 4 58.5H20A2 2 0 0 0 22 56.5V16.5Q22 15.7 21.3 15.3L15 12V5Z" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><rect x="8.5" y="1" width="7" height="4" rx=".6" fill="none" stroke="currentColor" stroke-width="1.3"/><path d="M8.5 2.7H15.5" stroke="currentColor" stroke-width="1.1"/></svg><span class="bouteille-texte"><span class="bouteille-nom">Marasca</span><span class="bouteille-tailles" dir="ltr">250 ml · 500 ml · 750 ml · 1 L</span></span></button><button class="bouteille-onglet" data-huile="organic" data-forme="dorica" aria-pressed="false" onclick="choisirBouteille('organic','dorica')" type="button"><svg viewBox="0 0 24 60" aria-hidden="true"><path d="M9.6 4.5V10.5C9.6 14.2 2.4 14.6 2.4 21.2V51.8C2.4 56.4 5.6 58.7 12 58.7C18.4 58.7 21.6 56.4 21.6 51.8V21.2C21.6 14.6 14.4 14.2 14.4 10.5V4.5Z" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><rect x="9" y="0.6" width="6" height="3.9" rx=".6" fill="none" stroke="currentColor" stroke-width="1.3"/><path d="M9 2.2H15" stroke="currentColor" stroke-width="1.1"/></svg><span class="bouteille-texte"><span class="bouteille-nom">Dorica</span><span class="bouteille-tailles" dir="ltr">250 ml · 500 ml · 750 ml</span></span></button></div>
<p class="text-xs text-on-surface-variant font-body mb-5">Choisissez une bouteille, puis une contenance.</p>
<div class="flex flex-wrap items-end gap-8">
<button class="organic-size-btn size-icon-btn flex flex-col items-center gap-2" data-size="250ml" onclick="switchOrganicSize('250ml')" type="button"><svg class="taille-svg" height="36" viewBox="0 0 24 60" width="16" xmlns="http://www.w3.org/2000/svg"><defs><clipPath id="organic-clip-250"><path class="forme-corps" d="M9 5V12L2.7 15.3Q2 15.7 2 16.5V56.5A2 2 0 0 0 4 58.5H20A2 2 0 0 0 22 56.5V16.5Q22 15.7 21.3 15.3L15 12V5Z"></path></clipPath></defs><rect class="bottle-liquid" clip-path="url(#organic-clip-250)" fill="currentColor" height="60" width="24" x="0" y="0"></rect><path class="forme-corps" d="M9 5V12L2.7 15.3Q2 15.7 2 16.5V56.5A2 2 0 0 0 4 58.5H20A2 2 0 0 0 22 56.5V16.5Q22 15.7 21.3 15.3L15 12V5Z" fill="none" stroke="#9aa39a" stroke-width="1.6" stroke-linejoin="round"></path><rect class="forme-bouchon" x="8.5" y="1" width="7" height="4" rx=".6" fill="none" stroke="#9aa39a" stroke-width="1.4"></rect></svg><span class="text-xs font-bold font-label">250ml</span></button>
<button class="organic-size-btn size-icon-btn flex flex-col items-center gap-2" data-size="500ml" onclick="switchOrganicSize('500ml')" type="button"><svg class="taille-svg" height="46" viewBox="0 0 24 60" width="16" xmlns="http://www.w3.org/2000/svg"><defs><clipPath id="organic-clip-500"><path class="forme-corps" d="M9 5V12L2.7 15.3Q2 15.7 2 16.5V56.5A2 2 0 0 0 4 58.5H20A2 2 0 0 0 22 56.5V16.5Q22 15.7 21.3 15.3L15 12V5Z"></path></clipPath></defs><rect class="bottle-liquid" clip-path="url(#organic-clip-500)" fill="currentColor" height="60" width="24" x="0" y="0"></rect><path class="forme-corps" d="M9 5V12L2.7 15.3Q2 15.7 2 16.5V56.5A2 2 0 0 0 4 58.5H20A2 2 0 0 0 22 56.5V16.5Q22 15.7 21.3 15.3L15 12V5Z" fill="none" stroke="#9aa39a" stroke-width="1.6" stroke-linejoin="round"></path><rect class="forme-bouchon" x="8.5" y="1" width="7" height="4" rx=".6" fill="none" stroke="#9aa39a" stroke-width="1.4"></rect></svg><span class="text-xs font-bold font-label">500ml</span></button>
<button class="organic-size-btn size-icon-btn flex flex-col items-center gap-2" data-size="750ml" onclick="switchOrganicSize('750ml')" type="button"><svg class="taille-svg" height="56" viewBox="0 0 24 60" width="16" xmlns="http://www.w3.org/2000/svg"><defs><clipPath id="organic-clip-750"><path class="forme-corps" d="M9 5V12L2.7 15.3Q2 15.7 2 16.5V56.5A2 2 0 0 0 4 58.5H20A2 2 0 0 0 22 56.5V16.5Q22 15.7 21.3 15.3L15 12V5Z"></path></clipPath></defs><rect class="bottle-liquid" clip-path="url(#organic-clip-750)" fill="currentColor" height="60" width="24" x="0" y="0"></rect><path class="forme-corps" d="M9 5V12L2.7 15.3Q2 15.7 2 16.5V56.5A2 2 0 0 0 4 58.5H20A2 2 0 0 0 22 56.5V16.5Q22 15.7 21.3 15.3L15 12V5Z" fill="none" stroke="#9aa39a" stroke-width="1.6" stroke-linejoin="round"></path><rect class="forme-bouchon" x="8.5" y="1" width="7" height="4" rx=".6" fill="none" stroke="#9aa39a" stroke-width="1.4"></rect></svg><span class="text-xs font-bold font-label">750ml</span></button>
<button class="organic-size-btn size-icon-btn active flex flex-col items-center gap-2" data-size="1l" onclick="switchOrganicSize('1l')" type="button"><svg class="taille-svg" height="64" viewBox="0 0 24 60" width="16" xmlns="http://www.w3.org/2000/svg"><defs><clipPath id="organic-clip-1l"><path class="forme-corps" d="M9 5V12L2.7 15.3Q2 15.7 2 16.5V56.5A2 2 0 0 0 4 58.5H20A2 2 0 0 0 22 56.5V16.5Q22 15.7 21.3 15.3L15 12V5Z"></path></clipPath></defs><rect class="bottle-liquid" clip-path="url(#organic-clip-1l)" fill="currentColor" height="60" width="24" x="0" y="0"></rect><path class="forme-corps" d="M9 5V12L2.7 15.3Q2 15.7 2 16.5V56.5A2 2 0 0 0 4 58.5H20A2 2 0 0 0 22 56.5V16.5Q22 15.7 21.3 15.3L15 12V5Z" fill="none" stroke="#9aa39a" stroke-width="1.6" stroke-linejoin="round"></path><rect class="forme-bouchon" x="8.5" y="1" width="7" height="4" rx=".6" fill="none" stroke="#9aa39a" stroke-width="1.4"></rect></svg><span class="text-xs font-bold font-label">1L</span></button>
</div>
</div>
</div>
</div>
</div>
<div class="lg:col-span-7">
<span class="text-xs font-label uppercase tracking-widest text-tertiary font-bold expertise-fade-up" style="animation-delay:.1s">L'essentiel bio</span>
<h1 class="text-3xl sm:text-4xl font-headline font-bold text-on-surface mt-2 expertise-fade-up" style="animation-delay:.15s">Organic Signature</h1>
<p class="text-on-surface-variant text-sm sm:text-base font-body mt-2 expertise-fade-up" style="animation-delay:.2s">Huile d'olive vierge extra multivariétale biologique — Acidité ≤ 0,3% — Extraction à froid</p>
<div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-8 expertise-fade-up" style="animation-delay:.3s">
<div class="rounded-xl bg-surface-container-low p-5 flex gap-4 transition-shadow hover:shadow-md">
<span class="material-symbols-outlined text-2xl text-primary shrink-0">water_drop</span>
<div><h3 class="text-xs font-bold uppercase tracking-wider text-on-surface font-label mb-2">En quelques mots</h3>
<p class="text-sm text-on-surface-variant font-body leading-relaxed">Une huile d'olive extra vierge certifiée biologique, pour ceux qui privilégient l'authenticité, la traçabilité et un approvisionnement responsable. Une expression pure et équilibrée.</p>
<p class="mt-3 text-sm text-on-surface-variant font-body leading-relaxed">La nature, sans artifice. Huile d'olive vierge extra issue de l'agriculture biologique, obtenue directement du fruit de l'olivier par des procédés exclusivement mécaniques. Assemblage multivariétal (Chemlali, Koroneiki, Arbosana), 100% olives tunisiennes, sans pesticides ni intrants chimiques de synthèse. Extraction à froid (max. 27°C), sans ajout d'eau.</p></div>
</div>
<div class="rounded-xl bg-surface-container-low p-5 flex gap-4 transition-shadow hover:shadow-md">
<span class="material-symbols-outlined text-2xl text-primary shrink-0">restaurant</span>
<div><h3 class="text-xs font-bold uppercase tracking-wider text-on-surface font-label mb-2">Idéal avec</h3>
<p class="text-sm text-on-surface-variant font-body leading-relaxed">Cuisine premium au quotidien, recettes d'inspiration méditerranéenne, finition de plats — à savourer de préférence crue pour préserver tous ses polyphénols.</p></div>
</div>
</div>
<div class="mt-8 rounded-xl bg-surface-container-low p-5 expertise-fade-up" id="organic-taste-profile" style="animation-delay:.4s">
<h3 class="text-xs font-bold uppercase tracking-wider text-on-surface font-label mb-4">Le caractère</h3>
<p class="text-xs text-on-surface-variant font-body">Une huile fraîche et pure, issue de l'agriculture biologique. Acidité ≤ 0,3% · Extraction à froid · Conservation 18 à 24 mois.</p>
<p class="text-xs italic text-tertiary font-body mt-3">« Rien de superflu. Simplement de l'huile d'olive vierge extra, telle que la nature l'a voulue. »</p>
<div class="profil-aromatique" data-niveau="3"><h3 class="text-xs font-bold uppercase tracking-wider text-on-surface font-label">Profil aromatique</h3><div class="profil-echelle"><span class="profil-borne">Doux</span><span class="profil-gouttes" role="img" aria-label="3 / 5"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.5C12 2.5 5 11 5 15.5a7 7 0 0 0 14 0C19 11 12 2.5 12 2.5z" fill="#8DB255"/></svg><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.5C12 2.5 5 11 5 15.5a7 7 0 0 0 14 0C19 11 12 2.5 12 2.5z" fill="#74A046"/></svg><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.5C12 2.5 5 11 5 15.5a7 7 0 0 0 14 0C19 11 12 2.5 12 2.5z" fill="#5C8D3A"/></svg><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.5C12 2.5 5 11 5 15.5a7 7 0 0 0 14 0C19 11 12 2.5 12 2.5z" fill="none" stroke="#447A2E" stroke-width="1.4"/></svg><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.5C12 2.5 5 11 5 15.5a7 7 0 0 0 14 0C19 11 12 2.5 12 2.5z" fill="none" stroke="#2E6624" stroke-width="1.4"/></svg></span><span class="profil-borne">Intense</span></div></div>
</div>
<div class="mt-6 rounded-2xl overflow-hidden ring-1 ring-surface-container-highest expertise-fade-up" style="animation-delay:.4s">
<button class="w-full flex items-center justify-between gap-4 px-5 py-4 bg-primary text-on-primary font-bold text-xs uppercase tracking-widest font-label transition-colors hover:bg-primary-container hover:text-on-primary-container" onclick="toggleAccordion('organic-nutrition-panel','organic-nutrition-icon')" type="button"><span>Déclaration nutritionnelle</span><span class="material-symbols-outlined transition-transform duration-300" id="organic-nutrition-icon">remove</span></button>
<div class="accordion-panel" id="organic-nutrition-panel">
<div class="p-5 bg-surface-container-low">
<p class="text-xs text-on-surface-variant font-body mb-3">Pour 100 mL</p>
<ul class="text-sm text-on-surface font-body divide-y divide-surface-container-highest">
<li class="flex justify-between gap-4 py-2"><span>Énergie</span><span class="font-bold text-right whitespace-nowrap">3389 kJ / 824 kcal</span></li>
<li class="flex justify-between gap-4 py-2"><span>Matières grasses</span><span class="font-bold text-right whitespace-nowrap">91,6 g</span></li>
<li class="flex justify-between gap-4 py-2 pl-4 text-on-surface-variant"><span>dont acides gras saturés</span><span class="font-bold text-right whitespace-nowrap">13,1 g</span></li>
<li class="flex justify-between gap-4 py-2"><span>Glucides</span><span class="font-bold text-right whitespace-nowrap">0 g</span></li>
<li class="flex justify-between gap-4 py-2 pl-4 text-on-surface-variant"><span>dont sucres</span><span class="font-bold text-right whitespace-nowrap">0 g</span></li>
<li class="flex justify-between gap-4 py-2"><span>Protéines</span><span class="font-bold text-right whitespace-nowrap">0 g</span></li>
<li class="flex justify-between gap-4 py-2"><span>Sel</span><span class="font-bold text-right whitespace-nowrap">0 g</span></li>
</ul>
</div>
</div>
</div>
</div>
</div>
</div>
</section>
<section class="w-full pb-4 bg-surface">
<div class="max-w-7xl mx-auto px-6 lg:px-12 pt-12 border-t border-surface-container-highest">
<div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4 mb-8 expertise-fade-up" style="animation-delay:.05s">
<div>
<span class="text-xs font-label uppercase tracking-[0.25em] text-tertiary font-bold">La cuisine Orzeta</span>
<h2 class="mt-2 text-xl sm:text-2xl font-headline font-bold text-on-surface">Nos recettes avec cette huile</h2>
</div>
<a class="shrink-0 inline-flex items-center gap-2 text-xs font-bold uppercase tracking-widest font-label text-primary hover:gap-3 transition-all" href="#" onclick="showDetailPage('recettes'); return false;">Voir toutes les recettes <span class="material-symbols-outlined text-base">arrow_forward</span></a>
</div>
<div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
<button class="group text-left rounded-2xl overflow-hidden bg-surface-container-low ring-1 ring-surface-container-highest hover:shadow-xl hover:-translate-y-1 transition-all duration-500 flex flex-col expertise-enter" onclick="allerVersRecette('caprese')" style="animation-delay:0.10s" type="button">
<div class="relative aspect-[4/3] overflow-hidden shrink-0">
<img alt="Caprese à l'huile d'olive" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-[1.08]" src="assets/images/recette-organic-caprese.png" loading="lazy" decoding="async"/>
</div>
<div class="p-5 flex flex-col flex-1">
<h3 class="text-base font-headline font-bold text-on-surface leading-snug flex-1">Caprese à l'huile d'olive</h3>
<div class="mt-4 flex items-center justify-between">
<span class="text-xs text-on-surface-variant font-label flex items-center gap-1.5"><span class="material-symbols-outlined text-base">schedule</span><span>10 min</span></span>
<span class="text-xs font-bold text-primary font-label flex items-center gap-1 group-hover:gap-2 transition-all">Voir <span class="material-symbols-outlined text-sm">arrow_forward</span></span>
</div>
</div>
</button>
<button class="group text-left rounded-2xl overflow-hidden bg-surface-container-low ring-1 ring-surface-container-highest hover:shadow-xl hover:-translate-y-1 transition-all duration-500 flex flex-col expertise-enter" onclick="allerVersRecette('houmous')" style="animation-delay:0.20s" type="button">
<div class="relative aspect-[4/3] overflow-hidden shrink-0">
<img alt="Houmous crémeux à l'huile d'olive" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-[1.08]" src="assets/images/recette-organic-houmous.png" loading="lazy" decoding="async"/>
</div>
<div class="p-5 flex flex-col flex-1">
<h3 class="text-base font-headline font-bold text-on-surface leading-snug flex-1">Houmous crémeux à l'huile d'olive</h3>
<div class="mt-4 flex items-center justify-between">
<span class="text-xs text-on-surface-variant font-label flex items-center gap-1.5"><span class="material-symbols-outlined text-base">schedule</span><span>1 h 10</span></span>
<span class="text-xs font-bold text-primary font-label flex items-center gap-1 group-hover:gap-2 transition-all">Voir <span class="material-symbols-outlined text-sm">arrow_forward</span></span>
</div>
</div>
</button>
<button class="group text-left rounded-2xl overflow-hidden bg-surface-container-low ring-1 ring-surface-container-highest hover:shadow-xl hover:-translate-y-1 transition-all duration-500 flex flex-col expertise-enter" onclick="allerVersRecette('tartare-saumon')" style="animation-delay:0.30s" type="button">
<div class="relative aspect-[4/3] overflow-hidden shrink-0">
<img alt="Tartare de saumon, citron et huile d'olive" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-[1.08]" src="assets/images/recette-organic-tartare-saumon.png" loading="lazy" decoding="async"/>
</div>
<div class="p-5 flex flex-col flex-1">
<h3 class="text-base font-headline font-bold text-on-surface leading-snug flex-1">Tartare de saumon, citron et huile d'olive</h3>
<div class="mt-4 flex items-center justify-between">
<span class="text-xs text-on-surface-variant font-label flex items-center gap-1.5"><span class="material-symbols-outlined text-base">schedule</span><span>20 min</span></span>
<span class="text-xs font-bold text-primary font-label flex items-center gap-1 group-hover:gap-2 transition-all">Voir <span class="material-symbols-outlined text-sm">arrow_forward</span></span>
</div>
</div>
</button>
</div>
</div>
</section>
<section class="w-full pb-16 md:pb-20 bg-surface">
<div class="max-w-7xl mx-auto px-6 lg:px-12 pt-12 border-t border-surface-container-highest">
<h2 class="text-xl sm:text-2xl font-headline font-bold text-on-surface mb-8">Produits associés</h2>
<div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
<a class="group flex items-center gap-5 p-4 rounded-2xl bg-surface-container-low hover:bg-surface-container transition-colors" href="#" onclick="showDetailPage('sun'); return false;">
<div class="relative w-20 h-20 shrink-0 rounded-xl bg-surface flex items-center justify-center overflow-hidden"><img alt="Sun Signature - Huile d'Olive Vierge Extra" class="w-full h-full object-contain p-2 transition-transform duration-500 group-hover:scale-110" src="assets/images/sun-gamme.webp" loading="lazy" decoding="async"/></div>
<div class="flex-1"><span class="text-[10px] font-bold uppercase tracking-[0.25em] font-label text-tertiary">Signature Orzeta</span><h3 class="text-base font-headline font-bold text-on-surface mt-1">Sun Signature</h3></div>
<span class="material-symbols-outlined text-on-surface-variant group-hover:text-primary group-hover:translate-x-1 transition-all">arrow_forward</span>
</a>
<a class="group flex items-center gap-5 p-4 rounded-2xl bg-surface-container-low hover:bg-surface-container transition-colors" href="#" onclick="showDetailPage('grove'); return false;">
<div class="relative w-20 h-20 shrink-0 rounded-xl bg-surface flex items-center justify-center overflow-hidden"><img alt="Grove Signature - Huile d'Olive Vierge Extra" class="w-full h-full object-contain p-2 transition-transform duration-500 group-hover:scale-110" src="assets/images/grove-gamme.webp" loading="lazy" decoding="async"/></div>
<div class="flex-1"><span class="text-[10px] font-bold uppercase tracking-[0.25em] font-label text-tertiary">Signature Orzeta</span><h3 class="text-base font-headline font-bold text-on-surface mt-1">Grove Signature</h3></div>
<span class="material-symbols-outlined text-on-surface-variant group-hover:text-primary group-hover:translate-x-1 transition-all">arrow_forward</span>
</a>
</div>
</div>
</section>
</div>
<div class="hidden w-full" id="detail-produits">
<!-- BANNIÈRE -->
<section class="relative w-full h-[52vh] min-h-[340px] max-h-[540px] overflow-hidden">
<div class="absolute inset-0">
<img data-banniere="1" alt="La collection Orzeta" class="w-full h-full object-cover ken-burns-slow" src="assets/images/produits-banniere.png" loading="lazy" decoding="async"/>
</div>
<div class="absolute inset-0 bg-gradient-to-r from-black/75 via-black/30 to-black/45"></div>
<div class="relative z-10 h-full max-w-7xl mx-auto px-6 lg:px-12 flex flex-col justify-center">
<div class="max-w-lg">
<span class="text-xs font-bold uppercase tracking-[0.3em] font-label text-white/80 expertise-fade-up" style="animation-delay:.05s">La Collection</span>
<h1 class="mt-4 font-headline text-4xl sm:text-5xl lg:text-6xl font-bold text-white leading-tight expertise-fade-up" style="text-shadow:0 2px 20px rgba(0,0,0,.5);animation-delay:.15s">Nos Produits</h1>
<p class="mt-5 text-white/90 text-sm sm:text-base font-body leading-relaxed expertise-fade-up" style="text-shadow:0 1px 8px rgba(0,0,0,.45);animation-delay:.25s">D'une même terre baignée de soleil naissent trois expressions distinctes, qui révèlent chacune une facette de notre héritage oléicole.</p>
</div>
</div>
</section>
<section class="w-full py-16 md:py-20 bg-surface">
<div class="max-w-7xl mx-auto px-6 lg:px-12">
<div class="grid grid-cols-1 md:grid-cols-3 gap-7 lg:gap-9">
<!-- Carte : Sun Signature -->
<button type="button" onclick="showDetailPage('sun'); return false;" class="group relative flex flex-col w-full text-center cursor-pointer rounded-[2rem] bg-surface ring-1 ring-surface-container-highest hover:ring-tertiary/25 shadow-[0_2px_20px_rgba(46,50,48,0.04)] hover:shadow-[0_24px_60px_rgba(46,50,48,0.12)] transition-all duration-700 expertise-enter" style="animation-delay:0.15s">
<div class="relative h-[340px] sm:h-[380px] flex items-end justify-center overflow-hidden">
<div class="absolute inset-0 flex items-center justify-center pointer-events-none">
<div class="w-44 h-44 rounded-full bg-tertiary/12 blur-3xl transition-transform duration-1000 group-hover:scale-150"></div>
</div>
<div class="absolute bottom-8 left-1/2 -translate-x-1/2 w-40 h-4 rounded-[50%] bg-on-surface/15 blur-xl transition-all duration-700 group-hover:w-32 group-hover:opacity-70"></div>
<img alt="Sun Signature - Huile d'Olive Vierge Extra" class="relative z-10 h-[88%] w-auto object-contain transition-transform duration-700 ease-out group-hover:-translate-y-3 group-hover:scale-[1.04]" src="assets/images/sun-marasca-1l.webp" loading="lazy" decoding="async"/>
</div>
<div class="px-8 pb-10 pt-6 text-center flex flex-col flex-1">
<span class="text-[10px] font-bold uppercase tracking-[0.3em] font-label text-tertiary">L'essentiel du quotidien</span>
<h2 class="mt-3 text-2xl sm:text-[1.7rem] font-headline font-bold text-on-surface leading-tight">Sun Signature</h2>
<div class="w-10 h-px bg-tertiary/40 mx-auto my-5"></div>
<p class="text-sm text-on-surface-variant font-body leading-relaxed flex-1">Une huile d'olive extra vierge douce et équilibrée, aux notes fruitées et florales. Polyvalente et expressive, elle apporte une touche méditerranéenne au quotidien.</p>
<p class="mt-6 text-[11px] font-label uppercase tracking-[0.2em] text-on-surface-variant/70">250ml · 500ml · 750ml · 1L</p>
<p class="mt-2 text-[10px] font-bold uppercase tracking-[0.18em] text-on-surface-variant/70 font-label">2 bouteilles : Marasca &amp; Dorica</p>
<span class="mt-7 inline-flex items-center justify-center gap-2 text-xs font-bold uppercase tracking-[0.2em] font-label text-primary group-hover:gap-3 transition-all">
<span class="border-b border-primary/30 group-hover:border-primary pb-0.5 transition-colors">En savoir plus</span>
<span class="material-symbols-outlined text-base">arrow_forward</span>
</span>
</div>
</button>
<!-- Carte : Grove Signature -->
<button type="button" onclick="showDetailPage('grove'); return false;" class="group relative flex flex-col w-full text-center cursor-pointer rounded-[2rem] bg-surface ring-1 ring-surface-container-highest hover:ring-tertiary/25 shadow-[0_2px_20px_rgba(46,50,48,0.04)] hover:shadow-[0_24px_60px_rgba(46,50,48,0.12)] transition-all duration-700 expertise-enter" style="animation-delay:0.27s">
<div class="relative h-[340px] sm:h-[380px] flex items-end justify-center overflow-hidden">
<div class="absolute inset-0 flex items-center justify-center pointer-events-none">
<div class="w-44 h-44 rounded-full bg-tertiary/12 blur-3xl transition-transform duration-1000 group-hover:scale-150"></div>
</div>
<div class="absolute bottom-8 left-1/2 -translate-x-1/2 w-40 h-4 rounded-[50%] bg-on-surface/15 blur-xl transition-all duration-700 group-hover:w-32 group-hover:opacity-70"></div>
<img alt="Grove Signature - Huile d'Olive Vierge Extra" class="relative z-10 h-[88%] w-auto object-contain transition-transform duration-700 ease-out group-hover:-translate-y-3 group-hover:scale-[1.04]" src="assets/images/grove-marasca-1l.webp" loading="lazy" decoding="async"/>
</div>
<div class="px-8 pb-10 pt-6 text-center flex flex-col flex-1">
<span class="text-[10px] font-bold uppercase tracking-[0.3em] font-label text-tertiary">L'essentiel à table</span>
<h2 class="mt-3 text-2xl sm:text-[1.7rem] font-headline font-bold text-on-surface leading-tight">Grove Signature</h2>
<div class="w-10 h-px bg-tertiary/40 mx-auto my-5"></div>
<p class="text-sm text-on-surface-variant font-body leading-relaxed flex-1">Une huile d'olive extra vierge plus intense, au profil structuré et à l'amertume délicate. Elle enrichit les salades, les assaisonnements et les touches finales.</p>
<p class="mt-6 text-[11px] font-label uppercase tracking-[0.2em] text-on-surface-variant/70">250ml · 500ml · 750ml · 1L</p>
<p class="mt-2 text-[10px] font-bold uppercase tracking-[0.18em] text-on-surface-variant/70 font-label">2 bouteilles : Marasca &amp; Dorica</p>
<span class="mt-7 inline-flex items-center justify-center gap-2 text-xs font-bold uppercase tracking-[0.2em] font-label text-primary group-hover:gap-3 transition-all">
<span class="border-b border-primary/30 group-hover:border-primary pb-0.5 transition-colors">En savoir plus</span>
<span class="material-symbols-outlined text-base">arrow_forward</span>
</span>
</div>
</button>
<!-- Carte : Organic Signature -->
<button type="button" onclick="showDetailPage('organic'); return false;" class="group relative flex flex-col w-full text-center cursor-pointer rounded-[2rem] bg-surface ring-1 ring-surface-container-highest hover:ring-primary/25 shadow-[0_2px_20px_rgba(46,50,48,0.04)] hover:shadow-[0_24px_60px_rgba(46,50,48,0.12)] transition-all duration-700 expertise-enter" style="animation-delay:0.39s">
<div class="relative h-[340px] sm:h-[380px] flex items-end justify-center overflow-hidden">
<div class="absolute inset-0 flex items-center justify-center pointer-events-none">
<div class="w-44 h-44 rounded-full bg-primary/12 blur-3xl transition-transform duration-1000 group-hover:scale-150"></div>
</div>
<div class="absolute bottom-8 left-1/2 -translate-x-1/2 w-40 h-4 rounded-[50%] bg-on-surface/15 blur-xl transition-all duration-700 group-hover:w-32 group-hover:opacity-70"></div>
<img alt="Organic Signature - Huile d'Olive Vierge Extra Biologique" class="relative z-10 h-[88%] w-auto object-contain transition-transform duration-700 ease-out group-hover:-translate-y-3 group-hover:scale-[1.04]" src="assets/images/organic-marasca-1l.webp" loading="lazy" decoding="async"/>
</div>
<div class="px-8 pb-10 pt-6 text-center flex flex-col flex-1">
<span class="text-[10px] font-bold uppercase tracking-[0.3em] font-label text-primary">L'essentiel bio</span>
<h2 class="mt-3 text-2xl sm:text-[1.7rem] font-headline font-bold text-on-surface leading-tight">Organic Signature</h2>
<div class="w-10 h-px bg-primary/40 mx-auto my-5"></div>
<p class="text-sm text-on-surface-variant font-body leading-relaxed flex-1">Une huile d'olive extra vierge certifiée biologique, pour ceux qui privilégient l'authenticité, la traçabilité et un approvisionnement responsable. Une expression pure et équilibrée.</p>
<p class="mt-6 text-[11px] font-label uppercase tracking-[0.2em] text-on-surface-variant/70">250ml · 500ml · 750ml · 1L</p>
<p class="mt-2 text-[10px] font-bold uppercase tracking-[0.18em] text-on-surface-variant/70 font-label">2 bouteilles : Marasca &amp; Dorica</p>
<span class="mt-7 inline-flex items-center justify-center gap-2 text-xs font-bold uppercase tracking-[0.2em] font-label text-primary group-hover:gap-3 transition-all">
<span class="border-b border-primary/30 group-hover:border-primary pb-0.5 transition-colors">En savoir plus</span>
<span class="material-symbols-outlined text-base">arrow_forward</span>
</span>
</div>
</button>
</div>
</div>
</section>
<script>
(function() {
  var items = document.querySelectorAll('#detail-produits .reveal-item');
  if (items.length) {
    var produitsItemObserver = new IntersectionObserver(function(entries) {
      entries.forEach(function(entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('active');
          produitsItemObserver.unobserve(entry.target);
        }
      });
    }, { threshold: 0.15 });
    items.forEach(function(el) { produitsItemObserver.observe(el); });
  }
})();
</script>
</div>
<div class="hidden w-full" id="detail-recettes">
<div id="recettes-liste">
<section class="relative w-full h-[52vh] min-h-[360px] max-h-[560px] overflow-hidden">
<div class="absolute inset-0">
<img data-banniere="1" alt="Les recettes Orzeta" class="w-full h-full object-cover ken-burns-slow" src="assets/images/recettes-banniere.png" loading="lazy" decoding="async"/>
</div>
<div class="absolute inset-0 bg-gradient-to-r from-black/70 via-black/35 to-black/10"></div>
<div class="absolute inset-x-0 top-0 h-28 sm:h-32 bg-gradient-to-b from-black/55 to-transparent"></div>
<div class="relative z-10 h-full max-w-7xl mx-auto px-6 lg:px-12 flex flex-col justify-center">
<div class="max-w-xl">
<span class="text-xs font-bold uppercase tracking-[0.3em] font-label text-white/80 expertise-fade-up" style="animation-delay:.05s">À table</span>
<h1 class="mt-4 font-headline text-4xl sm:text-5xl lg:text-6xl font-bold text-white leading-tight expertise-fade-up" style="text-shadow:0 2px 20px rgba(0,0,0,.45);animation-delay:.15s">Nos Recettes</h1>
<p class="mt-5 text-white/90 text-sm sm:text-base font-body expertise-fade-up" style="text-shadow:0 1px 8px rgba(0,0,0,.4);animation-delay:.25s">Des recettes méditerranéennes pensées pour chacune de nos trois huiles. Des gestes simples, des produits justes, et une huile qu'on sent vraiment.</p>
</div>
</div>
</section>
<section class="w-full py-14 md:py-16 bg-surface">
<div class="max-w-7xl mx-auto px-6 lg:px-12">
<div class="recettes-barre-filtres mb-12 expertise-fade-up" style="animation-delay:.1s">
<h2 class="text-center text-xl sm:text-2xl font-headline font-bold uppercase tracking-wide text-on-surface">Filtrer les recettes par :</h2>
<div class="mt-8 flex flex-wrap items-start justify-center gap-y-6" id="recettes-filtres">
<div class="filtre-groupe relative px-5 sm:px-8 lg:px-10 border-l border-surface-container-highest first:border-l-0" data-groupe="gamme">
<button aria-haspopup="true" class="group flex flex-col items-center gap-1.5 py-1 focus:outline-none" onclick="ouvrirFiltre('gamme')" type="button">
<span class="material-symbols-outlined text-[26px] text-on-surface group-hover:text-primary transition-colors">water_drop</span>
<span class="font-headline italic text-base text-on-surface group-hover:text-primary transition-colors whitespace-nowrap" id="libelle-gamme">Huile</span>
<span class="material-symbols-outlined text-lg text-on-surface-variant transition-transform duration-300" id="caret-gamme">expand_more</span>
</button>
<div class="filtre-panel w-56 rounded-2xl bg-surface shadow-2xl ring-1 ring-surface-container-highest overflow-hidden py-1" id="panel-gamme">
<button class="filtre-option w-full text-left px-5 py-3 text-sm font-body text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-colors" data-groupe="gamme" data-valeur="all" onclick="choisirFiltre('gamme','all')" type="button">Toutes les huiles</button>
<button class="filtre-option w-full text-left px-5 py-3 text-sm font-body text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-colors" data-groupe="gamme" data-valeur="sun" onclick="choisirFiltre('gamme','sun')" type="button">Sun Signature</button>
<button class="filtre-option w-full text-left px-5 py-3 text-sm font-body text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-colors" data-groupe="gamme" data-valeur="grove" onclick="choisirFiltre('gamme','grove')" type="button">Grove Signature</button>
<button class="filtre-option w-full text-left px-5 py-3 text-sm font-body text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-colors" data-groupe="gamme" data-valeur="organic" onclick="choisirFiltre('gamme','organic')" type="button">Organic Signature</button>
</div>
</div>
<div class="filtre-groupe relative px-5 sm:px-8 lg:px-10 border-l border-surface-container-highest first:border-l-0" data-groupe="categorie">
<button aria-haspopup="true" class="group flex flex-col items-center gap-1.5 py-1 focus:outline-none" onclick="ouvrirFiltre('categorie')" type="button">
<span class="material-symbols-outlined text-[26px] text-on-surface group-hover:text-primary transition-colors">room_service</span>
<span class="font-headline italic text-base text-on-surface group-hover:text-primary transition-colors whitespace-nowrap" id="libelle-categorie">Catégorie</span>
<span class="material-symbols-outlined text-lg text-on-surface-variant transition-transform duration-300" id="caret-categorie">expand_more</span>
</button>
<div class="filtre-panel w-56 rounded-2xl bg-surface shadow-2xl ring-1 ring-surface-container-highest overflow-hidden py-1" id="panel-categorie">
<button class="filtre-option w-full text-left px-5 py-3 text-sm font-body text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-colors" data-groupe="categorie" data-valeur="all" onclick="choisirFiltre('categorie','all')" type="button">Toutes les catégories</button>
<button class="filtre-option w-full text-left px-5 py-3 text-sm font-body text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-colors" data-groupe="categorie" data-valeur="aperitif" onclick="choisirFiltre('categorie','aperitif')" type="button">Apéritif</button>
<button class="filtre-option w-full text-left px-5 py-3 text-sm font-body text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-colors" data-groupe="categorie" data-valeur="entree" onclick="choisirFiltre('categorie','entree')" type="button">Entrée</button>
<button class="filtre-option w-full text-left px-5 py-3 text-sm font-body text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-colors" data-groupe="categorie" data-valeur="salade" onclick="choisirFiltre('categorie','salade')" type="button">Salade</button>
<button class="filtre-option w-full text-left px-5 py-3 text-sm font-body text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-colors" data-groupe="categorie" data-valeur="plat" onclick="choisirFiltre('categorie','plat')" type="button">Plat</button>
</div>
</div>
<div class="filtre-groupe relative px-5 sm:px-8 lg:px-10 border-l border-surface-container-highest first:border-l-0" data-groupe="temps">
<button aria-haspopup="true" class="group flex flex-col items-center gap-1.5 py-1 focus:outline-none" onclick="ouvrirFiltre('temps')" type="button">
<span class="material-symbols-outlined text-[26px] text-on-surface group-hover:text-primary transition-colors">schedule</span>
<span class="font-headline italic text-base text-on-surface group-hover:text-primary transition-colors whitespace-nowrap" id="libelle-temps">Temps</span>
<span class="material-symbols-outlined text-lg text-on-surface-variant transition-transform duration-300" id="caret-temps">expand_more</span>
</button>
<div class="filtre-panel w-56 rounded-2xl bg-surface shadow-2xl ring-1 ring-surface-container-highest overflow-hidden py-1" id="panel-temps">
<button class="filtre-option w-full text-left px-5 py-3 text-sm font-body text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-colors" data-groupe="temps" data-valeur="all" onclick="choisirFiltre('temps','all')" type="button">Peu importe</button>
<button class="filtre-option w-full text-left px-5 py-3 text-sm font-body text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-colors" data-groupe="temps" data-valeur="court" onclick="choisirFiltre('temps','court')" type="button">Moins de 30 min</button>
<button class="filtre-option w-full text-left px-5 py-3 text-sm font-body text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-colors" data-groupe="temps" data-valeur="moyen" onclick="choisirFiltre('temps','moyen')" type="button">30 min à 1 h</button>
<button class="filtre-option w-full text-left px-5 py-3 text-sm font-body text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-colors" data-groupe="temps" data-valeur="long" onclick="choisirFiltre('temps','long')" type="button">Plus d'1 h</button>
</div>
</div>
<div class="filtre-groupe relative px-5 sm:px-8 lg:px-10 border-l border-surface-container-highest first:border-l-0" data-groupe="niveau">
<button aria-haspopup="true" class="group flex flex-col items-center gap-1.5 py-1 focus:outline-none" onclick="ouvrirFiltre('niveau')" type="button">
<span class="material-symbols-outlined text-[26px] text-on-surface group-hover:text-primary transition-colors">signal_cellular_alt</span>
<span class="font-headline italic text-base text-on-surface group-hover:text-primary transition-colors whitespace-nowrap" id="libelle-niveau">Niveau</span>
<span class="material-symbols-outlined text-lg text-on-surface-variant transition-transform duration-300" id="caret-niveau">expand_more</span>
</button>
<div class="filtre-panel w-56 rounded-2xl bg-surface shadow-2xl ring-1 ring-surface-container-highest overflow-hidden py-1" id="panel-niveau">
<button class="filtre-option w-full text-left px-5 py-3 text-sm font-body text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-colors" data-groupe="niveau" data-valeur="all" onclick="choisirFiltre('niveau','all')" type="button">Tous les niveaux</button>
<button class="filtre-option w-full text-left px-5 py-3 text-sm font-body text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-colors" data-groupe="niveau" data-valeur="facile" onclick="choisirFiltre('niveau','facile')" type="button">Facile</button>
<button class="filtre-option w-full text-left px-5 py-3 text-sm font-body text-on-surface-variant hover:bg-surface-container-low hover:text-on-surface transition-colors" data-groupe="niveau" data-valeur="intermediaire" onclick="choisirFiltre('niveau','intermediaire')" type="button">Intermédiaire</button>
</div>
</div>
</div>
<div class="mt-8 flex items-center justify-center gap-5">
<p class="text-xs font-label uppercase tracking-widest text-on-surface-variant"><span id="recettes-nombre">9</span> <span>recettes</span></p>
<button class="hidden text-xs font-label uppercase tracking-widest font-bold text-primary hover:underline" id="recettes-reset" onclick="reinitFiltres()" type="button">Tout effacer</button>
</div>
</div>
<div class="relative z-0 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-7" id="recettes-grille">
<button class="recette-card group text-left rounded-3xl overflow-hidden bg-surface-container-low ring-1 ring-surface-container-highest shadow-sm hover:shadow-2xl hover:-translate-y-1.5 transition-all duration-500 flex flex-col expertise-enter" data-gamme="sun" data-categorie="salade" data-temps="moyen" data-minutes="30" data-niveau="facile" onclick="ouvrirRecette('slata-tounsia')" style="animation-delay:0.10s" type="button">
<div class="relative aspect-[4/3] overflow-hidden shrink-0">
<img alt="Slata Tounsia" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-[1.08]" src="assets/images/recette-sun-slata-tounsia.png" loading="lazy" decoding="async"/>
<div class="absolute inset-0 bg-gradient-to-t from-black/45 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>
</div>
<div class="p-6 flex flex-col flex-1">
<span class="text-[10px] font-bold uppercase tracking-[0.18em] font-label text-tertiary">Sun Signature</span>
<h3 class="mt-1.5 text-lg font-headline font-bold text-on-surface leading-snug">Slata Tounsia</h3>
<p class="mt-2 text-sm text-on-surface-variant font-body leading-relaxed flex-1">La salade nationale tunisienne, taillée très fin, où l'huile d'olive fait tout le travail d'assaisonnement.</p>
<div class="mt-5 flex items-center justify-between">
<span class="text-xs text-on-surface-variant font-label flex items-center gap-1.5"><span class="material-symbols-outlined text-base">schedule</span><span>30 min</span> · <span>4 personnes</span></span>
<span class="text-xs font-bold text-primary font-label flex items-center gap-1 group-hover:gap-2 transition-all">Voir <span class="material-symbols-outlined text-sm">arrow_forward</span></span>
</div>
</div>
</button>
<button class="recette-card group text-left rounded-3xl overflow-hidden bg-surface-container-low ring-1 ring-surface-container-highest shadow-sm hover:shadow-2xl hover:-translate-y-1.5 transition-all duration-500 flex flex-col expertise-enter" data-gamme="sun" data-categorie="plat" data-temps="moyen" data-minutes="35" data-niveau="facile" onclick="ouvrirRecette('daurade')" style="animation-delay:0.18s" type="button">
<div class="relative aspect-[4/3] overflow-hidden shrink-0">
<img alt="Daurade grillée à la sfaxienne" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-[1.08]" src="assets/images/recette-sun-daurade.png" loading="lazy" decoding="async"/>
<div class="absolute inset-0 bg-gradient-to-t from-black/45 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>
</div>
<div class="p-6 flex flex-col flex-1">
<span class="text-[10px] font-bold uppercase tracking-[0.18em] font-label text-tertiary">Sun Signature</span>
<h3 class="mt-1.5 text-lg font-headline font-bold text-on-surface leading-snug">Daurade grillée à la sfaxienne</h3>
<p class="mt-2 text-sm text-on-surface-variant font-body leading-relaxed flex-1">Le poisson entier grillé comme sur la côte : peau croustillante, chair nacrée, et un filet d'huile crue à la sortie du gril.</p>
<div class="mt-5 flex items-center justify-between">
<span class="text-xs text-on-surface-variant font-label flex items-center gap-1.5"><span class="material-symbols-outlined text-base">schedule</span><span>35 min</span> · <span>2 personnes</span></span>
<span class="text-xs font-bold text-primary font-label flex items-center gap-1 group-hover:gap-2 transition-all">Voir <span class="material-symbols-outlined text-sm">arrow_forward</span></span>
</div>
</div>
</button>
<button class="recette-card group text-left rounded-3xl overflow-hidden bg-surface-container-low ring-1 ring-surface-container-highest shadow-sm hover:shadow-2xl hover:-translate-y-1.5 transition-all duration-500 flex flex-col expertise-enter" data-gamme="sun" data-categorie="salade" data-temps="court" data-minutes="15" data-niveau="facile" onclick="ouvrirRecette('fenouil-orange')" style="animation-delay:0.26s" type="button">
<div class="relative aspect-[4/3] overflow-hidden shrink-0">
<img alt="Salade de fenouil, orange et olives" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-[1.08]" src="assets/images/recette-sun-fenouil-orange.png" loading="lazy" decoding="async"/>
<div class="absolute inset-0 bg-gradient-to-t from-black/45 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>
</div>
<div class="p-6 flex flex-col flex-1">
<span class="text-[10px] font-bold uppercase tracking-[0.18em] font-label text-tertiary">Sun Signature</span>
<h3 class="mt-1.5 text-lg font-headline font-bold text-on-surface leading-snug">Salade de fenouil, orange et olives</h3>
<p class="mt-2 text-sm text-on-surface-variant font-body leading-relaxed flex-1">Croquante, acidulée, lumineuse. Une salade d'hiver méditerranéenne qui tient sur trois ingrédients et une bonne huile.</p>
<div class="mt-5 flex items-center justify-between">
<span class="text-xs text-on-surface-variant font-label flex items-center gap-1.5"><span class="material-symbols-outlined text-base">schedule</span><span>15 min</span> · <span>4 personnes</span></span>
<span class="text-xs font-bold text-primary font-label flex items-center gap-1 group-hover:gap-2 transition-all">Voir <span class="material-symbols-outlined text-sm">arrow_forward</span></span>
</div>
</div>
</button>
<button class="recette-card group text-left rounded-3xl overflow-hidden bg-surface-container-low ring-1 ring-surface-container-highest shadow-sm hover:shadow-2xl hover:-translate-y-1.5 transition-all duration-500 flex flex-col expertise-enter" data-gamme="grove" data-categorie="plat" data-temps="long" data-minutes="90" data-niveau="intermediaire" onclick="ouvrirRecette('poulpe')" style="animation-delay:0.10s" type="button">
<div class="relative aspect-[4/3] overflow-hidden shrink-0">
<img alt="Poulpe grillé, citron et persil" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-[1.08]" src="assets/images/recette-grove-poulpe.png" loading="lazy" decoding="async"/>
<div class="absolute inset-0 bg-gradient-to-t from-black/45 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>
</div>
<div class="p-6 flex flex-col flex-1">
<span class="text-[10px] font-bold uppercase tracking-[0.18em] font-label text-primary">Grove Signature</span>
<h3 class="mt-1.5 text-lg font-headline font-bold text-on-surface leading-snug">Poulpe grillé, citron et persil</h3>
<p class="mt-2 text-sm text-on-surface-variant font-body leading-relaxed flex-1">Tendre à l'intérieur, caramélisé à l'extérieur. Un plat de bord de mer qui demande une huile de caractère.</p>
<div class="mt-5 flex items-center justify-between">
<span class="text-xs text-on-surface-variant font-label flex items-center gap-1.5"><span class="material-symbols-outlined text-base">schedule</span><span>1 h 30</span> · <span>4 personnes</span></span>
<span class="text-xs font-bold text-primary font-label flex items-center gap-1 group-hover:gap-2 transition-all">Voir <span class="material-symbols-outlined text-sm">arrow_forward</span></span>
</div>
</div>
</button>
<button class="recette-card group text-left rounded-3xl overflow-hidden bg-surface-container-low ring-1 ring-surface-container-highest shadow-sm hover:shadow-2xl hover:-translate-y-1.5 transition-all duration-500 flex flex-col expertise-enter" data-gamme="grove" data-categorie="aperitif" data-temps="court" data-minutes="20" data-niveau="facile" onclick="ouvrirRecette('bruschetta')" style="animation-delay:0.18s" type="button">
<div class="relative aspect-[4/3] overflow-hidden shrink-0">
<img alt="Bruschetta tomate &amp; basilic" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-[1.08]" src="assets/images/recette-grove-bruschetta.png" loading="lazy" decoding="async"/>
<div class="absolute inset-0 bg-gradient-to-t from-black/45 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>
</div>
<div class="p-6 flex flex-col flex-1">
<span class="text-[10px] font-bold uppercase tracking-[0.18em] font-label text-primary">Grove Signature</span>
<h3 class="mt-1.5 text-lg font-headline font-bold text-on-surface leading-snug">Bruschetta tomate &amp; basilic</h3>
<p class="mt-2 text-sm text-on-surface-variant font-body leading-relaxed flex-1">Quatre ingrédients, aucun endroit où se cacher. C'est exactement pour ça que la bruschetta est le meilleur test d'une huile d'olive.</p>
<div class="mt-5 flex items-center justify-between">
<span class="text-xs text-on-surface-variant font-label flex items-center gap-1.5"><span class="material-symbols-outlined text-base">schedule</span><span>20 min</span> · <span>4 personnes</span></span>
<span class="text-xs font-bold text-primary font-label flex items-center gap-1 group-hover:gap-2 transition-all">Voir <span class="material-symbols-outlined text-sm">arrow_forward</span></span>
</div>
</div>
</button>
<button class="recette-card group text-left rounded-3xl overflow-hidden bg-surface-container-low ring-1 ring-surface-container-highest shadow-sm hover:shadow-2xl hover:-translate-y-1.5 transition-all duration-500 flex flex-col expertise-enter" data-gamme="grove" data-categorie="plat" data-temps="long" data-minutes="90" data-niveau="intermediaire" onclick="ouvrirRecette('agneau')" style="animation-delay:0.26s" type="button">
<div class="relative aspect-[4/3] overflow-hidden shrink-0">
<img alt="Gigot d'agneau rôti aux herbes" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-[1.08]" src="assets/images/recette-grove-agneau.png" loading="lazy" decoding="async"/>
<div class="absolute inset-0 bg-gradient-to-t from-black/45 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>
</div>
<div class="p-6 flex flex-col flex-1">
<span class="text-[10px] font-bold uppercase tracking-[0.18em] font-label text-primary">Grove Signature</span>
<h3 class="mt-1.5 text-lg font-headline font-bold text-on-surface leading-snug">Gigot d'agneau rôti aux herbes</h3>
<p class="mt-2 text-sm text-on-surface-variant font-body leading-relaxed flex-1">Le plat du dimanche. L'ail, le romarin et une huile corsée forment la croûte parfumée qui protège la chair.</p>
<div class="mt-5 flex items-center justify-between">
<span class="text-xs text-on-surface-variant font-label flex items-center gap-1.5"><span class="material-symbols-outlined text-base">schedule</span><span>1 h 30</span> · <span>6 personnes</span></span>
<span class="text-xs font-bold text-primary font-label flex items-center gap-1 group-hover:gap-2 transition-all">Voir <span class="material-symbols-outlined text-sm">arrow_forward</span></span>
</div>
</div>
</button>
<button class="recette-card group text-left rounded-3xl overflow-hidden bg-surface-container-low ring-1 ring-surface-container-highest shadow-sm hover:shadow-2xl hover:-translate-y-1.5 transition-all duration-500 flex flex-col expertise-enter" data-gamme="organic" data-categorie="entree" data-temps="court" data-minutes="10" data-niveau="facile" onclick="ouvrirRecette('caprese')" style="animation-delay:0.10s" type="button">
<div class="relative aspect-[4/3] overflow-hidden shrink-0">
<img alt="Caprese à l'huile d'olive" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-[1.08]" src="assets/images/recette-organic-caprese.png" loading="lazy" decoding="async"/>
<div class="absolute inset-0 bg-gradient-to-t from-black/45 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>
</div>
<div class="p-6 flex flex-col flex-1">
<span class="text-[10px] font-bold uppercase tracking-[0.18em] font-label text-primary">Organic Signature</span>
<h3 class="mt-1.5 text-lg font-headline font-bold text-on-surface leading-snug">Caprese à l'huile d'olive</h3>
<p class="mt-2 text-sm text-on-surface-variant font-body leading-relaxed flex-1">Tomate, mozzarella, basilic. Rien de plus. Ici l'huile n'accompagne pas le plat : elle en fait partie.</p>
<div class="mt-5 flex items-center justify-between">
<span class="text-xs text-on-surface-variant font-label flex items-center gap-1.5"><span class="material-symbols-outlined text-base">schedule</span><span>10 min</span> · <span>2 personnes</span></span>
<span class="text-xs font-bold text-primary font-label flex items-center gap-1 group-hover:gap-2 transition-all">Voir <span class="material-symbols-outlined text-sm">arrow_forward</span></span>
</div>
</div>
</button>
<button class="recette-card group text-left rounded-3xl overflow-hidden bg-surface-container-low ring-1 ring-surface-container-highest shadow-sm hover:shadow-2xl hover:-translate-y-1.5 transition-all duration-500 flex flex-col expertise-enter" data-gamme="organic" data-categorie="aperitif" data-temps="long" data-minutes="70" data-niveau="facile" onclick="ouvrirRecette('houmous')" style="animation-delay:0.18s" type="button">
<div class="relative aspect-[4/3] overflow-hidden shrink-0">
<img alt="Houmous crémeux à l'huile d'olive" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-[1.08]" src="assets/images/recette-organic-houmous.png" loading="lazy" decoding="async"/>
<div class="absolute inset-0 bg-gradient-to-t from-black/45 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>
</div>
<div class="p-6 flex flex-col flex-1">
<span class="text-[10px] font-bold uppercase tracking-[0.18em] font-label text-primary">Organic Signature</span>
<h3 class="mt-1.5 text-lg font-headline font-bold text-on-surface leading-snug">Houmous crémeux à l'huile d'olive</h3>
<p class="mt-2 text-sm text-on-surface-variant font-body leading-relaxed flex-1">Le sillon d'huile au centre n'est pas une décoration : c'est la moitié du goût.</p>
<div class="mt-5 flex items-center justify-between">
<span class="text-xs text-on-surface-variant font-label flex items-center gap-1.5"><span class="material-symbols-outlined text-base">schedule</span><span>1 h 10</span> · <span>4 personnes</span></span>
<span class="text-xs font-bold text-primary font-label flex items-center gap-1 group-hover:gap-2 transition-all">Voir <span class="material-symbols-outlined text-sm">arrow_forward</span></span>
</div>
</div>
</button>
<button class="recette-card group text-left rounded-3xl overflow-hidden bg-surface-container-low ring-1 ring-surface-container-highest shadow-sm hover:shadow-2xl hover:-translate-y-1.5 transition-all duration-500 flex flex-col expertise-enter" data-gamme="organic" data-categorie="entree" data-temps="court" data-minutes="20" data-niveau="facile" onclick="ouvrirRecette('tartare-saumon')" style="animation-delay:0.26s" type="button">
<div class="relative aspect-[4/3] overflow-hidden shrink-0">
<img alt="Tartare de saumon, citron et huile d'olive" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-[1.08]" src="assets/images/recette-organic-tartare-saumon.png" loading="lazy" decoding="async"/>
<div class="absolute inset-0 bg-gradient-to-t from-black/45 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>
</div>
<div class="p-6 flex flex-col flex-1">
<span class="text-[10px] font-bold uppercase tracking-[0.18em] font-label text-primary">Organic Signature</span>
<h3 class="mt-1.5 text-lg font-headline font-bold text-on-surface leading-snug">Tartare de saumon, citron et huile d'olive</h3>
<p class="mt-2 text-sm text-on-surface-variant font-body leading-relaxed flex-1">Pas de mayonnaise, pas de crème. L'huile d'olive tient lieu de sauce, donc elle doit être irréprochable.</p>
<div class="mt-5 flex items-center justify-between">
<span class="text-xs text-on-surface-variant font-label flex items-center gap-1.5"><span class="material-symbols-outlined text-base">schedule</span><span>20 min</span> · <span>2 personnes</span></span>
<span class="text-xs font-bold text-primary font-label flex items-center gap-1 group-hover:gap-2 transition-all">Voir <span class="material-symbols-outlined text-sm">arrow_forward</span></span>
</div>
</div>
</button>
</div>
<p class="hidden text-center text-on-surface-variant font-body py-12" id="recettes-vide">Aucune recette pour cette sélection.</p>
</div>
</section>
</div>
<div class="hidden w-full" id="recette-slata-tounsia">
<section class="w-full py-10 md:py-14 bg-surface">
<div class="max-w-6xl mx-auto px-6 lg:px-12">
<button class="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-widest font-label text-on-surface-variant hover:text-primary transition-colors mb-8 expertise-fade-up" onclick="retourRecettes()" type="button"><span class="material-symbols-outlined text-base">arrow_back</span>Toutes les recettes</button>
<div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-14 items-start">
<div class="lg:col-span-6">
<div class="relative rounded-3xl overflow-hidden shadow-xl ring-1 ring-surface-container-highest aspect-[4/3] expertise-enter" style="animation-delay:.1s">
<img alt="Slata Tounsia" class="w-full h-full object-cover" src="assets/images/recette-sun-slata-tounsia.png" loading="lazy" decoding="async"/>
</div>
<div class="mt-5 grid grid-cols-2 sm:grid-cols-4 gap-3 expertise-fade-up" style="animation-delay:.2s">
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">group</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Pour</p><p class="text-xs font-bold text-on-surface">4 personnes</p></div>
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">schedule</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Préparation</p><p class="text-xs font-bold text-on-surface">20 min</p></div>
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">local_fire_department</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Cuisson</p><p class="text-xs font-bold text-on-surface">10 min</p></div>
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">signal_cellular_alt</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Niveau</p><p class="text-xs font-bold text-on-surface">Facile</p></div>
</div>
</div>
<div class="lg:col-span-6">
<span class="inline-block px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider font-label expertise-fade-up bg-tertiary/12 text-tertiary" style="animation-delay:.1s">Sun Signature</span>
<h1 class="mt-3 text-3xl sm:text-4xl font-headline font-bold text-on-surface leading-tight expertise-fade-up" style="animation-delay:.15s">Slata Tounsia</h1>
<p class="mt-4 text-on-surface-variant text-sm sm:text-base font-body leading-relaxed expertise-fade-up" style="animation-delay:.2s">La salade nationale tunisienne, taillée très fin, où l'huile d'olive fait tout le travail d'assaisonnement.</p>
<div class="mt-8 rounded-2xl bg-surface-container-low p-6 expertise-fade-up" style="animation-delay:.25s">
<h2 class="text-xs font-bold uppercase tracking-wider text-on-surface font-label mb-4">Ingrédients</h2>
<ul class="space-y-2.5">
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>4 tomates bien mûres</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>1 concombre</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>1 poivron vert</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>1 petit oignon rouge</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>2 œufs durs</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>100 g de thon à l'huile, égoutté</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>12 olives Chemlali</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>4 c. à s. d'Orzeta Sun</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>Le jus d'un demi-citron</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>Sel, poivre</li>
</ul>
</div>
<div class="mt-8 expertise-fade-up" style="animation-delay:.3s">
<h2 class="text-xs font-bold uppercase tracking-wider text-on-surface font-label mb-5">Préparation</h2>
<ol class="space-y-5">
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">1</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Taillez tomates, concombre, poivron et oignon en tout petits dés réguliers de 2 à 3 mm. C'est la finesse de la coupe qui fait la différence.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">2</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Salez légèrement, laissez dégorger 5 minutes puis égouttez l'eau rendue.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">3</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Ajoutez le jus de citron, l'huile d'olive et le poivre, mélangez délicatement.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">4</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Dressez dans un plat creux, garnissez de thon émietté, d'œufs en quartiers et d'olives.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">5</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Servez frais, avec du pain pour saucer.</p></li>
</ol>
</div>
<div class="mt-8 rounded-2xl bg-secondary-fixed p-5 flex items-start gap-3 expertise-fade-up" style="animation-delay:.35s">
<span class="material-symbols-outlined text-lg text-tertiary shrink-0 mt-0.5">lightbulb</span>
<p class="text-sm text-on-surface font-body leading-relaxed"><span class="font-bold">Le conseil Orzeta : </span>Ne mélangez le thon qu'au dernier moment : il doit rester en morceaux visibles.</p>
</div>
<div class="mt-8 rounded-2xl bg-primary p-6 expertise-fade-up" style="animation-delay:.4s">
<p class="text-xs font-bold uppercase tracking-wider text-on-primary/70 font-label">L'huile de cette recette</p>
<h3 class="mt-1.5 text-xl font-headline font-bold text-on-primary">Sun Signature</h3>
<a class="btn-pop mt-5 inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-surface text-on-surface font-bold text-xs uppercase tracking-widest hover:bg-surface-container-low" href="#" onclick="showDetailPage('sun'); return false;">Découvrir cette huile <span class="material-symbols-outlined text-sm">arrow_forward</span></a>
</div>
</div>
</div>
</div>
</section>
</div>
<div class="hidden w-full" id="recette-daurade">
<section class="w-full py-10 md:py-14 bg-surface">
<div class="max-w-6xl mx-auto px-6 lg:px-12">
<button class="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-widest font-label text-on-surface-variant hover:text-primary transition-colors mb-8 expertise-fade-up" onclick="retourRecettes()" type="button"><span class="material-symbols-outlined text-base">arrow_back</span>Toutes les recettes</button>
<div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-14 items-start">
<div class="lg:col-span-6">
<div class="relative rounded-3xl overflow-hidden shadow-xl ring-1 ring-surface-container-highest aspect-[4/3] expertise-enter" style="animation-delay:.1s">
<img alt="Daurade grillée à la sfaxienne" class="w-full h-full object-cover" src="assets/images/recette-sun-daurade.png" loading="lazy" decoding="async"/>
</div>
<div class="mt-5 grid grid-cols-2 sm:grid-cols-4 gap-3 expertise-fade-up" style="animation-delay:.2s">
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">group</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Pour</p><p class="text-xs font-bold text-on-surface">2 personnes</p></div>
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">schedule</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Préparation</p><p class="text-xs font-bold text-on-surface">15 min</p></div>
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">local_fire_department</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Cuisson</p><p class="text-xs font-bold text-on-surface">20 min</p></div>
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">signal_cellular_alt</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Niveau</p><p class="text-xs font-bold text-on-surface">Facile</p></div>
</div>
</div>
<div class="lg:col-span-6">
<span class="inline-block px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider font-label expertise-fade-up bg-tertiary/12 text-tertiary" style="animation-delay:.1s">Sun Signature</span>
<h1 class="mt-3 text-3xl sm:text-4xl font-headline font-bold text-on-surface leading-tight expertise-fade-up" style="animation-delay:.15s">Daurade grillée à la sfaxienne</h1>
<p class="mt-4 text-on-surface-variant text-sm sm:text-base font-body leading-relaxed expertise-fade-up" style="animation-delay:.2s">Le poisson entier grillé comme sur la côte : peau croustillante, chair nacrée, et un filet d'huile crue à la sortie du gril.</p>
<div class="mt-8 rounded-2xl bg-surface-container-low p-6 expertise-fade-up" style="animation-delay:.25s">
<h2 class="text-xs font-bold uppercase tracking-wider text-on-surface font-label mb-4">Ingrédients</h2>
<ul class="space-y-2.5">
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>1 daurade de 800 g, vidée et écaillée</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>1 citron</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>3 gousses d'ail</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>1 bouquet de persil plat</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>4 c. à s. d'Orzeta Sun</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>Sel, poivre</li>
</ul>
</div>
<div class="mt-8 expertise-fade-up" style="animation-delay:.3s">
<h2 class="text-xs font-bold uppercase tracking-wider text-on-surface font-label mb-5">Préparation</h2>
<ol class="space-y-5">
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">1</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Incisez la peau du poisson trois fois de chaque côté, jusqu'à l'arête.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">2</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Mélangez l'ail haché, le persil ciselé, 2 c. à s. d'huile, du sel et du poivre. Garnissez l'intérieur du poisson et les incisions.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">3</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Grillez 10 minutes de chaque côté sur braises moyennes, ou au four à 220°C.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">4</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">À la sortie du gril, arrosez généreusement d'huile crue et de jus de citron.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">5</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Servez immédiatement, avec une salade fraîche.</p></li>
</ol>
</div>
<div class="mt-8 rounded-2xl bg-secondary-fixed p-5 flex items-start gap-3 expertise-fade-up" style="animation-delay:.35s">
<span class="material-symbols-outlined text-lg text-tertiary shrink-0 mt-0.5">lightbulb</span>
<p class="text-sm text-on-surface font-body leading-relaxed"><span class="font-bold">Le conseil Orzeta : </span>L'huile versée à cru sur le poisson brûlant libère tous ses arômes : ne la mettez jamais avant la cuisson.</p>
</div>
<div class="mt-8 rounded-2xl bg-primary p-6 expertise-fade-up" style="animation-delay:.4s">
<p class="text-xs font-bold uppercase tracking-wider text-on-primary/70 font-label">L'huile de cette recette</p>
<h3 class="mt-1.5 text-xl font-headline font-bold text-on-primary">Sun Signature</h3>
<a class="btn-pop mt-5 inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-surface text-on-surface font-bold text-xs uppercase tracking-widest hover:bg-surface-container-low" href="#" onclick="showDetailPage('sun'); return false;">Découvrir cette huile <span class="material-symbols-outlined text-sm">arrow_forward</span></a>
</div>
</div>
</div>
</div>
</section>
</div>
<div class="hidden w-full" id="recette-fenouil-orange">
<section class="w-full py-10 md:py-14 bg-surface">
<div class="max-w-6xl mx-auto px-6 lg:px-12">
<button class="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-widest font-label text-on-surface-variant hover:text-primary transition-colors mb-8 expertise-fade-up" onclick="retourRecettes()" type="button"><span class="material-symbols-outlined text-base">arrow_back</span>Toutes les recettes</button>
<div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-14 items-start">
<div class="lg:col-span-6">
<div class="relative rounded-3xl overflow-hidden shadow-xl ring-1 ring-surface-container-highest aspect-[4/3] expertise-enter" style="animation-delay:.1s">
<img alt="Salade de fenouil, orange et olives" class="w-full h-full object-cover" src="assets/images/recette-sun-fenouil-orange.png" loading="lazy" decoding="async"/>
</div>
<div class="mt-5 grid grid-cols-2 sm:grid-cols-4 gap-3 expertise-fade-up" style="animation-delay:.2s">
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">group</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Pour</p><p class="text-xs font-bold text-on-surface">4 personnes</p></div>
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">schedule</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Préparation</p><p class="text-xs font-bold text-on-surface">15 min</p></div>
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">local_fire_department</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Cuisson</p><p class="text-xs font-bold text-on-surface">Sans cuisson</p></div>
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">signal_cellular_alt</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Niveau</p><p class="text-xs font-bold text-on-surface">Facile</p></div>
</div>
</div>
<div class="lg:col-span-6">
<span class="inline-block px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider font-label expertise-fade-up bg-tertiary/12 text-tertiary" style="animation-delay:.1s">Sun Signature</span>
<h1 class="mt-3 text-3xl sm:text-4xl font-headline font-bold text-on-surface leading-tight expertise-fade-up" style="animation-delay:.15s">Salade de fenouil, orange et olives</h1>
<p class="mt-4 text-on-surface-variant text-sm sm:text-base font-body leading-relaxed expertise-fade-up" style="animation-delay:.2s">Croquante, acidulée, lumineuse. Une salade d'hiver méditerranéenne qui tient sur trois ingrédients et une bonne huile.</p>
<div class="mt-8 rounded-2xl bg-surface-container-low p-6 expertise-fade-up" style="animation-delay:.25s">
<h2 class="text-xs font-bold uppercase tracking-wider text-on-surface font-label mb-4">Ingrédients</h2>
<ul class="space-y-2.5">
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>2 bulbes de fenouil</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>2 oranges</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>12 olives noires Chemlali</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>Quelques feuilles de menthe fraîche</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>3 c. à s. d'Orzeta Sun</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>1 c. à s. de jus de citron</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>Fleur de sel, poivre du moulin</li>
</ul>
</div>
<div class="mt-8 expertise-fade-up" style="animation-delay:.3s">
<h2 class="text-xs font-bold uppercase tracking-wider text-on-surface font-label mb-5">Préparation</h2>
<ol class="space-y-5">
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">1</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Émincez le fenouil le plus finement possible, idéalement à la mandoline. Réservez quelques pluches vertes.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">2</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Pelez les oranges à vif et levez les suprêmes au-dessus d'un bol pour récupérer le jus.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">3</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Mélangez le fenouil avec le jus d'orange recueilli, le citron et l'huile d'olive.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">4</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Dressez en dôme, répartissez les suprêmes, les olives et la menthe.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">5</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Terminez par la fleur de sel, un tour de moulin et servez bien frais.</p></li>
</ol>
</div>
<div class="mt-8 rounded-2xl bg-secondary-fixed p-5 flex items-start gap-3 expertise-fade-up" style="animation-delay:.35s">
<span class="material-symbols-outlined text-lg text-tertiary shrink-0 mt-0.5">lightbulb</span>
<p class="text-sm text-on-surface font-body leading-relaxed"><span class="font-bold">Le conseil Orzeta : </span>Laissez le fenouil mariner 10 minutes dans l'huile et le citron : il s'attendrit sans perdre son croquant.</p>
</div>
<div class="mt-8 rounded-2xl bg-primary p-6 expertise-fade-up" style="animation-delay:.4s">
<p class="text-xs font-bold uppercase tracking-wider text-on-primary/70 font-label">L'huile de cette recette</p>
<h3 class="mt-1.5 text-xl font-headline font-bold text-on-primary">Sun Signature</h3>
<a class="btn-pop mt-5 inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-surface text-on-surface font-bold text-xs uppercase tracking-widest hover:bg-surface-container-low" href="#" onclick="showDetailPage('sun'); return false;">Découvrir cette huile <span class="material-symbols-outlined text-sm">arrow_forward</span></a>
</div>
</div>
</div>
</div>
</section>
</div>
<div class="hidden w-full" id="recette-poulpe">
<section class="w-full py-10 md:py-14 bg-surface">
<div class="max-w-6xl mx-auto px-6 lg:px-12">
<button class="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-widest font-label text-on-surface-variant hover:text-primary transition-colors mb-8 expertise-fade-up" onclick="retourRecettes()" type="button"><span class="material-symbols-outlined text-base">arrow_back</span>Toutes les recettes</button>
<div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-14 items-start">
<div class="lg:col-span-6">
<div class="relative rounded-3xl overflow-hidden shadow-xl ring-1 ring-surface-container-highest aspect-[4/3] expertise-enter" style="animation-delay:.1s">
<img alt="Poulpe grillé, citron et persil" class="w-full h-full object-cover" src="assets/images/recette-grove-poulpe.png" loading="lazy" decoding="async"/>
</div>
<div class="mt-5 grid grid-cols-2 sm:grid-cols-4 gap-3 expertise-fade-up" style="animation-delay:.2s">
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">group</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Pour</p><p class="text-xs font-bold text-on-surface">4 personnes</p></div>
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">schedule</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Préparation</p><p class="text-xs font-bold text-on-surface">20 min</p></div>
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">local_fire_department</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Cuisson</p><p class="text-xs font-bold text-on-surface">1 h 10</p></div>
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">signal_cellular_alt</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Niveau</p><p class="text-xs font-bold text-on-surface">Intermédiaire</p></div>
</div>
</div>
<div class="lg:col-span-6">
<span class="inline-block px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider font-label expertise-fade-up bg-primary/12 text-primary" style="animation-delay:.1s">Grove Signature</span>
<h1 class="mt-3 text-3xl sm:text-4xl font-headline font-bold text-on-surface leading-tight expertise-fade-up" style="animation-delay:.15s">Poulpe grillé, citron et persil</h1>
<p class="mt-4 text-on-surface-variant text-sm sm:text-base font-body leading-relaxed expertise-fade-up" style="animation-delay:.2s">Tendre à l'intérieur, caramélisé à l'extérieur. Un plat de bord de mer qui demande une huile de caractère.</p>
<div class="mt-8 rounded-2xl bg-surface-container-low p-6 expertise-fade-up" style="animation-delay:.25s">
<h2 class="text-xs font-bold uppercase tracking-wider text-on-surface font-label mb-4">Ingrédients</h2>
<ul class="space-y-2.5">
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>1 poulpe de 1,2 kg, nettoyé</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>1 oignon</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>1 feuille de laurier</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>4 gousses d'ail</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>1 bouquet de persil plat</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>1 citron</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>5 c. à s. d'Orzeta Grove</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>Piment doux en poudre, sel</li>
</ul>
</div>
<div class="mt-8 expertise-fade-up" style="animation-delay:.3s">
<h2 class="text-xs font-bold uppercase tracking-wider text-on-surface font-label mb-5">Préparation</h2>
<ol class="space-y-5">
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">1</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Plongez le poulpe dans une grande eau frémissante avec l'oignon et le laurier. Laissez cuire 45 min à 1 h : la pointe d'un couteau doit entrer sans résistance.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">2</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Égouttez, laissez tiédir puis séparez les tentacules.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">3</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Badigeonnez-les d'huile et grillez-les 3 à 4 minutes par face, jusqu'à ce que les ventouses caramélisent.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">4</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Dressez, parsemez d'ail émincé, de persil ciselé et d'une pincée de piment doux.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">5</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Arrosez généreusement d'huile crue et de citron juste avant de servir.</p></li>
</ol>
</div>
<div class="mt-8 rounded-2xl bg-secondary-fixed p-5 flex items-start gap-3 expertise-fade-up" style="animation-delay:.35s">
<span class="material-symbols-outlined text-lg text-tertiary shrink-0 mt-0.5">lightbulb</span>
<p class="text-sm text-on-surface font-body leading-relaxed"><span class="font-bold">Le conseil Orzeta : </span>Ne salez jamais l'eau de cuisson du poulpe : elle durcit la chair. Le sel vient à la fin.</p>
</div>
<div class="mt-8 rounded-2xl bg-primary p-6 expertise-fade-up" style="animation-delay:.4s">
<p class="text-xs font-bold uppercase tracking-wider text-on-primary/70 font-label">L'huile de cette recette</p>
<h3 class="mt-1.5 text-xl font-headline font-bold text-on-primary">Grove Signature</h3>
<a class="btn-pop mt-5 inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-surface text-on-surface font-bold text-xs uppercase tracking-widest hover:bg-surface-container-low" href="#" onclick="showDetailPage('grove'); return false;">Découvrir cette huile <span class="material-symbols-outlined text-sm">arrow_forward</span></a>
</div>
</div>
</div>
</div>
</section>
</div>
<div class="hidden w-full" id="recette-bruschetta">
<section class="w-full py-10 md:py-14 bg-surface">
<div class="max-w-6xl mx-auto px-6 lg:px-12">
<button class="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-widest font-label text-on-surface-variant hover:text-primary transition-colors mb-8 expertise-fade-up" onclick="retourRecettes()" type="button"><span class="material-symbols-outlined text-base">arrow_back</span>Toutes les recettes</button>
<div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-14 items-start">
<div class="lg:col-span-6">
<div class="relative rounded-3xl overflow-hidden shadow-xl ring-1 ring-surface-container-highest aspect-[4/3] expertise-enter" style="animation-delay:.1s">
<img alt="Bruschetta tomate &amp; basilic" class="w-full h-full object-cover" src="assets/images/recette-grove-bruschetta.png" loading="lazy" decoding="async"/>
</div>
<div class="mt-5 grid grid-cols-2 sm:grid-cols-4 gap-3 expertise-fade-up" style="animation-delay:.2s">
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">group</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Pour</p><p class="text-xs font-bold text-on-surface">4 personnes</p></div>
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">schedule</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Préparation</p><p class="text-xs font-bold text-on-surface">15 min</p></div>
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">local_fire_department</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Cuisson</p><p class="text-xs font-bold text-on-surface">5 min</p></div>
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">signal_cellular_alt</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Niveau</p><p class="text-xs font-bold text-on-surface">Facile</p></div>
</div>
</div>
<div class="lg:col-span-6">
<span class="inline-block px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider font-label expertise-fade-up bg-primary/12 text-primary" style="animation-delay:.1s">Grove Signature</span>
<h1 class="mt-3 text-3xl sm:text-4xl font-headline font-bold text-on-surface leading-tight expertise-fade-up" style="animation-delay:.15s">Bruschetta tomate &amp; basilic</h1>
<p class="mt-4 text-on-surface-variant text-sm sm:text-base font-body leading-relaxed expertise-fade-up" style="animation-delay:.2s">Quatre ingrédients, aucun endroit où se cacher. C'est exactement pour ça que la bruschetta est le meilleur test d'une huile d'olive.</p>
<div class="mt-8 rounded-2xl bg-surface-container-low p-6 expertise-fade-up" style="animation-delay:.25s">
<h2 class="text-xs font-bold uppercase tracking-wider text-on-surface font-label mb-4">Ingrédients</h2>
<ul class="space-y-2.5">
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>8 tranches de pain de campagne</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>4 tomates mûres</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>2 gousses d'ail</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>Une dizaine de feuilles de basilic</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>5 c. à s. d'Orzeta Grove</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>Fleur de sel, poivre</li>
</ul>
</div>
<div class="mt-8 expertise-fade-up" style="animation-delay:.3s">
<h2 class="text-xs font-bold uppercase tracking-wider text-on-surface font-label mb-5">Préparation</h2>
<ol class="space-y-5">
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">1</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Concassez les tomates en petits dés, salez et laissez égoutter 10 minutes dans une passoire.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">2</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Grillez les tranches de pain jusqu'à obtenir des marques nettes et une surface bien sèche.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">3</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Frottez chaque tranche encore chaude avec une demi-gousse d'ail.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">4</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Répartissez les tomates égouttées et le basilic déchiré à la main.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">5</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Arrosez généreusement d'huile, fleur de sel, poivre, et servez aussitôt.</p></li>
</ol>
</div>
<div class="mt-8 rounded-2xl bg-secondary-fixed p-5 flex items-start gap-3 expertise-fade-up" style="animation-delay:.35s">
<span class="material-symbols-outlined text-lg text-tertiary shrink-0 mt-0.5">lightbulb</span>
<p class="text-sm text-on-surface font-body leading-relaxed"><span class="font-bold">Le conseil Orzeta : </span>Le pain doit être grillé, pas séché : une croûte marquée et un cœur encore moelleux qui absorbe l'huile.</p>
</div>
<div class="mt-8 rounded-2xl bg-primary p-6 expertise-fade-up" style="animation-delay:.4s">
<p class="text-xs font-bold uppercase tracking-wider text-on-primary/70 font-label">L'huile de cette recette</p>
<h3 class="mt-1.5 text-xl font-headline font-bold text-on-primary">Grove Signature</h3>
<a class="btn-pop mt-5 inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-surface text-on-surface font-bold text-xs uppercase tracking-widest hover:bg-surface-container-low" href="#" onclick="showDetailPage('grove'); return false;">Découvrir cette huile <span class="material-symbols-outlined text-sm">arrow_forward</span></a>
</div>
</div>
</div>
</div>
</section>
</div>
<div class="hidden w-full" id="recette-agneau">
<section class="w-full py-10 md:py-14 bg-surface">
<div class="max-w-6xl mx-auto px-6 lg:px-12">
<button class="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-widest font-label text-on-surface-variant hover:text-primary transition-colors mb-8 expertise-fade-up" onclick="retourRecettes()" type="button"><span class="material-symbols-outlined text-base">arrow_back</span>Toutes les recettes</button>
<div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-14 items-start">
<div class="lg:col-span-6">
<div class="relative rounded-3xl overflow-hidden shadow-xl ring-1 ring-surface-container-highest aspect-[4/3] expertise-enter" style="animation-delay:.1s">
<img alt="Gigot d'agneau rôti aux herbes" class="w-full h-full object-cover" src="assets/images/recette-grove-agneau.png" loading="lazy" decoding="async"/>
</div>
<div class="mt-5 grid grid-cols-2 sm:grid-cols-4 gap-3 expertise-fade-up" style="animation-delay:.2s">
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">group</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Pour</p><p class="text-xs font-bold text-on-surface">6 personnes</p></div>
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">schedule</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Préparation</p><p class="text-xs font-bold text-on-surface">20 min</p></div>
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">local_fire_department</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Cuisson</p><p class="text-xs font-bold text-on-surface">1 h 10</p></div>
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">signal_cellular_alt</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Niveau</p><p class="text-xs font-bold text-on-surface">Intermédiaire</p></div>
</div>
</div>
<div class="lg:col-span-6">
<span class="inline-block px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider font-label expertise-fade-up bg-primary/12 text-primary" style="animation-delay:.1s">Grove Signature</span>
<h1 class="mt-3 text-3xl sm:text-4xl font-headline font-bold text-on-surface leading-tight expertise-fade-up" style="animation-delay:.15s">Gigot d'agneau rôti aux herbes</h1>
<p class="mt-4 text-on-surface-variant text-sm sm:text-base font-body leading-relaxed expertise-fade-up" style="animation-delay:.2s">Le plat du dimanche. L'ail, le romarin et une huile corsée forment la croûte parfumée qui protège la chair.</p>
<div class="mt-8 rounded-2xl bg-surface-container-low p-6 expertise-fade-up" style="animation-delay:.25s">
<h2 class="text-xs font-bold uppercase tracking-wider text-on-surface font-label mb-4">Ingrédients</h2>
<ul class="space-y-2.5">
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>1 gigot d'agneau de 2 kg</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>8 gousses d'ail</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>4 branches de romarin</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>Quelques branches de thym</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>6 c. à s. d'Orzeta Grove</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>Gros sel, poivre concassé</li>
</ul>
</div>
<div class="mt-8 expertise-fade-up" style="animation-delay:.3s">
<h2 class="text-xs font-bold uppercase tracking-wider text-on-surface font-label mb-5">Préparation</h2>
<ol class="space-y-5">
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">1</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Sortez la viande du réfrigérateur 1 heure avant. Préchauffez le four à 220°C.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">2</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Piquez l'ail en lamelles dans la chair, frottez tout le gigot d'huile, puis d'herbes, de sel et de poivre.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">3</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Enfournez 20 minutes à 220°C pour saisir, puis baissez à 180°C et poursuivez 50 minutes pour une cuisson rosée.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">4</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Arrosez du jus de cuisson toutes les 20 minutes.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">5</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Laissez reposer 15 minutes sous une feuille d'aluminium avant de trancher, puis finissez d'un filet d'huile crue.</p></li>
</ol>
</div>
<div class="mt-8 rounded-2xl bg-secondary-fixed p-5 flex items-start gap-3 expertise-fade-up" style="animation-delay:.35s">
<span class="material-symbols-outlined text-lg text-tertiary shrink-0 mt-0.5">lightbulb</span>
<p class="text-sm text-on-surface font-body leading-relaxed"><span class="font-bold">Le conseil Orzeta : </span>Le repos n'est pas optionnel : c'est lui qui redistribue les jus et rend la viande moelleuse.</p>
</div>
<div class="mt-8 rounded-2xl bg-primary p-6 expertise-fade-up" style="animation-delay:.4s">
<p class="text-xs font-bold uppercase tracking-wider text-on-primary/70 font-label">L'huile de cette recette</p>
<h3 class="mt-1.5 text-xl font-headline font-bold text-on-primary">Grove Signature</h3>
<a class="btn-pop mt-5 inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-surface text-on-surface font-bold text-xs uppercase tracking-widest hover:bg-surface-container-low" href="#" onclick="showDetailPage('grove'); return false;">Découvrir cette huile <span class="material-symbols-outlined text-sm">arrow_forward</span></a>
</div>
</div>
</div>
</div>
</section>
</div>
<div class="hidden w-full" id="recette-caprese">
<section class="w-full py-10 md:py-14 bg-surface">
<div class="max-w-6xl mx-auto px-6 lg:px-12">
<button class="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-widest font-label text-on-surface-variant hover:text-primary transition-colors mb-8 expertise-fade-up" onclick="retourRecettes()" type="button"><span class="material-symbols-outlined text-base">arrow_back</span>Toutes les recettes</button>
<div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-14 items-start">
<div class="lg:col-span-6">
<div class="relative rounded-3xl overflow-hidden shadow-xl ring-1 ring-surface-container-highest aspect-[4/3] expertise-enter" style="animation-delay:.1s">
<img alt="Caprese à l'huile d'olive" class="w-full h-full object-cover" src="assets/images/recette-organic-caprese.png" loading="lazy" decoding="async"/>
</div>
<div class="mt-5 grid grid-cols-2 sm:grid-cols-4 gap-3 expertise-fade-up" style="animation-delay:.2s">
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">group</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Pour</p><p class="text-xs font-bold text-on-surface">2 personnes</p></div>
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">schedule</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Préparation</p><p class="text-xs font-bold text-on-surface">10 min</p></div>
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">local_fire_department</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Cuisson</p><p class="text-xs font-bold text-on-surface">Sans cuisson</p></div>
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">signal_cellular_alt</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Niveau</p><p class="text-xs font-bold text-on-surface">Facile</p></div>
</div>
</div>
<div class="lg:col-span-6">
<span class="inline-block px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider font-label expertise-fade-up bg-primary-container text-on-primary-container" style="animation-delay:.1s">Organic Signature</span>
<h1 class="mt-3 text-3xl sm:text-4xl font-headline font-bold text-on-surface leading-tight expertise-fade-up" style="animation-delay:.15s">Caprese à l'huile d'olive</h1>
<p class="mt-4 text-on-surface-variant text-sm sm:text-base font-body leading-relaxed expertise-fade-up" style="animation-delay:.2s">Tomate, mozzarella, basilic. Rien de plus. Ici l'huile n'accompagne pas le plat : elle en fait partie.</p>
<div class="mt-8 rounded-2xl bg-surface-container-low p-6 expertise-fade-up" style="animation-delay:.25s">
<h2 class="text-xs font-bold uppercase tracking-wider text-on-surface font-label mb-4">Ingrédients</h2>
<ul class="space-y-2.5">
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>3 tomates bien mûres</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>1 mozzarella di bufala de 250 g</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>Une dizaine de feuilles de basilic</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>3 c. à s. d'Orzeta Organic</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>Fleur de sel, poivre du moulin</li>
</ul>
</div>
<div class="mt-8 expertise-fade-up" style="animation-delay:.3s">
<h2 class="text-xs font-bold uppercase tracking-wider text-on-surface font-label mb-5">Préparation</h2>
<ol class="space-y-5">
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">1</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Sortez la mozzarella du réfrigérateur 20 minutes avant : froide, elle n'a aucun goût.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">2</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Coupez tomates et mozzarella en rondelles régulières d'environ 1 cm.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">3</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Disposez-les en les faisant se chevaucher, en glissant une feuille de basilic entre chaque.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">4</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Arrosez d'huile crue en filet continu.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">5</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Fleur de sel, poivre, et à table immédiatement.</p></li>
</ol>
</div>
<div class="mt-8 rounded-2xl bg-secondary-fixed p-5 flex items-start gap-3 expertise-fade-up" style="animation-delay:.35s">
<span class="material-symbols-outlined text-lg text-tertiary shrink-0 mt-0.5">lightbulb</span>
<p class="text-sm text-on-surface font-body leading-relaxed"><span class="font-bold">Le conseil Orzeta : </span>Salez les tomates 5 minutes avant de dresser : elles rendent un jus qui se mélange à l'huile et forme la sauce.</p>
</div>
<div class="mt-8 rounded-2xl bg-primary p-6 expertise-fade-up" style="animation-delay:.4s">
<p class="text-xs font-bold uppercase tracking-wider text-on-primary/70 font-label">L'huile de cette recette</p>
<h3 class="mt-1.5 text-xl font-headline font-bold text-on-primary">Organic Signature</h3>
<a class="btn-pop mt-5 inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-surface text-on-surface font-bold text-xs uppercase tracking-widest hover:bg-surface-container-low" href="#" onclick="showDetailPage('organic'); return false;">Découvrir cette huile <span class="material-symbols-outlined text-sm">arrow_forward</span></a>
</div>
</div>
</div>
</div>
</section>
</div>
<div class="hidden w-full" id="recette-houmous">
<section class="w-full py-10 md:py-14 bg-surface">
<div class="max-w-6xl mx-auto px-6 lg:px-12">
<button class="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-widest font-label text-on-surface-variant hover:text-primary transition-colors mb-8 expertise-fade-up" onclick="retourRecettes()" type="button"><span class="material-symbols-outlined text-base">arrow_back</span>Toutes les recettes</button>
<div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-14 items-start">
<div class="lg:col-span-6">
<div class="relative rounded-3xl overflow-hidden shadow-xl ring-1 ring-surface-container-highest aspect-[4/3] expertise-enter" style="animation-delay:.1s">
<img alt="Houmous crémeux à l'huile d'olive" class="w-full h-full object-cover" src="assets/images/recette-organic-houmous.png" loading="lazy" decoding="async"/>
</div>
<div class="mt-5 grid grid-cols-2 sm:grid-cols-4 gap-3 expertise-fade-up" style="animation-delay:.2s">
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">group</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Pour</p><p class="text-xs font-bold text-on-surface">4 personnes</p></div>
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">schedule</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Préparation</p><p class="text-xs font-bold text-on-surface">10 min</p></div>
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">local_fire_department</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Cuisson</p><p class="text-xs font-bold text-on-surface">1 h</p></div>
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">signal_cellular_alt</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Niveau</p><p class="text-xs font-bold text-on-surface">Facile</p></div>
</div>
</div>
<div class="lg:col-span-6">
<span class="inline-block px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider font-label expertise-fade-up bg-primary-container text-on-primary-container" style="animation-delay:.1s">Organic Signature</span>
<h1 class="mt-3 text-3xl sm:text-4xl font-headline font-bold text-on-surface leading-tight expertise-fade-up" style="animation-delay:.15s">Houmous crémeux à l'huile d'olive</h1>
<p class="mt-4 text-on-surface-variant text-sm sm:text-base font-body leading-relaxed expertise-fade-up" style="animation-delay:.2s">Le sillon d'huile au centre n'est pas une décoration : c'est la moitié du goût.</p>
<div class="mt-8 rounded-2xl bg-surface-container-low p-6 expertise-fade-up" style="animation-delay:.25s">
<h2 class="text-xs font-bold uppercase tracking-wider text-on-surface font-label mb-4">Ingrédients</h2>
<ul class="space-y-2.5">
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>250 g de pois chiches secs (ou 500 g cuits)</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>3 c. à s. de tahini</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>1 citron</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>1 gousse d'ail</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>5 c. à s. d'Orzeta Organic</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>Cumin, paprika</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>Sel</li>
</ul>
</div>
<div class="mt-8 expertise-fade-up" style="animation-delay:.3s">
<h2 class="text-xs font-bold uppercase tracking-wider text-on-surface font-label mb-5">Préparation</h2>
<ol class="space-y-5">
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">1</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Faites tremper les pois chiches 12 heures, puis cuisez-les 1 heure jusqu'à ce qu'ils s'écrasent entre deux doigts.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">2</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Mixez-les avec le tahini, l'ail, le jus de citron, 3 c. à s. d'huile et un peu d'eau de cuisson, jusqu'à obtenir une texture parfaitement lisse.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">3</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Salez, rectifiez en citron.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">4</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Dressez dans une assiette creuse en creusant un sillon en spirale avec le dos d'une cuillère.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">5</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Remplissez le sillon d'huile crue, saupoudrez de paprika et de cumin, ajoutez quelques pois chiches entiers.</p></li>
</ol>
</div>
<div class="mt-8 rounded-2xl bg-secondary-fixed p-5 flex items-start gap-3 expertise-fade-up" style="animation-delay:.35s">
<span class="material-symbols-outlined text-lg text-tertiary shrink-0 mt-0.5">lightbulb</span>
<p class="text-sm text-on-surface font-body leading-relaxed"><span class="font-bold">Le conseil Orzeta : </span>Ajoutez l'eau de cuisson tiède petit à petit pendant le mixage : c'est le secret d'un houmous aérien.</p>
</div>
<div class="mt-8 rounded-2xl bg-primary p-6 expertise-fade-up" style="animation-delay:.4s">
<p class="text-xs font-bold uppercase tracking-wider text-on-primary/70 font-label">L'huile de cette recette</p>
<h3 class="mt-1.5 text-xl font-headline font-bold text-on-primary">Organic Signature</h3>
<a class="btn-pop mt-5 inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-surface text-on-surface font-bold text-xs uppercase tracking-widest hover:bg-surface-container-low" href="#" onclick="showDetailPage('organic'); return false;">Découvrir cette huile <span class="material-symbols-outlined text-sm">arrow_forward</span></a>
</div>
</div>
</div>
</div>
</section>
</div>
<div class="hidden w-full" id="recette-tartare-saumon">
<section class="w-full py-10 md:py-14 bg-surface">
<div class="max-w-6xl mx-auto px-6 lg:px-12">
<button class="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-widest font-label text-on-surface-variant hover:text-primary transition-colors mb-8 expertise-fade-up" onclick="retourRecettes()" type="button"><span class="material-symbols-outlined text-base">arrow_back</span>Toutes les recettes</button>
<div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-14 items-start">
<div class="lg:col-span-6">
<div class="relative rounded-3xl overflow-hidden shadow-xl ring-1 ring-surface-container-highest aspect-[4/3] expertise-enter" style="animation-delay:.1s">
<img alt="Tartare de saumon, citron et huile d'olive" class="w-full h-full object-cover" src="assets/images/recette-organic-tartare-saumon.png" loading="lazy" decoding="async"/>
</div>
<div class="mt-5 grid grid-cols-2 sm:grid-cols-4 gap-3 expertise-fade-up" style="animation-delay:.2s">
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">group</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Pour</p><p class="text-xs font-bold text-on-surface">2 personnes</p></div>
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">schedule</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Préparation</p><p class="text-xs font-bold text-on-surface">20 min</p></div>
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">local_fire_department</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Cuisson</p><p class="text-xs font-bold text-on-surface">Sans cuisson</p></div>
<div class="rounded-2xl bg-surface-container-low p-4 text-center"><span class="material-symbols-outlined text-lg text-primary">signal_cellular_alt</span><p class="mt-1 text-[11px] uppercase tracking-wider text-on-surface-variant font-label">Niveau</p><p class="text-xs font-bold text-on-surface">Facile</p></div>
</div>
</div>
<div class="lg:col-span-6">
<span class="inline-block px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider font-label expertise-fade-up bg-primary-container text-on-primary-container" style="animation-delay:.1s">Organic Signature</span>
<h1 class="mt-3 text-3xl sm:text-4xl font-headline font-bold text-on-surface leading-tight expertise-fade-up" style="animation-delay:.15s">Tartare de saumon, citron et huile d'olive</h1>
<p class="mt-4 text-on-surface-variant text-sm sm:text-base font-body leading-relaxed expertise-fade-up" style="animation-delay:.2s">Pas de mayonnaise, pas de crème. L'huile d'olive tient lieu de sauce, donc elle doit être irréprochable.</p>
<div class="mt-8 rounded-2xl bg-surface-container-low p-6 expertise-fade-up" style="animation-delay:.25s">
<h2 class="text-xs font-bold uppercase tracking-wider text-on-surface font-label mb-4">Ingrédients</h2>
<ul class="space-y-2.5">
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>300 g de filet de saumon extra-frais, sans peau</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>1 échalote</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>Quelques brins d'aneth</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>1 citron (zeste et jus)</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>3 c. à s. d'Orzeta Organic</li>
<li class="flex items-start gap-3 text-sm text-on-surface-variant font-body"><span class="mt-2 w-1.5 h-1.5 rounded-full bg-primary shrink-0"></span>Baies roses, fleur de sel, poivre</li>
</ul>
</div>
<div class="mt-8 expertise-fade-up" style="animation-delay:.3s">
<h2 class="text-xs font-bold uppercase tracking-wider text-on-surface font-label mb-5">Préparation</h2>
<ol class="space-y-5">
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">1</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Placez le saumon 20 minutes au congélateur : il se taille beaucoup plus net.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">2</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Coupez-le au couteau en cubes réguliers de 5 mm. Surtout pas de mixeur.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">3</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Mélangez avec l'échalote ciselée finement, l'aneth, le zeste et 1 c. à s. de jus de citron, puis l'huile.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">4</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Assaisonnez et moulez à l'aide d'un cercle au centre de l'assiette.</p></li>
<li class="flex gap-4"><span class="shrink-0 w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center text-sm font-bold font-label">5</span><p class="text-sm text-on-surface-variant font-body leading-relaxed pt-1">Terminez par un filet d'huile crue et quelques baies roses, servez aussitôt.</p></li>
</ol>
</div>
<div class="mt-8 rounded-2xl bg-secondary-fixed p-5 flex items-start gap-3 expertise-fade-up" style="animation-delay:.35s">
<span class="material-symbols-outlined text-lg text-tertiary shrink-0 mt-0.5">lightbulb</span>
<p class="text-sm text-on-surface font-body leading-relaxed"><span class="font-bold">Le conseil Orzeta : </span>Assaisonnez au dernier moment : le citron « cuit » le poisson et le fait blanchir s'il attend trop.</p>
</div>
<div class="mt-8 rounded-2xl bg-primary p-6 expertise-fade-up" style="animation-delay:.4s">
<p class="text-xs font-bold uppercase tracking-wider text-on-primary/70 font-label">L'huile de cette recette</p>
<h3 class="mt-1.5 text-xl font-headline font-bold text-on-primary">Organic Signature</h3>
<a class="btn-pop mt-5 inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-surface text-on-surface font-bold text-xs uppercase tracking-widest hover:bg-surface-container-low" href="#" onclick="showDetailPage('organic'); return false;">Découvrir cette huile <span class="material-symbols-outlined text-sm">arrow_forward</span></a>
</div>
</div>
</div>
</div>
</section>
</div>
</div>
<div class="hidden w-full" id="detail-contact">
<!-- HERO Contact -->
<section class="relative w-full h-[46vh] min-h-[340px] max-h-[520px] overflow-hidden">
<div class="absolute inset-0">
<img data-banniere="1" alt="Contactez Orzeta" class="w-full h-full object-cover ken-burns-slow" src="assets/images/L'ouverture%20à%20l'international.png" loading="lazy" decoding="async"/>
</div>
<div class="absolute inset-0 bg-gradient-to-b from-black/55 via-black/25 to-black/55"></div>
<div class="relative z-10 h-full max-w-7xl mx-auto px-6 lg:px-12 flex flex-col justify-end pb-14">
<span class="text-xs font-bold uppercase tracking-[0.3em] font-label text-white/80 expertise-fade-up" style="animation-delay:.05s">Restons en Contact</span>
<h1 class="mt-3 font-headline text-4xl sm:text-5xl lg:text-6xl font-bold text-white expertise-fade-up" style="text-shadow:0 2px 20px rgba(0,0,0,.5);animation-delay:.15s">Contactez-nous</h1>
<p class="mt-4 text-white/90 text-sm sm:text-base max-w-xl font-body expertise-fade-up" style="text-shadow:0 1px 8px rgba(0,0,0,.4);animation-delay:.25s">Une question, un projet, une commande professionnelle ? Notre équipe vous répond sous 24h, du dimanche au vendredi.</p>
</div>
</section>
<!-- FORMULAIRE + COORDONNÉES -->
<section class="w-full py-16 md:py-20 bg-surface">
<div class="max-w-7xl mx-auto px-6 lg:px-12">
<div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
<div class="lg:col-span-5 space-y-4">
<div class="rounded-2xl bg-surface-container-low p-6 shadow-sm flex items-start gap-4 expertise-enter transition-transform duration-300 hover:-translate-y-1 hover:shadow-md" style="animation-delay:.1s">
<div class="w-11 h-11 rounded-xl bg-surface-container-highest flex items-center justify-center text-primary shrink-0">
<span class="material-symbols-outlined">call</span>
</div>
<div>
<p class="text-xs font-bold uppercase tracking-wider text-on-surface-variant font-label">Téléphone</p>
<a class="text-base font-bold text-on-surface font-headline mt-1 hover:text-primary transition-colors block" href="tel:+21692300800">+216 92 300 800</a>
</div>
</div>
<div class="rounded-2xl bg-surface-container-low p-6 shadow-sm flex items-start gap-4 expertise-enter transition-transform duration-300 hover:-translate-y-1 hover:shadow-md" style="animation-delay:.2s">
<div class="w-11 h-11 rounded-xl bg-surface-container-highest flex items-center justify-center text-primary shrink-0">
<span class="material-symbols-outlined">mail</span>
</div>
<div>
<p class="text-xs font-bold uppercase tracking-wider text-on-surface-variant font-label">Email</p>
<a class="text-base font-bold text-on-surface font-headline mt-1 hover:text-primary transition-colors block" href="mailto:contact@orzeta.com">contact@orzeta.com</a>
</div>
</div>
<div class="rounded-2xl bg-surface-container-low p-6 shadow-sm flex items-start gap-4 expertise-enter transition-transform duration-300 hover:-translate-y-1 hover:shadow-md" style="animation-delay:.3s">
<div class="w-11 h-11 rounded-xl bg-surface-container-highest flex items-center justify-center text-primary shrink-0">
<span class="material-symbols-outlined">location_on</span>
</div>
<div>
<p class="text-xs font-bold uppercase tracking-wider text-on-surface-variant font-label">Adresse</p>
<p class="text-base font-bold text-on-surface font-headline mt-1">Tunisie, Méditerranée</p>
</div>
</div>
<div class="rounded-2xl bg-surface-container-low p-6 shadow-sm flex items-start gap-4 expertise-enter transition-transform duration-300 hover:-translate-y-1 hover:shadow-md" style="animation-delay:.4s">
<div class="w-11 h-11 rounded-xl bg-surface-container-highest flex items-center justify-center text-primary shrink-0">
<span class="material-symbols-outlined">chat</span>
</div>
<div>
<p class="text-xs font-bold uppercase tracking-wider text-on-surface-variant font-label">WhatsApp</p>
<a class="text-base font-bold text-on-surface font-headline mt-1 hover:text-primary transition-colors inline-flex items-center gap-1.5" href="https://wa.me/21692300800" target="_blank" rel="noopener">+216 92 300 800<span class="material-symbols-outlined text-base text-primary">open_in_new</span></a>
</div>
</div>
</div>
<div class="lg:col-span-7">
<form class="rounded-2xl bg-surface-container-low p-6 sm:p-8 shadow-sm space-y-5 expertise-enter" id="contact-form" method="post" action="#contact" novalidate onsubmit="return handleContactSubmit(event)" style="animation-delay:.15s">
<input name="orzeta_contact" type="hidden" value="1"/>
<input aria-hidden="true" autocomplete="off" class="hidden" name="website" tabindex="-1" type="text"/>
<div>
<span class="block text-xs font-bold uppercase tracking-wider text-on-surface-variant font-label mb-3" id="contact-type-label"><span>Vous êtes</span><span class="text-tertiary ml-0.5" aria-hidden="true">*</span></span>
<div aria-labelledby="contact-type-label" class="flex flex-wrap gap-2 rounded-xl" id="contact-type" role="radiogroup" tabindex="-1">
<label class="type-contact-chip cursor-pointer"><input class="sr-only peer" name="type_contact" type="radio" value="particulier"/><span class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-full bg-surface ring-1 ring-surface-container-highest text-xs font-bold text-on-surface font-label transition-all duration-200 hover:ring-primary/60 peer-checked:bg-primary peer-checked:text-on-primary peer-checked:ring-primary peer-focus-visible:ring-2 peer-focus-visible:ring-primary"><span class="material-symbols-outlined text-base">person</span><span>Particulier</span></span></label>
<label class="type-contact-chip cursor-pointer"><input class="sr-only peer" name="type_contact" type="radio" value="distributeur"/><span class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-full bg-surface ring-1 ring-surface-container-highest text-xs font-bold text-on-surface font-label transition-all duration-200 hover:ring-primary/60 peer-checked:bg-primary peer-checked:text-on-primary peer-checked:ring-primary peer-focus-visible:ring-2 peer-focus-visible:ring-primary"><span class="material-symbols-outlined text-base">local_shipping</span><span>Distributeur / importateur</span></span></label>
<label class="type-contact-chip cursor-pointer"><input class="sr-only peer" name="type_contact" type="radio" value="restaurant"/><span class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-full bg-surface ring-1 ring-surface-container-highest text-xs font-bold text-on-surface font-label transition-all duration-200 hover:ring-primary/60 peer-checked:bg-primary peer-checked:text-on-primary peer-checked:ring-primary peer-focus-visible:ring-2 peer-focus-visible:ring-primary"><span class="material-symbols-outlined text-base">restaurant</span><span>Restaurant &amp; hôtel</span></span></label>
<label class="type-contact-chip cursor-pointer"><input class="sr-only peer" name="type_contact" type="radio" value="epicerie"/><span class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-full bg-surface ring-1 ring-surface-container-highest text-xs font-bold text-on-surface font-label transition-all duration-200 hover:ring-primary/60 peer-checked:bg-primary peer-checked:text-on-primary peer-checked:ring-primary peer-focus-visible:ring-2 peer-focus-visible:ring-primary"><span class="material-symbols-outlined text-base">storefront</span><span>Épicerie fine</span></span></label>
<label class="type-contact-chip cursor-pointer"><input class="sr-only peer" name="type_contact" type="radio" value="cadeaux"/><span class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-full bg-surface ring-1 ring-surface-container-highest text-xs font-bold text-on-surface font-label transition-all duration-200 hover:ring-primary/60 peer-checked:bg-primary peer-checked:text-on-primary peer-checked:ring-primary peer-focus-visible:ring-2 peer-focus-visible:ring-primary"><span class="material-symbols-outlined text-base">redeem</span><span>Cadeaux d’entreprise</span></span></label>
<label class="type-contact-chip cursor-pointer"><input class="sr-only peer" name="type_contact" type="radio" value="autre"/><span class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-full bg-surface ring-1 ring-surface-container-highest text-xs font-bold text-on-surface font-label transition-all duration-200 hover:ring-primary/60 peer-checked:bg-primary peer-checked:text-on-primary peer-checked:ring-primary peer-focus-visible:ring-2 peer-focus-visible:ring-primary"><span class="material-symbols-outlined text-base">more_horiz</span><span>Autre</span></span></label>
</div>
<div class="hidden mt-3" id="contact-type-autre-bloc">
<label class="sr-only" for="contact-type-autre">Précisez</label>
<input class="w-full px-4 py-3 rounded-xl bg-surface text-on-surface placeholder:text-outline focus:outline-none focus:ring-2 focus:ring-primary text-sm transition-all duration-200 champ-contact" id="contact-type-autre" maxlength="60" name="type_contact_autre" placeholder="Précisez (ex : association, journaliste, grossiste…)" type="text"/>
<p class="erreur-champ hidden mt-2 flex items-center gap-1.5 text-xs font-semibold text-error font-body" id="contact-type-autre-erreur"><span class="material-symbols-outlined text-base">error</span><span class="erreur-texte"></span></p>
</div>
<p class="erreur-champ hidden mt-2 flex items-center gap-1.5 text-xs font-semibold text-error font-body" id="contact-type-erreur"><span class="material-symbols-outlined text-base">error</span><span class="erreur-texte"></span></p>
</div>
<div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
<div>
<label class="block text-xs font-bold uppercase tracking-wider text-on-surface-variant font-label mb-2" for="contact-name"><span>Nom complet</span><span class="text-tertiary ml-0.5" aria-hidden="true">*</span></label>
<input autocomplete="name" class="w-full px-4 py-3 rounded-xl bg-surface text-on-surface placeholder:text-outline focus:outline-none focus:ring-2 focus:ring-primary text-sm transition-all duration-200 champ-contact" id="contact-name" maxlength="80" name="name" placeholder="Votre nom" type="text"/>
<p class="erreur-champ hidden mt-2 flex items-center gap-1.5 text-xs font-semibold text-error font-body" id="contact-name-erreur"><span class="material-symbols-outlined text-base">error</span><span class="erreur-texte"></span></p>
</div>
<div>
<label class="block text-xs font-bold uppercase tracking-wider text-on-surface-variant font-label mb-2" for="contact-email"><span>Email</span><span class="text-tertiary ml-0.5" aria-hidden="true">*</span></label>
<input autocomplete="email" class="w-full px-4 py-3 rounded-xl bg-surface text-on-surface placeholder:text-outline focus:outline-none focus:ring-2 focus:ring-primary text-sm transition-all duration-200 champ-contact" id="contact-email" maxlength="120" name="email" placeholder="vous@exemple.com" type="email"/>
<p class="erreur-champ hidden mt-2 flex items-center gap-1.5 text-xs font-semibold text-error font-body" id="contact-email-erreur"><span class="material-symbols-outlined text-base">error</span><span class="erreur-texte"></span></p>
</div>
</div>
<div>
<label class="block text-xs font-bold uppercase tracking-wider text-on-surface-variant font-label mb-2" for="contact-subject">Sujet</label>
<input class="w-full px-4 py-3 rounded-xl bg-surface text-on-surface placeholder:text-outline focus:outline-none focus:ring-2 focus:ring-primary text-sm transition-all duration-200 champ-contact" id="contact-subject" maxlength="120" name="subject" placeholder="Objet de votre message" type="text"/>
<p class="erreur-champ hidden mt-2 flex items-center gap-1.5 text-xs font-semibold text-error font-body" id="contact-subject-erreur"><span class="material-symbols-outlined text-base">error</span><span class="erreur-texte"></span></p>
</div>
<div>
<label class="block text-xs font-bold uppercase tracking-wider text-on-surface-variant font-label mb-2" for="contact-message"><span>Message</span><span class="text-tertiary ml-0.5" aria-hidden="true">*</span></label>
<textarea class="w-full px-4 py-3 rounded-xl bg-surface text-on-surface placeholder:text-outline focus:outline-none focus:ring-2 focus:ring-primary text-sm transition-all duration-200 champ-contact resize-none" id="contact-message" maxlength="2000" name="message" placeholder="Votre message..." rows="5"></textarea>
<div class="mt-2 flex items-start justify-between gap-3">
<p class="erreur-champ hidden flex items-center gap-1.5 text-xs font-semibold text-error font-body" id="contact-message-erreur"><span class="material-symbols-outlined text-base">error</span><span class="erreur-texte"></span></p>
<span class="ml-auto shrink-0 text-[11px] text-on-surface-variant font-label tabular-nums" id="contact-message-compteur">0 / 2000</span>
</div>
</div>
<p class="text-[11px] text-on-surface-variant font-body"><span class="text-tertiary">*</span> <span>Champs obligatoires</span></p>
<button class="btn-pop w-full inline-flex items-center justify-center gap-2 px-6 py-3.5 rounded-xl bg-primary text-on-primary font-bold text-sm tracking-wide shadow-md hover:bg-primary-container hover:text-on-primary-container" type="submit">
<span class="material-symbols-outlined text-lg">send</span>
                Envoyer le message
              </button>
<p aria-live="polite" class="text-xs text-center text-on-surface-variant" id="contact-status"></p>
</form>
</div>
</div>
</div>
</section>
</div>
<div class="hidden w-full" id="detail-histoire">
<!-- Bannière d'ouverture -->
<section class="relative w-full h-[60vh] min-h-[420px] flex items-end overflow-hidden">
<div class="absolute inset-0">
<img data-banniere="1" alt="Oliveraie tunisienne au coucher du soleil" class="w-full h-full object-cover ken-burns-slow" src="assets/images/histoire-ouverture.jpg" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='assets/images/placeholder-photo.svg';"/>
<div class="absolute inset-0 bg-gradient-to-t from-black/75 via-black/25 to-black/10"></div>
<div class="absolute inset-x-0 top-0 h-28 sm:h-32 bg-gradient-to-b from-black/55 to-transparent"></div>
</div>
<div class="histoire-devise expertise-fade-up" style="animation-delay:.45s"><span class="devise-trait" aria-hidden="true"></span><p class="devise-l1">Quand le soleil</p><p class="devise-l2">rencontre l'oliveraie.</p></div>
<div class="relative z-10 max-w-7xl mx-auto px-6 lg:px-12 pb-14 w-full">
<span class="text-xs font-bold uppercase tracking-[0.3em] font-label text-white/80 expertise-fade-up" style="animation-delay:.05s">L'Héritage Orzeta</span>
<h1 class="mt-3 font-headline text-4xl sm:text-5xl lg:text-6xl font-bold text-white expertise-fade-up" style="text-shadow:0 2px 20px rgba(0,0,0,.5);animation-delay:.15s">Notre Histoire</h1>
<p class="mt-4 text-white/90 text-sm sm:text-base max-w-xl font-body expertise-fade-up" style="text-shadow:0 1px 8px rgba(0,0,0,.4);animation-delay:.25s">Un terroir façonné par plus de deux mille ans d'histoire oléicole, entre la Méditerranée et le soleil tunisien.</p>
</div>
</section>
<!-- Bandeau de confiance : signaux vérifiés -->
<section class="w-full py-10 bg-surface-container-low border-y border-outline/10">
<div class="max-w-6xl mx-auto px-6 lg:px-12">
<div class="grid grid-cols-2 sm:grid-cols-4 gap-6 sm:gap-4">
<div class="flex flex-col items-center text-center gap-2 reveal-item" style="transition-delay:.05s">
<span class="material-symbols-outlined text-3xl text-primary">verified</span>
<p class="text-xs sm:text-[13px] font-bold text-on-surface font-label leading-snug">Agriculture biologique</p>
</div>
<div class="flex flex-col items-center text-center gap-2 reveal-item" style="transition-delay:.15s">
<span class="material-symbols-outlined text-3xl text-primary">workspace_premium</span>
<p class="text-xs sm:text-[13px] font-bold text-on-surface font-label leading-snug"><span class="rang-fr">2<sup>e</sup> </span>producteur mondial<br/>d'huile d'olive</p>
</div>
<div class="flex flex-col items-center text-center gap-2 reveal-item" style="transition-delay:.25s">
<span class="material-symbols-outlined text-3xl text-primary">eco</span>
<p class="text-xs sm:text-[13px] font-bold text-on-surface font-label leading-snug"><span class="rang-fr">1<sup>er</sup> </span>exportateur mondial<br/>d'huile d'olive bio</p>
</div>
<div class="flex flex-col items-center text-center gap-2 reveal-item" style="transition-delay:.35s">
<span class="material-symbols-outlined text-3xl text-primary">agriculture</span>
<p class="text-xs sm:text-[13px] font-bold text-on-surface font-label leading-snug">Récolte &amp; pressage<br/>à froid, savoir-faire artisanal</p>
</div>
</div>
</div>
</section>
<!-- Blocs chronologiques -->
<section class="w-full pt-20 pb-20 bg-surface">
<div class="max-w-7xl mx-auto px-6 lg:px-12 space-y-24">
<!-- Bloc 1 : Racines antiques -->
<div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-16 items-center">
<div class="lg:col-span-6 reveal-item">
<div class="relative rounded-3xl overflow-hidden shadow-lg group shine-card">
<img alt="Site archéologique romain et olivier, Tunisie" class="w-full h-[380px] object-cover transition-transform duration-700 group-hover:scale-105" src="assets/images/histoire-racines-antiques.jpg" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='assets/images/placeholder-photo.svg';"/>
</div>
</div>
<div class="lg:col-span-6 reveal-item" style="transition-delay:.15s">
<span class="text-xs font-bold uppercase tracking-widest font-label text-tertiary">Depuis l'Antiquité</span>
<h2 class="mt-2 text-2xl sm:text-3xl font-headline font-bold text-on-surface">Des racines plus vieilles que Rome</h2>
<div class="w-10 h-px bg-tertiary/40 my-4"></div>
<p class="text-sm sm:text-base text-on-surface-variant font-body leading-relaxed">Dès l'époque de Carthage, vers 814 av. J.-C., l'olivier s'enracine sur cette terre. Mago, agronome carthaginois, rédige le traité agricole de référence du monde antique — un texte si précieux que Rome le fait traduire après avoir détruit la ville. Sous l'Empire romain, la Tunisie devient l'un des greniers à huile de la Méditerranée : la richesse tirée de l'huile d'olive finance jusqu'à l'amphithéâtre d'El Jem.</p>
</div>
</div>
<!-- Bloc 2 : Arbres centenaires -->
<div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-16 items-center">
<div class="lg:col-span-6 lg:order-2 reveal-item">
<div class="relative rounded-3xl overflow-hidden shadow-lg group shine-card">
<img alt="Olivier centenaire au tronc noueux, Tunisie" class="w-full h-[380px] object-cover transition-transform duration-700 group-hover:scale-105" src="assets/images/histoire-arbre-centenaire.jpg" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='assets/images/placeholder-photo.svg';"/>
</div>
</div>
<div class="lg:col-span-6 lg:order-1 reveal-item" style="transition-delay:.15s">
<span class="text-xs font-bold uppercase tracking-widest font-label text-tertiary">Des arbres toujours vivants</span>
<h2 class="mt-2 text-2xl sm:text-3xl font-headline font-bold text-on-surface">Certains oliviers ont vu passer 25 siècles</h2>
<div class="w-10 h-px bg-tertiary/40 my-4"></div>
<p class="text-sm sm:text-base text-on-surface-variant font-body leading-relaxed">Près d'El Haouaria, au Cap Bon, un olivier planté à l'époque carthaginoise produit encore aujourd'hui — environ 2 500 ans d'âge. Dans le Sahel, les terrasses d'irrigation traditionnelles « meskat », conçues il y a des siècles, fonctionnent toujours. Cette continuité vivante est rare : peu de cultures au monde peuvent se targuer d'un tel héritage encore en production.</p>
</div>
</div>
<!-- Bloc 3 : Terroir de Sfax et variétés -->
<div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-16 items-center">
<div class="lg:col-span-6 reveal-item">
<div class="relative rounded-3xl overflow-hidden shadow-lg group shine-card">
<img alt="Oliveraie aride de Sfax, Tunisie" class="w-full h-[380px] object-cover transition-transform duration-700 group-hover:scale-105" src="assets/images/histoire-terroir-sfax.jpg" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='assets/images/placeholder-photo.svg';"/>
<div class="absolute bottom-4 right-4 w-24 h-24 sm:w-28 sm:h-28 rounded-2xl overflow-hidden shadow-xl ring-4 ring-surface">
<img alt="Olives Chemlali mûres sur la branche" class="w-full h-full object-cover" src="assets/images/histoire-terroir-varietes.jpg" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='assets/images/placeholder-photo.svg';"/>
</div>
</div>
</div>
<div class="lg:col-span-6 reveal-item" style="transition-delay:.15s">
<span class="text-xs font-bold uppercase tracking-widest font-label text-tertiary">Le terroir de Sfax</span>
<h2 class="mt-2 text-2xl sm:text-3xl font-headline font-bold text-on-surface">Un sol aride, une olive généreuse</h2>
<div class="w-10 h-px bg-tertiary/40 my-4"></div>
<p class="text-sm sm:text-base text-on-surface-variant font-body leading-relaxed">À Sfax, les oliviers poussent espacés, sur un sol sec et sablonneux, sans irrigation — une conduite exigeante qui concentre les arômes. C'est le territoire de la Chemlali, variété dorée et douce qui domine le centre et le sud du pays, aux côtés de la Chetoui, plus poivrée, cultivée au nord.</p>
</div>
</div>
</div>
</section>
<!-- Bande de chiffres : la Tunisie aujourd'hui -->
<section class="w-full py-20 bg-primary relative overflow-hidden" id="histoire-chiffres">
<div class="max-w-6xl mx-auto px-6 lg:px-12 relative z-10 text-center">
<span class="text-xs font-bold uppercase tracking-widest font-label text-on-primary/70 reveal-item">En chiffres</span>
<h2 class="mt-2 text-2xl sm:text-3xl font-headline font-bold text-on-primary reveal-item" style="transition-delay:.1s">La Tunisie aujourd'hui</h2>
<div class="mt-12 grid grid-cols-1 sm:grid-cols-3 gap-8">
<div class="reveal-item" style="transition-delay:.15s">
<div class="text-4xl sm:text-5xl font-headline font-bold text-on-primary counter-value" data-target="80">0</div>
<div class="text-sm text-on-primary/80 font-label mt-1">millions d'oliviers</div>
</div>
<div class="reveal-item" style="transition-delay:.25s">
<div class="text-4xl sm:text-5xl font-headline font-bold text-on-primary counter-value" data-suffix="e" data-target="2" data-rang-ar="المرتبة الثانية">0</div>
<div class="text-sm text-on-primary/80 font-label mt-1">producteur mondial d'huile d'olive</div>
</div>
<div class="reveal-item" style="transition-delay:.35s">
<div class="text-4xl sm:text-5xl font-headline font-bold text-on-primary counter-value" data-suffix="er" data-target="1" data-rang-ar="المرتبة الأولى">0</div>
<div class="text-sm text-on-primary/80 font-label mt-1">exportateur mondial d'huile bio</div>
</div>
</div>
<p class="mt-10 max-w-2xl mx-auto text-sm text-on-primary/85 font-body leading-relaxed reveal-item" style="transition-delay:.45s">L'huile d'olive représente plus de la moitié des exportations agroalimentaires tunisiennes — le fruit d'une agriculture sèche, peu mécanisée, mais d'une résilience remarquable face au climat méditerranéen.</p>
</div>
</section>
<!-- Bienfaits santé -->
<section class="w-full py-20 md:py-24 bg-surface">
<div class="max-w-7xl mx-auto px-6 lg:px-12">
<div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-16 items-center">
<div class="lg:col-span-6">
<div class="relative rounded-3xl overflow-hidden shadow-lg group shine-card">
<img alt="Table méditerranéenne avec huile d'olive vierge extra" class="w-full h-[380px] object-cover transition-transform duration-700 group-hover:scale-105" src="assets/images/histoire-bienfaits.jpg" loading="lazy" decoding="async" onerror="this.onerror=function(){this.onerror=null;this.src='assets/images/placeholder-photo.svg';};this.src='assets/images/histoire-bienfaits.jpg.png';"/>
</div>
</div>
<div class="lg:col-span-6">
<span class="text-xs font-bold uppercase tracking-widest font-label text-tertiary">Bien plus qu'un ingrédient</span>
<h2 class="mt-2 text-2xl sm:text-3xl font-headline font-bold text-on-surface">Les bienfaits d'une huile vierge extra</h2>
<div class="w-10 h-px bg-tertiary/40 my-4"></div>
<div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mt-2">
<div class="flex items-start gap-3">
<span class="material-symbols-outlined text-primary">favorite</span>
<div><p class="text-sm font-bold text-on-surface">Cœur &amp; cardiovasculaire</p><p class="text-xs text-on-surface-variant mt-0.5">Riche en acides gras mono-insaturés</p></div>
</div>
<div class="flex items-start gap-3">
<span class="material-symbols-outlined text-primary">verified</span>
<div><p class="text-sm font-bold text-on-surface">Antioxydants</p><p class="text-xs text-on-surface-variant mt-0.5">Polyphénols qui protègent les cellules</p></div>
</div>
<div class="flex items-start gap-3">
<span class="material-symbols-outlined text-primary">eco</span>
<div><p class="text-sm font-bold text-on-surface">Anti-inflammatoire</p><p class="text-xs text-on-surface-variant mt-0.5">Propriétés naturelles reconnues</p></div>
</div>
<div class="flex items-start gap-3">
<span class="material-symbols-outlined text-primary">restaurant</span>
<div><p class="text-sm font-bold text-on-surface">Régime méditerranéen</p><p class="text-xs text-on-surface-variant mt-0.5">Pilier d'une alimentation équilibrée</p></div>
</div>
</div>
</div>
</div>
</div>
</section>
<!-- Tradition vivante (clôture) -->
<section class="relative w-full h-[70vh] min-h-[480px] flex items-center overflow-hidden">
<div class="absolute inset-0">
<img alt="Récolte traditionnelle des olives en Tunisie" class="w-full h-full object-cover ken-burns-slow" src="assets/images/histoire-tradition-recolte.jpg" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='assets/images/placeholder-photo.svg';"/>
<div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/35 to-black/15"></div>
</div>
<div class="relative z-10 max-w-3xl mx-auto px-6 text-center reveal-item">
<span class="text-xs font-bold uppercase tracking-widest font-label text-white/80">Une tradition transmise</span>
<p class="mt-4 font-headline italic text-2xl sm:text-3xl text-white leading-snug" style="text-shadow:0 2px 16px rgba(0,0,0,.5)">« La récolte reste, en Tunisie, un moment de famille — un geste transmis, presque inchangé, de génération en génération. »</p>
<a class="btn-pop mt-8 inline-flex items-center justify-center gap-2 px-7 py-3.5 rounded-xl bg-primary text-on-primary font-bold text-xs uppercase tracking-widest hover:bg-primary-container hover:text-on-primary-container" href="#" onclick="showDetailPage('produits'); return false;">Découvrir nos huiles <span class="material-symbols-outlined text-sm">arrow_forward</span></a>
</div>
</section>
<script>
(function() {
  var items = document.querySelectorAll('#detail-histoire .reveal-item');
  if (items.length) {
    var histoireItemObserver = new IntersectionObserver(function(entries) {
      entries.forEach(function(entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('active');
          histoireItemObserver.unobserve(entry.target);
        }
      });
    }, { threshold: 0.15 });
    items.forEach(function(el) { histoireItemObserver.observe(el); });
  }
})();
(function() {
  var counters = document.querySelectorAll('#detail-histoire .counter-value');
  if (!counters.length) { return; }
  function animateCounter(el) {
    var target = parseFloat(el.getAttribute('data-target')) || 0;
    var duration = 1400;
    var start = null;
    function step(ts) {
      if (!start) { start = ts; }
      var progress = Math.min((ts - start) / duration, 1);
      var eased = 1 - Math.pow(1 - progress, 3);
      var value = Math.round(target * eased);
      el.textContent = (progress >= 1) ? texteCompteur(el) : value + suffixeCompteur(el);
      if (progress < 1) { requestAnimationFrame(step); } else { el.setAttribute('data-fini', '1'); }
    }
    requestAnimationFrame(step);
  }
  var counterObserver = new IntersectionObserver(function(entries) {
    entries.forEach(function(entry) {
      if (entry.isIntersecting) {
        animateCounter(entry.target);
        counterObserver.unobserve(entry.target);
      }
    });
  }, { threshold: 0.4 });
  counters.forEach(function(el) { counterObserver.observe(el); });
})();
</script>
</div>
</main><footer class="w-full bg-surface-container-low text-on-surface pt-16 pb-12 relative overflow-hidden">
<svg class="absolute -right-6 -bottom-10 w-56 h-56 text-primary/15 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 200 200"><path d="M20 190 Q 70 130 130 90 Q 160 70 185 30"/><ellipse cx="140" cy="82" rx="14" ry="8" transform="rotate(-35 140 82)" fill="currentColor" stroke="none"/><ellipse cx="112" cy="104" rx="14" ry="8" transform="rotate(-35 112 104)" fill="currentColor" stroke="none"/><ellipse cx="86" cy="126" rx="14" ry="8" transform="rotate(-35 86 126)" fill="currentColor" stroke="none"/><ellipse cx="60" cy="148" rx="14" ry="8" transform="rotate(-35 60 148)" fill="currentColor" stroke="none"/><ellipse cx="160" cy="58" rx="10" ry="6" transform="rotate(-35 160 58)" fill="currentColor" stroke="none"/></svg>
<svg class="absolute -left-10 -top-8 w-40 h-40 text-primary/10 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 200 200"><path d="M180 20 Q 130 80 70 110 Q 40 130 15 170"/><ellipse cx="60" cy="118" rx="12" ry="7" transform="rotate(35 60 118)" fill="currentColor" stroke="none"/><ellipse cx="88" cy="96" rx="12" ry="7" transform="rotate(35 88 96)" fill="currentColor" stroke="none"/><ellipse cx="116" cy="74" rx="12" ry="7" transform="rotate(35 116 74)" fill="currentColor" stroke="none"/></svg>
<div class="max-w-7xl mx-auto px-6 lg:px-12 relative z-10"><div class="grid grid-cols-1 lg:grid-cols-12 gap-12 pb-14"><div class="lg:col-span-6 space-y-4"><span class="text-2xl font-headline font-bold tracking-tight text-primary block">ORZETA</span><p class="text-sm text-on-surface-variant font-body leading-relaxed max-w-sm mt-3">La nature l'a perfectionnée. Nous l'avons mise en bouteille.</p><p class="text-xs text-tertiary font-label font-bold uppercase tracking-wider mt-2">Née en Tunisie · Faite pour le monde</p>
<!-- TODO Orzeta : remplacez les href="#" ci-dessous par vos vraies pages Instagram / Facebook dès qu'elles existent. -->
<div class="flex items-center gap-3 pt-1">
<a aria-label="Instagram Orzeta" class="w-10 h-10 rounded-full bg-surface flex items-center justify-center text-on-surface-variant hover:text-primary hover:shadow-md transition-all" href="#" target="_blank" rel="noopener"><svg fill="none" height="18" viewBox="0 0 24 24" width="18"><path d="M7 2h10a5 5 0 0 1 5 5v10a5 5 0 0 1-5 5H7a5 5 0 0 1-5-5V7a5 5 0 0 1 5-5Z" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="1.8"/><circle cx="17.2" cy="6.8" fill="currentColor" r="1.1"/></svg></a>
<a aria-label="Facebook Orzeta" class="w-10 h-10 rounded-full bg-surface flex items-center justify-center text-on-surface-variant hover:text-primary hover:shadow-md transition-all" href="#" target="_blank" rel="noopener"><svg fill="none" height="18" viewBox="0 0 24 24" width="18"><path d="M15 8.5h2V5.2c-.35-.05-1.55-.15-2.95-.15-2.92 0-4.92 1.78-4.92 5.05v2.6H6.3v3.7h2.83V22h3.72v-5.6h2.8l.44-3.7h-3.24v-2.27c0-1.07.29-1.8 1.85-1.8Z" fill="currentColor"/></svg></a>
</div>
</div><div class="lg:col-span-6 space-y-3"><h4 class="text-xs font-bold uppercase tracking-wider text-on-surface font-label">Découvrir</h4><ul class="space-y-2 text-sm text-on-surface-variant font-body"><li><a class="hover:text-primary transition-colors" href="#" onclick="showDetailPage('produits'); return false;">Nos Produits</a></li><li><a class="hover:text-primary transition-colors" href="#" onclick="showDetailPage('recettes'); return false;">Nos Recettes</a></li><li><a class="hover:text-primary transition-colors" href="#" onclick="showDetailPage('histoire'); return false;">Notre Histoire</a></li><li><a class="hover:text-primary transition-colors" href="#" onclick="showDetailPage('contact'); return false;">Contact</a></li></ul></div></div><div class="pt-8 flex flex-col md:flex-row items-center justify-between gap-4 text-xs text-on-surface-variant"><p>© 2026 Orzeta. Tous droits réservés.</p></div></div></footer>
<div class="fixed inset-0 z-[100] hidden flex items-center justify-center p-6" id="image-lightbox" onclick="if(event.target===this){closeImageLightbox();}">
<button aria-label="Fermer" class="absolute top-6 right-6 md:top-8 md:right-10 w-11 h-11 rounded-full bg-surface/95 backdrop-blur-sm shadow-lg flex items-center justify-center text-on-surface hover:bg-surface hover:scale-110 transition-all duration-300" id="lightbox-close" onclick="closeImageLightbox()" type="button"><span class="material-symbols-outlined">close</span></button>
<img alt="" class="max-h-[85vh] max-w-[92vw] md:max-w-[70vw] object-contain rounded-2xl shadow-2xl" id="lightbox-img" src=""/>
</div>
<script>
var SITE_I18N = {"Aller au contenu principal": "Skip to main content", "Huile d'Olive": "Olive Oil", "Expertise & Fabrication": "Expertise & Craftsmanship", "Accueil": "Home", "Acidité": "Acidity", "Découvrir": "Discover", "Sfax, Tunisie": "Sfax, Tunisia", "Acidité ≤ 0,3%": "Acidity ≤ 0.3%", "Extraction à froid": "Cold extraction", "Contactez-nous": "Contact Us", "Notre équipe vous répond sous 24h, du dimanche au vendredi.": "Our team replies within 24h, Sunday to Friday.", "Téléphone": "Phone", "Adresse": "Address", "Tunisie, Méditerranée": "Tunisia, Mediterranean", "Nom complet": "Full Name", "Sujet": "Subject", "Message": "Message", "Envoyer le message": "Send Message", "Notre Gamme": "Our Range", "Idéal avec": "Ideal With", "Énergie": "Energy", "Matières grasses": "Fat", "© 2026 Orzeta. Tous droits réservés.": "© 2026 Orzeta. All rights reserved.", "Mentions Légales": "Legal Notice", "e": "nd", "Sun Signature": "Sun Signature", "Grove Signature": "Grove Signature", "Organic Signature": "Organic Signature", "Notre Histoire": "Our Story", "La Collection": "The Collection", "Trouvez votre Signature": "Find your Signature", "Une origine. Trois expressions différentes d'huile d'olive vierge extra. Des personnalités différentes, un même engagement Orzeta envers l'excellence.": "One origin. Three different expressions of Extra Virgin Olive Oil. Different personalities. The same ORZETA commitment to excellence.", "Trois expressions. Une seule origine méditerranéenne.": "Three Expressions. One Mediterranean Origin.", "Lumineuse par nature. Certaines huiles chuchotent, la Sun Signature rayonne. Expressive sans jamais dominer, elle apporte de la lumière à chaque plat tout en laissant les ingrédients frais au centre de l'assiette. Obtenue uniquement par des procédés mécaniques à froid, sans aucun traitement chimique.": "Bright by nature. Some oils whisper. SUN SIGNATURE shines. Expressive without overpowering, it brings brightness to every dish while allowing fresh ingredients to remain at the center. Obtained solely through cold mechanical processes, with no chemical treatment.", "Cuisine premium au quotidien, recettes d'inspiration méditerranéenne, finition de plats — à savourer de préférence crue pour préserver tous ses polyphénols.": "Everyday premium cooking, Mediterranean-inspired recipes, finishing — best enjoyed raw to preserve all its polyphenols.", "« Une touche de lumière méditerranéenne dans chaque assiette. »": "“A touch of Mediterranean light on every plate.”", "« La force du verger, capturée dans chaque goutte. »": "“The strength of the grove, captured in every drop.”", "« Rien de superflu. Simplement de l'huile d'olive vierge extra, telle que la nature l'a voulue. »": "“Nothing unnecessary. Simply Extra Virgin Olive Oil as nature intended.”", "La nature l'a perfectionnée. Nous l'avons mise en bouteille.": "Nature Perfected It. We Bottled It.", "Née en Tunisie · Faite pour le monde": "Born in Tunisia · Made for the World", "Nos Produits": "Our Products", "L'Histoire Orzeta": "The Orzeta Story", "Née sous le soleil méditerranéen": "Born Under the Mediterranean Sun", "Nos Recettes": "Our Recipes", "Des idées simples et gourmandes pour sublimer chaque plat.": "Simple and delicious ideas to elevate every dish.", "Signature Orzeta": "Orzeta Signature", "L'Héritage Orzeta": "The Orzeta Heritage", "Un terroir façonné par plus de deux mille ans d'histoire oléicole, entre la Méditerranée et le soleil tunisien.": "A terroir shaped by more than two thousand years of olive-growing history, between the Mediterranean and the Tunisian sun.", "« Il y a des endroits où l'huile d'olive se fabrique. Et il y a la Tunisie, où elle est chez elle depuis l'Antiquité. »": "“There are places where olive oil is made. And there is Tunisia, where it has belonged since antiquity.”", "Depuis l'Antiquité": "Since Antiquity", "Des racines plus vieilles que Rome": "Roots Older Than Rome", "Des arbres toujours vivants": "Trees Still Alive Today", "Certains oliviers ont vu passer 25 siècles": "Some Olive Trees Have Seen 25 Centuries", "Le terroir de Sfax": "The Terroir of Sfax", "Un sol aride, une olive généreuse": "Arid Soil, a Generous Olive", "À Sfax, les oliviers poussent espacés, sur un sol sec et sablonneux, sans irrigation — une conduite exigeante qui concentre les arômes. C'est le territoire de la Chemlali, variété dorée et douce qui domine le centre et le sud du pays, aux côtés de la Chetoui, plus poivrée, cultivée au nord.": "In Sfax, olive trees grow widely spaced, on dry, sandy soil, without irrigation — a demanding practice that concentrates flavor. This is the land of the Chemlali, a golden, mild variety that dominates the center and south of the country, alongside the more peppery Chetoui grown in the north.", "En chiffres": "By the Numbers", "La Tunisie aujourd'hui": "Tunisia Today", "millions d'oliviers": "million olive trees", "producteur mondial": "world producer", "exportateur mondial d'huile bio": "world exporter of organic oil", "L'huile d'olive représente plus de la moitié des exportations agroalimentaires tunisiennes — le fruit d'une agriculture sèche, peu mécanisée, mais d'une résilience remarquable face au climat méditerranéen.": "Olive oil accounts for more than half of Tunisia's agri-food exports — the result of dry, largely unmechanized farming with remarkable resilience to the Mediterranean climate.", "Bien plus qu'un ingrédient": "More Than an Ingredient", "Les bienfaits d'une huile vierge extra": "The Benefits of an Extra Virgin Oil", "Riche en acides gras mono-insaturés": "Rich in monounsaturated fats", "Antioxydants": "Antioxidants", "Polyphénols qui protègent les cellules": "Polyphenols that protect cells", "Anti-inflammatoire": "Anti-inflammatory", "Propriétés naturelles reconnues": "Recognized natural properties", "Régime méditerranéen": "Mediterranean diet", "Pilier d'une alimentation équilibrée": "A pillar of balanced eating", "Une tradition transmise": "A Living Tradition", "« La récolte reste, en Tunisie, un moment de famille — un geste transmis, presque inchangé, de génération en génération. »": "“In Tunisia, the harvest remains a family moment — a gesture passed down, almost unchanged, from generation to generation.”", "Découvrir nos huiles": "Discover Our Oils", "Deux mille ans d'histoire, entre soleil et Méditerranée.": "Two thousand years of history, between sun and the Mediterranean.", "er": "st", "exportateur mondial": "world exporter", "d'huile d'olive": "of olive oil", "d'huile d'olive bio": "of organic olive oil", "à froid, savoir-faire artisanal": "cold, artisanal know-how", "Restons en Contact": "Let's Stay in Touch", "Une question, un projet, une commande professionnelle ? Notre équipe vous répond sous 24h, du dimanche au vendredi.": "A question, a project, a trade order? Our team replies within 24h, Sunday to Friday.", "La nature l'a perfectionnée.": "Nature Perfected It.", "Nous l'avons mise en bouteille.": "We Bottled It.", "Née en Tunisie. Faite pour le monde.": "Born in Tunisia. Made for the World.", "En quelques mots": "In a Few Words", "Le caractère": "The Character", "Une huile douce et légère, la plus accessible de la gamme — celle qu'on utilise tous les jours, sans hésiter.": "A soft, light oil, the most approachable in the range — the one you reach for every day, without thinking twice.", "Une huile plus corsée, au caractère affirmé — pour celles et ceux qui aiment qu'une huile se remarque dans l'assiette.": "A bolder oil with real character — for those who like an olive oil you actually notice on the plate.", "Une huile fraîche et pure, issue de l'agriculture biologique. Acidité ≤ 0,3% · Extraction à froid · Conservation 18 à 24 mois.": "A fresh, pure oil from organic farming. Acidity ≤ 0.3% · Cold extraction · Shelf life 18 to 24 months.", "Lumineuse par nature. Certaines huiles chuchotent, la Sun Signature rayonne : une huile d'olive vierge extra douce et lumineuse, qui apporte de la fraîcheur à chaque plat sans jamais couvrir les ingrédients.": "Bright by nature. Some oils whisper. SUN SIGNATURE shines: a soft, luminous Extra Virgin Olive Oil that brings freshness to every dish without ever covering the ingredients.", "Enracinée dans le caractère. Plus profonde, plus audacieuse, plus expressive : la Grove Signature révèle une facette plus intense de l'huile d'olive vierge extra, pour celles et ceux qui aiment une huile qui se fait remarquer.": "Rooted in character. Deeper. Bolder. More expressive: The Grove Signature reveals a more intense side of Extra Virgin Olive Oil, for those who like an olive oil you actually notice.", "Lumineuse par nature, douce et légère — l'huile du quotidien, celle qu'on utilise sans hésiter.": "Bright by nature, soft and light — the everyday oil, the one you reach for without thinking twice.", "Enracinée dans le caractère, plus corsée et plus expressive — pour ceux qui aiment une huile qui se fait remarquer.": "Rooted in character, bolder and more expressive — for those who like an olive oil you actually notice.", "La nature, sans artifice : issue de l'agriculture biologique, pour celles et ceux qui recherchent authenticité et confiance.": "Nature, no embellishment: from organic farming, for those who seek authenticity and confidence.", "La nature, sans artifice. Orzeta Organic est notre huile d'olive vierge extra issue de l'agriculture biologique, pensée pour celles et ceux qui recherchent authenticité, approvisionnement responsable et confiance dans ce qu'ils mettent sur leur table.": "Nature, no embellishment. ORZETA Organic is our Extra Virgin Olive Oil from organic farming, created for those who seek authenticity, responsible sourcing and confidence in what they bring to their table.", "La nature, sans artifice. Huile d'olive vierge extra issue de l'agriculture biologique, obtenue directement du fruit de l'olivier par des procédés exclusivement mécaniques. Assemblage multivariétal (Chemlali, Koroneiki, Arbosana), 100% olives tunisiennes, sans pesticides ni intrants chimiques de synthèse. Extraction à froid (max. 27°C), sans ajout d'eau.": "Nature, no embellishment. Extra virgin olive oil from organic farming, obtained directly from the olive fruit through exclusively mechanical processes. Multi-varietal blend (Chemlali, Koroneiki, Arbosana), 100% Tunisian olives, without pesticides or synthetic chemical inputs. Cold extraction (max. 27°C), with no added water.", "Agriculture biologique": "Organic farming", "Enracinée dans le caractère. Plus profonde, plus audacieuse, plus expressive : la Grove Signature révèle une facette plus intense de l'huile d'olive vierge extra, conçue pour celles et ceux qui cherchent une huile qui ne se contente pas d'accompagner un plat, mais qui en devient partie intégrante.": "Rooted in character. Deeper. Bolder. More expressive: The Grove Signature reveals a more intense side of Extra Virgin Olive Oil, created for those who look for an olive oil that does more than accompany food — one that becomes part of the experience.", "Près d'El Haouaria, au Cap Bon, un olivier planté à l'époque carthaginoise produit encore aujourd'hui — environ 2 500 ans d'âge. Dans le Sahel, les terrasses d'irrigation traditionnelles « meskat », conçues il y a des siècles, fonctionnent toujours. Cette continuité vivante est rare : peu de cultures au monde peuvent se targuer d'un tel héritage encore en production.": "Near El Haouaria, on Cap Bon, an olive tree planted in Carthaginian times is still producing today — around 2,500 years old. In the Sahel, the traditional \"meskat\" irrigation terraces, designed centuries ago, are still in use. This living continuity is rare: few farming traditions in the world can claim such heritage still in production.", "Cœur & cardiovasculaire": "Heart & Cardiovascular", "Récolte & pressage": "Harvest & pressing", "La Signature Orzeta": "The Orzeta Signature", "À table": "At the Table", "La cuisine Orzeta": "The Orzeta Kitchen", "Choisissez votre huile": "Choose Your Oil", "Toutes": "All", "Aucune recette pour cette sélection.": "No recipes for this selection.", "Toutes les recettes": "All Recipes", "Voir": "View", "Pour": "Serves", "Préparation": "Prep", "Cuisson": "Cook", "Niveau": "Level", "Ingrédients": "Ingredients", "Le conseil Orzeta :": "The Orzeta tip:", "L'huile de cette recette": "The Oil in This Recipe", "Découvrir cette huile": "Discover This Oil", "Sans cuisson": "No cooking", "Facile": "Easy", "Intermédiaire": "Intermediate", "4 personnes": "4 people", "2 personnes": "2 people", "6 personnes": "6 people", "20 min": "20 min", "15 min": "15 min", "10 min": "10 min", "5 min": "5 min", "1 h": "1 hr", "1 h 10": "1 hr 10", "Slata Tounsia": "Slata Tounsia", "La salade nationale tunisienne, taillée très fin, où l'huile d'olive fait tout le travail d'assaisonnement.": "Tunisia's national salad, cut very fine, where the olive oil does all the seasoning work.", "Ne mélangez le thon qu'au dernier moment : il doit rester en morceaux visibles.": "Add the tuna at the very last moment: it should stay in visible pieces.", "4 tomates bien mûres": "4 very ripe tomatoes", "1 concombre": "1 cucumber", "1 poivron vert": "1 green pepper", "1 petit oignon rouge": "1 small red onion", "2 œufs durs": "2 hard-boiled eggs", "100 g de thon à l'huile, égoutté": "100 g tuna in oil, drained", "12 olives Chemlali": "12 Chemlali olives", "4 c. à s. d'Orzeta Sun": "4 tbsp Orzeta Sun", "Le jus d'un demi-citron": "Juice of half a lemon", "Sel, poivre": "Salt, pepper", "Taillez tomates, concombre, poivron et oignon en tout petits dés réguliers de 2 à 3 mm. C'est la finesse de la coupe qui fait la différence.": "Cut the tomatoes, cucumber, pepper and onion into very small, even 2–3 mm dice. The fineness of the cut is what makes the difference.", "Salez légèrement, laissez dégorger 5 minutes puis égouttez l'eau rendue.": "Salt lightly, let stand 5 minutes, then drain off the water released.", "Ajoutez le jus de citron, l'huile d'olive et le poivre, mélangez délicatement.": "Add the lemon juice, olive oil and pepper, and fold together gently.", "Dressez dans un plat creux, garnissez de thon émietté, d'œufs en quartiers et d'olives.": "Serve in a shallow dish topped with flaked tuna, egg quarters and olives.", "Servez frais, avec du pain pour saucer.": "Serve chilled, with bread to mop up the juices.", "Daurade grillée à la sfaxienne": "Grilled Sea Bream, Sfax Style", "Le poisson entier grillé comme sur la côte : peau croustillante, chair nacrée, et un filet d'huile crue à la sortie du gril.": "Whole grilled fish the way it's done on the coast: crisp skin, pearly flesh, and a stream of raw olive oil straight off the grill.", "L'huile versée à cru sur le poisson brûlant libère tous ses arômes : ne la mettez jamais avant la cuisson.": "Raw oil poured over hot fish releases all its aroma — never add it before cooking.", "1 daurade de 800 g, vidée et écaillée": "1 sea bream, 800 g, gutted and scaled", "1 citron": "1 lemon", "3 gousses d'ail": "3 garlic cloves", "1 bouquet de persil plat": "1 bunch flat-leaf parsley", "Incisez la peau du poisson trois fois de chaque côté, jusqu'à l'arête.": "Score the skin three times on each side, down to the bone.", "Mélangez l'ail haché, le persil ciselé, 2 c. à s. d'huile, du sel et du poivre. Garnissez l'intérieur du poisson et les incisions.": "Mix the chopped garlic, parsley, 2 tbsp of oil, salt and pepper. Fill the cavity and the scores.", "Grillez 10 minutes de chaque côté sur braises moyennes, ou au four à 220°C.": "Grill 10 minutes per side over medium coals, or roast at 220°C.", "À la sortie du gril, arrosez généreusement d'huile crue et de jus de citron.": "Straight off the grill, pour over plenty of raw olive oil and lemon juice.", "Servez immédiatement, avec une salade fraîche.": "Serve immediately with a fresh salad.", "Salade de fenouil, orange et olives": "Fennel, Orange and Olive Salad", "Croquante, acidulée, lumineuse. Une salade d'hiver méditerranéenne qui tient sur trois ingrédients et une bonne huile.": "Crunchy, sharp, bright. A Mediterranean winter salad that rests on three ingredients and one good oil.", "Laissez le fenouil mariner 10 minutes dans l'huile et le citron : il s'attendrit sans perdre son croquant.": "Let the fennel sit 10 minutes in the oil and lemon: it softens without losing its crunch.", "2 bulbes de fenouil": "2 fennel bulbs", "2 oranges": "2 oranges", "12 olives noires Chemlali": "12 black Chemlali olives", "Quelques feuilles de menthe fraîche": "A few fresh mint leaves", "3 c. à s. d'Orzeta Sun": "3 tbsp Orzeta Sun", "1 c. à s. de jus de citron": "1 tbsp lemon juice", "Fleur de sel, poivre du moulin": "Sea salt flakes, cracked pepper", "Émincez le fenouil le plus finement possible, idéalement à la mandoline. Réservez quelques pluches vertes.": "Slice the fennel as thinly as possible, ideally on a mandoline. Keep a few green fronds aside.", "Pelez les oranges à vif et levez les suprêmes au-dessus d'un bol pour récupérer le jus.": "Peel the oranges down to the flesh and cut out the segments over a bowl to catch the juice.", "Mélangez le fenouil avec le jus d'orange recueilli, le citron et l'huile d'olive.": "Toss the fennel with the collected orange juice, the lemon juice and the olive oil.", "Dressez en dôme, répartissez les suprêmes, les olives et la menthe.": "Pile it up, then arrange the orange segments, olives and mint on top.", "Terminez par la fleur de sel, un tour de moulin et servez bien frais.": "Finish with sea salt flakes, a turn of pepper, and serve well chilled.", "Poulpe grillé, citron et persil": "Grilled Octopus, Lemon and Parsley", "Tendre à l'intérieur, caramélisé à l'extérieur. Un plat de bord de mer qui demande une huile de caractère.": "Tender inside, caramelised outside. A seaside dish that calls for an oil with real character.", "Ne salez jamais l'eau de cuisson du poulpe : elle durcit la chair. Le sel vient à la fin.": "Never salt the octopus cooking water: it toughens the flesh. Salt comes at the end.", "1 poulpe de 1,2 kg, nettoyé": "1 octopus, 1.2 kg, cleaned", "1 oignon": "1 onion", "1 feuille de laurier": "1 bay leaf", "4 gousses d'ail": "4 garlic cloves", "5 c. à s. d'Orzeta Grove": "5 tbsp Orzeta Grove", "Piment doux en poudre, sel": "Sweet paprika, salt", "Plongez le poulpe dans une grande eau frémissante avec l'oignon et le laurier. Laissez cuire 45 min à 1 h : la pointe d'un couteau doit entrer sans résistance.": "Lower the octopus into a large pan of barely simmering water with the onion and bay leaf. Cook 45 minutes to 1 hour: a knife tip should slide in without resistance.", "Égouttez, laissez tiédir puis séparez les tentacules.": "Drain, let cool slightly, then separate the tentacles.", "Badigeonnez-les d'huile et grillez-les 3 à 4 minutes par face, jusqu'à ce que les ventouses caramélisent.": "Brush with oil and grill 3–4 minutes per side, until the suckers caramelise.", "Dressez, parsemez d'ail émincé, de persil ciselé et d'une pincée de piment doux.": "Plate up and scatter with sliced garlic, chopped parsley and a pinch of sweet paprika.", "Arrosez généreusement d'huile crue et de citron juste avant de servir.": "Pour over plenty of raw oil and lemon just before serving.", "Bruschetta tomate & basilic": "Tomato & Basil Bruschetta", "Quatre ingrédients, aucun endroit où se cacher. C'est exactement pour ça que la bruschetta est le meilleur test d'une huile d'olive.": "Four ingredients, nowhere to hide. Which is exactly why bruschetta is the best test of an olive oil.", "Le pain doit être grillé, pas séché : une croûte marquée et un cœur encore moelleux qui absorbe l'huile.": "The bread should be grilled, not dried out: a marked crust with a soft centre that soaks up the oil.", "8 tranches de pain de campagne": "8 slices of country bread", "4 tomates mûres": "4 ripe tomatoes", "2 gousses d'ail": "2 garlic cloves", "Une dizaine de feuilles de basilic": "About ten basil leaves", "Fleur de sel, poivre": "Sea salt flakes, pepper", "Concassez les tomates en petits dés, salez et laissez égoutter 10 minutes dans une passoire.": "Dice the tomatoes small, salt them and let them drain 10 minutes in a sieve.", "Grillez les tranches de pain jusqu'à obtenir des marques nettes et une surface bien sèche.": "Grill the bread until you get clear char marks and a properly dry surface.", "Frottez chaque tranche encore chaude avec une demi-gousse d'ail.": "Rub each slice, still hot, with half a garlic clove.", "Répartissez les tomates égouttées et le basilic déchiré à la main.": "Spoon over the drained tomatoes and the basil, torn by hand.", "Arrosez généreusement d'huile, fleur de sel, poivre, et servez aussitôt.": "Pour over plenty of oil, add sea salt and pepper, and serve straight away.", "Gigot d'agneau rôti aux herbes": "Herb-Roasted Leg of Lamb", "Le plat du dimanche. L'ail, le romarin et une huile corsée forment la croûte parfumée qui protège la chair.": "The Sunday roast. Garlic, rosemary and a bold oil form the fragrant crust that protects the meat.", "Le repos n'est pas optionnel : c'est lui qui redistribue les jus et rend la viande moelleuse.": "Resting isn't optional: it's what redistributes the juices and keeps the meat tender.", "1 gigot d'agneau de 2 kg": "1 leg of lamb, 2 kg", "8 gousses d'ail": "8 garlic cloves", "4 branches de romarin": "4 sprigs of rosemary", "Quelques branches de thym": "A few sprigs of thyme", "6 c. à s. d'Orzeta Grove": "6 tbsp Orzeta Grove", "Gros sel, poivre concassé": "Coarse salt, cracked pepper", "Sortez la viande du réfrigérateur 1 heure avant. Préchauffez le four à 220°C.": "Take the meat out of the fridge 1 hour ahead. Preheat the oven to 220°C.", "Piquez l'ail en lamelles dans la chair, frottez tout le gigot d'huile, puis d'herbes, de sel et de poivre.": "Stud the meat with slivers of garlic, rub the whole leg with oil, then with herbs, salt and pepper.", "Enfournez 20 minutes à 220°C pour saisir, puis baissez à 180°C et poursuivez 50 minutes pour une cuisson rosée.": "Roast 20 minutes at 220°C to sear, then drop to 180°C and continue 50 minutes for pink meat.", "Arrosez du jus de cuisson toutes les 20 minutes.": "Baste with the pan juices every 20 minutes.", "Laissez reposer 15 minutes sous une feuille d'aluminium avant de trancher, puis finissez d'un filet d'huile crue.": "Rest 15 minutes under foil before carving, then finish with a thread of raw oil.", "Caprese à l'huile d'olive": "Caprese with Olive Oil", "Tomate, mozzarella, basilic. Rien de plus. Ici l'huile n'accompagne pas le plat : elle en fait partie.": "Tomato, mozzarella, basil. Nothing else. Here the oil doesn't accompany the dish — it is part of it.", "Salez les tomates 5 minutes avant de dresser : elles rendent un jus qui se mélange à l'huile et forme la sauce.": "Salt the tomatoes 5 minutes before plating: they release a juice that mixes with the oil and becomes the dressing.", "3 tomates bien mûres": "3 very ripe tomatoes", "1 mozzarella di bufala de 250 g": "1 buffalo mozzarella, 250 g", "3 c. à s. d'Orzeta Organic": "3 tbsp Orzeta Organic", "Sortez la mozzarella du réfrigérateur 20 minutes avant : froide, elle n'a aucun goût.": "Take the mozzarella out of the fridge 20 minutes ahead: cold, it has no flavour at all.", "Coupez tomates et mozzarella en rondelles régulières d'environ 1 cm.": "Slice the tomatoes and mozzarella into even rounds about 1 cm thick.", "Disposez-les en les faisant se chevaucher, en glissant une feuille de basilic entre chaque.": "Arrange them overlapping, tucking a basil leaf between each one.", "Arrosez d'huile crue en filet continu.": "Pour over raw oil in a steady stream.", "Fleur de sel, poivre, et à table immédiatement.": "Sea salt, pepper, and straight to the table.", "Houmous crémeux à l'huile d'olive": "Creamy Hummus with Olive Oil", "Le sillon d'huile au centre n'est pas une décoration : c'est la moitié du goût.": "The groove of oil in the middle isn't decoration: it's half the flavour.", "Ajoutez l'eau de cuisson tiède petit à petit pendant le mixage : c'est le secret d'un houmous aérien.": "Add the warm cooking water gradually while blending: that's the secret to a light, airy hummus.", "250 g de pois chiches secs (ou 500 g cuits)": "250 g dried chickpeas (or 500 g cooked)", "3 c. à s. de tahini": "3 tbsp tahini", "1 gousse d'ail": "1 garlic clove", "5 c. à s. d'Orzeta Organic": "5 tbsp Orzeta Organic", "Cumin, paprika": "Cumin, paprika", "Sel": "Salt", "Faites tremper les pois chiches 12 heures, puis cuisez-les 1 heure jusqu'à ce qu'ils s'écrasent entre deux doigts.": "Soak the chickpeas 12 hours, then cook 1 hour until they crush easily between two fingers.", "Mixez-les avec le tahini, l'ail, le jus de citron, 3 c. à s. d'huile et un peu d'eau de cuisson, jusqu'à obtenir une texture parfaitement lisse.": "Blend with the tahini, garlic, lemon juice, 3 tbsp of oil and a little cooking water, until perfectly smooth.", "Salez, rectifiez en citron.": "Season with salt and adjust the lemon.", "Dressez dans une assiette creuse en creusant un sillon en spirale avec le dos d'une cuillère.": "Spread in a shallow dish, hollowing out a spiral groove with the back of a spoon.", "Remplissez le sillon d'huile crue, saupoudrez de paprika et de cumin, ajoutez quelques pois chiches entiers.": "Fill the groove with raw oil, dust with paprika and cumin, and add a few whole chickpeas.", "Tartare de saumon, citron et huile d'olive": "Salmon Tartare, Lemon and Olive Oil", "Pas de mayonnaise, pas de crème. L'huile d'olive tient lieu de sauce, donc elle doit être irréprochable.": "No mayonnaise, no cream. The olive oil stands in for the sauce, so it has to be flawless.", "Assaisonnez au dernier moment : le citron « cuit » le poisson et le fait blanchir s'il attend trop.": "Season at the last moment: lemon 'cooks' the fish and turns it pale if it sits too long.", "300 g de filet de saumon extra-frais, sans peau": "300 g extra-fresh salmon fillet, skinned", "1 échalote": "1 shallot", "Quelques brins d'aneth": "A few sprigs of dill", "1 citron (zeste et jus)": "1 lemon (zest and juice)", "Baies roses, fleur de sel, poivre": "Pink peppercorns, sea salt flakes, pepper", "Placez le saumon 20 minutes au congélateur : il se taille beaucoup plus net.": "Put the salmon in the freezer for 20 minutes: it cuts far more cleanly.", "Coupez-le au couteau en cubes réguliers de 5 mm. Surtout pas de mixeur.": "Cut it by knife into even 5 mm cubes. Never use a food processor.", "Mélangez avec l'échalote ciselée finement, l'aneth, le zeste et 1 c. à s. de jus de citron, puis l'huile.": "Mix with the finely chopped shallot, dill, lemon zest and 1 tbsp of lemon juice, then the oil.", "Assaisonnez et moulez à l'aide d'un cercle au centre de l'assiette.": "Season and mould with a ring in the centre of the plate.", "Terminez par un filet d'huile crue et quelques baies roses, servez aussitôt.": "Finish with a thread of raw oil and a few pink peppercorns, and serve at once.", "Filtrer les recettes par :": "Filter recipes by:", "Huile": "Oil", "Catégorie": "Category", "Temps": "Time", "Toutes les huiles": "All oils", "Toutes les catégories": "All categories", "Peu importe": "Any time", "Tous les niveaux": "All levels", "Apéritif": "Appetizer", "Entrée": "Starter", "Salade": "Salad", "Plat": "Main course", "recettes": "recipes", "Tout effacer": "Clear all", "Sublimez chaque plat": "Elevate Every Dish", "Voir toutes les recettes": "See All Recipes", "Voir toute la collection": "See the Full Collection", "Nos recettes avec cette huile": "Our Recipes With This Oil", "En savoir plus": "Learn more", "Conditionnements disponibles": "Available Sizes", "Produits associés": "Related Products", "Cliquez sur une bouteille pour voir la contenance correspondante.": "Click a bottle to see the matching size.", "Née au cœur de la Tunisie, ORZETA puise son identité dans une terre d’oliviers et un savoir-faire transmis à travers les générations.": "Born in the heart of Tunisia, ORZETA draws its identity from a land of olive trees and know-how passed down through generations.", "ORZETA transforme ainsi l’héritage tunisien en une expérience contemporaine.": "ORZETA thus turns Tunisian heritage into a contemporary experience.", "Une signature tunisienne pensée pour rayonner au-delà des frontières.": "A Tunisian signature designed to shine beyond borders.", "Déclaration nutritionnelle": "Nutrition facts", "Pour 100 mL": "Per 100 mL", "dont acides gras saturés": "of which saturates", "Glucides": "Carbohydrate", "dont sucres": "of which sugars", "Protéines": "Protein", "91,6 g": "91.6 g", "13,1 g": "13.1 g", "Huile d'olive vierge extra — Acidité < 0,8% — Extraction à froid": "Extra virgin olive oil — Acidity < 0.8% — Cold extraction", "Huile d'olive vierge extra — Acidité < 0,5% — Récolte de parcelles sélectionnées — Extraction à froid": "Extra virgin olive oil — Acidity < 0.5% — Harvest from selected plots — Cold extraction", "Huile d'olive vierge extra multivariétale biologique — Acidité ≤ 0,3% — Extraction à froid": "Organic multi-varietal extra virgin olive oil — Acidity ≤ 0.3% — Cold extraction", "Professionnels": "Professionals", "Distributeurs, restaurateurs, épiceries fines : faites entrer une huile d’olive vierge extra tunisienne d’exception dans votre offre.": "Distributors, restaurateurs, fine grocers: bring an exceptional Tunisian extra virgin olive oil into your range.", "Devenir partenaire": "Become a partner", "Restaurants & hôtels": "Restaurants & hotels", "Cadeaux d’entreprise": "Corporate gifts", "Une huile d’exception, pensée pour les tables et les rayons les plus exigeants.": "An exceptional oil, crafted for the most demanding tables and shelves.", "Grandissons ensemble.": "Let’s grow together.", "Qualité constante": "Consistent quality", "Extraction à froid et acidité maîtrisée selon la gamme.": "Cold extraction and controlled acidity for each range.", "Trois gammes": "Three ranges", "Sun, Grove et Organic, du format 250 mL au 1 L.": "Sun, Grove and Organic, from 250 mL to 1 L.", "Une expertise oléicole ancrée en Tunisie.": "Olive-growing expertise rooted in Tunisia.", "Accompagnement dédié": "Dedicated support", "Échantillons, fiches techniques et supports de présentation.": "Samples, technical sheets and presentation materials.", "Distributeurs · Restaurants & hôtels · Épiceries fines · Cadeaux d’entreprise": "Distributors · Restaurants & hotels · Fine grocers · Corporate gifts", "Champs obligatoires": "Required fields", "Vous êtes": "You are", "Particulier": "Individual", "Distributeur / importateur": "Distributor / importer", "Restaurant & hôtel": "Restaurant & hotel", "Épicerie fine": "Fine grocery", "Autre": "Other", "Précisez": "Please specify", "Huile d'olive vierge extra · Tunisie": "Extra virgin olive oil · Tunisia", "L'or vert de Tunisie,": "Tunisia's green gold,", "extrait à froid.": "cold extracted.", "Trois signatures, une seule origine : des olives tunisiennes extraites à froid pour une huile d'exception.": "Three signatures, one origin: Tunisian olives, cold extracted for an exceptional oil.", "Découvrir la collection": "Discover the collection", "Née sous le soleil": "Born under the", "méditerranéen.": "Mediterranean sun.", "ORZETA naît en Tunisie, là où le soleil, la terre et des générations d'oléiculteurs façonnent des huiles au caractère méditerranéen singulier.": "ORZETA is born in Tunisia, where sun, land and generations of olive growers shape oils with a distinctive Mediterranean character.", "Authentique par son origine.": "Authentic by origin.", "Précise par son art.": "Precise by craft.", "Distinctive par sa nature.": "Distinctive by nature.", "Une origine. Trois expressions.": "One origin. Three expressions.", "D'une même terre baignée de soleil naissent trois expressions distinctes, qui révèlent chacune une facette de notre héritage oléicole.": "From the same sun-drenched land come three distinct expressions, each revealing a different facet of our olive heritage.", "L'essentiel du quotidien": "Everyday Essential", "L'essentiel à table": "Finishing Essential", "L'essentiel bio": "Organic Essential", "Une huile d'olive extra vierge douce et équilibrée, aux notes fruitées et florales. Polyvalente et expressive, elle apporte une touche méditerranéenne au quotidien.": "A smooth and balanced Extra Virgin Olive Oil with a fruity, floral profile. Versatile and expressive, it brings Mediterranean brightness to everyday cooking.", "Une huile d'olive extra vierge plus intense, au profil structuré et à l'amertume délicate. Elle enrichit les salades, les assaisonnements et les touches finales.": "A more intense Extra Virgin Olive Oil with a structured profile and a refined touch of bitterness. It brings depth to salads, dressings and finishing touches.", "Une huile d'olive extra vierge certifiée biologique, pour ceux qui privilégient l'authenticité, la traçabilité et un approvisionnement responsable. Une expression pure et équilibrée.": "Certified organic Extra Virgin Olive Oil for those who value authenticity, traceability and responsible sourcing. A pure and balanced expression.", "Portée par un savoir-faire oléicole tunisien, la marque conjugue authenticité, exigence et innovation. Chaque huile est élaborée avec une attention particulière portée à la qualité et à la constance.": "Backed by Tunisian olive-growing expertise, the brand combines authenticity, high standards and innovation. Each oil is crafted with particular care for quality and consistency.", "Un savoir-faire tunisien": "Tunisian know-how", "Cuisine de tous les jours : salades composées, poissons grillés, légumes, pâtes, plats mijotés.": "Everyday cooking: mixed salads, grilled fish, vegetables, pasta, slow-cooked dishes.", "Salades, vinaigrettes et assaisonnements, légumes grillés, viandes rôties, pain rustique, touches finales.": "Salads, vinaigrettes and dressings, grilled vegetables, roast meats, rustic bread, finishing touches.", "Des recettes méditerranéennes pensées pour chacune de nos trois huiles. Des gestes simples, des produits justes, et une huile qu'on sent vraiment.": "Mediterranean recipes built around each of our three oils. Simple technique, honest ingredients, and an olive oil you can actually taste.", "Des recettes méditerranéennes pour tirer le meilleur de chaque huile. Des gestes simples, des produits justes.": "Mediterranean recipes to get the best out of each oil. Simple technique, honest ingredients.", "Dès l'époque de Carthage, vers 814 av. J.-C., l'olivier s'enracine sur cette terre. Mago, agronome carthaginois, rédige le traité agricole de référence du monde antique — un texte si précieux que Rome le fait traduire après avoir détruit la ville. Sous l'Empire romain, la Tunisie devient l'un des greniers à huile de la Méditerranée : la richesse tirée de l'huile d'olive finance jusqu'à l'amphithéâtre d'El Jem.": "As early as the founding of Carthage, around 814 BC, the olive tree took root in this land. Mago, a Carthaginian agronomist, wrote the ancient world's reference treatise on farming — a text so valuable that Rome had it translated after destroying the city. Under the Roman Empire, Tunisia became one of the Mediterranean's olive-oil granaries: the wealth drawn from olive oil helped fund the amphitheater of El Jem.", "Profil aromatique": "Flavour profile", "Doux": "Mild", "Intense": "Intense", "Moins de 30 min": "Under 30 min", "30 min à 1 h": "30 min to 1 hr", "Plus d'1 h": "Over 1 hr", "30 min": "30 min", "35 min": "35 min", "1 h 30": "1 hr 30", "Choisissez une bouteille, puis une contenance.": "Choose a bottle, then a size.", "2 bouteilles : Marasca & Dorica": "2 bottles: Marasca & Dorica", "3 huiles · 2 bouteilles (Marasca et Dorica) · de 250 mL à 1 L.": "3 oils · 2 bottles (Marasca and Dorica) · from 250 mL to 1 L.", "Quand le soleil": "When the sun", "rencontre l'oliveraie.": "meets the grove.", "producteur mondial d'huile d'olive": "world producer of olive oil"};
var SITE_I18N_PLACEHOLDERS = {"contact-name": "Your name", "contact-subject": "Subject of your message", "contact-message": "Your message...", "contact-type-autre": "Please specify (e.g. association, journalist, wholesaler…)"};
var SITE_I18N_AR = {"Aller au contenu principal": "الانتقال إلى المحتوى الرئيسي", "Huile d'Olive": "زيت الزيتون", "Expertise & Fabrication": "الخبرة والصناعة", "Accueil": "الرئيسية", "Acidité": "الحموضة", "Découvrir": "اكتشف", "Sfax, Tunisie": "صفاقس، تونس", "Acidité ≤ 0,3%": "حموضة ≤ 0.3%", "Extraction à froid": "استخلاص على البارد", "Contactez-nous": "اتصل بنا", "Notre équipe vous répond sous 24h, du dimanche au vendredi.": "يردّ فريقنا خلال 24 ساعة، من الأحد إلى الجمعة.", "Téléphone": "الهاتف", "Adresse": "العنوان", "Tunisie, Méditerranée": "تونس، حوض المتوسط", "Nom complet": "الاسم الكامل", "Sujet": "الموضوع", "Message": "الرسالة", "Envoyer le message": "إرسال الرسالة", "Notre Gamme": "تشكيلتنا", "Idéal avec": "الأنسب مع", "Énergie": "الطاقة", "Matières grasses": "الدهون", "© 2026 Orzeta. Tous droits réservés.": "© 2026 Orzeta. جميع الحقوق محفوظة.", "Mentions Légales": "الإشعار القانوني", "e": "", "Sun Signature": "Sun Signature", "Grove Signature": "Grove Signature", "Organic Signature": "Organic Signature", "Notre Histoire": "قصتنا", "La Collection": "المجموعة", "Trouvez votre Signature": "اكتشف بصمتك", "Une origine. Trois expressions différentes d'huile d'olive vierge extra. Des personnalités différentes, un même engagement Orzeta envers l'excellence.": "أصلٌ واحد، وثلاثة أوجه لزيت الزيتون البكر الممتاز. شخصيات مختلفة، والتزامٌ واحد من ORZETA بالتميّز.", "Trois expressions. Une seule origine méditerranéenne.": "ثلاثة أوجه، وأصلٌ متوسطي واحد.", "Lumineuse par nature. Certaines huiles chuchotent, la Sun Signature rayonne. Expressive sans jamais dominer, elle apporte de la lumière à chaque plat tout en laissant les ingrédients frais au centre de l'assiette. Obtenue uniquement par des procédés mécaniques à froid, sans aucun traitement chimique.": "مشرقٌ بطبعه. إن كانت بعض الزيوت تهمس، فإن Sun Signature يتألّق. حاضرٌ دون أن يطغى، يمنح كل طبق إشراقة ويترك للمكوّنات الطازجة مكان الصدارة. يُستخلص على البارد بطرق ميكانيكية فقط، دون أي معالجة كيميائية.", "Cuisine premium au quotidien, recettes d'inspiration méditerranéenne, finition de plats — à savourer de préférence crue pour préserver tous ses polyphénols.": "لمطبخ راقٍ كل يوم، ولوصفات مستوحاة من المتوسط، ولمسة ختامية على الأطباق. ويُفضَّل تناوله نيئًا للحفاظ على كامل البوليفينولات.", "« Une touche de lumière méditerranéenne dans chaque assiette. »": "« لمسة من ضياء المتوسط في كل طبق. »", "« La force du verger, capturée dans chaque goutte. »": "« قوّة البستان وعبقه، في كل قطرة. »", "« Rien de superflu. Simplement de l'huile d'olive vierge extra, telle que la nature l'a voulue. »": "« لا شيء زائد. زيت زيتون بكر ممتاز، كما أرادته الطبيعة تمامًا. »", "La nature l'a perfectionnée. Nous l'avons mise en bouteille.": "أتقنته الطبيعة، وعبّأناه نحن.", "Née en Tunisie · Faite pour le monde": "وُلدت في تونس · وصُنعت للعالم", "Nos Produits": "منتجاتنا", "L'Histoire Orzeta": "حكاية ORZETA", "Née sous le soleil méditerranéen": "وُلدت تحت شمس المتوسط", "Nos Recettes": "وصفاتنا", "Des idées simples et gourmandes pour sublimer chaque plat.": "أفكار بسيطة وشهية ترتقي بكل طبق.", "Signature Orzeta": "بصمة ORZETA", "L'Héritage Orzeta": "إرث ORZETA", "Un terroir façonné par plus de deux mille ans d'histoire oléicole, entre la Méditerranée et le soleil tunisien.": "أرضٌ صنعت مجدها أكثر من ألفي عام من زراعة الزيتون، بين زرقة المتوسط وشمس تونس.", "« Il y a des endroits où l'huile d'olive se fabrique. Et il y a la Tunisie, où elle est chez elle depuis l'Antiquité. »": "« في العالم أماكن كثيرة يُصنع فيها زيت الزيتون، أمّا تونس فهو فيها ابنُ الأرض منذ العصور القديمة. »", "Depuis l'Antiquité": "منذ العصور القديمة", "Des racines plus vieilles que Rome": "جذورٌ أعرق من روما", "Des arbres toujours vivants": "أشجارٌ ما زالت تنبض بالحياة", "Certains oliviers ont vu passer 25 siècles": "أشجار زيتون عاصرت خمسةً وعشرين قرنًا", "Le terroir de Sfax": "أرض صفاقس", "Un sol aride, une olive généreuse": "أرضٌ قاحلة… وزيتونٌ سخيّ", "À Sfax, les oliviers poussent espacés, sur un sol sec et sablonneux, sans irrigation — une conduite exigeante qui concentre les arômes. C'est le territoire de la Chemlali, variété dorée et douce qui domine le centre et le sud du pays, aux côtés de la Chetoui, plus poivrée, cultivée au nord.": "في صفاقس، تُغرس أشجار الزيتون متباعدةً في تربة جافة رملية ومن دون ريّ، وهي زراعة شاقّة تمنح الزيت نكهةً أكثر تركيزًا. هنا موطن صنف الشملالي، الزيتون الذهبي اللطيف الذي يسود وسط تونس وجنوبها، فيما ينتشر صنف الشتوي، الأكثر حدّة، في الشمال.", "En chiffres": "بالأرقام", "La Tunisie aujourd'hui": "تونس اليوم", "millions d'oliviers": "مليون شجرة زيتون", "producteur mondial": "ثاني أكبر منتج", "exportateur mondial d'huile bio": "عالميًا في تصدير زيت الزيتون العضوي", "L'huile d'olive représente plus de la moitié des exportations agroalimentaires tunisiennes — le fruit d'une agriculture sèche, peu mécanisée, mais d'une résilience remarquable face au climat méditerranéen.": "يشكّل زيت الزيتون أكثر من نصف الصادرات الغذائية التونسية، ثمرةً لزراعة بعلية قليلة الاعتماد على المكننة، لكنها تُبدي صمودًا لافتًا في وجه مناخ المتوسط.", "Bien plus qu'un ingrédient": "أكثر من مجرّد مكوّن", "Les bienfaits d'une huile vierge extra": "فوائد زيت الزيتون البكر الممتاز", "Riche en acides gras mono-insaturés": "غنيّ بالأحماض الدهنية الأحادية غير المشبعة", "Antioxydants": "مضادات الأكسدة", "Polyphénols qui protègent les cellules": "بوليفينولات تحمي خلايا الجسم", "Anti-inflammatoire": "مضادّ للالتهابات", "Propriétés naturelles reconnues": "خصائص طبيعية معترف بها", "Régime méditerranéen": "حمية البحر المتوسط", "Pilier d'une alimentation équilibrée": "ركيزة أساسية للتغذية المتوازنة", "Une tradition transmise": "تقليدٌ متوارث", "« La récolte reste, en Tunisie, un moment de famille — un geste transmis, presque inchangé, de génération en génération. »": "« في تونس، ما يزال موسم جني الزيتون مناسبةً تجمع العائلة، وطقسًا يتوارثه الأبناء عن الآباء كما هو تقريبًا. »", "Découvrir nos huiles": "اكتشف زيوتنا", "Deux mille ans d'histoire, entre soleil et Méditerranée.": "ألفا عام من التاريخ، بين الشمس والمتوسط.", "er": "", "exportateur mondial": "أكبر مصدّر", "d'huile d'olive": "لزيت الزيتون في العالم", "d'huile d'olive bio": "لزيت الزيتون العضوي في العالم", "à froid, savoir-faire artisanal": "بخبرة حرفية متوارثة", "Restons en Contact": "لنبقَ على تواصل", "Une question, un projet, une commande professionnelle ? Notre équipe vous répond sous 24h, du dimanche au vendredi.": "سؤال، مشروع، أو طلبية للمهنيين؟ يردّ فريقنا خلال 24 ساعة، من الأحد إلى الجمعة.", "La nature l'a perfectionnée.": "أتقنته الطبيعة.", "Nous l'avons mise en bouteille.": "وعبّأناه نحن.", "Née en Tunisie. Faite pour le monde.": "وُلدت في تونس. وصُنعت للعالم.", "En quelques mots": "باختصار", "Le caractère": "شخصيّته", "Une huile douce et légère, la plus accessible de la gamme — celle qu'on utilise tous les jours, sans hésiter.": "زيت لطيف وخفيف، الأسهل في تشكيلتنا — رفيقك اليومي الذي تمدّ يدك إليه دون تفكير.", "Une huile plus corsée, au caractère affirmé — pour celles et ceux qui aiment qu'une huile se remarque dans l'assiette.": "زيت أكثر جرأة وذو شخصية واضحة — لمن يحبّ أن يكون للزيت حضورٌ في الطبق.", "Une huile fraîche et pure, issue de l'agriculture biologique. Acidité ≤ 0,3% · Extraction à froid · Conservation 18 à 24 mois.": "زيت طازج ونقيّ من الزراعة العضوية. حموضة ≤ 0.3% · استخلاص على البارد · صلاحية من 18 إلى 24 شهرًا.", "Lumineuse par nature. Certaines huiles chuchotent, la Sun Signature rayonne : une huile d'olive vierge extra douce et lumineuse, qui apporte de la fraîcheur à chaque plat sans jamais couvrir les ingrédients.": "مشرقٌ بطبعه. إن كانت بعض الزيوت تهمس، فإن Sun Signature يتألّق: زيت زيتون بكر ممتاز لطيف ومشرق، يمنح كل طبق انتعاشًا دون أن يحجب مذاق مكوّناته.", "Enracinée dans le caractère. Plus profonde, plus audacieuse, plus expressive : la Grove Signature révèle une facette plus intense de l'huile d'olive vierge extra, pour celles et ceux qui aiment une huile qui se fait remarquer.": "متجذّر في الأصالة. أعمق، أجرأ، وأكثر حضورًا: يكشف Grove Signature عن وجهٍ أكثر كثافة لزيت الزيتون البكر الممتاز، لمن يحبّ زيتًا يترك أثره.", "Lumineuse par nature, douce et légère — l'huile du quotidien, celle qu'on utilise sans hésiter.": "مشرقٌ بطبعه، لطيف وخفيف — زيت كل يوم، تمدّ يدك إليه دون تفكير.", "Enracinée dans le caractère, plus corsée et plus expressive — pour ceux qui aiment une huile qui se fait remarquer.": "متجذّر في الأصالة، أجرأ وأكثر حضورًا — لمن يحبّ زيتًا يترك أثره.", "La nature, sans artifice : issue de l'agriculture biologique, pour celles et ceux qui recherchent authenticité et confiance.": "الطبيعة كما هي: من الزراعة العضوية، لمن يبحث عن الأصالة والثقة.", "La nature, sans artifice. Orzeta Organic est notre huile d'olive vierge extra issue de l'agriculture biologique, pensée pour celles et ceux qui recherchent authenticité, approvisionnement responsable et confiance dans ce qu'ils mettent sur leur table.": "الطبيعة كما هي. Organic هو زيت الزيتون البكر الممتاز العضوي من ORZETA، لمن يبحث عن الأصالة، ومصادر مسؤولة، وثقة تامّة في ما يقدّمه على مائدته.", "La nature, sans artifice. Huile d'olive vierge extra issue de l'agriculture biologique, obtenue directement du fruit de l'olivier par des procédés exclusivement mécaniques. Assemblage multivariétal (Chemlali, Koroneiki, Arbosana), 100% olives tunisiennes, sans pesticides ni intrants chimiques de synthèse. Extraction à froid (max. 27°C), sans ajout d'eau.": "الطبيعة كما هي. زيت زيتون بكر ممتاز من الزراعة العضوية، يُستخلص مباشرة من ثمار الزيتون بطرق ميكانيكية فقط. مزيج من عدّة أصناف (شملالي، كورونيكي، أربوسانا)، من زيتون تونسي 100%، دون مبيدات ولا مواد كيميائية مُصنَّعة. استخلاص على البارد (27°م كحدّ أقصى)، دون إضافة ماء.", "Agriculture biologique": "زراعة عضوية", "Enracinée dans le caractère. Plus profonde, plus audacieuse, plus expressive : la Grove Signature révèle une facette plus intense de l'huile d'olive vierge extra, conçue pour celles et ceux qui cherchent une huile qui ne se contente pas d'accompagner un plat, mais qui en devient partie intégrante.": "متجذّر في الأصالة. أعمق، أجرأ، وأكثر حضورًا: يكشف Grove Signature عن وجهٍ أكثر كثافة لزيت الزيتون البكر الممتاز، لمن يبحث عن زيت لا يكتفي بمرافقة الطبق، بل يصبح جزءًا من التجربة.", "Près d'El Haouaria, au Cap Bon, un olivier planté à l'époque carthaginoise produit encore aujourd'hui — environ 2 500 ans d'âge. Dans le Sahel, les terrasses d'irrigation traditionnelles « meskat », conçues il y a des siècles, fonctionnent toujours. Cette continuité vivante est rare : peu de cultures au monde peuvent se targuer d'un tel héritage encore en production.": "قرب الهوارية، في شبه جزيرة الوطن القبلي، ما تزال شجرة زيتون غُرست في العهد القرطاجي تؤتي ثمارها حتى اليوم، ويُقدَّر عمرها بنحو 2500 عام. وفي منطقة الساحل التونسي، ما تزال مدرّجات الريّ التقليدية المعروفة بـ« المسقاة » تؤدّي دورها منذ قرون. إنها استمرارية نادرة، فقلّة من الموروثات الزراعية في العالم ما زالت تُنتج حتى اليوم.", "Cœur & cardiovasculaire": "صحة القلب والشرايين", "Récolte & pressage": "جنيٌ وعصرٌ على البارد", "La Signature Orzeta": "بصمة ORZETA", "À table": "على المائدة", "La cuisine Orzeta": "مطبخ ORZETA", "Choisissez votre huile": "اختر زيتك", "Toutes": "الكل", "Aucune recette pour cette sélection.": "لا توجد وصفات تطابق هذا الاختيار.", "Toutes les recettes": "كل الوصفات", "Voir": "عرض", "Pour": "عدد الحصص", "Préparation": "التحضير", "Cuisson": "الطهي", "Niveau": "المستوى", "Ingrédients": "المكوّنات", "Le conseil Orzeta :": "نصيحة ORZETA:", "L'huile de cette recette": "الزيت المستعمل في هذه الوصفة", "Découvrir cette huile": "اكتشف هذا الزيت", "Sans cuisson": "بدون طهي", "Facile": "سهل", "Intermédiaire": "متوسط", "4 personnes": "4 أشخاص", "2 personnes": "شخصان", "6 personnes": "6 أشخاص", "20 min": "20 دقيقة", "15 min": "15 دقيقة", "10 min": "10 دقائق", "5 min": "5 دقائق", "1 h": "ساعة واحدة", "1 h 10": "ساعة و10 دقائق", "Slata Tounsia": "السلطة التونسية", "La salade nationale tunisienne, taillée très fin, où l'huile d'olive fait tout le travail d'assaisonnement.": "السلطة الوطنية التونسية، مقطّعة ناعمًا جدًا، وزيت الزيتون وحده يتكفّل بتتبيلها.", "Ne mélangez le thon qu'au dernier moment : il doit rester en morceaux visibles.": "أضف التونة في آخر لحظة: يجب أن تبقى قطعها ظاهرة.", "4 tomates bien mûres": "4 حبات طماطم ناضجة جدًا", "1 concombre": "حبة خيار", "1 poivron vert": "حبة فلفل رومي أخضر", "1 petit oignon rouge": "حبة بصل أحمر صغيرة", "2 œufs durs": "بيضتان مسلوقتان", "100 g de thon à l'huile, égoutté": "100 غرام تونة بالزيت، مصفّاة", "12 olives Chemlali": "12 حبة زيتون شملالي", "4 c. à s. d'Orzeta Sun": "4 ملاعق كبيرة من Orzeta Sun", "Le jus d'un demi-citron": "عصير نصف حبة ليمون", "Sel, poivre": "ملح وفلفل أسود", "Taillez tomates, concombre, poivron et oignon en tout petits dés réguliers de 2 à 3 mm. C'est la finesse de la coupe qui fait la différence.": "قطّع الطماطم والخيار والفلفل والبصل إلى مكعبات صغيرة جدًا ومنتظمة من 2 إلى 3 مم. دقّة التقطيع هي ما يصنع الفرق.", "Salez légèrement, laissez dégorger 5 minutes puis égouttez l'eau rendue.": "رشّ قليلًا من الملح، واتركها 5 دقائق ثم صفِّ الماء الناتج.", "Ajoutez le jus de citron, l'huile d'olive et le poivre, mélangez délicatement.": "أضف عصير الليمون وزيت الزيتون والفلفل الأسود، وقلّب برفق.", "Dressez dans un plat creux, garnissez de thon émietté, d'œufs en quartiers et d'olives.": "قدّمها في صحن واسع، وزيّنها بالتونة المفتّتة وأرباع البيض والزيتون.", "Servez frais, avec du pain pour saucer.": "قدّمها باردة، مع الخبز لتغميسه في عصارتها.", "Daurade grillée à la sfaxienne": "سمك دنيس مشوي على طريقة صفاقس", "Le poisson entier grillé comme sur la côte : peau croustillante, chair nacrée, et un filet d'huile crue à la sortie du gril.": "سمكة كاملة مشوية كما في المدن الساحلية: جلد مقرمش، لحم ناصع، وخيط من الزيت النيء فور خروجها من الشواية.", "L'huile versée à cru sur le poisson brûlant libère tous ses arômes : ne la mettez jamais avant la cuisson.": "الزيت النيء على السمك الساخن يُطلق كامل عبيره، فلا تضعه أبدًا قبل الشيّ.", "1 daurade de 800 g, vidée et écaillée": "سمكة دنيس واحدة (800 غرام)، منظّفة ومقشّرة", "1 citron": "حبة ليمون", "3 gousses d'ail": "3 فصوص ثوم", "1 bouquet de persil plat": "حزمة بقدونس", "Incisez la peau du poisson trois fois de chaque côté, jusqu'à l'arête.": "اشقق جلد السمكة ثلاث شقوق من كل جهة، حتى العظم.", "Mélangez l'ail haché, le persil ciselé, 2 c. à s. d'huile, du sel et du poivre. Garnissez l'intérieur du poisson et les incisions.": "اخلط الثوم المهروس والبقدونس المفروم وملعقتين كبيرتين من الزيت مع الملح والفلفل، ثم احشُ بها بطن السمكة والشقوق.", "Grillez 10 minutes de chaque côté sur braises moyennes, ou au four à 220°C.": "اشوِها 10 دقائق من كل جهة على جمر متوسط، أو في الفرن على 220°م.", "À la sortie du gril, arrosez généreusement d'huile crue et de jus de citron.": "فور خروجها من الشواية، اسكب عليها بسخاء الزيت النيء وعصير الليمون.", "Servez immédiatement, avec une salade fraîche.": "قدّمها فورًا، مع سلطة طازجة.", "Salade de fenouil, orange et olives": "سلطة الشمر والبرتقال والزيتون", "Croquante, acidulée, lumineuse. Une salade d'hiver méditerranéenne qui tient sur trois ingrédients et une bonne huile.": "مقرمشة، منعشة، ومشرقة. سلطة شتوية متوسطية تقوم على ثلاثة مكوّنات وزيت جيّد.", "Laissez le fenouil mariner 10 minutes dans l'huile et le citron : il s'attendrit sans perdre son croquant.": "اترك الشمر 10 دقائق في الزيت والليمون: يلين دون أن يفقد قرمشته.", "2 bulbes de fenouil": "حبتا شمر (شمرة)", "2 oranges": "برتقالتان", "12 olives noires Chemlali": "12 حبة زيتون أسود شملالي", "Quelques feuilles de menthe fraîche": "بضع أوراق من النعناع الطازج", "3 c. à s. d'Orzeta Sun": "3 ملاعق كبيرة من Orzeta Sun", "1 c. à s. de jus de citron": "ملعقة كبيرة من عصير الليمون", "Fleur de sel, poivre du moulin": "رقائق ملح البحر، فلفل مطحون طازجًا", "Émincez le fenouil le plus finement possible, idéalement à la mandoline. Réservez quelques pluches vertes.": "قطّع الشمر شرائح رقيقة جدًا، ويُفضّل استعمال قطّاعة الخضار (المندولين). واحتفظ ببعض أوراقه الخضراء للتزيين.", "Pelez les oranges à vif et levez les suprêmes au-dessus d'un bol pour récupérer le jus.": "قشّر البرتقال حتى اللب، واستخرج الفصوص فوق وعاء لجمع العصير.", "Mélangez le fenouil avec le jus d'orange recueilli, le citron et l'huile d'olive.": "اخلط الشمر مع عصير البرتقال المجمّع، والليمون، وزيت الزيتون.", "Dressez en dôme, répartissez les suprêmes, les olives et la menthe.": "كوّمه في الصحن، ثم وزّع فوقه فصوص البرتقال والزيتون والنعناع.", "Terminez par la fleur de sel, un tour de moulin et servez bien frais.": "اختم برقائق ملح البحر ورشّة فلفل مطحون، وقدّمها باردة جدًا.", "Poulpe grillé, citron et persil": "أخطبوط مشوي بالليمون والبقدونس", "Tendre à l'intérieur, caramélisé à l'extérieur. Un plat de bord de mer qui demande une huile de caractère.": "طريّ من الداخل، مُحمّر من الخارج. طبق بحريّ يحتاج إلى زيت ذي شخصية.", "Ne salez jamais l'eau de cuisson du poulpe : elle durcit la chair. Le sel vient à la fin.": "لا تملّح ماء سلق الأخطبوط أبدًا لأنه يُقسّي لحمه؛ الملح يأتي في النهاية.", "1 poulpe de 1,2 kg, nettoyé": "أخطبوط واحد بوزن 1.2 كغ، منظّف", "1 oignon": "حبة بصل", "1 feuille de laurier": "ورقة غار واحدة", "4 gousses d'ail": "4 فصوص ثوم", "5 c. à s. d'Orzeta Grove": "5 ملاعق كبيرة من Orzeta Grove", "Piment doux en poudre, sel": "بابريكا حلوة، ملح", "Plongez le poulpe dans une grande eau frémissante avec l'oignon et le laurier. Laissez cuire 45 min à 1 h : la pointe d'un couteau doit entrer sans résistance.": "ضع الأخطبوط في قدر كبيرة من الماء على نار هادئة جدًا مع البصل وورقة الغار. اتركه من 45 دقيقة إلى ساعة، حتى يدخل رأس السكين بلا مقاومة.", "Égouttez, laissez tiédir puis séparez les tentacules.": "صفّه واتركه يبرد قليلًا، ثم افصل أذرعه.", "Badigeonnez-les d'huile et grillez-les 3 à 4 minutes par face, jusqu'à ce que les ventouses caramélisent.": "ادهنه بالزيت واشوِه من 3 إلى 4 دقائق من كل جهة، حتى تتحمّر الممصّات.", "Dressez, parsemez d'ail émincé, de persil ciselé et d'une pincée de piment doux.": "رتّبه في الصحن، ورشّ عليه شرائح الثوم والبقدونس المفروم وقليلًا من البابريكا الحلوة.", "Arrosez généreusement d'huile crue et de citron juste avant de servir.": "اسكب عليها بسخاء الزيت النيء والليمون قبل التقديم مباشرة.", "Bruschetta tomate & basilic": "بروسكيتا بالطماطم والريحان", "Quatre ingrédients, aucun endroit où se cacher. C'est exactement pour ça que la bruschetta est le meilleur test d'une huile d'olive.": "أربعة مكوّنات، ولا مجال للتمويه. لهذا تحديدًا تُعدّ البروسكيتا أصدق اختبار لزيت الزيتون.", "Le pain doit être grillé, pas séché : une croûte marquée et un cœur encore moelleux qui absorbe l'huile.": "يجب أن يُحمَّص الخبز دون أن يجفّ: قشرة واضحة وقلب طريّ يتشرّب الزيت.", "8 tranches de pain de campagne": "8 شرائح من الخبز الريفي", "4 tomates mûres": "4 حبات طماطم ناضجة", "2 gousses d'ail": "فصّا ثوم", "Une dizaine de feuilles de basilic": "نحو عشر أوراق من الريحان", "Fleur de sel, poivre": "رقائق ملح البحر، فلفل أسود", "Concassez les tomates en petits dés, salez et laissez égoutter 10 minutes dans une passoire.": "قطّع الطماطم مكعبات صغيرة، وملّحها واتركها تُصفّى 10 دقائق في مصفاة.", "Grillez les tranches de pain jusqu'à obtenir des marques nettes et une surface bien sèche.": "حمّص شرائح الخبز حتى تظهر علامات الشواء بوضوح ويصبح سطحها جافًا.", "Frottez chaque tranche encore chaude avec une demi-gousse d'ail.": "افرك كل شريحة وهي ساخنة بنصف فص ثوم.", "Répartissez les tomates égouttées et le basilic déchiré à la main.": "وزّع الطماطم المصفّاة والريحان المقطّع باليد.", "Arrosez généreusement d'huile, fleur de sel, poivre, et servez aussitôt.": "اسكب الزيت بسخاء، وأضف رقائق الملح والفلفل، وقدّمها فورًا.", "Gigot d'agneau rôti aux herbes": "فخذ خروف مشوي في الفرن بالأعشاب", "Le plat du dimanche. L'ail, le romarin et une huile corsée forment la croûte parfumée qui protège la chair.": "طبق الولائم العائلية. الثوم والروزماري وزيت قوي يكوّنون قشرة عطرة تحمي اللحم.", "Le repos n'est pas optionnel : c'est lui qui redistribue les jus et rend la viande moelleuse.": "الراحة ليست خطوة اختيارية: هي التي تعيد توزيع العصارة وتحافظ على طراوة اللحم.", "1 gigot d'agneau de 2 kg": "فخذ خروف واحد بوزن 2 كغ", "8 gousses d'ail": "8 فصوص ثوم", "4 branches de romarin": "4 أغصان من إكليل الجبل (روزماري)", "Quelques branches de thym": "بضعة أغصان من الزعتر", "6 c. à s. d'Orzeta Grove": "6 ملاعق كبيرة من Orzeta Grove", "Gros sel, poivre concassé": "ملح خشن، فلفل مجروش", "Sortez la viande du réfrigérateur 1 heure avant. Préchauffez le four à 220°C.": "أخرج اللحم من الثلاجة قبل ساعة. سخّن الفرن مسبقًا على 220°م.", "Piquez l'ail en lamelles dans la chair, frottez tout le gigot d'huile, puis d'herbes, de sel et de poivre.": "اغرز شرائح الثوم في اللحم، وادهن الفخذ كله بالزيت، ثم بالأعشاب والملح والفلفل.", "Enfournez 20 minutes à 220°C pour saisir, puis baissez à 180°C et poursuivez 50 minutes pour une cuisson rosée.": "أدخله الفرن 20 دقيقة على 220°م ليكتسب لونًا ذهبيًا، ثم اخفض الحرارة إلى 180°م وأكمل 50 دقيقة لطهي وردي.", "Arrosez du jus de cuisson toutes les 20 minutes.": "اسقِه بعصارة الطهي كل 20 دقيقة.", "Laissez reposer 15 minutes sous une feuille d'aluminium avant de trancher, puis finissez d'un filet d'huile crue.": "اتركه يرتاح 15 دقيقة مغطّى بورق الألمنيوم قبل تقطيعه، ثم اختم بخيط من الزيت النيء.", "Caprese à l'huile d'olive": "كابريزي بزيت الزيتون", "Tomate, mozzarella, basilic. Rien de plus. Ici l'huile n'accompagne pas le plat : elle en fait partie.": "طماطم، موزاريلا، ريحان. لا شيء غير ذلك. هنا لا يرافق الزيت الطبق، بل هو جزء منه.", "Salez les tomates 5 minutes avant de dresser : elles rendent un jus qui se mélange à l'huile et forme la sauce.": "ملّح الطماطم قبل 5 دقائق من التقديم: ستُطلق عصيرًا يمتزج بالزيت ليصبح هو الصلصة.", "3 tomates bien mûres": "3 حبات طماطم ناضجة جيدًا", "1 mozzarella di bufala de 250 g": "قطعة موزاريلا دي بوفالا بوزن 250 غرام", "3 c. à s. d'Orzeta Organic": "3 ملاعق كبيرة من Orzeta Organic", "Sortez la mozzarella du réfrigérateur 20 minutes avant : froide, elle n'a aucun goût.": "أخرج الموزاريلا من الثلاجة قبل 20 دقيقة: باردةً تفقد كل طعمها.", "Coupez tomates et mozzarella en rondelles régulières d'environ 1 cm.": "قطّع الطماطم والموزاريلا إلى دوائر منتظمة بسمك 1 سم تقريبًا.", "Disposez-les en les faisant se chevaucher, en glissant une feuille de basilic entre chaque.": "رتّبها متراكبة، مع ورقة ريحان بين كل شريحتين.", "Arrosez d'huile crue en filet continu.": "اسكب الزيت النيء في خيط متواصل.", "Fleur de sel, poivre, et à table immédiatement.": "رقائق ملح البحر، فلفل، ثم إلى المائدة مباشرة.", "Houmous crémeux à l'huile d'olive": "حمّص كريمي بزيت الزيتون", "Le sillon d'huile au centre n'est pas une décoration : c'est la moitié du goût.": "خيط الزيت في الوسط ليس للزينة: إنه نصف المذاق.", "Ajoutez l'eau de cuisson tiède petit à petit pendant le mixage : c'est le secret d'un houmous aérien.": "أضف ماء السلق الدافئ تدريجيًا أثناء الخلط: هذا سرّ الحمّص الخفيف الهشّ.", "250 g de pois chiches secs (ou 500 g cuits)": "250 غرام من الحمّص الجاف (أو 500 غرام مطبوخ)", "3 c. à s. de tahini": "3 ملاعق كبيرة من الطحينة", "1 gousse d'ail": "فص ثوم واحد", "5 c. à s. d'Orzeta Organic": "5 ملاعق كبيرة من Orzeta Organic", "Cumin, paprika": "كمون، بابريكا", "Sel": "ملح", "Faites tremper les pois chiches 12 heures, puis cuisez-les 1 heure jusqu'à ce qu'ils s'écrasent entre deux doigts.": "انقع الحمّص 12 ساعة، ثم اسلقه ساعة حتى يُهرس بسهولة بين إصبعين.", "Mixez-les avec le tahini, l'ail, le jus de citron, 3 c. à s. d'huile et un peu d'eau de cuisson, jusqu'à obtenir une texture parfaitement lisse.": "اخلطه مع الطحينة والثوم وعصير الليمون و3 ملاعق كبيرة من الزيت وقليل من ماء السلق، حتى يصبح ناعمًا تمامًا.", "Salez, rectifiez en citron.": "ملّحه، وعدّل كمية الليمون.", "Dressez dans une assiette creuse en creusant un sillon en spirale avec le dos d'une cuillère.": "افرده في صحن واسع، وارسم فيه أخدودًا حلزونيًا بظهر الملعقة.", "Remplissez le sillon d'huile crue, saupoudrez de paprika et de cumin, ajoutez quelques pois chiches entiers.": "املأ الأخدود بالزيت النيء، ورشّ البابريكا والكمون، وأضف بضع حبات حمّص كاملة.", "Tartare de saumon, citron et huile d'olive": "تارتار السلمون بالليمون وزيت الزيتون", "Pas de mayonnaise, pas de crème. L'huile d'olive tient lieu de sauce, donc elle doit être irréprochable.": "لا مايونيز ولا كريمة. زيت الزيتون هنا هو الصلصة، لذا يجب أن يكون مثاليًا.", "Assaisonnez au dernier moment : le citron « cuit » le poisson et le fait blanchir s'il attend trop.": "تبّل في آخر لحظة: الليمون « يطهو » السمك ويُبهت لونه إذا انتظر طويلًا.", "300 g de filet de saumon extra-frais, sans peau": "300 غرام من فيليه السلمون الطازج جدًا، دون جلد", "1 échalote": "حبة بصل شالوت", "Quelques brins d'aneth": "بضعة أعواد من الشبت", "1 citron (zeste et jus)": "حبة ليمون (القشر المبشور والعصير)", "Baies roses, fleur de sel, poivre": "فلفل وردي، رقائق ملح البحر، فلفل أسود", "Placez le saumon 20 minutes au congélateur : il se taille beaucoup plus net.": "ضع السلمون 20 دقيقة في الفريزر: يصبح تقطيعه أسهل وأدقّ بكثير.", "Coupez-le au couteau en cubes réguliers de 5 mm. Surtout pas de mixeur.": "قطّعه بالسكين إلى مكعبات منتظمة من 5 مم. لا تستعمل الخلاط أبدًا.", "Mélangez avec l'échalote ciselée finement, l'aneth, le zeste et 1 c. à s. de jus de citron, puis l'huile.": "اخلطه مع الشالوت المفروم ناعمًا، والشبت، وقشر الليمون المبشور، وملعقة كبيرة من عصير الليمون، ثم الزيت.", "Assaisonnez et moulez à l'aide d'un cercle au centre de l'assiette.": "تبّله ثم شكّله بقالب دائري في وسط الصحن.", "Terminez par un filet d'huile crue et quelques baies roses, servez aussitôt.": "اختم بخيط من الزيت النيء وبضع حبات فلفل وردي، وقدّمه فورًا.", "Filtrer les recettes par :": "صنّف الوصفات حسب:", "Huile": "الزيت", "Catégorie": "الفئة", "Temps": "الوقت", "Toutes les huiles": "كل الزيوت", "Toutes les catégories": "كل الفئات", "Peu importe": "كل المدد", "Tous les niveaux": "كل المستويات", "Apéritif": "مقبلات", "Entrée": "طبق افتتاحي", "Salade": "سلطة", "Plat": "طبق رئيسي", "recettes": "وصفات", "Tout effacer": "مسح الكل", "Sublimez chaque plat": "ارتقِ بأطباقك", "Voir toutes les recettes": "عرض كل الوصفات", "Voir toute la collection": "اكتشف المجموعة كاملة", "Nos recettes avec cette huile": "وصفات بهذا الزيت", "En savoir plus": "اكتشف المزيد", "Conditionnements disponibles": "العبوات المتوفّرة", "Produits associés": "منتجات ذات صلة", "Cliquez sur une bouteille pour voir la contenance correspondante.": "اختر قارورة لعرض سعتها.", "Née au cœur de la Tunisie, ORZETA puise son identité dans une terre d’oliviers et un savoir-faire transmis à travers les générations.": "وُلدت ORZETA في قلب تونس، واستمدّت هويتها من أرض الزيتون ومن مهارة تتوارثها الأجيال.", "ORZETA transforme ainsi l’héritage tunisien en une expérience contemporaine.": "هكذا تحوّل ORZETA الإرث التونسي إلى تجربة معاصرة.", "Une signature tunisienne pensée pour rayonner au-delà des frontières.": "بصمة تونسية صُمّمت لتتألّق خارج الحدود.", "Déclaration nutritionnelle": "الحقائق الغذائية", "Pour 100 mL": "لكل 100 مل", "dont acides gras saturés": "منها دهون مشبعة", "Glucides": "الكربوهيدرات", "dont sucres": "منها سكريات", "Protéines": "البروتين", "91,6 g": "91.6 غرام", "13,1 g": "13.1 غرام", "Huile d'olive vierge extra — Acidité < 0,8% — Extraction à froid": "زيت زيتون بكر ممتاز — حموضة أقل من 0.8% — استخلاص على البارد", "Huile d'olive vierge extra — Acidité < 0,5% — Récolte de parcelles sélectionnées — Extraction à froid": "زيت زيتون بكر ممتاز — حموضة أقل من 0.5% — من قطع أرض منتقاة — استخلاص على البارد", "Huile d'olive vierge extra multivariétale biologique — Acidité ≤ 0,3% — Extraction à froid": "زيت زيتون بكر ممتاز عضوي متعدد الأصناف — حموضة لا تتجاوز 0.3% — استخلاص على البارد", "Professionnels": "للمهنيين", "Distributeurs, restaurateurs, épiceries fines : faites entrer une huile d’olive vierge extra tunisienne d’exception dans votre offre.": "موزّعون، مطاعم، متاجر المنتجات الراقية: أضيفوا إلى عرضكم زيت زيتون بكرًا ممتازًا تونسيًا استثنائيًا.", "Devenir partenaire": "كن شريكنا", "Restaurants & hôtels": "المطاعم والفنادق", "Cadeaux d’entreprise": "هدايا الشركات", "Une huile d’exception, pensée pour les tables et les rayons les plus exigeants.": "زيت استثنائي، لأرقى الموائد وأرقى الرفوف.", "Grandissons ensemble.": "لنكبر معًا.", "Qualité constante": "جودة ثابتة", "Extraction à froid et acidité maîtrisée selon la gamme.": "استخلاص على البارد وحموضة مضبوطة لكل تشكيلة.", "Trois gammes": "ثلاث تشكيلات", "Sun, Grove et Organic, du format 250 mL au 1 L.": "Sun وGrove وOrganic، من عبوة 250 مل إلى 1 لتر.", "Une expertise oléicole ancrée en Tunisie.": "خبرة في زراعة الزيتون متجذّرة في تونس.", "Accompagnement dédié": "مرافقة خاصة", "Échantillons, fiches techniques et supports de présentation.": "عيّنات، بطاقات تقنية، ومواد ترويجية.", "Distributeurs · Restaurants & hôtels · Épiceries fines · Cadeaux d’entreprise": "موزّعون · مطاعم وفنادق · متاجر المنتجات الراقية · هدايا الشركات", "Champs obligatoires": "حقول إجبارية", "Vous êtes": "صفتك", "Particulier": "زبون فردي", "Distributeur / importateur": "موزّع / مستورد", "Restaurant & hôtel": "مطعم وفندق", "Épicerie fine": "متجر منتجات راقية", "Autre": "أخرى", "Précisez": "يرجى التحديد", "Contact": "اتصل بنا", "Email": "البريد الإلكتروني", "WhatsApp": "واتساب", "0 g": "0 غرام", "3389 kJ / 824 kcal": "3389 كيلوجول / 824 سعرة حرارية", "Huile d'olive vierge extra · Tunisie": "زيت زيتون بكر ممتاز · تونس", "L'or vert de Tunisie,": "الذهب الأخضر لتونس،", "extrait à froid.": "مستخلص على البارد.", "Trois signatures, une seule origine : des olives tunisiennes extraites à froid pour une huile d'exception.": "ثلاثة توقيعات، وأصل واحد: زيتون تونسي مستخلص على البارد لزيت استثنائي.", "Découvrir la collection": "اكتشف المجموعة", "Née sous le soleil": "وُلدت تحت", "méditerranéen.": "شمس المتوسط.", "ORZETA naît en Tunisie, là où le soleil, la terre et des générations d'oléiculteurs façonnent des huiles au caractère méditerranéen singulier.": "في تونس وُلدت ORZETA، حيث صاغت الشمس والأرض وأجيالٌ من مزارعي الزيتون زيوتًا بطابع متوسطي لا يشبه سواه.", "Authentique par son origine.": "أصالةٌ في المنشأ.", "Précise par son art.": "دقّةٌ في الصنعة.", "Distinctive par sa nature.": "تميّزٌ بالفطرة.", "Une origine. Trois expressions.": "أصلٌ واحد. ثلاثة أوجه.", "D'une même terre baignée de soleil naissent trois expressions distinctes, qui révèlent chacune une facette de notre héritage oléicole.": "من أرضٍ واحدة تغمرها الشمس، تولد ثلاثة أوجه مختلفة، يكشف كلٌّ منها جانبًا من إرثنا الزيتوني.", "L'essentiel du quotidien": "رفيق كل يوم", "L'essentiel à table": "لمسة المائدة", "L'essentiel bio": "الخيار العضوي", "Une huile d'olive extra vierge douce et équilibrée, aux notes fruitées et florales. Polyvalente et expressive, elle apporte une touche méditerranéenne au quotidien.": "زيت زيتون بكر ممتاز لطيف ومتوازن، بنفحات فاكهية وزهرية. متعدّد الاستعمالات وغنيّ بالنكهة، يحمل إشراقة المتوسط إلى مطبخك اليومي.", "Une huile d'olive extra vierge plus intense, au profil structuré et à l'amertume délicate. Elle enrichit les salades, les assaisonnements et les touches finales.": "زيت زيتون بكر ممتاز أكثر كثافة، بقوام متماسك ولمسة مرارة راقية. يمنح العمق للسلطات والتتبيلات واللمسات الأخيرة.", "Une huile d'olive extra vierge certifiée biologique, pour ceux qui privilégient l'authenticité, la traçabilité et un approvisionnement responsable. Une expression pure et équilibrée.": "زيت زيتون بكر ممتاز عضوي معتمد، لمن يُقدّر الأصالة وإمكانية تتبّع المنشأ والمصادر المسؤولة. نقيّ ومتوازن.", "Portée par un savoir-faire oléicole tunisien, la marque conjugue authenticité, exigence et innovation. Chaque huile est élaborée avec une attention particulière portée à la qualité et à la constance.": "بخبرة تونسية في زراعة الزيتون، تجمع العلامة بين الأصالة والإتقان والابتكار. ويُصنع كل زيت بعناية خاصة بالجودة وثباتها.", "Un savoir-faire tunisien": "خبرة تونسية", "Cuisine de tous les jours : salades composées, poissons grillés, légumes, pâtes, plats mijotés.": "للطبخ اليومي: السلطات المشكّلة، السمك المشوي، الخضار، المعكرونة، والأطباق المطهوّة على نار هادئة.", "Salades, vinaigrettes et assaisonnements, légumes grillés, viandes rôties, pain rustique, touches finales.": "للسلطات والصلصات والتتبيلات، والخضار المشوية، واللحوم المشوية في الفرن، والخبز الريفي، واللمسات الأخيرة.", "Des recettes méditerranéennes pensées pour chacune de nos trois huiles. Des gestes simples, des produits justes, et une huile qu'on sent vraiment.": "وصفات متوسطية صُمّمت لكل زيت من زيوتنا الثلاثة. خطوات بسيطة، مكوّنات صادقة، وزيت تشعر بمذاقه حقًا.", "Des recettes méditerranéennes pour tirer le meilleur de chaque huile. Des gestes simples, des produits justes.": "وصفات متوسطية تُبرز أفضل ما في كل زيت. خطوات بسيطة ومكوّنات صادقة.", "Dès l'époque de Carthage, vers 814 av. J.-C., l'olivier s'enracine sur cette terre. Mago, agronome carthaginois, rédige le traité agricole de référence du monde antique — un texte si précieux que Rome le fait traduire après avoir détruit la ville. Sous l'Empire romain, la Tunisie devient l'un des greniers à huile de la Méditerranée : la richesse tirée de l'huile d'olive finance jusqu'à l'amphithéâtre d'El Jem.": "منذ عهد قرطاج، نحو عام 814 قبل الميلاد، ترسّخت شجرة الزيتون في هذه الأرض. وفيها وضع العالِم الزراعي القرطاجي ماغون أهمّ مرجع في الزراعة عرفه العالم القديم، وهو كتابٌ بلغت قيمته أن أمرت روما بترجمته بعد أن دمّرت قرطاج. وفي العهد الروماني، غدت تونس من أكبر مخازن الزيت في حوض المتوسط، حتى إن الثروة التي درّها زيت الزيتون أسهمت في تشييد مدرّج الجم الشهير.", "Profil aromatique": "شدّة النكهة", "Doux": "خفيفة", "Intense": "قوية", "Moins de 30 min": "أقل من 30 دقيقة", "30 min à 1 h": "من 30 دقيقة إلى ساعة", "Plus d'1 h": "أكثر من ساعة", "30 min": "30 دقيقة", "35 min": "35 دقيقة", "1 h 30": "ساعة و30 دقيقة", "Choisissez une bouteille, puis une contenance.": "اختر القارورة، ثم السعة.", "2 bouteilles : Marasca & Dorica": "قارورتان: Marasca وDorica", "3 huiles · 2 bouteilles (Marasca et Dorica) · de 250 mL à 1 L.": "3 زيوت · قارورتان (Marasca وDorica) · من 250 مل إلى 1 لتر.", "Quand le soleil": "حين تعانق الشمسُ", "rencontre l'oliveraie.": "بستانَ الزيتون.", "producteur mondial d'huile d'olive": "عالميًا في إنتاج زيت الزيتون"};
var SITE_I18N_PLACEHOLDERS_AR = {"contact-name": "اسمك", "contact-subject": "موضوع رسالتك", "contact-message": "رسالتك...", "contact-type-autre": "حدّد (مثال: جمعية، صحفي، تاجر جملة…)"};
var SITE_LANGS = ['fr', 'en', 'ar'];
var siteLang = 'fr';
try { siteLang = localStorage.getItem('orzeta_lang') || 'fr'; } catch (e) {}
if (SITE_LANGS.indexOf(siteLang) === -1) { siteLang = 'fr'; }
var ORIGINAL_TITLE = document.title;
var TITLE_EN = "Orzeta — Extra Virgin Olive Oil from Tunisia";
var TITLE_AR = "Orzeta — زيت زيتون بكر ممتاز من تونس";
function i18nDict(lang) { return lang === 'en' ? SITE_I18N : (lang === 'ar' ? SITE_I18N_AR : null); }
var i18nNodeOriginals = (typeof WeakMap !== 'undefined') ? new WeakMap() : null;
var i18nApplying = false;

function i18nWalk(root, lang) {
  if (!root || root.nodeType !== 1) { return; }
  var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
    acceptNode: function(node) {
      var p = node.parentNode;
      if (!p) { return NodeFilter.FILTER_REJECT; }
      var tag = p.nodeName;
      if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'TEXTAREA') { return NodeFilter.FILTER_REJECT; }
      return NodeFilter.FILTER_ACCEPT;
    }
  });
  var node;
  while ((node = walker.nextNode())) {
    var original = node.textContent;
    if (i18nNodeOriginals) {
      if (i18nNodeOriginals.has(node)) { original = i18nNodeOriginals.get(node); }
      else { i18nNodeOriginals.set(node, original); }
    }
    var trimmed = original.replace(/[ \t\n\r\f\v]+/g, ' ').trim();
    var dict = i18nDict(lang);
    if (dict && Object.prototype.hasOwnProperty.call(dict, trimmed)) {
      var leadMatch = original.match(/^\s*/);
      var trailMatch = original.match(/\s*$/);
      var leading = leadMatch ? leadMatch[0] : '';
      var trailing = trailMatch ? trailMatch[0] : '';
      var next = leading + dict[trimmed] + trailing;
      if (node.textContent !== next) { node.textContent = next; }
    } else if (node.textContent !== original) {
      node.textContent = original;
    }
  }
}

var ICONES_DIRECTIONNELLES = ['arrow_forward', 'arrow_back', 'send'];
function chargerPoliceArabe() {
  if (document.getElementById('police-arabe')) { return; }
  var l = document.createElement('link');
  l.id = 'police-arabe';
  l.rel = 'stylesheet';
  l.href = 'https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&family=Noto+Sans+Arabic:wght@300..800&display=swap';
  document.head.appendChild(l);
}
function texteCompteur(el) {
  if (siteLang === 'ar' && el.getAttribute('data-rang-ar')) { return el.getAttribute('data-rang-ar'); }
  return el.getAttribute('data-target') + suffixeCompteur(el);
}
function suffixeCompteur(el) {
  var s = el.getAttribute('data-suffix') || '';
  if (siteLang === 'en') { return s === 'er' ? 'st' : (s === 'e' ? 'nd' : s); }
  if (siteLang === 'ar') { return ''; }
  return s;
}
function applySiteLanguage(lang) {
  i18nApplying = true;
  i18nWalk(document.body, lang);
  Object.keys(SITE_I18N_PLACEHOLDERS).forEach(function(id) {
    var el = document.getElementById(id);
    if (!el) { return; }
    if (!el.hasAttribute('data-fr-placeholder')) { el.setAttribute('data-fr-placeholder', el.getAttribute('placeholder') || ''); }
    var ph = lang === 'en' ? SITE_I18N_PLACEHOLDERS[id] : (lang === 'ar' ? SITE_I18N_PLACEHOLDERS_AR[id] : null);
    el.setAttribute('placeholder', ph || el.getAttribute('data-fr-placeholder'));
  });
  document.title = lang === 'en' ? TITLE_EN : (lang === 'ar' ? TITLE_AR : ORIGINAL_TITLE);
  document.documentElement.setAttribute('lang', lang);
  document.documentElement.setAttribute('dir', lang === 'ar' ? 'rtl' : 'ltr');
  if (lang === 'ar') { chargerPoliceArabe(); }
  // Flèches : inversées en arabe (lecture de droite à gauche)
  document.querySelectorAll('.material-symbols-outlined').forEach(function(ic) {
    var nom = (ic.textContent || '').trim();
    if (ICONES_DIRECTIONNELLES.indexOf(nom) !== -1) { ic.classList.add('icone-rtl'); }
  });
  // Compteurs déjà animés (2e, 1er...) : suffixe dans la bonne langue
  document.querySelectorAll('.counter-value[data-fini="1"]').forEach(function(el) {
    el.textContent = texteCompteur(el);
  });
  document.querySelectorAll('.lang-btn').forEach(function(btn) {
    if (btn.getAttribute('data-lang') === lang) {
      btn.classList.add('text-primary');
    } else {
      btn.classList.remove('text-primary');
    }
  });
  requestAnimationFrame(function() { i18nApplying = false; });
}

function setSiteLanguage(lang) {
  siteLang = lang;
  try { localStorage.setItem('orzeta_lang', lang); } catch (e) {}
  applySiteLanguage(lang);
}

if ('MutationObserver' in window) {
  var i18nObserver = new MutationObserver(function(mutations) {
    if (i18nApplying || siteLang === 'fr') { return; }
    i18nApplying = true;
    mutations.forEach(function(m) {
      if (m.type === 'childList') {
        m.addedNodes.forEach(function(n) {
          if (n.nodeType === 1) { i18nWalk(n, siteLang); }
          else if (n.nodeType === 3) { i18nWalk(n.parentNode, siteLang); }
        });
      } else if (m.type === 'characterData' && m.target.parentNode) {
        i18nWalk(m.target.parentNode, siteLang);
      }
    });
    requestAnimationFrame(function() { i18nApplying = false; });
  });
  var i18nRoot = document.getElementById('main-content') || document.body;
  i18nObserver.observe(i18nRoot, { childList: true, subtree: true, characterData: true });
}

applySiteLanguage(siteLang);
</script>
</body></html>