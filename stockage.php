<?php
/* ==== Orzeta — stockage des messages du formulaire de contact (SQLite) ====
   Base de données contenue dans un simple fichier : data/messages.sqlite.
   Aucun serveur MySQL nécessaire : fonctionne sur l'offre OVH Starter.
   Ce fichier est utilisé par index.php (enregistrement) et admin.php (consultation). */

if (defined('ORZETA_STOCKAGE')) { return; }
define('ORZETA_STOCKAGE', 1);

// Heure de Tunisie pour les dates enregistrées
date_default_timezone_set('Africa/Tunis');

/** Retourne la connexion à la base (ou null si SQLite n'est pas disponible). */
function orzeta_db() {
    static $pdo = false;
    if ($pdo !== false) { return $pdo; }
    $pdo = null;
    if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        error_log('Orzeta stockage : extension pdo_sqlite indisponible.');
        return null;
    }
    $dossier = __DIR__ . '/data';
    if (!is_dir($dossier)) { @mkdir($dossier, 0755, true); }
    // Protection du dossier : personne ne doit pouvoir télécharger la base depuis le navigateur.
    if (is_dir($dossier) && !file_exists($dossier . '/.htaccess')) {
        @file_put_contents($dossier . '/.htaccess', "Require all denied\n");
    }
    try {
        $pdo = new PDO('sqlite:' . $dossier . '/messages.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cree_le TEXT NOT NULL,
            type_contact TEXT NOT NULL,
            nom TEXT NOT NULL,
            email TEXT NOT NULL,
            sujet TEXT NOT NULL DEFAULT "",
            message TEXT NOT NULL,
            traite INTEGER NOT NULL DEFAULT 0,
            mail_envoye INTEGER NOT NULL DEFAULT 0
        )');
    } catch (Exception $e) {
        error_log('Orzeta stockage : ' . $e->getMessage());
        $pdo = null;
    }
    return $pdo;
}

/** Enregistre un message. Retourne son identifiant, ou 0 en cas d'échec. */
function orzeta_enregistrer_message($type, $nom, $email, $sujet, $message) {
    $db = orzeta_db();
    if (!$db) { return 0; }
    try {
        $req = $db->prepare('INSERT INTO messages (cree_le, type_contact, nom, email, sujet, message) VALUES (?, ?, ?, ?, ?, ?)');
        $req->execute([date('Y-m-d H:i:s'), $type, $nom, $email, $sujet, $message]);
        return (int) $db->lastInsertId();
    } catch (Exception $e) {
        error_log('Orzeta stockage : ' . $e->getMessage());
        return 0;
    }
}

/** Note si le mail de notification est bien parti pour ce message. */
function orzeta_marquer_mail_envoye($id, $envoye) {
    $db = orzeta_db();
    if (!$db || !$id) { return; }
    try {
        $db->prepare('UPDATE messages SET mail_envoye = ? WHERE id = ?')->execute([$envoye ? 1 : 0, (int) $id]);
    } catch (Exception $e) {
        error_log('Orzeta stockage : ' . $e->getMessage());
    }
}
