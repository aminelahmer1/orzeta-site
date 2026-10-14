<?php
/* ==== Orzeta — cartes de visite électroniques ====
   https://orzeta.com/carte.php            → carte de la société
   https://orzeta.com/carte.php?p=olfa     → carte d'une personne (clé du tableau $PERSONNES)
   ...&vcf=1                               → télécharge la fiche contact (.vcf)
   Pour ajouter quelqu'un : copier un bloc de $PERSONNES, changer la clé et les infos. */

$SITE = 'https://orzeta.com';
$ADRESSES = [
    'siege' => ['type' => ['fr' => 'Siège social', 'en' => 'Head Office', 'ar' => 'المقرّ الرئيسي'],
                'rue' => 'Zen Building, Rue du Lac Tchad, Les Berges du Lac I', 'cp' => '1053', 'ville' => 'Tunis', 'pays' => 'Tunisia',
                'gps' => '36.8387757,10.2404081'],   // PROMED Group, Immeuble ZEN, 4e étage
    'usine' => ['type' => ['fr' => 'Unité de production', 'en' => 'Production Facility', 'ar' => 'وحدة الإنتاج'],
                'rue' => 'Mahdia Road Km 19', 'cp' => '3064', 'ville' => 'Sfax', 'pays' => 'Tunisia',
                'gps' => '34.884132,10.845287'],   // point exact de l'unité de production sur Google Maps
];

// Carte de la société (sans ?p=)
$SOCIETE = [
    'nom' => 'Orzeta', 'prefixe' => '',
    'poste' => ['fr' => "Huile d'olive vierge extra de Tunisie", 'en' => 'Extra virgin olive oil from Tunisia', 'ar' => 'زيت زيتون بكر ممتاز من تونس'],
    'mobile' => '(+216) 92 300 800', 'fixe' => '(+216) 71 960 995', 'whatsapp' => '(+216) 92 300 800',
    'email' => 'contact@orzeta.com', 'adresses' => ['siege', 'usine'],
];

// Cartes du staff — lien : https://orzeta.com/carte.php?p=CLE
$PERSONNES = [
    'olfa' => [
        'nom' => 'Olfa BEN HAJEL', 'prenom' => 'Olfa', 'famille' => 'BEN HAJEL', 'prefixe' => 'Ing.',
        'poste' => ['fr' => 'Coordinatrice générale', 'en' => 'General Coordinator', 'ar' => 'المنسّقة العامّة'],
        'mobile' => '(+216) 92 300 800', 'fixe' => '(+216) 71 960 995', 'whatsapp' => '(+216) 92 300 800',
        'email' => 'sales@olived.tn',
        'adresses' => ['siege', 'usine'],
    ],
    'fatma' => [
        'nom' => 'Fatma KHEMAKHEM ZOUARI', 'prenom' => 'Fatma', 'famille' => 'KHEMAKHEM ZOUARI', 'prefixe' => '',
        'poste' => ['fr' => 'Conseillère de marque', 'en' => 'Brand Advisor', 'ar' => 'مستشارة العلامة التجارية'],
        'mobile' => '(+216) 98 463 000', 'fixe' => '(+216) 71 960 995', 'whatsapp' => '(+216) 98 463 000',
        'email' => 'fkz@promedgroup.tn',
        'adresses' => ['siege', 'usine'],
    ],
    'sonia' => [
        'nom' => 'Sonia MKAOUAR', 'prenom' => 'Sonia', 'famille' => 'MKAOUAR', 'prefixe' => '',
        'poste' => ['fr' => 'Responsable du développement commercial', 'en' => 'Business Development Executive', 'ar' => 'مسؤولة تطوير الأعمال'],
        'mobile' => '(+216) 98 780 838', 'fixe' => '(+216) 71 960 995', 'whatsapp' => '(+216) 98 780 838',
        'email' => 'sm@promedgroup.tn',
        'adresses' => ['siege', 'usine'],
    ],
    'zina' => [
        'nom' => 'Zina GUERMAZI', 'prenom' => 'Zina', 'famille' => 'GUERMAZI', 'prefixe' => 'PhD | Ing.',
        'poste' => ['fr' => 'Responsable Management de la Qualité (RMQ)', 'en' => 'Quality Management Manager', 'ar' => 'مسؤولة إدارة الجودة'],
        'mobile' => '(+216) 99 488 006', 'fixe' => '(+216) 71 960 995', 'whatsapp' => '(+216) 99 488 006',
        'email' => 'quality@olived.tn',
        'adresses' => ['siege', 'usine'],
    ],
    'mohamed' => [
        'nom' => 'Mohamed ZOUARI', 'prenom' => 'Mohamed', 'famille' => 'ZOUARI', 'prefixe' => '',
        'poste' => ['fr' => 'Président-directeur général', 'en' => 'CEO', 'ar' => 'الرئيس التنفيذي'],
        'mobile' => '(+216) 98 462 000', 'fixe' => '(+216) 71 960 995', 'whatsapp' => '(+216) 98 462 000',
        'email' => 'mz@olived.tn',
        'adresses' => ['siege', 'usine'],
    ],
];

