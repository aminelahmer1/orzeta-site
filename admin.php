<?php
/* ==== Orzeta — espace d'administration des messages de contact ====
   Adresse : https://orzeta.com/admin.php
   Accès protégé par le mot de passe ADMIN_PASSWORD défini dans le fichier .env.
   Fonctions : liste des messages, filtres (type / statut), marquer traité, export CSV (Excel). */

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');

// ---- Lecture du .env (même logique que index.php) ----
function admin_charger_env($chemin) {
    if (!is_readable($chemin)) { return; }
    foreach (file($chemin, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ligne) {
        $ligne = trim($ligne);
        if ($ligne === '' || $ligne[0] === '#' || strpos($ligne, '=') === false) { continue; }
        list($cle, $valeur) = explode('=', $ligne, 2);
        $cle = trim($cle); $valeur = trim($valeur);
        if (strlen($valeur) >= 2 && (($valeur[0] === '"' && substr($valeur, -1) === '"') || ($valeur[0] === "'" && substr($valeur, -1) === "'"))) {
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
admin_charger_env(__DIR__ . '/.env');
require_once __DIR__ . '/stockage.php';

$MOT_DE_PASSE = (string) orzeta_env('ADMIN_PASSWORD');

// ---- Session sécurisée ----
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('orzeta_admin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$CSRF = $_SESSION['csrf'];

function h($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function csrf_ok() { return isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', (string) $_POST['csrf']); }

$erreurConnexion = '';
$action = $_POST['action'] ?? ($_GET['action'] ?? '');

// ---- Connexion / déconnexion ----
if ($action === 'connexion' && $MOT_DE_PASSE !== '') {
    $_SESSION['essais'] = ($_SESSION['essais'] ?? 0);
    if ($_SESSION['essais'] >= 5 && time() - ($_SESSION['dernier_essai'] ?? 0) < 300) {
        $erreurConnexion = 'Trop de tentatives. Réessayez dans 5 minutes.';
    } elseif (csrf_ok() && hash_equals($MOT_DE_PASSE, (string) ($_POST['mot_de_passe'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['essais'] = 0;
        header('Location: admin.php');
        exit;
    } else {
        sleep(1);
        $_SESSION['essais']++;
        $_SESSION['dernier_essai'] = time();
        $erreurConnexion = 'Mot de passe incorrect.';
    }
}
if ($action === 'deconnexion') {
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}
// Accès protégé par mot de passe, en local comme en ligne (ADMIN_PASSWORD dans le .env).
$connecte = !empty($_SESSION['admin']) && $MOT_DE_PASSE !== '';

// ---- Catégories de contact (identiques au formulaire) ----
$TYPES = [
    'Particulier' => 'Particulier',
    'Distributeur / importateur' => 'Distributeurs',
    'Restaurant & hôtel' => 'Restaurants & hôtels',
    'Épicerie fine' => 'Épiceries fines',
    "Cadeaux d'entreprise" => "Cadeaux d'entreprise",
    'Autre' => 'Autre',
];

$db = $connecte ? orzeta_db() : null;

// ---- Actions (utilisateur connecté) ----
if ($connecte && $db && $_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    if ($action === 'basculer' && isset($_POST['id'])) {
        $db->prepare('UPDATE messages SET traite = 1 - traite WHERE id = ?')->execute([(int) $_POST['id']]);
    }
    if ($action === 'supprimer' && isset($_POST['id'])) {
        $db->prepare('DELETE FROM messages WHERE id = ?')->execute([(int) $_POST['id']]);
    }
    header('Location: admin.php?' . http_build_query(array_intersect_key($_GET, ['type' => 1, 'statut' => 1, 'q' => 1, 'page' => 1])));
    exit;
}

// ---- Filtres ----
$fType = $_GET['type'] ?? '';
$fStatut = $_GET['statut'] ?? '';
$fRecherche = trim($_GET['q'] ?? '');
$where = []; $params = [];
if ($fType !== '' && isset($TYPES[$fType])) {
    if ($fType === 'Autre') { $where[] = "type_contact LIKE 'Autre%'"; }
    else { $where[] = 'type_contact = ?'; $params[] = $fType; }
}
if ($fStatut === 'nouveau') { $where[] = 'traite = 0'; }
if ($fStatut === 'traite') { $where[] = 'traite = 1'; }
if ($fRecherche !== '') {
    $where[] = '(nom LIKE ? OR email LIKE ? OR sujet LIKE ? OR message LIKE ? OR type_contact LIKE ?)';
    for ($i = 0; $i < 5; $i++) { $params[] = '%' . $fRecherche . '%'; }
}
$sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';

// ---- Export CSV (ouvre directement dans Excel) ----
if ($connecte && $db && $action === 'export') {
    $req = $db->prepare('SELECT * FROM messages' . $sqlWhere . ' ORDER BY id DESC');
    $req->execute($params);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="messages-orzeta-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM pour que Excel lise bien les accents
    fputcsv($out, ['N°', 'Date', 'Type de contact', 'Nom', 'Email', 'Sujet', 'Message', 'Statut', 'Mail envoyé'], ';');
    // Neutralise les cellules qui commencent par = + - @ (sinon Excel pourrait les exécuter comme formules)
    $sur = function ($v) { $v = (string) $v; return ($v !== '' && strpos('=+-@', $v[0]) !== false) ? "'" . $v : $v; };
    while ($l = $req->fetch()) {
        $l = array_map($sur, $l);
        fputcsv($out, [$l['id'], $l['cree_le'], $l['type_contact'], $l['nom'], $l['email'], $l['sujet'], $l['message'],
            $l['traite'] ? 'Traité' : 'Nouveau', $l['mail_envoye'] ? 'Oui' : 'Non'], ';');
    }
    fclose($out);
    exit;
}

// ---- Lecture des messages (pagination 30 par page) ----
$messages = []; $total = 0; $nbNouveaux = 0; $page = max(1, (int) ($_GET['page'] ?? 1)); $parPage = 30;
if ($connecte && $db) {
    $c = $db->prepare('SELECT COUNT(*) FROM messages' . $sqlWhere); $c->execute($params); $total = (int) $c->fetchColumn();
    $nbNouveaux = (int) $db->query('SELECT COUNT(*) FROM messages WHERE traite = 0')->fetchColumn();
    $req = $db->prepare('SELECT * FROM messages' . $sqlWhere . ' ORDER BY id DESC LIMIT ' . $parPage . ' OFFSET ' . (($page - 1) * $parPage));
    $req->execute($params);
    $messages = $req->fetchAll();
}
$nbPages = max(1, (int) ceil($total / $parPage));
function lien_filtre($changements) {
    $p = array_merge(array_intersect_key($_GET, ['type' => 1, 'statut' => 1, 'q' => 1]), $changements);
    $p = array_filter($p, function ($v) { return $v !== '' && $v !== null; });
    return 'admin.php' . ($p ? '?' . http_build_query($p) : '');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<meta name="robots" content="noindex, nofollow"/>
<title>Orzeta — Messages</title>
<link rel="icon" href="assets/images/logo-orzeta.png" type="image/png"/>
<link href="https://fonts.googleapis.com/css2?family=Literata:wght@600;700&family=Nunito+Sans:wght@400;600;700;800&display=swap" rel="stylesheet"/>
<style>
:root{--vert:#4a7c59;--vert-clair:#78a886;--creme:#faf6f0;--creme2:#f5f1ea;--bord:#e4e0d8;--texte:#2e3230;--texte2:#4a4e4a;--or:#705c30;--rouge:#b83230;}
*{box-sizing:border-box}
body{margin:0;background:var(--creme);color:var(--texte);font-family:"Nunito Sans",system-ui,sans-serif;font-size:14px}
a{color:var(--vert)}
.entete{background:#fff;border-bottom:1px solid var(--bord);padding:14px 24px;display:flex;align-items:center;justify-content:space-between;gap:16px;position:sticky;top:0;z-index:5}
.entete .marque{display:flex;align-items:center;gap:12px;font-family:Literata,serif;font-weight:700;font-size:18px}
.entete img{height:40px}
.sur-titre{font-size:11px;font-weight:800;letter-spacing:.2em;text-transform:uppercase;color:var(--or)}
.conteneur{max-width:1200px;margin:0 auto;padding:28px 20px 60px}
h1{font-family:Literata,serif;font-size:30px;margin:6px 0 4px}
.stats{display:flex;gap:12px;flex-wrap:wrap;margin:18px 0 22px}
.stat{background:#fff;border:1px solid var(--bord);border-radius:16px;padding:14px 18px;min-width:150px}
.stat b{display:block;font-family:Literata,serif;font-size:26px;color:var(--vert)}
.filtres{display:flex;flex-wrap:wrap;gap:10px;align-items:center;background:#fff;border:1px solid var(--bord);border-radius:16px;padding:14px;margin-bottom:18px}
.pastille{display:inline-block;padding:7px 13px;border-radius:999px;border:1px solid var(--bord);background:var(--creme2);color:var(--texte);text-decoration:none;font-weight:700;font-size:12px}
.pastille.actif{background:var(--vert);border-color:var(--vert);color:#fff}
.filtres form{display:flex;gap:8px;margin-left:auto}
input[type=text],input[type=password]{padding:10px 14px;border-radius:12px;border:1px solid var(--bord);background:var(--creme);font:inherit;min-width:220px}
input:focus{outline:2px solid var(--vert);outline-offset:1px}
.bouton{display:inline-flex;align-items:center;gap:6px;padding:10px 16px;border-radius:999px;border:0;background:var(--vert);color:#fff;font:inherit;font-weight:800;font-size:12px;letter-spacing:.06em;text-transform:uppercase;cursor:pointer;text-decoration:none}
.bouton.secondaire{background:#fff;color:var(--vert);border:1px solid var(--vert)}
.bouton.petit{padding:6px 12px;font-size:11px}
.bouton.danger{background:#fff;color:var(--rouge);border:1px solid #e8c2c1}
.carte{background:#fff;border:1px solid var(--bord);border-radius:18px;padding:18px 20px;margin-bottom:12px}
.carte.nouveau{border-left:5px solid var(--vert)}
.carte-haut{display:flex;flex-wrap:wrap;align-items:center;gap:10px;justify-content:space-between}
.qui{font-weight:800;font-size:15px}
.qui a{font-weight:600;font-size:13px;margin-left:6px}
.etiquette{display:inline-block;padding:4px 10px;border-radius:999px;background:var(--creme2);border:1px solid var(--bord);font-size:11px;font-weight:800;color:var(--or)}
.etiquette.nouveau{background:#e8f1ea;color:var(--vert);border-color:#cfe2d4}
.etiquette.alerte{background:#fdecec;color:var(--rouge);border-color:#f3cfce}
.date{color:var(--texte2);font-size:12px}
.sujet{margin:10px 0 4px;font-weight:700}
.message{white-space:pre-wrap;color:var(--texte2);line-height:1.55;margin:0}
.actions{display:flex;gap:8px;margin-top:12px;flex-wrap:wrap}
.actions form{margin:0}
.vide{text-align:center;padding:50px 20px;color:var(--texte2);background:#fff;border:1px dashed var(--bord);border-radius:18px}
.pagination{display:flex;gap:6px;justify-content:center;margin-top:20px}
.connexion{max-width:380px;margin:12vh auto;background:#fff;border:1px solid var(--bord);border-radius:24px;padding:34px;text-align:center;box-shadow:0 20px 50px -25px rgba(30,40,25,.35)}
.connexion img{height:70px}
.connexion input{width:100%;margin:18px 0 12px}
.connexion .bouton{width:100%;justify-content:center}
.erreur{color:var(--rouge);font-weight:700;font-size:13px;margin-top:12px}
.bouton.rouge{background:var(--rouge);color:#fff}
.bouton:disabled{opacity:.45;cursor:not-allowed}
.modale-fond{position:fixed;inset:0;background:rgba(30,35,30,.45);backdrop-filter:blur(3px);display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .2s;z-index:50}
.modale-fond.ouvert{opacity:1;pointer-events:auto}
.modale{background:#fff;border-radius:24px;max-width:430px;width:100%;padding:30px;text-align:center;box-shadow:0 30px 70px -25px rgba(0,0,0,.4);transform:translateY(10px) scale(.97);transition:transform .2s}
.modale-fond.ouvert .modale{transform:none}
.modale-icone{width:52px;height:52px;border-radius:50%;background:#fdecec;color:var(--rouge);font-family:Literata,serif;font-weight:700;font-size:26px;display:flex;align-items:center;justify-content:center;margin:0 auto 12px}
.modale h2{font-family:Literata,serif;font-size:22px;margin:0 0 8px}
.modale-qui{font-weight:700;margin:0 0 10px;word-break:break-word}
.modale-texte{color:var(--texte2);line-height:1.5;margin:0 0 16px}
.modale-check{display:flex;gap:8px;align-items:center;justify-content:center;font-weight:700;font-size:13px;margin-bottom:18px;cursor:pointer}
.modale-check input{width:18px;height:18px;accent-color:var(--rouge)}
.modale-actions{display:flex;gap:10px;justify-content:center}
@media (max-width:640px){.filtres form{margin-left:0;width:100%}.filtres input{flex:1;min-width:0}h1{font-size:24px}}
</style>
</head>
<body>
<?php if ($MOT_DE_PASSE === '' && !$connecte): ?>
<div class="connexion">
  <img src="assets/images/logo-orzeta.png" alt="Orzeta"/>
  <p class="sur-titre" style="margin-top:14px">Espace administration</p>
  <p>L'accès est désactivé : ajoutez la ligne <b>ADMIN_PASSWORD=...</b> dans le fichier <b>.env</b>.</p>
</div>
<?php elseif (!$connecte): ?>
<form class="connexion" method="post" action="admin.php">
  <img src="assets/images/logo-orzeta.png" alt="Orzeta"/>
  <p class="sur-titre" style="margin-top:14px">Espace administration</p>
  <h1 style="font-size:24px">Messages de contact</h1>
  <input type="hidden" name="action" value="connexion"/>
  <input type="hidden" name="csrf" value="<?= h($CSRF) ?>"/>
  <input type="password" name="mot_de_passe" placeholder="Mot de passe" autocomplete="current-password" required autofocus/>
  <button class="bouton" type="submit">Se connecter</button>
  <?php if ($erreurConnexion): ?><p class="erreur"><?= h($erreurConnexion) ?></p><?php endif; ?>
</form>
<?php else: ?>
<div class="entete">
  <div class="marque"><img src="assets/images/logo-orzeta.png" alt="Orzeta"/><span>Messages de contact</span></div>
  <div style="display:flex;gap:8px">
    <a class="bouton secondaire petit" href="index.php" target="_blank">Voir le site</a>
    <a class="bouton petit" href="admin.php?action=deconnexion">Déconnexion</a>
  </div>
</div>
<div class="conteneur">
  <p class="sur-titre">Tableau de bord</p>
  <h1>Messages reçus</h1>
  <?php if (!$db): ?>
    <p class="erreur">La base de données n'est pas disponible (extension SQLite absente ou dossier « data » non accessible en écriture).</p>
  <?php else: ?>
  <div class="stats">
    <div class="stat"><b><?= $nbNouveaux ?></b>À traiter</div>
    <div class="stat"><b><?= (int) $db->query('SELECT COUNT(*) FROM messages')->fetchColumn() ?></b>Messages au total</div>
    <div class="stat"><b><?= (int) $db->query("SELECT COUNT(*) FROM messages WHERE type_contact NOT IN ('Particulier') AND type_contact NOT LIKE 'Autre%'")->fetchColumn() ?></b>Contacts professionnels</div>
  </div>

  <div class="filtres">
    <a class="pastille <?= $fType === '' ? 'actif' : '' ?>" href="<?= h(lien_filtre(['type' => '', 'page' => ''])) ?>">Tous</a>
    <?php foreach ($TYPES as $valeur => $libelle): ?>
      <a class="pastille <?= $fType === $valeur ? 'actif' : '' ?>" href="<?= h(lien_filtre(['type' => $valeur, 'page' => ''])) ?>"><?= h($libelle) ?></a>
    <?php endforeach; ?>
    <span style="width:1px;height:24px;background:var(--bord);margin:0 4px"></span>
    <a class="pastille <?= $fStatut === '' ? 'actif' : '' ?>" href="<?= h(lien_filtre(['statut' => '', 'page' => ''])) ?>">Tous statuts</a>
    <a class="pastille <?= $fStatut === 'nouveau' ? 'actif' : '' ?>" href="<?= h(lien_filtre(['statut' => 'nouveau', 'page' => ''])) ?>">À traiter</a>
    <a class="pastille <?= $fStatut === 'traite' ? 'actif' : '' ?>" href="<?= h(lien_filtre(['statut' => 'traite', 'page' => ''])) ?>">Traités</a>
    <form method="get" action="admin.php">
      <?php if ($fType !== ''): ?><input type="hidden" name="type" value="<?= h($fType) ?>"/><?php endif; ?>
      <?php if ($fStatut !== ''): ?><input type="hidden" name="statut" value="<?= h($fStatut) ?>"/><?php endif; ?>
      <input type="text" name="q" value="<?= h($fRecherche) ?>" placeholder="Rechercher (nom, email, mot…)"/>
      <button class="bouton secondaire" type="submit">OK</button>
    </form>
  </div>

  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;gap:10px;flex-wrap:wrap">
    <span class="date"><?= $total ?> message(s) affiché(s)</span>
    <a class="bouton" href="<?= h(lien_filtre(['action' => 'export'])) ?>">Exporter en Excel (CSV)</a>
  </div>

  <?php if (!$messages): ?>
    <div class="vide">Aucun message pour ces filtres.</div>
  <?php endif; ?>

  <?php foreach ($messages as $m): ?>
    <div class="carte <?= $m['traite'] ? '' : 'nouveau' ?>">
      <div class="carte-haut">
        <div>
          <span class="qui"><?= h($m['nom']) ?><a href="mailto:<?= h($m['email']) ?>?subject=<?= rawurlencode('Re: ' . ($m['sujet'] !== '' ? $m['sujet'] : 'Votre message à Orzeta')) ?>"><?= h($m['email']) ?></a></span>
        </div>
        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
          <span class="etiquette"><?= h($m['type_contact']) ?></span>
          <span class="etiquette <?= $m['traite'] ? '' : 'nouveau' ?>"><?= $m['traite'] ? 'Traité' : 'À traiter' ?></span>
          <?php if (!$m['mail_envoye']): ?><span class="etiquette alerte" title="Le mail de notification n'est pas parti, mais le message est bien enregistré ici.">Mail non envoyé</span><?php endif; ?>
          <span class="date"><?= h(date('d/m/Y à H:i', strtotime($m['cree_le']))) ?></span>
        </div>
      </div>
      <?php if ($m['sujet'] !== ''): ?><p class="sujet"><?= h($m['sujet']) ?></p><?php endif; ?>
      <p class="message"><?= h($m['message']) ?></p>
      <div class="actions">
        <form method="post" action="<?= h(lien_filtre(['page' => $page > 1 ? $page : ''])) ?>">
          <input type="hidden" name="csrf" value="<?= h($CSRF) ?>"/><input type="hidden" name="action" value="basculer"/><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"/>
          <button class="bouton secondaire petit" type="submit"><?= $m['traite'] ? 'Remettre à traiter' : 'Marquer comme traité' ?></button>
        </form>
        <form class="form-supprimer" data-nom="<?= h($m['nom']) ?>" data-email="<?= h($m['email']) ?>" data-date="<?= h(date('d/m/Y à H:i', strtotime($m['cree_le']))) ?>" method="post" action="<?= h(lien_filtre(['page' => $page > 1 ? $page : ''])) ?>">
          <input type="hidden" name="csrf" value="<?= h($CSRF) ?>"/><input type="hidden" name="action" value="supprimer"/><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"/>
          <button class="bouton danger petit" type="submit">Supprimer</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if ($nbPages > 1): ?>
    <div class="pagination">
      <?php for ($p = 1; $p <= $nbPages; $p++): ?>
        <a class="pastille <?= $p === $page ? 'actif' : '' ?>" href="<?= h(lien_filtre(['page' => $p])) ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php if ($connecte): ?>
<div class="modale-fond" id="modale-suppression" aria-hidden="true">
  <div class="modale" role="dialog" aria-modal="true" aria-labelledby="modale-titre">
    <div class="modale-icone">!</div>
    <h2 id="modale-titre">Supprimer ce message ?</h2>
    <p class="modale-qui" id="modale-qui"></p>
    <p class="modale-texte">Cette action est <b>définitive</b> : le message sera effacé de la base et ne pourra pas être récupéré.</p>
    <label class="modale-check"><input id="modale-confirme" type="checkbox"/> <span>Je confirme vouloir supprimer ce message</span></label>
    <div class="modale-actions">
      <button class="bouton secondaire" id="modale-annuler" type="button">Annuler</button>
      <button class="bouton rouge" id="modale-valider" type="button" disabled>Supprimer</button>
    </div>
  </div>
</div>
<script>
(function () {
  var fond = document.getElementById('modale-suppression');
  var check = document.getElementById('modale-confirme');
  var valider = document.getElementById('modale-valider');
  var formEnCours = null;
  function ouvrir(form) {
    formEnCours = form;
    document.getElementById('modale-qui').textContent =
      form.dataset.nom + ' — ' + form.dataset.email + ' (' + form.dataset.date + ')';
    check.checked = false; valider.disabled = true;
    fond.classList.add('ouvert'); fond.setAttribute('aria-hidden', 'false');
    setTimeout(function () { check.focus(); }, 50);
  }
  function fermer() {
    fond.classList.remove('ouvert'); fond.setAttribute('aria-hidden', 'true'); formEnCours = null;
  }
  document.querySelectorAll('.form-supprimer').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (f.dataset.confirme === '1') { return; }
      e.preventDefault(); ouvrir(f);
    });
  });
  check.addEventListener('change', function () { valider.disabled = !check.checked; });
  valider.addEventListener('click', function () {
    if (!formEnCours || !check.checked) { return; }
    formEnCours.dataset.confirme = '1';
    valider.disabled = true; valider.textContent = 'Suppression…';
    formEnCours.submit();
  });
  document.getElementById('modale-annuler').addEventListener('click', fermer);
  fond.addEventListener('click', function (e) { if (e.target === fond) { fermer(); } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && fond.classList.contains('ouvert')) { fermer(); } });
})();
</script>
<?php endif; ?>
</body>
</html>