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
                'rue' => 'Mahdia Road Km 19', 'cp' => '3066', 'ville' => 'Sfax', 'pays' => 'Tunisia',
                'gps' => '34.884132,10.845287'],   // point exact de l'unité de production sur Google Maps
];

// Carte de la société (sans ?p=)
$SOCIETE = [
    'nom' => 'Orzeta', 'prefixe' => '',
    'poste' => ['fr' => "Huile d'olive vierge extra de Tunisie", 'en' => 'Extra virgin olive oil from Tunisia', 'ar' => 'زيت زيتون بكر ممتاز من تونس'],
    'mobile' => '+216 92 300 800', 'fixe' => '', 'whatsapp' => '+216 92 300 800',
    'email' => 'contact@orzeta.com', 'adresses' => ['siege', 'usine'],
];

// Cartes du staff — lien : https://orzeta.com/carte.php?p=CLE
$PERSONNES = [
    'olfa' => [
        'nom' => 'Olfa BEN HAJEL', 'prenom' => 'Olfa', 'famille' => 'BEN HAJEL', 'prefixe' => '',
        'poste' => ['fr' => 'Coordinatrice générale', 'en' => 'General Coordinator', 'ar' => 'المنسّقة العامّة'],
        'mobile' => '+216 92 300 800', 'fixe' => '', 'whatsapp' => '+216 92 300 800',
        'email' => 'contact@orzeta.com',
        'adresses' => ['siege', 'usine'],
    ],
    'fatma' => [
        'nom' => 'Fatma KHEMAKHEM ZOUARI', 'prenom' => 'Fatma', 'famille' => 'KHEMAKHEM ZOUARI', 'prefixe' => '',
        'poste' => ['fr' => 'Conseillère de marque', 'en' => 'Brand Advisor', 'ar' => 'مستشارة العلامة التجارية'],
        'mobile' => '+216 98 463 000', 'fixe' => '', 'whatsapp' => '+216 98 463 000',
        'email' => 'fkz@promedgroup.tn',
        'adresses' => ['siege', 'usine'],
    ],
    'sonia' => [
        'nom' => 'Sonia MKAOUAR', 'prenom' => 'Sonia', 'famille' => 'MKAOUAR', 'prefixe' => '',
        'poste' => ['fr' => 'Responsable du développement commercial', 'en' => 'Business Development Executive', 'ar' => 'مسؤولة تطوير الأعمال'],
        'mobile' => '+216 98 780 838', 'fixe' => '', 'whatsapp' => '+216 98 780 838',
        'email' => 'sm@promedgroup.tn',
        'adresses' => ['siege', 'usine'],
    ],
    'mohamed' => [
        'nom' => 'Mohamed ZOUARI', 'prenom' => 'Mohamed', 'famille' => 'ZOUARI', 'prefixe' => '',
        'poste' => ['fr' => 'Président-directeur général', 'en' => 'CEO', 'ar' => 'الرئيس التنفيذي'],
        'mobile' => '+216 98 462 000', 'fixe' => '', 'whatsapp' => '+216 98 462 000',
        'email' => 'contact@orzeta.com',
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
?><!DOCTYPE html>
<html lang="fr" dir="ltr">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
<title><?= h($titre) ?></title>
<meta name="description" content="Orzeta, huile d'olive vierge extra de Tunisie. Coordonnées et contact."/>
<meta name="robots" content="noindex"/>
<meta name="theme-color" content="#153c1f"/>
<meta property="og:title" content="<?= h($titre) ?>"/>
<meta property="og:description" content="<?= h($estPersonne ? $P['poste']['en'] . ' · Orzeta' : "Huile d'olive vierge extra de Tunisie") ?>"/>
<meta property="og:image" content="<?= $SITE ?>/assets/images/sun-marasca-1l.webp"/>
<link rel="icon" type="image/png" href="assets/images/logo-orzeta-orange.png"/>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
<link href="https://fonts.googleapis.com/css2?family=Literata:ital,wght@0,600;0,700;1,500&family=Nunito+Sans:wght@400;600;700;800&family=Noto+Sans+Arabic:wght@400;600;700&display=swap" rel="stylesheet"/>
<style>
:root{--vert:#153c1f;--vert2:#153c1f;--orange:#ea580d;--orange2:#c8460a;--creme:#f6eee2;--creme2:#ece0ce;--blanc:#fbf7f0;--texte:#152419;--gris:#5d615c;}
*{box-sizing:border-box;margin:0;padding:0;}
html,body{background:var(--creme2);}
body{font-family:'Nunito Sans',system-ui,sans-serif;color:var(--texte);min-height:100vh;display:flex;justify-content:center;padding:0 0 2rem;}
html[dir="rtl"] body{font-family:'Noto Sans Arabic','Nunito Sans',sans-serif;}
.carte{width:100%;max-width:440px;background:var(--creme);min-height:100vh;box-shadow:0 0 60px -20px rgba(15,45,23,.35);position:relative;overflow:hidden;}
@media (min-width:480px){body{padding:2rem 1rem;}.carte{min-height:auto;border-radius:28px;}}
.entete{background:radial-gradient(120% 90% at 50% 0%,#245433 0%,var(--vert) 60%,#0e2a15 100%);color:var(--creme);padding:1.2rem 1.4rem 5.5rem;position:relative;text-align:center;}
.entete::after{content:'';position:absolute;left:0;right:0;bottom:-1px;height:60px;background:var(--creme);border-radius:50% 50% 0 0 / 100% 100% 0 0;}
.langues{display:flex;justify-content:flex-end;gap:.35rem;}
.langues button{background:rgba(246,238,226,.12);color:var(--creme);border:0;border-radius:999px;padding:.3rem .65rem;font:700 .72rem 'Nunito Sans',sans-serif;cursor:pointer;}
.langues button.actif{background:var(--orange);color:var(--creme);}
.logo{width:104px;height:auto;margin:1.1rem auto 0;display:block;}
.slogan{margin-top:.9rem;font-family:'Literata',serif;font-style:italic;font-size:1.05rem;color:var(--creme);opacity:.92;}
.bouteilles{position:relative;z-index:2;display:flex;justify-content:center;align-items:flex-end;margin-top:-5.2rem;height:210px;}
.bouteilles img{height:100%;width:auto;margin:0 -1.9rem;filter:drop-shadow(0 12px 14px rgba(15,45,23,.25));}
.bouteilles img:nth-child(1),.bouteilles img:nth-child(3){height:88%;}
.bouteilles img:nth-child(2){position:relative;z-index:1;}
.corps{padding:1.2rem 1.4rem 1.6rem;text-align:center;}
.prefixe{font-size:.8rem;font-weight:700;color:var(--gris);letter-spacing:.08em;}
h1{font-family:'Literata',serif;font-size:1.85rem;font-weight:700;line-height:1.15;color:var(--vert);}
.poste{margin-top:.4rem;color:var(--orange);font-weight:800;font-size:.95rem;}
.marque{margin-top:.6rem;font-size:.68rem;font-weight:800;letter-spacing:.2em;text-transform:uppercase;color:var(--gris);}
.trait{width:44px;height:2px;border-radius:2px;background:var(--orange);margin:.9rem auto 0;}
.actions{display:grid;grid-template-columns:repeat(4,1fr);gap:.6rem;margin-top:1.5rem;}
.action{display:flex;flex-direction:column;align-items:center;gap:.4rem;text-decoration:none;color:var(--texte);font-size:.72rem;font-weight:700;}
.action .rond{width:56px;height:56px;border-radius:18px;background:var(--blanc);color:var(--vert);display:flex;align-items:center;justify-content:center;box-shadow:inset 0 0 0 1px rgba(234,88,13,.15),0 6px 16px -10px rgba(15,45,23,.4);transition:transform .2s ease;}
.action:active .rond{transform:scale(.94);}
.action svg{width:24px;height:24px;}
.enregistrer{margin-top:1.5rem;display:flex;align-items:center;justify-content:center;gap:.6rem;width:100%;padding:1rem;border-radius:16px;background:var(--orange);color:var(--creme);text-decoration:none;font-weight:800;font-size:.95rem;box-shadow:0 12px 26px -14px rgba(200,70,10,.8);}
.enregistrer:active{background:var(--orange2);}
.enregistrer svg{width:20px;height:20px;}
.infos{margin-top:1.5rem;text-align:start;background:var(--blanc);border-radius:18px;padding:.4rem 1rem;box-shadow:inset 0 0 0 1px rgba(234,88,13,.12);}
.info{display:flex;align-items:flex-start;gap:.8rem;padding:.85rem 0;text-decoration:none;color:var(--texte);}
.info + .info{border-top:1px solid #f1e7d7;}
.info svg{width:20px;height:20px;flex-shrink:0;color:var(--vert2);margin-top:.15rem;}
.info small{display:block;font-size:.66rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:var(--orange);}
.info b{font-weight:700;font-size:.95rem;}
.info .adr{font-weight:600;font-size:.86rem;line-height:1.45;}
.info .carte-lien{display:inline-block;margin-top:.25rem;font-size:.75rem;font-weight:700;color:var(--vert2);}
.ltr{direction:ltr;unicode-bidi:embed;}
html[dir="rtl"] .info .adr{direction:ltr;text-align:right;}
.reseaux{display:flex;justify-content:center;gap:.7rem;margin-top:1.3rem;}
.reseaux a{width:44px;height:44px;border-radius:50%;background:var(--blanc);display:flex;align-items:center;justify-content:center;color:var(--vert);box-shadow:inset 0 0 0 1px rgba(234,88,13,.15);}
.reseaux svg{width:20px;height:20px;}
.partager{margin-top:1.3rem;background:none;border:0;color:var(--vert2);font:700 .85rem inherit;cursor:pointer;text-decoration:underline;text-underline-offset:3px;}
.pied{margin-top:1.4rem;font-size:.72rem;color:var(--gris);}
</style>
</head>
<body>
<main class="carte">
  <header class="entete">
    <div class="langues" role="group" aria-label="Langue">
      <button type="button" data-lang="fr" class="actif">FR</button>
      <button type="button" data-lang="en">EN</button>
      <button type="button" data-lang="ar" lang="ar">ع</button>
    </div>
    <img class="logo" src="assets/images/logo-orzeta-orange.png" alt="Orzeta"/>
    <p class="slogan" data-t="slogan">Née sous le soleil méditerranéen.</p>
  </header>
  <div class="bouteilles" aria-hidden="true">
    <img src="assets/images/sun-marasca-1l.webp" alt=""/>
    <img src="assets/images/grove-marasca-1l.webp" alt=""/>
    <img src="assets/images/organic-marasca-1l.webp" alt=""/>
  </div>
  <section class="corps">
    <?php if ($estPersonne): ?>
      <?php if ($P['prefixe'] !== ''): ?><p class="prefixe ltr"><?= h($P['prefixe']) ?></p><?php endif; ?>
      <h1><?= h($P['nom']) ?></h1>
      <p class="poste" data-t="poste"><?= h($P['poste']['fr']) ?></p>
      <p class="marque">Orzeta · Sun · Grove · Organic</p>
      <div class="trait"></div>
    <?php else: ?>
      <h1>Orzeta</h1>
      <p class="poste" data-t="poste"><?= h($P['poste']['fr']) ?></p>
      <p class="marque">Sun · Grove · Organic</p>
      <div class="trait"></div>
    <?php endif; ?>

    <nav class="actions">
      <a class="action" href="tel:<?= h($num($P['mobile'])) ?>"><span class="rond"><?= $SVG['tel'] ?></span><span data-t="appeler">Appeler</span></a>
      <a class="action" href="https://wa.me/<?= h(ltrim($num($P['whatsapp']), '+')) ?>" target="_blank" rel="noopener"><span class="rond"><?= $SVG['wa'] ?></span><span>WhatsApp</span></a>
      <a class="action" href="mailto:<?= h($P['email']) ?>"><span class="rond"><?= $SVG['mail'] ?></span><span>Email</span></a>
      <a class="action" href="<?= h($SITE) ?>"><span class="rond"><?= $SVG['web'] ?></span><span data-t="site">Site web</span></a>
    </nav>

    <a class="enregistrer" href="<?= h($lienVcf) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></svg><span data-t="enregistrer">Enregistrer le contact</span></a>

    <div class="infos">
      <a class="info" href="tel:<?= h($num($P['mobile'])) ?>"><?= $SVG['mob'] ?><span><small data-t="mobile">Mobile</small><b class="ltr"><?= h($P['mobile']) ?></b></span></a>
      <?php if ($P['fixe'] !== ''): ?>
      <a class="info" href="tel:<?= h($num($P['fixe'])) ?>"><?= $SVG['tel'] ?><span><small data-t="fixe">Téléphone fixe</small><b class="ltr"><?= h($P['fixe']) ?></b></span></a>
      <?php endif; ?>
      <a class="info" href="mailto:<?= h($P['email']) ?>"><?= $SVG['mail'] ?><span><small>Email</small><b class="ltr"><?= h($P['email']) ?></b></span></a>
      <a class="info" href="<?= h($SITE) ?>"><?= $SVG['web'] ?><span><small data-t="site">Site web</small><b class="ltr">orzeta.com</b></span></a>
      <?php foreach ($P['adresses'] as $a): $A = $ADRESSES[$a]; $plein = !empty($A['gps']) ? $A['gps'] : $A['rue'] . ', ' . $A['cp'] . ' ' . $A['ville'] . ', ' . $A['pays']; ?>
      <div class="info"><?= $SVG['pin'] ?><span><small data-adr='<?= h(json_encode($A['type'], JSON_UNESCAPED_UNICODE)) ?>'><?= h($A['type']['fr']) ?></small><span class="adr"><?= h($A['rue']) ?>, <?= h($A['cp']) ?> <?= h($A['ville']) ?>, <?= h($A['pays']) ?></span><br/><a class="carte-lien" href="https://www.google.com/maps/search/?api=1&amp;query=<?= h(urlencode($plein)) ?>" target="_blank" rel="noopener" data-t="itineraire">Voir sur la carte</a></span></div>
      <?php endforeach; ?>
      <?php if (!$P['adresses']): ?>
      <div class="info"><?= $SVG['pin'] ?><span><small data-t="adresse">Adresse</small><b data-t="tunisie">Tunisie</b></span></div>
      <?php endif; ?>
    </div>

    <?php if ($INSTAGRAM !== '' || $FACEBOOK !== ''): ?>
    <div class="reseaux">
      <?php if ($INSTAGRAM !== ''): ?><a href="<?= h($INSTAGRAM) ?>" target="_blank" rel="noopener" aria-label="Instagram"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor"/></svg></a><?php endif; ?>
      <?php if ($FACEBOOK !== ''): ?><a href="<?= h($FACEBOOK) ?>" target="_blank" rel="noopener" aria-label="Facebook"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M14 8h3V4h-3a4 4 0 0 0-4 4v2H8v4h2v8h4v-8h3l1-4h-4V8z"/></svg></a><?php endif; ?>
    </div>
    <?php endif; ?>

    <button class="partager" type="button" id="partager" data-t="partager">Partager cette carte</button>
    <p class="pied">© <?= date('Y') ?> Orzeta</p>
  </section>
</main>
<script>
var POSTE = <?= json_encode($P['poste'], JSON_UNESCAPED_UNICODE) ?>;
var T = {
  fr: { slogan: 'Née sous le soleil méditerranéen.', appeler: 'Appeler', site: 'Site web', enregistrer: 'Enregistrer le contact',
        mobile: 'Mobile', fixe: 'Téléphone fixe', adresse: 'Adresse', tunisie: 'Tunisie', itineraire: 'Voir sur la carte', partager: 'Partager cette carte' },
  en: { slogan: 'Born under the Mediterranean sun.', appeler: 'Call', site: 'Website', enregistrer: 'Save contact',
        mobile: 'Mobile', fixe: 'Office phone', adresse: 'Address', tunisie: 'Tunisia', itineraire: 'View on map', partager: 'Share this card' },
  ar: { slogan: 'وُلدت تحت شمس المتوسط.', appeler: 'اتصال', site: 'الموقع', enregistrer: 'حفظ جهة الاتصال',
        mobile: 'الجوّال', fixe: 'الهاتف الثابت', adresse: 'العنوان', tunisie: 'تونس', itineraire: 'عرض على الخريطة', partager: 'مشاركة هذه البطاقة' }
};
function langue(l) {
  document.documentElement.lang = l;
  document.documentElement.dir = l === 'ar' ? 'rtl' : 'ltr';
  document.querySelectorAll('[data-t]').forEach(function (el) {
    var k = el.getAttribute('data-t');
    if (k === 'poste') { el.textContent = POSTE[l] || POSTE.fr; }
    else if (T[l][k]) { el.textContent = T[l][k]; }
  });
  document.querySelectorAll('[data-adr]').forEach(function (el) { var t = JSON.parse(el.getAttribute('data-adr')); el.textContent = t[l] || t.fr; });
  document.querySelectorAll('.langues button').forEach(function (b) { b.classList.toggle('actif', b.getAttribute('data-lang') === l); });
  try { localStorage.setItem('orzeta_lang', l); } catch (e) {}
}
document.querySelectorAll('.langues button').forEach(function (b) { b.addEventListener('click', function () { langue(b.getAttribute('data-lang')); }); });
var depart = 'fr';
try { depart = localStorage.getItem('orzeta_lang') || (navigator.language || 'fr').slice(0, 2); } catch (e) {}
langue(T[depart] ? depart : 'fr');
document.getElementById('partager').addEventListener('click', function () {
  var url = location.href.replace(/[&?]vcf=1/, '');
  if (navigator.share) { navigator.share({ title: document.title, url: url }).catch(function () {}); }
  else if (navigator.clipboard) { navigator.clipboard.writeText(url); this.textContent = '✓ ' + url; }
});
</script>
</body>
</html>