$INSTAGRAM = '';   // ex. 'https://instagram.com/orzeta' — vide = bouton masqué
$FACEBOOK  = '';

$cle = isset($_GET['p']) ? strtolower(preg_replace('/[^a-z0-9_-]/i', '', $_GET['p'])) : '';
$P = ($cle !== '' && isset($PERSONNES[$cle])) ? $PERSONNES[$cle] : $SOCIETE;
$estPersonne = ($P !== $SOCIETE);
$num = function ($t) { return preg_replace('/[^0-9+]/', '', $t); };

// ---- Fiche contact (vCard) ----
if (isset($_GET['vcf'])) {
    $e = function ($v) { return str_replace(['\\', ',', ';', "\n"], ['\\\\', '\,', '\;', ' '], $v); };
    $l = ['BEGIN:VCARD', 'VERSION:3.0'];
    if ($estPersonne) {
        $l[] = 'N:' . $e($P['famille']) . ';' . $e($P['prenom']) . ';;' . $e($P['prefixe']) . ';';
        $l[] = 'FN:' . $e(trim($P['prefixe'] . ' ' . $P['nom']));
        $l[] = 'TITLE:' . $e($P['poste']['en']);
    } else {
        $l[] = 'N:;;;;'; $l[] = 'FN:Orzeta';
    }
    $l[] = 'ORG:Orzeta';
    if ($P['mobile'] !== '') { $l[] = 'TEL;TYPE=CELL:' . $num($P['mobile']); }
    if ($P['fixe'] !== '')   { $l[] = 'TEL;TYPE=WORK,VOICE:' . $num($P['fixe']); }
    $l[] = 'EMAIL;TYPE=WORK:' . $P['email'];
    $l[] = 'URL:' . $SITE;
    foreach ($P['adresses'] as $a) { $A = $ADRESSES[$a];
        $l[] = 'ADR;TYPE=WORK:;' . $e($A['type']['en']) . ';' . $e($A['rue']) . ';' . $e($A['ville']) . ';;' . $A['cp'] . ';' . $e($A['pays']); }
    if (!$P['adresses']) { $l[] = 'ADR;TYPE=WORK:;;;;;;Tunisia'; }
    $logo = __DIR__ . '/assets/images/logo-orzeta-orange.png';
    if (is_readable($logo) && filesize($logo) < 400000) { $l[] = 'PHOTO;ENCODING=b;TYPE=PNG:' . base64_encode(file_get_contents($logo)); }
    $l[] = 'END:VCARD';
    $fichier = $estPersonne ? preg_replace('/[^A-Za-z0-9]+/', '-', $P['nom']) . '-Orzeta' : 'Orzeta';
    header('Content-Type: text/vcard; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fichier . '.vcf"');
    echo implode("\r\n", $l) . "\r\n";
    exit;
}
function h($v) { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
$titre = $estPersonne ? trim($P['prefixe'] . ' ' . $P['nom']) . ' — Orzeta' : 'Orzeta — Carte de visite';
$lienVcf = 'carte.php?' . ($estPersonne ? 'p=' . urlencode($cle) . '&' : '') . 'vcf=1';
$SVG = [
 'tel'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/></svg>',
 'mob'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M11 18h2"/></svg>',
 'mail' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>',
 'web'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
 'pin'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>',
 'wa'   => '<svg viewBox="0 0 24 24" fill="#25D366"><path d="M17.5 14.4c-.3-.1-1.8-.9-2-1-.3-.1-.5-.1-.7.1-.2.3-.8 1-.9 1.2-.2.2-.3.2-.6.1-.3-.1-1.3-.5-2.4-1.5-.9-.8-1.5-1.8-1.7-2.1-.2-.3 0-.5.1-.6l.4-.5c.2-.2.2-.3.3-.5.1-.2 0-.4 0-.5l-.9-2.2c-.2-.6-.5-.5-.7-.5h-.6c-.2 0-.5.1-.8.4-.3.3-1 1-1 2.5s1.1 2.9 1.2 3.1c.1.2 2.1 3.2 5.1 4.5.7.3 1.3.5 1.7.6.7.2 1.4.2 1.9.1.6-.1 1.8-.7 2-1.4.2-.7.2-1.3.2-1.4-.1-.1-.3-.2-.6-.4zM12 21.8c-1.8 0-3.5-.5-5-1.4l-.4-.2-3.7 1 1-3.6-.2-.4A9.8 9.8 0 1 1 12 21.8zM20.5 3.5A11.8 11.8 0 0 0 1.9 17.8L.2 24l6.4-1.7A11.8 11.8 0 0 0 24 12c0-3.2-1.2-6.1-3.5-8.5z"/></svg>',
];
$SVG['flip'] = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 1-15.5 6.2M3 12A9 9 0 0 1 18.5 5.8"/><path d="M18 2v4h-4M6 22v-4h4"/></svg>';
$SVG['plus'] = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></svg>';
$waNum = ltrim($num($P['whatsapp'] !== '' ? $P['whatsapp'] : $P['mobile']), '+');
?><!DOCTYPE html>
<html lang="fr" dir="ltr">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
<title><?= h($titre) ?></title>
<meta name="description" content="Orzeta, huile d'olive vierge extra de Tunisie. Coordonnées et contact."/>
<meta name="robots" content="noindex"/>
<meta name="format-detection" content="telephone=no, email=no, address=no, date=no"/>
<meta name="x-apple-disable-message-reformatting"/>
<meta name="theme-color" content="#153c1f"/>
<meta property="og:title" content="<?= h($titre) ?>"/>
<meta property="og:description" content="<?= h($estPersonne ? $P['poste']['en'] . ' · Orzeta' : "Huile d'olive vierge extra de Tunisie") ?>"/>
<meta property="og:image" content="<?= $SITE ?>/assets/images/sun-marasca-1l.webp"/>
<link rel="icon" type="image/png" href="assets/images/logo-orzeta-orange.png"/>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
<link href="https://fonts.googleapis.com/css2?family=Literata:ital,wght@0,600;0,700;1,500&family=Nunito+Sans:wght@400;600;700;800&display=swap" rel="stylesheet"/>
<style>
:root{--vert:#153c1f;--vert-f:#0f2e17;--orange:#ea580d;--orange2:#c8460a;--creme:#f6eee2;--carte:#fbf6ee;--beige:#f3e3cf;--texte:#153c1f;}
*{box-sizing:border-box;margin:0;padding:0;}
.face a,.face a[href^="tel"],.face a[href^="mailto"],.face a[x-apple-data-detectors]{color:inherit !important;text-decoration:none !important;font:inherit !important;pointer-events:none;}
html{background:var(--vert-f);}
body{min-height:100vh;font-family:'Nunito Sans',system-ui,sans-serif;color:var(--creme);
  background:radial-gradient(130% 55% at 50% 0%,#215030 0%,var(--vert) 55%,var(--vert-f) 100%) fixed;
  display:flex;justify-content:center;padding:0 16px calc(28px + env(safe-area-inset-bottom));}
html[dir="rtl"] body{font-family:'Noto Sans Arabic','Nunito Sans',sans-serif;}
.page{width:100%;max-width:460px;display:flex;flex-direction:column;align-items:center;}
.tete{margin-top:34px;width:150px;display:flex;flex-direction:column;align-items:center;}
.logo{width:100%;height:auto;display:block;}
.devise{margin-top:12px;font-size:.55rem;font-weight:700;letter-spacing:.2em;text-transform:uppercase;white-space:nowrap;text-indent:.2em;}
html[dir="rtl"] .devise{letter-spacing:0;font-size:.9rem;}

/* ---- Carte qui se retourne ---- */
.scene{width:100%;margin-top:46px;perspective:1400px;cursor:pointer;-webkit-tap-highlight-color:transparent;outline:none;}
.scene:focus-visible .face{box-shadow:0 0 0 3px var(--creme),0 24px 40px -18px rgba(0,0,0,.6);}
.carte3d{position:relative;width:100%;aspect-ratio:1.9;transform-style:preserve-3d;transition:transform .8s cubic-bezier(.3,.7,.2,1);}
.scene.retournee .carte3d{transform:rotateY(180deg);}
.scene.indice .carte3d{animation:indice 1.1s ease-in-out .9s 1;}
@keyframes indice{0%,100%{transform:rotateY(0)}40%{transform:rotateY(-16deg)}70%{transform:rotateY(5deg)}}
.face{position:absolute;inset:0;border-radius:14px;overflow:hidden;
  backface-visibility:hidden;-webkit-backface-visibility:hidden;box-shadow:0 24px 40px -18px rgba(0,0,0,.6);container-type:inline-size;}
.verso{transform:rotateY(180deg);}

/* Recto — reprend la carte imprimée */
.recto{background:#f8f4e8;color:var(--vert);display:flex;align-items:stretch;}
.r-g{width:29%;flex-shrink:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4cqw;}
.r-g img{width:64%;height:auto;}
.r-g img.lg-olived{width:70%;}
.face a.lien-olived{pointer-events:auto !important;text-decoration:none !important;color:var(--vert) !important;cursor:pointer;}
.lien-olived small{text-decoration:none;}
.lien-olived:active{opacity:.7;}
.scene.retournee .lien-olived{pointer-events:none !important;}
.r-g small{font-size:2.3cqw;font-weight:600;color:var(--vert);letter-spacing:.02em;direction:ltr;}
.r-sep{width:1.5px;background:var(--orange);margin:7.5cqw 0;flex-shrink:0;}
.r-d{flex:1;min-width:0;padding:4.6cqw 4cqw 4cqw 6.5cqw;display:flex;flex-direction:column;justify-content:center;font-size:calc(1cqw * var(--k,1));}
html[dir="rtl"] .r-d{padding:4.6cqw 6.5cqw 4cqw 4cqw;}
.nom{font-size:calc(4.3em * var(--kn,1));line-height:1.1;font-weight:800;color:var(--vert);white-space:nowrap;direction:ltr;unicode-bidi:isolate;}
html[dir="rtl"] .nom{text-align:right;}
.poste{margin-top:.15em;font-size:3.1em;line-height:1.2;font-weight:400;color:var(--vert);}
.coord{list-style:none;margin-top:2.6em;display:grid;gap:.95em;}
.coord li{display:flex;align-items:center;gap:1.1em;font-size:2.65em;font-weight:400;}
.coord li span{direction:ltr;unicode-bidi:isolate;}
.coord i{width:1.15em;display:flex;justify-content:center;color:var(--orange);flex-shrink:0;}
.coord svg{width:1.05em;height:1.05em;}
.adrs{margin-top:2.6em;display:grid;gap:1.4em;}
.adr{display:flex;gap:1.1em;}
.adr i{color:var(--orange);flex-shrink:0;width:2.65em;display:flex;justify-content:center;padding-top:.15em;}
.adr i svg{width:2.65em;height:2.65em;fill:var(--orange);stroke:#f8f4e8;}
.adr p{font-size:2.45em;line-height:1.35;}
.adr p b{display:block;font-size:1.06em;font-weight:800;text-transform:uppercase;}
.adr p span{display:block;direction:ltr;unicode-bidi:isolate;}
html[dir="rtl"] .adr p span{text-align:right;}

/* Verso — tout en vert, logo centré */
.verso{background:var(--vert);display:flex;align-items:center;justify-content:center;box-shadow:inset 0 0 0 1px rgba(246,238,226,.14),0 24px 40px -18px rgba(0,0,0,.6);}
.verso img{width:30%;height:auto;}

/* Sous la carte */
.astuce{margin-top:20px;display:flex;flex-direction:column;align-items:center;gap:8px;font-size:.95rem;text-align:center;background:none;border:0;color:var(--creme);font-family:inherit;cursor:pointer;}
.astuce svg{width:26px;height:26px;color:var(--orange);transition:transform .8s ease;}
.scene.retournee + .astuce svg{transform:rotate(180deg);}
.actions{width:100%;margin-top:30px;display:grid;grid-template-columns:repeat(4,1fr);gap:8px;}
.action{border:1.5px solid rgba(246,238,226,.75);border-radius:12px;padding:12px 2px 10px;display:flex;flex-direction:column;align-items:center;gap:8px;color:var(--creme);text-decoration:none;font-size:.78rem;font-weight:700;transition:background-color .2s ease,transform .15s ease;}
.action:hover{background:rgba(246,238,226,.08);}
.action:active{transform:scale(.96);}
.action span.ic{width:40px;height:40px;border-radius:10px;background:var(--vert);box-shadow:inset 0 0 0 1px rgba(246,238,226,.22);color:var(--orange);display:flex;align-items:center;justify-content:center;}
.action svg{width:21px;height:21px;}
.action .ic svg[fill="#25D366"]{fill:var(--orange);}
.enregistrer{width:100%;margin-top:12px;display:flex;align-items:center;justify-content:center;gap:.6rem;padding:15px;border-radius:12px;background:var(--orange);color:var(--creme);text-decoration:none;font-weight:800;font-size:.95rem;box-shadow:0 14px 26px -14px rgba(234,88,13,.9);}
.enregistrer:active{background:var(--orange2);}
.enregistrer svg{width:20px;height:20px;}
.cartes-lieux{width:100%;margin-top:12px;display:grid;grid-template-columns:1fr 1fr;gap:8px;}
.lieu{display:flex;align-items:center;gap:8px;padding:10px 12px;border-radius:12px;background:rgba(246,238,226,.07);color:var(--creme);text-decoration:none;font-size:.74rem;line-height:1.3;}
.lieu svg{width:18px;height:18px;flex-shrink:0;color:var(--orange);}
.lieu b{display:block;font-weight:800;}
.reseaux{display:flex;justify-content:center;gap:.7rem;margin-top:18px;}
.reseaux a{width:42px;height:42px;border-radius:50%;border:1.5px solid rgba(246,238,226,.6);display:flex;align-items:center;justify-content:center;color:var(--creme);}
.reseaux svg{width:19px;height:19px;}
.partager{margin-top:20px;background:none;border:0;color:var(--creme);opacity:.85;font:700 .85rem inherit;cursor:pointer;text-decoration:underline;text-underline-offset:3px;}
.pied{margin-top:12px;font-size:.72rem;opacity:.55;}
@media (prefers-reduced-motion:reduce){.carte3d{transition:none}.scene.indice .carte3d{animation:none}}
@media (min-width:600px){body{padding-top:20px}}
</style>
</head>
<body>
<main class="page">
  <div class="tete">
    <img class="logo" src="assets/images/logo-orzeta-orange.png" alt="Orzeta" width="480" height="370"/>
    <p class="devise" id="devise">Extra virgin olive oil</p>
  </div>

  <div class="scene indice" id="scene" role="button" tabindex="0" aria-pressed="false" aria-label="Retourner la carte">
    <div class="carte3d">
      <!-- Recto : comme la carte imprimée -->
      <section class="face recto" aria-hidden="false">
        <a class="r-g lien-olived" href="https://www.olived.tn/" target="_blank" rel="noopener">
          <img src="assets/images/logo-olived.png" alt="Olived" class="lg-olived"/>
          <small>www.olived.tn</small>
        </a>
        <span class="r-sep" aria-hidden="true"></span>
        <div class="r-d" id="r-d">
          <?php if ($estPersonne): ?>
            <p class="nom"><?= h(trim($P['prefixe'] . ' ' . $P['nom'])) ?></p>
          <?php else: ?>
            <p class="nom">ORZETA</p>
          <?php endif; ?>
          <p class="poste"><?= h($P['poste']['en']) ?></p>
          <ul class="coord">
            <?php if ($P['mobile'] !== ''): ?><li><i><?= $SVG['mob'] ?></i><span><?= h($P['mobile']) ?></span></li><?php endif; ?>
            <?php if ($P['fixe'] !== ''): ?><li><i><?= $SVG['tel'] ?></i><span><?= h($P['fixe']) ?></span></li><?php endif; ?>
            <li><i><?= $SVG['mail'] ?></i><span><?= h($P['email']) ?></span></li>
          </ul>
          <?php if ($P['adresses']): ?>
          <div class="adrs">
            <?php foreach ($P['adresses'] as $a): $A = $ADRESSES[$a];
              $fin = $A['cp'] . ' ' . $A['ville'] . ', ' . $A['pays'];
              $pos = strrpos($A['rue'], ',');
              if ($pos !== false && substr_count($A['rue'], ',') >= 2) { $l1 = substr($A['rue'], 0, $pos + 1); $l2 = trim(substr($A['rue'], $pos + 1)) . ', ' . $fin; }
              else { $l1 = $A['rue'] . ', ' . $fin; $l2 = ''; } ?>
            <div class="adr"><i><?= $SVG['pin'] ?></i><p><b><?= h($A['type']['en']) ?></b><span><?= h($l1) ?></span><?php if ($l2 !== ''): ?><span><?= h($l2) ?></span><?php endif; ?></p></div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </section>
      <!-- Verso : tout vert, logo centré -->
      <section class="face verso" aria-hidden="true">
        <img src="assets/images/logo-orzeta-orange.png" alt="Orzeta"/>
      </section>
    </div>
  </div>
  <button class="astuce" type="button" id="astuce"><?= $SVG['flip'] ?><span id="astuce-txt">Touchez la carte pour découvrir ORZETA</span></button>

  <nav class="actions">
    <a class="action" href="tel:<?= h($num($P['mobile'])) ?>"><span class="ic"><?= $SVG['mob'] ?></span><span>Appeler</span></a>
    <a class="action" href="https://wa.me/<?= h($waNum) ?>" target="_blank" rel="noopener"><span class="ic"><?= $SVG['wa'] ?></span><span>WhatsApp</span></a>
    <a class="action" href="mailto:<?= h($P['email']) ?>"><span class="ic"><?= $SVG['mail'] ?></span><span>Email</span></a>
    <a class="action" href="<?= h($SITE) ?>"><span class="ic"><?= $SVG['web'] ?></span><span>Site web</span></a>
  </nav>
  <a class="enregistrer" href="<?= h($lienVcf) ?>"><?= $SVG['plus'] ?><span>Enregistrer le contact</span></a>

  <?php if ($P['adresses']): ?>
  <div class="cartes-lieux">
    <?php foreach ($P['adresses'] as $a): $A = $ADRESSES[$a]; $plein = !empty($A['gps']) ? $A['gps'] : $A['rue'] . ', ' . $A['cp'] . ' ' . $A['ville'] . ', ' . $A['pays']; ?>
    <a class="lieu" href="https://www.google.com/maps/search/?api=1&amp;query=<?= h(urlencode($plein)) ?>" target="_blank" rel="noopener"><?= $SVG['pin'] ?><span><b><?= h($A['type']['fr']) ?></b><span>Voir sur la carte</span></span></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($INSTAGRAM !== '' || $FACEBOOK !== ''): ?>
  <div class="reseaux">
    <?php if ($INSTAGRAM !== ''): ?><a href="<?= h($INSTAGRAM) ?>" target="_blank" rel="noopener" aria-label="Instagram"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor"/></svg></a><?php endif; ?>
    <?php if ($FACEBOOK !== ''): ?><a href="<?= h($FACEBOOK) ?>" target="_blank" rel="noopener" aria-label="Facebook"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M14 8h3V4h-3a4 4 0 0 0-4 4v2H8v4h2v8h4v-8h3l1-4h-4V8z"/></svg></a><?php endif; ?>
  </div>
  <?php endif; ?>

  <button class="partager" type="button" id="partager">Partager cette carte</button>
  <p class="pied">© <?= date('Y') ?> Orzeta</p>
</main>
<script>
var scene = document.getElementById('scene');
var ASTUCE = ['Touchez la carte pour découvrir ORZETA', 'Touchez pour revenir au contact'];

// Réduit le texte du recto si une info longue déborde (noms ou postes longs)
function ajusterRecto() {
  var r = document.getElementById('r-d'), k = 1, n = r.querySelector('.nom'), kn = 1;
  r.style.setProperty('--k', 1); n.style.setProperty('--kn', 1);
  while (n.scrollWidth > n.clientWidth + 1 && kn > 0.6) { kn -= 0.03; n.style.setProperty('--kn', kn.toFixed(2)); }
  while (r.scrollHeight > r.clientHeight + 1 && k > 0.72) { k -= 0.04; r.style.setProperty('--k', k.toFixed(2)); }
}
function retourner() {
  scene.classList.remove('indice');
  var dos = scene.classList.toggle('retournee');
  document.getElementById('astuce-txt').textContent = ASTUCE[dos ? 1 : 0];
  scene.setAttribute('aria-pressed', dos ? 'true' : 'false');
  scene.querySelector('.recto').setAttribute('aria-hidden', dos ? 'true' : 'false');
  scene.querySelector('.verso').setAttribute('aria-hidden', dos ? 'false' : 'true');
}
scene.addEventListener('click', function (e) { if (e.target.closest('.lien-olived')) { return; } retourner(); });
scene.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); retourner(); } });
document.getElementById('astuce').addEventListener('click', retourner);
ajusterRecto();
// « EXTRA VIRGIN OLIVE OIL » exactement de la largeur du logo
function ajusterDevise() {
  var d = document.getElementById('devise'), w = d.parentNode.clientWidth, ls = 0.12, fs = 0.6, i;
  for (i = 0; i < 40; i++) {
    d.style.letterSpacing = ls + 'em'; d.style.textIndent = ls + 'em';
    var r = d.scrollWidth / w;
    if (Math.abs(r - 1) < 0.01) { break; }
    fs = fs / r; d.style.fontSize = fs + 'rem';
  }
}
ajusterDevise();
if (document.fonts && document.fonts.ready) { document.fonts.ready.then(ajusterDevise); }
window.addEventListener('resize', ajusterRecto);
if (document.fonts && document.fonts.ready) { document.fonts.ready.then(ajusterRecto); }

document.getElementById('partager').addEventListener('click', function () {
  var url = location.href.replace(/[&?]vcf=1/, '');
  if (navigator.share) { navigator.share({ title: document.title, url: url }).catch(function () {}); }
  else if (navigator.clipboard) { navigator.clipboard.writeText(url); this.textContent = '✓ ' + url; }
});
</script>
</body>
</html>