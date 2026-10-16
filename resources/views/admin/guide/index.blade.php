<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="robots" content="noindex">
<link rel="icon" type="image/png" href="{{ asset('logo/Asso.png') }}">
<title>Aide & guide - ASSO Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@700;800;900&family=Noto+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400&display=swap">
<style>
/* Mise en page « documentation » : barre de marque en haut, sommaire des modules à gauche,
   lecture au centre (≈ 70 caractères), « Sur cette page » à droite sur grand écran.
   Couleurs = charte du back-office ASSO : orange primary-500/600, neutres zinc du thème sombre. */
:root{
  --brand:#FF5722;          /* primary-600 du back-office */
  --brand-soft:#FF7344;     /* primary-500 */
  --brand-deep:#E64A19;     /* primary-700 */
  --brand-ink:#B23A12;      /* texte orange lisible sur fond clair */
  --brand-tint:#FFF1EA;     /* primary-50 adouci */
  --brand-line:#FFD0BB;     /* primary-200 */
  --bg:#FBFAF9;
  --surface:#FFFFFF;
  --surface-2:#F4F3F1;
  --line:#E7E5E2;
  --fg:#18181B;
  --fg-2:#52525B;
  --fg-3:#71717A;
  --ok:#15803D; --ok-tint:#ECF8F0;
  --warn:#9A6700; --warn-tint:#FBF3E2;
  --bad:#B42318; --bad-tint:#FDEEEC;
  --info:#2B5F8A; --info-tint:#EDF3F8;
  --shadow:0 1px 2px rgba(24,24,27,.06),0 8px 24px rgba(24,24,27,.06);
  --f-display:"Nunito","Avenir Next","Segoe UI",system-ui,sans-serif;
  --f-body:"Noto Sans","Segoe UI",system-ui,-apple-system,sans-serif;
  --f-mono:ui-monospace,"SF Mono",Menlo,Consolas,monospace;
  --top:64px;
}
@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){
  --brand:#FF6A3D; --brand-soft:#FF7344; --brand-deep:#FF9266; --brand-ink:#FF9C78;
  --brand-tint:#2A1710; --brand-line:#5A2A18;
  --bg:#09090B; --surface:#141416; --surface-2:#1C1C1F; --line:#27272A;
  --fg:#F4F4F5; --fg-2:#C4C4CA; --fg-3:#8E8E96;
  --ok:#4ADE80; --ok-tint:#10241A; --warn:#F5C451; --warn-tint:#2A2212;
  --bad:#F87171; --bad-tint:#2C1414; --info:#7DB3E0; --info-tint:#122030;
  --shadow:0 1px 2px rgba(0,0,0,.4),0 10px 30px rgba(0,0,0,.35);
  color-scheme:dark;
}}
:root[data-theme="dark"]{
  --brand:#FF6A3D; --brand-soft:#FF7344; --brand-deep:#FF9266; --brand-ink:#FF9C78;
  --brand-tint:#2A1710; --brand-line:#5A2A18;
  --bg:#09090B; --surface:#141416; --surface-2:#1C1C1F; --line:#27272A;
  --fg:#F4F4F5; --fg-2:#C4C4CA; --fg-3:#8E8E96;
  --ok:#4ADE80; --ok-tint:#10241A; --warn:#F5C451; --warn-tint:#2A2212;
  --bad:#F87171; --bad-tint:#2C1414; --info:#7DB3E0; --info-tint:#122030;
  --shadow:0 1px 2px rgba(0,0,0,.4),0 10px 30px rgba(0,0,0,.35);
  color-scheme:dark;
}
*{box-sizing:border-box}
[hidden]{display:none!important}
html{scroll-behavior:smooth;scroll-padding-top:calc(var(--top) + 16px)}
@media (prefers-reduced-motion: reduce){html{scroll-behavior:auto}*{transition:none!important;animation:none!important}}
body{background:var(--bg);color:var(--fg);font-family:var(--f-body);font-size:16px;line-height:1.65;-webkit-font-smoothing:antialiased}
a{color:var(--brand-ink)}
:focus-visible{outline:2px solid var(--brand);outline-offset:2px;border-radius:4px}

/* ---------- Barre haute ---------- */
.topbar{position:sticky;top:env(safe-area-inset-top,0px);z-index:40;height:var(--top);background:color-mix(in srgb,var(--surface) 88%,transparent);backdrop-filter:blur(10px);border-bottom:1px solid var(--line)}
.topbar-in{height:100%;display:flex;align-items:center;gap:14px;padding-left:max(16px,env(safe-area-inset-left,0px));padding-right:max(16px,env(safe-area-inset-right,0px))}
.brand{display:flex;align-items:center;gap:10px;text-decoration:none;color:var(--fg);flex-shrink:0}
.brand img{width:38px;height:38px;border-radius:50%;background:#fff;box-shadow:0 0 0 2px var(--brand-line)}
.brand b{font-family:var(--f-display);font-weight:900;font-size:22px;letter-spacing:.5px}
.brand span{font-size:13px;color:var(--fg-3);border-left:1px solid var(--line);padding-left:10px;white-space:nowrap}
.search{flex:1;max-width:460px;margin-left:auto;position:relative}
.search input{width:100%;height:40px;border-radius:10px;border:1px solid var(--line);background:var(--surface-2);color:var(--fg);padding:0 14px 0 38px;font:inherit;font-size:14px}
.search input:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 3px color-mix(in srgb,var(--brand) 22%,transparent)}
.search svg{position:absolute;left:12px;top:11px;width:18px;height:18px;color:var(--fg-3)}
.search .results{position:absolute;top:46px;left:0;right:0;background:var(--surface);border:1px solid var(--line);border-radius:12px;box-shadow:var(--shadow);max-height:60vh;overflow:auto;padding:6px}
.search .results a{display:block;padding:8px 10px;border-radius:8px;text-decoration:none;color:var(--fg)}
.search .results a small{display:block;color:var(--fg-3);font-size:12px}
.search .results a:hover,.search .results a.sel{background:var(--brand-tint)}
.search .results .none{padding:10px;color:var(--fg-3);font-size:14px}
.iconbtn{width:40px;height:40px;border-radius:10px;border:1px solid var(--line);background:var(--surface);color:var(--fg-2);display:grid;place-items:center;cursor:pointer;flex-shrink:0}
.iconbtn:hover{color:var(--brand-ink);border-color:var(--brand-line)}
.iconbtn svg{width:20px;height:20px}
.menu-btn{display:none}
.iconbtn.back{width:auto;padding:0 12px;gap:6px;display:flex;align-items:center;text-decoration:none;font-size:14px;font-weight:600}
@media (max-width:640px){.iconbtn.back span{display:none}}

/* ---------- Grille principale ---------- */
.shell{display:grid;grid-template-columns:280px minmax(0,1fr) 220px;max-width:1480px;margin:0 auto}
.nav{position:sticky;top:calc(var(--top) + env(safe-area-inset-top,0px));height:calc(100vh - var(--top));overflow:auto;border-right:1px solid var(--line);padding:22px 14px 40px max(16px,env(safe-area-inset-left,0px))}
.nav h4{font-family:var(--f-body);font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--fg-3);margin:20px 10px 6px}
.nav h4:first-child{margin-top:0}
.nav a{display:flex;gap:10px;align-items:center;padding:7px 10px;border-radius:8px;color:var(--fg-2);text-decoration:none;font-size:14.5px;line-height:1.35}
.nav a:hover{background:var(--surface-2);color:var(--fg)}
.nav a.on{background:var(--brand-tint);color:var(--brand-ink);font-weight:600}
.nav a .dot{width:6px;height:6px;border-radius:50%;background:var(--line);flex-shrink:0}
.nav a.on .dot{background:var(--brand)}
main{min-width:0;padding:34px max(16px,env(safe-area-inset-right,0px)) 80px max(16px,env(safe-area-inset-left,0px))}
.content{max-width:880px;margin:0 auto}
.toc{position:sticky;top:calc(var(--top) + env(safe-area-inset-top,0px));height:calc(100vh - var(--top));overflow:auto;padding:30px 16px 40px 8px}
.toc h5{font-size:11px;letter-spacing:.09em;text-transform:uppercase;color:var(--fg-3);margin:0 0 10px}
.toc a{display:block;font-size:13px;color:var(--fg-3);text-decoration:none;padding:4px 0 4px 12px;border-left:2px solid var(--line);line-height:1.4}
.toc a:hover{color:var(--fg)}
.toc a.on{color:var(--brand-ink);border-left-color:var(--brand)}

/* ---------- Contenu ---------- */
.hero{position:relative;border-radius:20px;overflow:hidden;background:#141416;color:#F4F4F5;padding:34px 34px 30px;display:grid;grid-template-columns:minmax(0,1fr) 150px;gap:24px;align-items:center;margin-bottom:46px}
.hero::before{content:"";position:absolute;inset:0;background:radial-gradient(600px 300px at 85% 10%,rgba(255,87,34,.35),transparent 60%),radial-gradient(400px 260px at 0% 100%,rgba(255,115,68,.18),transparent 60%);pointer-events:none}
.hero>*{position:relative}
.hero .eyebrow{display:inline-flex;align-items:center;gap:8px;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#FFB199;background:rgba(255,87,34,.14);border:1px solid rgba(255,115,68,.35);padding:4px 10px;border-radius:999px}
.hero h1{font-family:var(--f-display);font-weight:900;font-size:clamp(30px,4.4vw,44px);line-height:1.08;margin:14px 0 12px;text-wrap:balance;letter-spacing:-.01em}
.hero h1 em{font-style:normal;color:#FF7344}
.hero p{color:#C4C4CA;margin:0;max-width:56ch}
.hero .logo{width:150px;height:150px;border-radius:50%;background:#fff;padding:10px;box-shadow:0 0 0 6px rgba(255,87,34,.25),0 20px 50px rgba(0,0,0,.45)}
.hero .meta{display:flex;flex-wrap:wrap;gap:8px 18px;margin-top:20px;font-size:13px;color:#A1A1AA}
.hero .meta b{color:#F4F4F5;font-weight:600}

section.doc{padding-top:10px;margin-bottom:64px}
.group-label{font-size:12px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--brand-ink);margin:0 0 6px}
section.doc h2{font-family:var(--f-display);font-weight:800;font-size:30px;line-height:1.15;margin:0 0 6px;text-wrap:balance;letter-spacing:-.005em}
.path{display:flex;flex-wrap:wrap;align-items:center;gap:6px;font-size:13px;color:var(--fg-3);margin:4px 0 18px}
.path .crumb{background:var(--surface-2);border:1px solid var(--line);border-radius:6px;padding:1px 8px;color:var(--fg-2)}
.path .sep{opacity:.6}
.lead{font-size:17.5px;color:var(--fg-2);margin:0 0 20px;max-width:70ch}
section.doc h3{font-family:var(--f-display);font-weight:800;font-size:20px;margin:30px 0 10px}
section.doc p,section.doc li{max-width:72ch}
section.doc ul,section.doc ol{padding-left:22px}
section.doc li{margin:4px 0}
.k{font-weight:600;color:var(--fg);background:var(--surface-2);border:1px solid var(--line);border-bottom-width:2px;border-radius:6px;padding:0 6px;font-size:.92em;white-space:nowrap}

/* Étapes */
.steps{list-style:none;padding:0!important;counter-reset:s;margin:14px 0 20px}
.steps li{counter-increment:s;position:relative;padding:2px 0 12px 44px;max-width:72ch}
.steps li::before{content:counter(s);position:absolute;left:0;top:0;width:28px;height:28px;border-radius:8px;background:var(--surface);border:1.5px solid var(--brand-line);color:var(--brand-ink);font-weight:700;font-size:14px;display:grid;place-items:center;font-variant-numeric:tabular-nums}
.steps li:not(:last-child)::after{content:"";position:absolute;left:13.5px;top:32px;bottom:2px;width:1.5px;background:var(--line)}

/* Encadrés */
.note{display:grid;grid-template-columns:22px minmax(0,1fr);gap:12px;border-radius:12px;padding:14px 16px;margin:18px 0;font-size:15px;border:1px solid}
.note svg{width:20px;height:20px;margin-top:2px}
.note b{display:block;margin-bottom:2px}
.note p{margin:0}
.note.tip{background:var(--ok-tint);border-color:color-mix(in srgb,var(--ok) 30%,transparent)} .note.tip svg{color:var(--ok)}
.note.warn{background:var(--warn-tint);border-color:color-mix(in srgb,var(--warn) 30%,transparent)} .note.warn svg{color:var(--warn)}
.note.bad{background:var(--bad-tint);border-color:color-mix(in srgb,var(--bad) 30%,transparent)} .note.bad svg{color:var(--bad)}
.note.info{background:var(--info-tint);border-color:color-mix(in srgb,var(--info) 30%,transparent)} .note.info svg{color:var(--info)}

/* Capture annotée */
figure.shot{margin:22px 0 26px}
.frame{border-radius:14px;overflow:hidden;border:1px solid var(--line);box-shadow:var(--shadow);background:#0b0b0d}
.frame .chrome{display:flex;align-items:center;gap:6px;height:30px;padding:0 12px;background:#1c1c1f;border-bottom:1px solid #27272a}
.frame .chrome i{width:10px;height:10px;border-radius:50%;background:#3f3f46}
.frame .chrome i:first-child{background:#ff5f57}.frame .chrome i:nth-child(2){background:#febc2e}.frame .chrome i:nth-child(3){background:#28c840}
.frame .chrome span{margin-left:10px;flex:1;min-width:0;height:18px;border-radius:5px;background:#27272a;color:#a1a1aa;font:11px/18px var(--f-mono);padding:0 8px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
.stage{position:relative;aspect-ratio:1440/900;max-width:100%;cursor:zoom-in}
.stage img{display:block;width:100%;height:100%;object-fit:cover}
.mk{position:absolute;border:2px solid #FF5722;border-radius:6px;box-shadow:0 0 0 2px rgba(255,87,34,.25),0 0 0 9999px rgba(0,0,0,0);transition:box-shadow .2s,background .2s;pointer-events:none}
.mk b{position:absolute;left:-12px;top:-12px;width:24px;height:24px;border-radius:50%;background:#FF5722;color:#fff;font:700 12.5px/24px var(--f-body);text-align:center;box-shadow:0 2px 6px rgba(0,0,0,.45),0 0 0 2px #fff;font-variant-numeric:tabular-nums}
.stage.focus .mk{box-shadow:0 0 0 2px rgba(255,87,34,.12)}
.stage.focus .mk:not(.hl){opacity:.35}
.stage .mk.hl{box-shadow:0 0 0 3px rgba(255,87,34,.5),0 0 0 9999px rgba(0,0,0,.45);background:rgba(255,87,34,.08);opacity:1;z-index:2}
figcaption{font-size:13px;color:var(--fg-3);margin:8px 2px 0}
.legend{list-style:none;padding:0!important;margin:14px 0 0;display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:8px}
.legend li{display:grid;grid-template-columns:26px minmax(0,1fr);gap:10px;align-items:start;padding:10px 12px;border-radius:10px;border:1px solid var(--line);background:var(--surface);margin:0!important;max-width:none!important;cursor:default;transition:border-color .15s,background .15s}
.legend li:hover,.legend li.hl{border-color:var(--brand-line);background:var(--brand-tint)}
.legend .n{width:24px;height:24px;border-radius:50%;background:var(--brand);color:#fff;font:700 12.5px/24px var(--f-body);text-align:center;font-variant-numeric:tabular-nums}
.legend strong{display:block;font-size:14.5px;line-height:1.35}
.legend span{display:block;font-size:13.5px;color:var(--fg-2);line-height:1.45;margin-top:2px}

/* Tableaux */
.tbl{overflow-x:auto;border:1px solid var(--line);border-radius:12px;margin:16px 0 22px;background:var(--surface)}
table{border-collapse:collapse;width:100%;font-size:14.5px;min-width:520px}
th{text-align:left;font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:var(--fg-3);background:var(--surface-2);padding:10px 14px;border-bottom:1px solid var(--line)}
td{padding:10px 14px;border-bottom:1px solid var(--line);vertical-align:top}
tr:last-child td{border-bottom:0}
.pill{display:inline-block;font-size:12px;font-weight:600;padding:1px 9px;border-radius:999px;border:1px solid;white-space:nowrap}
.pill.o{color:var(--brand-ink);background:var(--brand-tint);border-color:var(--brand-line)}
.pill.g{color:var(--ok);background:var(--ok-tint);border-color:color-mix(in srgb,var(--ok) 30%,transparent)}
.pill.y{color:var(--warn);background:var(--warn-tint);border-color:color-mix(in srgb,var(--warn) 30%,transparent)}
.pill.r{color:var(--bad);background:var(--bad-tint);border-color:color-mix(in srgb,var(--bad) 30%,transparent)}
.pill.b{color:var(--info);background:var(--info-tint);border-color:color-mix(in srgb,var(--info) 30%,transparent)}

/* Cartes de modules (accueil) */
.cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,200px),1fr));gap:10px;margin:16px 0 8px}
.cards a{display:block;padding:14px;border:1px solid var(--line);border-radius:12px;background:var(--surface);text-decoration:none;color:var(--fg);transition:border-color .15s,transform .15s}
.cards a:hover{border-color:var(--brand-line);transform:translateY(-1px)}
.cards a b{display:block;font-family:var(--f-display);font-weight:800;font-size:16px}
.cards a small{display:block;color:var(--fg-3);font-size:13px;line-height:1.4;margin-top:3px}

.pager{display:flex;justify-content:space-between;gap:12px;margin-top:30px;padding-top:18px;border-top:1px solid var(--line)}
.pager a{flex:1;min-width:0;padding:12px 14px;border:1px solid var(--line);border-radius:12px;text-decoration:none;color:var(--fg);background:var(--surface)}
.pager a:hover{border-color:var(--brand-line)}
.pager a small{display:block;color:var(--fg-3);font-size:12px}
.pager a b{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:600}
.pager a.next{text-align:right}
.pager span{flex:1}

footer.foot{border-top:1px solid var(--line);margin-top:40px;padding-top:20px;color:var(--fg-3);font-size:13px;display:flex;flex-wrap:wrap;gap:8px 20px;align-items:center}
footer.foot img{width:26px;height:26px;border-radius:50%;background:#fff}

/* Visionneuse */
.lb{position:fixed;inset:0;z-index:80;background:rgba(9,9,11,.92);display:grid;place-items:center;padding:calc(20px + env(safe-area-inset-top,0px)) max(16px,env(safe-area-inset-right,0px)) calc(20px + env(safe-area-inset-bottom,0px)) max(16px,env(safe-area-inset-left,0px))}
.lb .stage{width:min(100%,calc((100vh - 120px) * 1.6));cursor:zoom-out;border-radius:10px;overflow:hidden}
.lb .close{position:absolute;top:calc(14px + env(safe-area-inset-top,0px));right:max(14px,env(safe-area-inset-right,0px));background:#27272a;color:#fff;border:0}
.lb p{color:#a1a1aa;font-size:13px;margin:10px 0 0;text-align:center}

/* Tiroir mobile */
.scrim{display:none}
@media (max-width:1180px){.shell{grid-template-columns:260px minmax(0,1fr)}.toc{display:none}}
@media (max-width:900px){
  .shell{grid-template-columns:minmax(0,1fr)}
  .menu-btn{display:grid}
  .nav{position:fixed;top:0;left:0;bottom:0;width:min(86vw,320px);height:auto;z-index:60;background:var(--surface);transform:translateX(-102%);transition:transform .25s ease;padding-top:calc(18px + env(safe-area-inset-top,0px));box-shadow:var(--shadow)}
  .nav.open{transform:none}
  .scrim.open{display:block;position:fixed;inset:0;z-index:55;background:rgba(0,0,0,.45)}
  .brand span{display:none}
  .hero{grid-template-columns:minmax(0,1fr);padding:26px 22px}
  .hero .logo{width:84px;height:84px;order:-1}
}
@media (max-width:560px){
  .search{max-width:none}
  .brand b{font-size:19px}
  section.doc h2{font-size:25px}
  .mk b{width:20px;height:20px;line-height:20px;font-size:11px;left:-10px;top:-10px}
}
</style>
</head>
<body>
<header class="topbar">
  <div class="topbar-in">
    <button class="iconbtn menu-btn" id="menuBtn" aria-label="Ouvrir le sommaire"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
    <a class="brand" href="#bienvenue"><img src="{{ asset('guide/logo.png') }}" alt="Logo ASSO"><b>ASSO</b><span>Guide du back-office</span></a>
    <div class="search">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
      <input id="q" type="search" placeholder="Rechercher : litige, acompte, retrait…" autocomplete="off" aria-label="Rechercher dans le guide">
      <div class="results" id="results" hidden></div>
    </div>
    @if($homeRoute)
    <a class="iconbtn back" href="{{ route($homeRoute) }}" title="Retour au back-office" aria-label="Retour au back-office"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg><span>Back-office</span></a>
    @endif
    <button class="iconbtn" id="themeBtn" aria-label="Changer de thème" title="Thème clair / sombre"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 3a9 9 0 1 0 9 9 7 7 0 0 1-9-9Z"/></svg></button>
  </div>
</header>
<div class="scrim" id="scrim"></div>
<div class="shell">
  <nav class="nav" id="nav" aria-label="Sommaire"></nav>
  <main><div class="content" id="content"></div></main>
  <aside class="toc" id="toc" aria-label="Sur cette page"></aside>
</div>
<div class="lb" id="lb" hidden></div>

<script>
const ALLOWED = @json($allowedPages);
const IS_ADMIN = @json($isAdmin);
const IMG = @json(url('admin/guide/img')).replace(/\/$/, '') + '/';
const can = id => ALLOWED.includes(id);
/* ======================================================================
   CONTENU DU GUIDE
   Coordonnées des repères : pixels de l'écran capturé (1440 × 900).
   ====================================================================== */
const W = 1440, H = 900;
const URL_PREFIX = "asso — /admin/";

const ICON = {
  tip:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18h6M10 22h4M12 2a7 7 0 0 0-4 12.7V17h8v-2.3A7 7 0 0 0 12 2Z"/></svg>',
  warn:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0ZM12 9v4M12 17h.01"/></svg>',
  bad:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/></svg>',
  info:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>'
};
const note = (type, title, body) => `<div class="note ${type}">${ICON[type]}<div><b>${title}</b><p>${body}</p></div></div>`;
const steps = arr => `<ol class="steps">${arr.map(s=>`<li>${s}</li>`).join('')}</ol>`;
const k = t => `<span class="k">${t}</span>`;
const pill = (c,t) => `<span class="pill ${c}">${t}</span>`;
const table = (head, rows) => `<div class="tbl"><table><thead><tr>${head.map(h=>`<th>${h}</th>`).join('')}</tr></thead><tbody>${rows.map(r=>`<tr>${r.map(c=>`<td>${c}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;

/* Une capture : { img, url, cap, marks:[[x,y,w,h,"Titre","Explication"], …] } */
const SHOTS = {
  login:{img:'login',url:'login',cap:'Écran de connexion des administrateurs.',marks:[
    [715,328,503,48,'Adresse e-mail','L’adresse de votre compte administrateur.'],
    [715,425,503,48,'Mot de passe','Saisissez-le à l’abri des regards. Il n’est jamais affiché.'],
    [711,494,160,28,'Se souvenir de moi','À cocher uniquement sur votre ordinateur personnel.'],
    [715,549,503,49,'Se connecter','Ouvre le Dashboard, ou la première section qui vous est ouverte.']]},
  manager_login:{img:'manager_login',url:'gestionnaire/login',cap:'Écran de connexion des gestionnaires (couleur verte pour le distinguer).',marks:[
    [715,333,503,48,'Adresse e-mail','Celle indiquée par l’administrateur à la création de votre compte.'],
    [715,431,503,48,'Mot de passe','Reçu par e-mail à la création du compte.'],
    [715,554,503,49,'Accéder à mon espace','Seules les sections que l’administrateur vous a ouvertes s’affichent ensuite.'],
    [731,624,480,46,'Mot de passe oublié','Demandez à l’administrateur de le réinitialiser : le nouveau vous arrive par e-mail.']]},
  tour:{img:'dashboard',url:'dashboard',cap:'Les zones fixes, présentes sur toutes les pages.',marks:[
    [12,8,140,48,'Logo ASSO','Un clic ramène à votre page d’accueil du back-office.'],
    [0,64,256,762,'Menu latéral','Toutes les sections, rangées par familles : Gestion, DIASPO Exchange, Affiliation, Commerciaux, Annonce, Outils, Administration. Il défile.'],
    [12,88,222,44,'Section ouverte','La section affichée est surlignée en orange.'],
    [1378,12,36,36,'Notifications','La pastille indique les éléments en attente (demandes à valider, vérifications…).'],
    [0,830,256,70,'Votre profil','Nom et rôle du compte connecté. L’icône à droite vous déconnecte.']]},
  dashboard:{img:'dashboard',url:'dashboard',cap:'Le Dashboard : l’état de la plateforme en un coup d’œil.',marks:[
    [257,64,1173,138,'Comptes par rôle','Livreurs, vendeurs, clients et total, avec les inscriptions du jour.'],
    [257,235,1173,124,'Activité du catalogue','Produits, transactions, échanges et catégories / sous-catégories.'],
    [257,393,573,356,'Inscriptions sur 30 jours','Courbe par rôle : repérez une campagne qui fonctionne ou un creux.'],
    [855,393,575,356,'Transactions et échanges','Volume quotidien des paiements et des échanges.'],
    [257,783,1173,117,'Dernières activités','Top catégories, produits récents et transactions récentes.']]},
  revenue:{img:'revenue',url:'revenue',cap:'Revenus ASSO : ce que la plateforme gagne, service par service.',marks:[
    [847,88,420,86,'Période','7, 30, 90 jours, 12 mois ou depuis le début. Tous les chiffres suivent.'],
    [847,180,90,36,'Retirer','Ouvre la page de retrait des fonds de la plateforme.'],
    [945,180,95,36,'Exporter','Télécharge les chiffres de la période (tableur).'],
    [281,240,1125,141,'Indicateurs','Chiffre d’affaires, pertes et charges, revenu net, ventes des boutiques ASSO, avec l’écart à la période précédente.'],
    [281,407,1125,380,'Évolution journalière','Chiffre d’affaires, charges et net, jour par jour.'],
    [281,813,1125,87,'Par service','D’où vient l’argent. Ouvrez une ligne pour voir chaque mouvement.']]},
  withdrawal:{img:'revenue_withdrawal',url:'revenue/withdrawal',cap:'Retrait ASSO : un solde par pays et par devise.',marks:[
    [281,200,1125,56,'Mode de retrait','Mobile Money (un solde par pays) ou virement bancaire sur l’IBAN de la plateforme.'],
    [281,320,365,252,'Carte pays','Solde disponible, devise, et opérateurs actifs dans ce pays.'],
    [681,511,323,40,'Retirer','Actif seulement si le solde le permet. Les fonds partent vers un opérateur du même pays.'],
    [662,590,362,248,'Cas particulier','Un message explique pourquoi un retrait est indisponible (ex. devise non versée par les opérateurs).']]},
  statistics:{img:'statistics',url:'statistics',cap:'Statistiques boutiques : l’audience et les ventes de toutes les boutiques.',marks:[
    [899,92,420,84,'Période','Choisissez la fenêtre d’analyse.'],
    [281,210,1125,206,'Audience','Visites, visiteurs uniques, produits consultés, contacts vendeur.'],
    [281,416,1125,210,'Ventes','Commandes reçues et validées, chiffre d’affaires vendeur, taux de conversion, volume payé, commission ASSO.'],
    [281,660,1125,240,'Évolution journalière','Les courbes jour par jour, à comparer entre elles.']]},
  users:{img:'users',url:'users',cap:'La liste des utilisateurs, avec ses filtres.',marks:[
    [1201,94,205,48,'Nouvel utilisateur','Crée un compte client, vendeur ou livreur à la main.'],
    [290,172,1116,262,'Filtres','Recherche (nom, e-mail, téléphone), rôle, genre, pays et période d’inscription. Puis Filtrer ou Réinitialiser.'],
    [281,468,250,432,'Utilisateur','Nom, initiales et pays.'],
    [781,468,141,432,'Rôle','Client, Vendeur ou Livreur, en couleur.'],
    [925,468,180,432,'Code parrainage','L’icône à côté copie le code.'],
    [1374,525,36,36,'Voir la fiche','L’œil ouvre la fiche complète de l’utilisateur.']]},
  user_show:{img:'users_7',url:'users/7',cap:'La fiche d’un utilisateur.',marks:[
    [1143,96,124,40,'Modifier','Corrige les informations du compte.'],
    [1278,96,138,40,'Supprimer','Supprime le compte. Action définitive : une confirmation est demandée.'],
    [692,193,699,320,'Informations personnelles','Identité, contact, pays, date d’inscription.'],
    [305,674,320,56,'Boutiques','Les boutiques du compte. « Ajouter une boutique » en crée une à son nom.']]},
  user_create:{img:'users_create',url:'users/create',cap:'Création manuelle d’un compte.',marks:[
    [304,200,1078,170,'Identité et contact','Les champs marqués d’une étoile sont obligatoires.'],
    [304,388,1078,90,'Mot de passe','8 caractères minimum, saisi deux fois.'],
    [304,502,527,70,'Rôle','Client, Vendeur ou Livreur : il détermine ce que la personne verra dans l’application.'],
    [304,770,1078,130,'Position','« Ma position » remplit la latitude et la longitude, ou saisissez l’adresse.']]},
  managers:{img:'managers',url:'managers',cap:'Les comptes gestionnaires et leurs accès.',marks:[
    [1196,98,220,40,'Nouveau gestionnaire','Crée un accès limité pour un membre de l’équipe.'],
    [296,188,1104,42,'Recherche','Par nom ou e-mail.'],
    [280,270,1136,106,'Liste','Rôle, sections supplémentaires accordées, date de création et actions.']]},
  manager_create:{img:'managers_create',url:'managers/create',cap:'Créer un gestionnaire et choisir ce qu’il peut faire.',marks:[
    [305,197,1076,280,'Identité','Prénom, nom, e-mail. L’e-mail sert d’identifiant de connexion.'],
    [305,560,1076,130,'Droits sur les produits','Consulter, ajouter, modifier, supprimer, masquer / afficher.'],
    [305,725,1076,175,'Sections ouvertes','Cochez chaque section du menu que la personne pourra voir. Le reste lui reste invisible.']]},
  preferences:{img:'preferences',url:'preferences',cap:'Les centres d’intérêt choisis par les utilisateurs dans l’application.',marks:[
    [281,174,1125,106,'Synthèse','Utilisateurs avec et sans préférences, nombre de préférences différentes.'],
    [281,320,1125,580,'Catégories par popularité','Chaque carte montre le nombre d’utilisateurs intéressés et qui ils sont.']]},
  map:{img:'map',url:'map',cap:'Carte des utilisateurs.',marks:[
    [281,176,1125,112,'Compteurs','Utilisateurs, vendeurs, acheteurs et livreurs localisés.'],
    [281,328,1125,74,'Filtres','Recherche et rôle.'],
    [281,426,1125,474,'Carte','Un marqueur par personne, en couleur selon le rôle. Les boutons + et − zooment.']]},
  shops:{img:'shops',url:'shops',cap:'La gestion des boutiques.',marks:[
    [1197,94,209,48,'Nouvelle boutique','Crée une boutique pour un vendeur existant.'],
    [280,172,1126,56,'Demandes en attente','Ici, un vendeur demande à changer l’emplacement de sa boutique. Cliquez pour traiter.'],
    [304,272,1078,190,'Filtres','Recherche, statut (active / inactive) et propriétaire.'],
    [1175,272,203,40,'Grille ou liste','Deux façons d’afficher les boutiques.'],
    [281,506,358,394,'Carte boutique','Propriétaire, statut, nombre de produits, date de création et vérification. Boutons Voir, Modifier, Supprimer en bas.']]},
  shop_show:{img:'shops_2',url:'shops/2',cap:'La fiche d’une boutique.',marks:[
    [1134,96,282,40,'Modifier / Supprimer','Corriger ou supprimer la boutique.'],
    [281,166,1125,98,'Statut de vérification','Boutique vérifiée et active. « Désactiver » la retire temporairement de l’application.'],
    [690,300,716,70,'Livraison gratuite','Activée, les clients ne paient plus la livraison sur toute la boutique : le vendeur la finance.'],
    [690,425,716,475,'Informations','Propriétaire (lien vers son profil), description et emplacement sur la carte.'],
    [281,610,390,290,'Chiffres clés','Produits, dates, statistiques cumulées de la boutique.']]},
  products:{img:'products',url:'products',cap:'Le catalogue de toutes les boutiques.',marks:[
    [1221,93,185,46,'Nouveau produit','Ajoute un produit dans la boutique de votre choix.'],
    [1120,94,92,44,'Affichage','Grille ou liste.'],
    [305,193,1076,98,'Filtres','Recherche, boutique, catégorie, type (article / service), statut et origine (importé / local).'],
    [285,380,272,380,'Carte produit','Photo, statut, type, prix et stock.'],
    [292,700,262,38,'Actions','Voir, modifier, masquer, supprimer.']]},
  product_show:{img:'products_42',url:'products/42',cap:'La fiche d’un produit.',marks:[
    [870,96,546,40,'Actions','Retour, Modifier, Masquer (le produit disparaît de l’application sans être supprimé), Supprimer.'],
    [281,190,744,700,'Médias','Image principale (étoile) et autres images.'],
    [1050,190,356,140,'Tarification','Prix affiché aux clients.'],
    [1050,560,356,170,'Stock','Quantité disponible. Un badge prévient quand le stock est faible.'],
    [1050,745,356,155,'Livraison gratuite','Indique qui paie la livraison pour ce produit.']]},
  product_create:{img:'products_create',url:'products/create',cap:'Créer un produit.',marks:[
    [304,192,695,600,'Informations de base','Boutique, nom, description, caractéristiques et informations commerciales.'],
    [919,323,78,24,'FR / EN','Rédigez le texte en français puis en anglais : l’application affiche la langue du client.'],
    [304,810,695,114,'Catégorisation','Catégorie, puis sous-catégorie.'],
    [1071,192,311,708,'Configuration','Type (article ou service), poids unitaire, origine (importé ou local), stock et statut.']]},
  categories:{img:'settings_categories',url:'settings/categories',cap:'Catégories et sous-catégories du catalogue.',marks:[
    [1204,96,202,40,'Nouvelle catégorie','Ajoute une catégorie principale avec son icône et ses traductions.'],
    [281,166,1125,110,'Compteurs','Catégories, sous-catégories et produits classés.'],
    [281,296,1125,150,'Catégorie','Nom FR / EN, délai de livraison par défaut, nombre de sous-catégories et de produits.'],
    [1290,322,94,44,'Modifier / supprimer','Le crayon modifie, la corbeille supprime.'],
    [1290,486,96,36,'Ajouter','Ajoute une sous-catégorie à cette catégorie.']]},
  packages:{img:'packages',url:'packages',cap:'Les forfaits vendus aux vendeurs.',marks:[
    [1213,100,193,40,'Nouveau package','Crée un forfait (stockage, boost ou certification).'],
    [281,174,1125,130,'Compteurs','Par statut et par type.'],
    [297,349,1092,72,'Filtres','Type de package et statut.'],
    [281,450,1125,450,'Forfaits','Chaque carte affiche le prix, la durée et le contenu. « Populaire » met un forfait en avant.']]},
  ads:{img:'ads',url:'ads',cap:'Asso Ads : les produits sponsorisés.',marks:[
    [1185,112,221,40,'Grille tarifaire','Ouvre les 3 forfaits de sponsoring.'],
    [281,176,1125,116,'Résultats','Revenus, campagnes en diffusion, impressions, taux de clic.'],
    [281,340,1125,372,'Diffusion','Activer l’affichage des produits sponsorisés, nombre d’emplacements et positions dans la page. Puis Enregistrer.'],
    [281,757,1125,143,'Campagnes','Chaque campagne : produit, vendeur, forfait, portée, clics, montant, échéance.']]},
  local:{img:'local-orders',url:'local-orders',cap:'Commandes locales : achats faits auprès des vendeurs locaux.',marks:[
    [280,296,946,38,'Étapes','Un onglet par étape, avec le nombre de commandes. « Toutes » enlève le filtre.'],
    [280,348,1136,38,'Recherche','Numéro de commande, client, téléphone ou vendeur.'],
    [281,403,1134,128,'Liste','Commande, client, articles, vendeur, montant, étape.']]},
  wholesale:{img:'wholesale-orders',url:'wholesale-orders',cap:'Commandes en gros importées (Chine, Turquie, Dubaï…).',marks:[
    [280,296,1080,38,'Étapes','Du paiement à la livraison : à valider, à expédier, en route vers Douala, livraison SOLEX, livrée.'],
    [280,348,133,39,'Pays d’origine','Filtre par pays importateur.'],
    [425,348,991,39,'Recherche','N° de commande, client, téléphone ou n° de suivi.'],
    [1330,462,78,38,'Gérer','Ouvre le suivi détaillé de la commande.']]},
  wholesale_show:{img:'wholesale-orders_24',url:'wholesale-orders/24',cap:'Le suivi d’une commande en gros.',marks:[
    [1246,101,160,32,'Statut','L’étape actuelle de la commande.'],
    [281,169,1124,88,'Parcours','Payée → validée par ASSO → expédiée → arrivée à Douala → livraison SOLEX → livrée.'],
    [281,283,1124,182,'Décision','Valider la commande, ou la refuser avec un motif envoyé au client. Impossible tant que le paiement n’est pas confirmé.'],
    [281,491,741,220,'Articles','Produit, palier de prix, quantité, poids et total.'],
    [1047,491,359,316,'Montants','Ce que le client a payé et la répartition entre le vendeur et l’import ASSO.']]},
  deposit:{img:'deposit-orders',url:'deposit-orders',cap:'Commandes avec acompte : le client paie une partie à la commande, le solde après livraison.',marks:[
    [280,190,950,80,'Étapes','De l’acompte en attente jusqu’à la remise au client.'],
    [280,284,1136,38,'Recherche','N° de commande, client, téléphone.'],
    [281,339,1134,128,'Liste','Produit, mode de récupération, acompte et solde, étape.']]},
  deposit_show:{img:'deposit-orders_35',url:'deposit-orders/35',cap:'La vérification ASSO d’une commande avec acompte.',marks:[
    [281,193,741,80,'Montants','Prix total, acompte versé, solde restant.'],
    [1047,193,359,208,'Client','Coordonnées et mode de livraison.'],
    [1047,427,359,170,'État de la vérification','Le solde reste bloqué tant que la vérification n’est pas faite.'],
    [1070,580,313,118,'Client contacté','Une note interne facultative, puis ce bouton une fois le client joint.'],
    [1070,710,313,118,'Signaler un problème','Décrivez le problème constaté, puis signalez-le.']]},
  disputes:{img:'disputes',url:'disputes',cap:'Réclamations et litiges ouverts par les clients.',marks:[
    [280,166,1050,80,'Statuts','Nouveau, en analyse, vendeur contacté, remplacement, retour, remboursé, rejeté, résolu, fermé.'],
    [280,260,1136,38,'Recherche','N° de litige, commande, client, vendeur, téléphone.'],
    [995,315,223,44,'Part bloquée','La somme du vendeur retenue pendant le traitement.'],
    [281,315,1134,128,'Liste','Produit, client, vendeur, motif et statut de chaque litige.']]},
  partners:{img:'delivery-partners',url:'delivery-partners',cap:'Partenaires logistiques et réglages de livraison.',marks:[
    [1197,96,209,40,'Nouveau partenaire','Ajoute une société de livraison.'],
    [305,193,1076,214,'Réglages de livraison','Rayon d’une zone urbaine, TVA des grilles, poids par défaut d’un article, délai par défaut. Puis Enregistrer.'],
    [281,457,1124,443,'Partenaires','Services, mode (domicile / agence), zones, trajets, TVA, statut.'],
    [1130,566,262,32,'Conditions et coursiers','Tarifs et trajets du partenaire, et ses coursiers.']]},
  deliverers:{img:'deliverers',url:'deliverers',cap:'Les entreprises de livraison.',marks:[
    [1189,94,217,48,'Nouvelle entreprise','Ajoute une entreprise de livraison.'],
    [305,197,1076,128,'Filtres','Recherche, statut, période.'],
    [281,372,1125,90,'Compteurs','Entreprises, synchronisées, en attente, zones.'],
    [281,480,540,270,'Fiche entreprise','Statut, contact, zone couverte. Voir, Éditer, Supprimer.']]},
  shipments:{img:'shipments',url:'shipments',cap:'Expéditions confiées à un transporteur.',marks:[
    [280,192,193,39,'Étape','En cours, en attente du vendeur, expédiées, livrées, annulées.'],
    [485,192,286,39,'Recherche','N° de commande ou n° de suivi.'],
    [281,248,1134,226,'Liste','Acheteur, transporteur et trajet, poids, n° de suivi, dernière étape.'],
    [1336,304,72,36,'Suivi','Ouvre l’historique du colis.']]},
  countries:{img:'import-countries',url:'import-countries',cap:'Pays importés et livraison des commandes en gros.',marks:[
    [305,193,1076,280,'Livraison en gros','Le trajet en 3 temps : pays d’origine → entrepôt de Douala → client (SOLEX).'],
    [305,525,1076,192,'Ajouter un pays','Code ISO à 2 lettres, nom FR / EN, drapeau et ordre d’affichage.'],
    [281,767,1124,133,'Pays actifs','Le bouton Actif masque ou affiche le pays dans l’application.']]},
  transactions:{img:'transactions',url:'transactions',cap:'Toutes les transactions de paiement.',marks:[
    [1214,96,192,40,'Exports','Excel ou PDF, selon les filtres appliqués.'],
    [281,190,1125,130,'Filtres','Dates, type (recharge, retrait, remboursement, bonus), prestataire, statut.'],
    [281,370,1125,126,'Totaux','Crédits, débits, nombre de transactions et leur état.'],
    [281,520,1125,380,'Graphiques','Évolution du revenu et répartition par moyen de paiement.']]},
  stripe:{img:'stripe_accounts',url:'stripe/accounts',cap:'Comptes de virement (IBAN) à valider.',marks:[
    [296,188,653,48,'Statuts','En attente, validés, rejetés.'],
    [281,260,1125,240,'Comptes','Les IBAN soumis par les vendeurs et les acheteurs, à valider avant tout virement.']]},
  payments:{img:'settings_payments',url:'settings/payments',cap:'Configuration des moyens de paiement (clés masquées).',marks:[
    [281,135,421,54,'Prestataires','KPay, ElgioPay (Cameroun), encaissement direct.'],
    [1329,256,76,32,'Activer','Active ou coupe le prestataire.'],
    [257,353,1172,73,'État','Clés renseignées, environnement (test / production), dernière connexion testée.'],
    [1331,372,82,36,'Tester','Vérifie que la connexion au prestataire fonctionne.'],
    [281,628,1124,272,'Clés d’accès','Fournies par le prestataire. À ne jamais partager.']]},
  dverif:{img:'diaspo_verifications',url:'diaspo/verifications',cap:'Vérification d’identité des voyageurs DIASPO.',marks:[
    [296,188,1104,88,'Délai de régularisation','Nombre de jours laissés à un voyageur non vérifié pour envoyer ses pièces. Puis Enregistrer.'],
    [296,332,866,48,'Statuts','En attente, sans pièces, vérifiés, rejetés, tous.']]},
  doffers:{img:'diaspo_offers',url:'diaspo/offers',cap:'Les offres de transport de colis publiées par les voyageurs.',marks:[
    [281,176,1125,90,'Compteurs','Total, en attente, profil non vérifié, approuvées, disponibles, rejetées.'],
    [296,336,1094,96,'Filtres','Recherche, statut, vérification, tri.'],
    [281,486,1125,200,'Offre','Voyageur, trajet, date de départ, prix au kilo, poids disponible, réservations.'],
    [1240,494,92,44,'Voir','Ouvre l’offre pour l’approuver ou la rejeter.']]},
  dposts:{img:'diaspo_posts',url:'diaspo/posts',cap:'Voice of Customer : la parole des utilisateurs.',marks:[
    [296,316,1094,42,'Filtres','Recherche, publics ou anonymes, tri par récence ou popularité.'],
    [281,400,1125,170,'Publication','Auteur (ou anonyme), contenu, réactions et commentaires.'],
    [1240,418,92,44,'Voir','Ouvre la publication et ses commentaires.']]},
  agents:{img:'sales_agents',url:'sales/agents',cap:'Les commerciaux et leurs codes.',marks:[
    [962,98,218,40,'Commissions à payer','Accès direct aux commissions dues.'],
    [1193,98,213,40,'Nouveau commercial','Crée un commercial et son code.'],
    [280,300,1126,92,'Taux par défaut','Pourcentage du montant payé par le vendeur, versé au commercial.'],
    [280,416,1126,340,'Modalités','Texte affiché dans l’application pour « Devenir un commercial ASSO ».'],
    [296,796,1094,42,'Recherche','Nom, e-mail, téléphone ou code ; actifs ou désactivés.']]},
  agent_create:{img:'sales_agents_create',url:'sales/agents/create',cap:'Créer un commercial.',marks:[
    [305,197,1076,170,'Identité','Prénom et nom obligatoires, téléphone et e-mail conseillés.'],
    [305,385,526,70,'Code commercial','Laissez vide pour qu’il soit généré automatiquement.'],
    [855,385,526,70,'Taux particulier','Remplace le taux par défaut pour ce commercial.'],
    [305,755,345,34,'Actif','Tant que la case est cochée, le code est accepté lors des souscriptions.'],
    [1161,810,220,40,'Créer le commercial','Enregistre la fiche.']]},
  agent_comm:{img:'sales_commissions',url:'sales/commissions',cap:'Les commissions des commerciaux.',marks:[
    [1278,98,138,40,'Export CSV','Pour la comptabilité.'],
    [280,172,1136,104,'Totaux','À payer et payées, en nombre et en montant.'],
    [296,316,1104,96,'Filtres','Statut, commercial, forfait.']]},
  affiliate:{img:'affiliate_settings',url:'affiliate/settings',cap:'Paramètres du programme d’affiliation.',marks:[
    [305,377,693,350,'Paramètres généraux','Activer l’affiliation, approbation automatique, nombre de niveaux, retrait minimum.'],
    [305,850,693,50,'Pourcentages','Commission versée à chaque niveau.'],
    [1072,377,309,340,'Exemple de calcul','Montre l’effet de vos réglages sur une vente.'],
    [1072,738,309,48,'Enregistrer','Applique la configuration.']]},
  banners:{img:'banners',url:'banners',cap:'Bannières publicitaires de l’application.',marks:[
    [1220,96,196,40,'Nouvelle bannière','Ajoute une image cliquable sur l’accueil de l’application.'],
    [281,170,1125,340,'Bannières','La liste des bannières créées, ou un message si aucune n’existe.']]},
  support:{img:'support',url:'support',cap:'Les tickets du support client.',marks:[
    [281,176,1125,128,'Compteurs','Total, ouverts, en cours, résolus, fermés, urgents.'],
    [281,336,1125,90,'Filtres','N° de ticket ou sujet, statut, priorité, catégorie.'],
    [281,460,1125,200,'Tickets','La liste des demandes à traiter.']]},
  messages:{img:'messages',url:'messages',cap:'Messagerie du support.',marks:[
    [256,70,336,330,'Conversations','Une ligne par interlocuteur, la plus récente en haut.'],
    [592,70,836,780,'Fil de discussion','Sélectionnez une conversation pour lire et répondre.']]},
  exchanges:{img:'exchanges',url:'exchanges',cap:'Surveillance des échanges entre utilisateurs.',marks:[
    [297,193,1092,72,'Période','Choisissez les dates puis Filtrer.'],
    [281,445,1125,420,'Évolution','Nombre d’échanges sur 30 jours.'],
    [1284,885,97,15,'Voir tout','Liste complète des conversations récentes.']]},
  settings:{img:'settings',url:'settings',cap:'Paramètres généraux de l’application.',marks:[
    [281,65,324,54,'Onglets','Général, Système, Commissions.'],
    [281,144,1124,590,'Informations générales','Nom, logo, slogan et description de l’application.'],
    [1315,473,89,24,'FR / EN','Chaque texte visible par les clients existe en deux langues.'],
    [281,750,1124,150,'Contact','E-mail, téléphone et adresse affichés aux clients.']]},
  maintenance:{img:'settings_maintenance',url:'settings/maintenance',cap:'Mode maintenance.',marks:[
    [281,290,1124,60,'Activer','Bloque l’application pendant une intervention.'],
    [281,397,1124,210,'Message','Le texte vu par les utilisateurs.'],
    [281,625,1124,80,'Heure de fin','Indique quand le service revient.']]},
  services:{img:'settings_services',url:'settings/services',cap:'Envoi des codes de connexion (OTP).',marks:[
    [281,177,1124,76,'Canal par défaut','Automatique (WhatsApp d’abord), toujours WhatsApp ou toujours SMS.'],
    [1186,269,219,40,'Enregistrer le choix','Applique le canal choisi.'],
    [281,533,292,54,'Fournisseurs','Réglages du fournisseur SMS et de WhatsApp Business.']]},
  legal:{img:'legal-pages',url:'legal-pages',cap:'Pages légales affichées dans l’application.',marks:[
    [1274,64,166,40,'Nouvelle page','Ajoute une page (CGU, confidentialité, mentions…).'],
    [257,129,1182,267,'Pages','Titre, adresse (slug), ordre et date.'],
    [983,129,117,267,'Statut','Une page inactive n’est plus visible dans l’application.'],
    [1274,129,165,267,'Actions','Masquer, modifier, supprimer.']]},
  documents:{img:'documents',url:'documents',cap:'Documents de l’entreprise.',marks:[
    [1019,99,397,42,'Catégories / Uploader','Gérez les catégories, ou ajoutez un document.'],
    [281,176,1125,112,'Compteurs','Nombre, taille totale, catégories, téléchargements.'],
    [281,326,1125,48,'Filtres','Recherche et catégorie.'],
    [305,436,350,166,'Document','Nom, taille, date. Télécharger, modifier, supprimer.']]}
};

/* Groupes et pages */
const GROUPS = [
 {name:'Bien démarrer', pages:[
  {id:'bienvenue', title:'Bienvenue', render:renderHome},
  {id:'connexion', title:'Se connecter', path:['Navigateur','/admin/login'], lead:'Deux portes d’entrée existent : celle des administrateurs et celle des gestionnaires. Elles mènent au même back-office, mais un gestionnaire ne voit que les sections qui lui ont été ouvertes.', body:()=>`
   <h3>Administrateur</h3>
   ${steps(['Ouvrez l’adresse du back-office dans votre navigateur (Chrome, Edge, Firefox ou Safari).','Saisissez votre e-mail et votre mot de passe.','Cliquez sur '+k('Se connecter')+'.'])}
   ${fig('login')}
   <h3>Gestionnaire</h3>
   <p>L’espace gestionnaire a sa propre page, reconnaissable à sa couleur verte. Vos identifiants vous ont été envoyés par e-mail à la création de votre compte.</p>
   ${fig('manager_login')}
   ${note('warn','Sécurité du compte','Ne partagez jamais vos identifiants et ne cochez « Se souvenir de moi » que sur votre propre ordinateur. Déconnectez-vous en fin de journée avec l’icône en bas du menu.')}`},
  {id:'interface', title:'Découvrir l’interface', path:['Toutes les pages'], lead:'Toutes les pages partagent la même structure : le menu à gauche, le titre de la page en haut, et le contenu au centre.', body:()=>`
   ${fig('tour')}
   <h3>Le menu, famille par famille</h3>
   ${table(['Famille','Sections','À quoi ça sert'],[
     ['<b>Pilotage</b>','Dashboard, Revenus ASSO, Statistiques boutiques','Suivre l’activité et l’argent de la plateforme.',['dashboard','revenus','statistiques']],
     ['<b>Comptes</b>','Utilisateurs, Préférences, Carte','Les comptes de l’application et leurs centres d’intérêt.',['utilisateurs','preferences','carte']],
     ['<b>Catalogue</b>','Boutiques, Produits, Catégories, Packages, Asso Ads','Les boutiques, les produits et ce que les vendeurs achètent.',['boutiques','produits','categories','packages','ads']],
     ['<b>Commandes</b>','Commandes en gros, locales, avec acompte, Réclamations / Litiges','Suivre chaque commande jusqu’au client.',['commandes-locales','commandes-gros','acompte','litiges']],
     ['<b>Livraison</b>','Livreurs, Partenaires logistiques, Expéditions, Pays importés','Qui livre, à quel prix, et où en sont les colis.',['partenaires','livreurs','expeditions','pays']],
     ['<b>Paiements</b>','Transactions, Comptes de virement, Paiements','L’argent qui entre et qui sort.',['transactions','iban','config-paiements']],
     ['<b>DIASPO Exchange</b>','Vérifications, Voice of Customer, Offres','Voyageurs qui transportent des colis, et parole des utilisateurs.',['diaspo-verifs','diaspo-offres','voc']],
     ['<b>Commerciaux et affiliation</b>','Commerciaux & codes, Commissions, Affiliation','Codes commerciaux, parrainage et leur rémunération.',['commerciaux','commissions','affiliation']],
     ['<b>Communication</b>','Bannières, Annonces, Support, Messagerie, Échanges','Parler aux utilisateurs et leur répondre.',['bannieres','support','messagerie','echanges']],
     ['<b>Administration</b>','Gestionnaires, Maintenance, Paramètres, Services, Pages légales, Documents','Réglages de la plateforme.',['gestionnaires','parametres','maintenance','services','pages-legales','documents']]
   ].filter(r=>r[3].some(can)).map(r=>r.slice(0,3)))}
   ${note('info','Ce que vous voyez dépend de votre rôle','Un gestionnaire ne voit que les sections cochées pour lui. Si une section décrite ici manque dans votre menu, demandez-la à l’administrateur.')}
   <h3>Des gestes qui reviennent partout</h3>
   <ul>
    <li><b>Filtrer :</b> remplissez un ou plusieurs champs, puis ${k('Filtrer')}. ${k('Réinitialiser')} efface tout.</li>
    <li><b>Onglets d’étapes :</b> sur les pages de commandes, chaque pastille indique combien d’éléments attendent à cette étape. Commencez par celles qui ne sont pas à 0.</li>
    <li><b>Bouton orange :</b> l’action principale de la page (créer, enregistrer).</li>
    <li><b>Bouton rouge :</b> action définitive (supprimer, refuser). Une confirmation est demandée.</li>
    <li><b>Œil :</b> voir le détail. <b>Crayon :</b> modifier. <b>Corbeille :</b> supprimer.</li>
   </ul>`}
 ]},
 {name:'Pilotage', pages:[
  {id:'dashboard', title:'Dashboard', path:['Menu','Dashboard'], lead:'La page d’accueil des administrateurs. Elle résume la plateforme : combien de comptes, combien de produits, et comment l’activité évolue.', body:()=>`
   ${fig('dashboard')}
   ${note('tip','Routine du matin','Regardez d’abord la cloche des notifications, puis les inscriptions du jour. Une demande en attente apparaît aussi dans la section concernée.')}`},
  {id:'revenus', title:'Revenus ASSO', path:['Menu','Revenus ASSO'], lead:'Ce que la plateforme gagne réellement : chiffre d’affaires par service, moins les pertes et frais, pour obtenir le revenu net.', body:()=>`
   ${fig('revenue')}
   <h3>Lire les indicateurs</h3>
   ${table(['Indicateur','Signification'],[
     ['Chiffre d’affaires ASSO','Tout ce que la plateforme encaisse pour elle-même : commissions, forfaits, sponsoring, import…'],
     ['Pertes et charges','Ce qui vient en déduction, dont les frais des prestataires de paiement.'],
     ['Revenu net','Chiffre d’affaires moins pertes et charges.'],
     ['CA des produits ASSO','Part vendeur des boutiques détenues par le compte de la plateforme.']
   ])}
   <p>Les montants sont en FCFA. La flèche sous chaque chiffre compare à la période précédente de même durée.</p>`},
  {id:'retrait', title:'Retirer les fonds ASSO', path:['Revenus ASSO','Retirer'], lead:'Les commissions de la plateforme arrivent sur le portefeuille du compte plateforme. Chaque pays a son propre solde : on le retire vers un opérateur Mobile Money de ce pays, ou par virement bancaire.', body:()=>`
   ${fig('withdrawal')}
   ${steps(['Choisissez l’onglet '+k('Mobile Money')+' ou '+k('Virement bancaire')+'.','Repérez la carte du pays et vérifiez le solde.','Cliquez sur '+k('Retirer')+', puis indiquez le montant et le numéro qui recevra les fonds.','Le retrait apparaît dans « Derniers retraits ASSO », en bas de page.'])}
   ${note('info','Soldes partagés','Les pays qui utilisent la même devise partagent un solde (par exemple Cameroun et Gabon en XAF). Retirer depuis l’un fait baisser l’autre.')}`},
  {id:'statistiques', title:'Statistiques boutiques', path:['Menu','Statistiques boutiques'], lead:'L’audience et les ventes de toutes les boutiques réunies, sur la période choisie.', body:()=>`
   ${fig('statistics')}
   <p>Le <b>taux de conversion</b> rapporte les ventes au nombre de visiteurs uniques. Un taux qui baisse alors que les visites montent signale souvent un problème de prix, de stock ou de photos.</p>`}
 ]},
 {name:'Utilisateurs et accès', pages:[
  {id:'utilisateurs', title:'Utilisateurs', path:['Menu','Gestion','Utilisateurs'], lead:'Tous les comptes de l’application : clients, vendeurs et livreurs.', body:()=>`
   <h3>Trouver un utilisateur</h3>
   ${fig('users')}
   <h3>Consulter une fiche</h3>
   ${fig('user_show')}
   <h3>Créer un compte</h3>
   ${steps(['Cliquez sur '+k('Nouvel Utilisateur')+'.','Remplissez l’identité, l’e-mail et le mot de passe.','Choisissez le rôle, puis la position si besoin.','Enregistrez. La personne peut se connecter à l’application.'])}
   ${fig('user_create')}
   ${note('bad','Supprimer un compte','La suppression est définitive. Si la personne a des commandes ou une boutique en cours, préférez désactiver sa boutique.')}`},
  {id:'gestionnaires', title:'Gestionnaires', path:['Menu','Administration','Gestionnaires'], lead:'Donnez accès au back-office à un membre de l’équipe, sans lui ouvrir tout. Vous choisissez section par section.', body:()=>`
   ${fig('managers')}
   ${steps(['Cliquez sur '+k('Nouveau gestionnaire')+'.','Saisissez son identité et son e-mail.','Cochez ses droits sur les produits, puis chaque section qu’il pourra voir.','Enregistrez : il reçoit son mot de passe par e-mail.'])}
   ${fig('manager_create')}
   ${note('tip','Le juste nécessaire','N’ouvrez que les sections utiles au poste. Vous pourrez en ajouter plus tard depuis la liste.')}`},
  {id:'preferences', title:'Préférences', path:['Menu','Gestion','Préférences'], lead:'Les catégories qui intéressent les utilisateurs, telles qu’ils les ont choisies dans l’application.', body:()=>`${fig('preferences')}<p>Utilisez cette page pour orienter les achats en gros, les bannières et le recrutement de vendeurs.</p>`},
  {id:'carte', title:'Carte', path:['Menu','Gestion','Carte'], lead:'Où se trouvent vos utilisateurs.', body:()=>`${fig('map')}`}
 ]},
 {name:'Boutiques et catalogue', pages:[
  {id:'boutiques', title:'Boutiques', path:['Menu','Gestion','Boutiques'], lead:'Chaque vendeur a une boutique. Vous les vérifiez, les modifiez, les désactivez, et traitez leurs demandes de changement d’emplacement.', body:()=>`
   ${fig('shops')}
   <h3>La fiche boutique</h3>
   ${fig('shop_show')}
   ${note('info','Livraison gratuite','Quand elle est activée sur une boutique ou un produit, le client voit le prix barré de la livraison. Le vendeur finance la course.')}`},
  {id:'produits', title:'Produits', path:['Menu','Gestion','Produits'], lead:'Le catalogue complet, toutes boutiques confondues.', body:()=>`
   ${fig('products')}
   <h3>Fiche produit</h3>
   ${fig('product_show')}
   <h3>Ajouter un produit</h3>
   ${steps(['Cliquez sur '+k('Nouveau Produit')+'.','Choisissez la boutique, puis rédigez le nom et la description en '+k('FR')+' et en '+k('EN')+'.','Classez le produit dans une catégorie et une sous-catégorie.','Indiquez le type, le poids, l’origine, le stock et le statut.','Ajoutez les photos et enregistrez.'])}
   ${fig('product_create')}
   ${note('tip','Masquer plutôt que supprimer',k('Masquer')+' retire le produit de l’application tout en le gardant. Vous pourrez le réafficher plus tard.')}
   ${note('info','Origine du produit','Laissée sur « importé » par défaut. Choisissez « Produit local » pour un produit fabriqué au Cameroun : il apparaît alors dans les commandes locales.')}`},
  {id:'categories', title:'Catégories', path:['Menu','Gestion','Catégories'], lead:'L’arborescence du catalogue : catégories principales et sous-catégories, en français et en anglais.', body:()=>`${fig('categories')}${note('warn','Avant de supprimer','Une catégorie qui contient des produits doit d’abord être vidée : déplacez les produits vers une autre catégorie.')}`},
  {id:'packages', title:'Packages', path:['Menu','Gestion','Packages'], lead:'Les forfaits payants des vendeurs : espace de stockage pour les photos et vidéos, boost de visibilité, certification de la boutique.', body:()=>`${fig('packages')}`},
  {id:'ads', title:'Asso Ads', path:['Menu','Gestion','Asso Ads'], lead:'Les produits sponsorisés par les vendeurs : ce qui a été vendu, ce qui a été diffusé, et où ils apparaissent dans l’application.', body:()=>`${fig('ads')}${note('info','Couper la diffusion','Décocher « Diffuser les produits sponsorisés » suspend l’affichage sans annuler les campagnes payées ni consommer leur quota.')}`}
 ]},
 {name:'Commandes', pages:[
  {id:'commandes-locales', title:'Commandes locales', path:['Menu','Gestion','Commandes locales'], lead:'Les commandes passées auprès des vendeurs locaux, de la confirmation du vendeur jusqu’à la livraison.', body:()=>`${fig('local')}
   ${table(['Étape','Ce qui se passe'],[
     [pill('y','Paiement en attente'),'Le client n’a pas encore payé.'],
     [pill('o','À confirmer par le vendeur'),'Le vendeur doit accepter la commande.'],
     [pill('b','En préparation'),'Le vendeur prépare le colis.'],
     [pill('b','En livraison'),'Le colis est en route.'],
     [pill('g','Livrée'),'Le client a reçu sa commande.'],
     [pill('r','Annulée'),'La commande est arrêtée.']])}`},
  {id:'commandes-gros', title:'Commandes en gros', path:['Menu','Gestion','Commandes en gros'], lead:'Les achats en gros importés : la commande part du pays d’origine, arrive à l’entrepôt de Douala, puis SOLEX la livre au client.', body:()=>`
   ${fig('wholesale')}
   <h3>Traiter une commande</h3>
   ${steps(['Ouvrez l’onglet '+k('À valider')+', puis '+k('Gérer')+' sur une commande.','Vérifiez les articles, les quantités et les montants.','Validez, ou refusez en écrivant le motif : il est envoyé au client et la commande est remboursée.','Faites avancer la commande à chaque étape : expédition, arrivée à Douala, livraison SOLEX, livrée.'])}
   ${fig('wholesale_show')}
   ${note('warn','Paiement non confirmé','Tant que le paiement n’est pas confirmé, la commande ne peut pas être validée. Attendez la confirmation avant de contacter le fournisseur.')}`},
  {id:'acompte', title:'Commandes avec acompte', path:['Menu','Gestion','Commandes avec acompte'], lead:'Le client paie un acompte à la commande et le solde après livraison. ASSO vérifie avec lui que le produit est conforme avant de libérer le solde. Le vendeur est payé après le solde.', body:()=>`
   ${fig('deposit')}
   <h3>La vérification ASSO</h3>
   ${steps(['Ouvrez la commande depuis l’onglet '+k('À contacter')+'.','Appelez le client au moment où le produit lui est présenté (livraison ou retrait).','Tout est conforme : ajoutez si besoin une note, puis '+k('Client contacté')+'.','Un problème : décrivez-le, puis '+k('Signaler un problème')+'. Le solde reste bloqué.'])}
   ${fig('deposit_show')}`},
  {id:'litiges', title:'Réclamations et litiges', path:['Menu','Gestion','Réclamations / Litiges'], lead:'Quand un client signale un problème sur un produit reçu, un litige s’ouvre. La part du vendeur est bloquée pendant le traitement.', body:()=>`
   ${fig('disputes')}
   ${steps(['Ouvrez les litiges '+k('Nouveau')+' en premier.','Analysez le motif et les éléments fournis par le client.','Contactez le vendeur.','Choisissez l’issue : remplacement, retour, remboursement ou rejet.','Passez le litige en '+k('Résolu')+' puis '+k('Fermé')+'.'])}
   ${note('warn','Délai','La part du vendeur est retenue pendant 48 heures après l’ouverture du litige. Traitez les nouveaux litiges chaque jour.')}`}
 ]},
 {name:'Immobilier', pages:[
  {id:'immobilier', title:'Annonces immobilières', path:['Menu','Immobilier','Annonces'], lead:'Les maisons à louer sont publiées par des bailleurs vérifiés ; ASSO relit chaque annonce avant sa mise en ligne. Les terrains sont saisis par ASSO pour ses cabinets partenaires.', body:()=>`
   <h3>Vérifier un bailleur</h3>
   ${steps(['Ouvrez '+k('Bailleurs')+' : les demandes '+k('En attente')+' sont en tête.','Contrôlez la CNI, la preuve de propriété et la vidéo où le bailleur tient ses documents.','Tout concorde : '+k('Valider le bailleur')+'. Sinon '+k('Refuser')+' avec un motif clair : il le reçoit en notification.'])}
   <h3>Relire une annonce</h3>
   ${steps(['Ouvrez les annonces '+k('À relire')+'.','Vérifiez le contrôle avant publication : photo de l’entrée, une photo par pièce, vidéo, loyer.','Jugez la qualité des photos, la cohérence du prix et la description du quartier (route, eau, électricité, sécurité).',k('Publier')+' ou '+k('Refuser')+' avec un motif.'])}
   ${note('info','Argent','Au paiement, la part ASSO (majoration et commission) est encaissée tout de suite et n’est pas remboursable. La part du bailleur est bloquée sur son Wallet jusqu’à la confirmation du locataire ou la fin du délai de visite.')}
   ${note('warn','Modification','Une annonce modifiée par le bailleur repasse en relecture.')}`},
  {id:'immo-cabinets', title:'Cabinets et terrains', path:['Menu','Immobilier','Cabinets partenaires'], lead:'Les terrains ne sont pas proposés par les utilisateurs : ASSO crée chaque cabinet partenaire, puis saisit ses terrains.', body:()=>`
   ${steps(['Créez le cabinet : nom, accroche, spécialités, « Pourquoi nous choisir » (une puce par ligne), logo, bannière, coordonnées.','Ajoutez ses terrains : un par localité, avec un lot par surface proposée et son prix net.','Pour une affiche avec beaucoup de localités, utilisez '+k('Saisie rapide de terrains')+' dans Annonces.','Ajoutez les photos, puis '+k('Publier')+'.'])}
   ${note('info','Coordonnées','Le nom du cabinet est toujours affiché. Ses coordonnées ne sont montrées qu’à l’acheteur qui a versé l’acompte.')}`}
 ]},
 {name:'Livraison', pages:[
  {id:'partenaires', title:'Partenaires logistiques', path:['Menu','Gestion','Partenaires logistiques'], lead:'Les sociétés qui livrent (SOLEX, DHL, FedEx…), leurs tarifs au poids, leurs zones et les réglages communs à toutes les livraisons.', body:()=>`${fig('partners')}${note('info','Délai par défaut','Le délai de livraison est compté en jours ouvrables, hors week-ends. Il s’applique quand le produit n’a pas son propre délai.')}`},
  {id:'livreurs', title:'Livreurs', path:['Menu','Gestion','Livreurs'], lead:'Les entreprises de livraison inscrites et leurs zones.', body:()=>`${fig('deliverers')}`},
  {id:'expeditions', title:'Expéditions', path:['Menu','Gestion','Expéditions'], lead:'Les colis confiés à un transporteur, avec leur numéro de suivi.', body:()=>`${fig('shipments')}`},
  {id:'pays', title:'Pays importés', path:['Menu','Gestion','Pays importés'], lead:'Les pays d’où viennent les produits importés. Vous en ajoutez ou en retirez sans mettre à jour l’application.', body:()=>`${fig('countries')}`}
 ]},
 {name:'Paiements', pages:[
  {id:'transactions', title:'Transactions', path:['Menu','Gestion','Transactions'], lead:'L’historique de tous les paiements : recharges, retraits, remboursements, bonus.', body:()=>`${fig('transactions')}`},
  {id:'iban', title:'Comptes de virement', path:['Menu','Administration','Comptes de virement'], lead:'Avant tout virement (retrait d’un vendeur, remboursement d’un litige), l’IBAN doit être validé ici.', body:()=>`${fig('stripe')}${steps(['Ouvrez l’onglet '+k('En attente')+'.','Vérifiez que le titulaire du compte correspond à l’utilisateur.','Validez, ou rejetez en indiquant la raison.'])}`},
  {id:'config-paiements', title:'Moyens de paiement', path:['Menu','Administration','Paiements'], lead:'Les prestataires qui encaissent les paiements des clients. Page réservée aux administrateurs.', body:()=>`${fig('payments')}${note('bad','Données sensibles','Les clés d’accès donnent la main sur l’argent de la plateforme. Ne les copiez jamais dans un e-mail ou une messagerie. Sur cette capture, elles sont masquées.')}`}
 ]},
 {name:'DIASPO Exchange', pages:[
  {id:'diaspo-verifs', title:'Vérifications', path:['Menu','DIASPO Exchange','Vérifications'], lead:'Un voyageur peut publier une offre avant d’être vérifié : elle s’affiche avec la mention « Profil non vérifié » et ne peut pas être réservée. Il a un délai pour envoyer sa pièce d’identité.', body:()=>`${fig('dverif')}${steps(['Ouvrez l’onglet '+k('En attente')+'.','Comparez la pièce (CNI ou passeport, recto et verso) avec le profil.','Validez, ou rejetez avec la raison.'])}`},
  {id:'diaspo-offres', title:'Offres', path:['Menu','DIASPO Exchange','Offres'], lead:'Les trajets publiés par les voyageurs qui proposent de transporter des colis.', body:()=>`${fig('doffers')}`},
  {id:'voc', title:'Voice of Customer', path:['Menu','DIASPO Exchange','Voice of Customer'], lead:'Les messages publiés par les utilisateurs dans « Ma voix » : questions, problèmes, bonnes expériences.', body:()=>`${fig('dposts')}${note('tip','Écouter','Triez par « Plus populaires » une fois par semaine : les sujets qui reviennent sont vos priorités.')}`}
 ]},
 {name:'Commerciaux et affiliation', pages:[
  {id:'commerciaux', title:'Commerciaux et codes', path:['Menu','Commerciaux','Commerciaux & codes'], lead:'Les commerciaux recrutent des vendeurs. Le vendeur saisit leur code en payant son forfait, et le commercial touche une commission.', body:()=>`${fig('agents')}${fig('agent_create')}`},
  {id:'commissions', title:'Commissions commerciaux', path:['Menu','Commerciaux','Commissions commerciaux'], lead:'Ce qui est dû à chaque commercial. Le montant est fixé au moment où le forfait du vendeur est activé.', body:()=>`${fig('agent_comm')}`},
  {id:'affiliation', title:'Affiliation', path:['Menu','Affiliation','Configuration'], lead:'Le programme de parrainage entre utilisateurs.', body:()=>`${fig('affiliate')}`}
 ]},
 {name:'Communication et support', pages:[
  {id:'bannieres', title:'Bannières et annonces', path:['Menu','Annonce'], lead:'Les bannières illustrées de l’accueil de l’application, et les annonces générales envoyées à tous.', body:()=>`${fig('banners')}<p>Les <b>Annonces générales</b> fonctionnent de la même façon : ${k('Nouvelle Annonce')}, rédigez le message, publiez.</p>`},
  {id:'support', title:'Support client', path:['Menu','Gestion','Support'], lead:'Les demandes d’aide envoyées par les utilisateurs.', body:()=>`${fig('support')}${note('tip','Priorités','Filtrez par priorité « Urgente » en début de journée.')}`},
  {id:'messagerie', title:'Messagerie', path:['Menu','Gestion','Messagerie'], lead:'Les conversations directes entre le support et les utilisateurs.', body:()=>`${fig('messages')}`},
  {id:'echanges', title:'Échanges', path:['Menu','Gestion','Échanges'], lead:'Le volume des conversations entre acheteurs et vendeurs.', body:()=>`${fig('exchanges')}`}
 ]},
 {name:'Administration', pages:[
  {id:'parametres', title:'Paramètres', path:['Menu','Administration','Paramètres'], lead:'L’identité de l’application vue par les clients : nom, logo, textes et contacts.', body:()=>`${fig('settings')}`},
  {id:'maintenance', title:'Maintenance', path:['Menu','Administration','Maintenance'], lead:'Pour fermer temporairement l’application pendant une intervention.', body:()=>`${fig('maintenance')}${note('bad','À utiliser avec précaution','Tant que le mode est actif, personne ne peut acheter ni vendre. Pensez à le désactiver à la fin.')}`},
  {id:'services', title:'Services (codes OTP)', path:['Menu','Administration','Services'], lead:'Le code de connexion envoyé aux utilisateurs part par WhatsApp ou par SMS. Vous choisissez le canal ici.', body:()=>`${fig('services')}`},
  {id:'pages-legales', title:'Pages légales', path:['Menu','Administration','Pages légales'], lead:'Les CGU, la politique de confidentialité et les mentions légales visibles dans l’application.', body:()=>`${fig('legal')}`},
  {id:'documents', title:'Documents', path:['Menu','Outils','Documents'], lead:'Les documents de l’entreprise, rangés par catégories.', body:()=>`${fig('documents')}`}
 ]},
 {name:'Référence', pages:[
  {id:'bonnes-pratiques', title:'Routine quotidienne', path:['Référence'], lead:'Une liste courte pour ne rien laisser attendre.', body:()=>`
   ${table(['Quand','Où','Quoi'],[
    ['Chaque matin','Cloche des notifications','Voir ce qui attend une action.',null],
    ['Chaque matin','Réclamations / Litiges','Traiter les litiges « Nouveau » : la part vendeur n’est bloquée que 48 h.','litiges'],
    ['Chaque matin','Commandes en gros','Valider ou refuser les commandes « À valider ».','commandes-gros'],
    ['Chaque jour','Commandes avec acompte','Appeler les clients « À contacter ».','acompte'],
    ['Chaque jour','Boutiques','Traiter les demandes de changement d’emplacement.','boutiques'],
    ['Chaque jour','Produits','Vérifier les nouveaux produits et les stocks faibles.','produits'],
    ['Chaque jour','Vérifications DIASPO','Valider les pièces d’identité en attente.','diaspo-verifs'],
    ['Chaque jour','Support client','Répondre aux tickets urgents.','support'],
    ['Chaque semaine','Comptes de virement','Valider les IBAN en attente.','iban'],
    ['Chaque semaine','Revenus ASSO','Suivre le revenu net et retirer les fonds.','revenus'],
    ['Chaque semaine','Voice of Customer','Lire les sujets les plus populaires.','voc']
   ].filter(r=>!r[3]||can(r[3])).map(r=>r.slice(0,3)))}`},
  {id:'faq', title:'Questions fréquentes', path:['Référence'], lead:'Les réponses aux questions qui reviennent le plus.', body:()=>`
   ${[
    [null,'Une section n’apparaît pas dans mon menu ni dans ce guide.','Votre compte n’y a pas accès. Le guide ne montre que les sections ouvertes pour vous : demandez à l’administrateur de l’ajouter dans « Gestionnaires ».'],
    [null,'J’ai oublié mon mot de passe de gestionnaire.','L’administrateur le réinitialise. Le nouveau vous est envoyé par e-mail.'],
    [['commandes-locales','commandes-gros','acompte'],'Je ne trouve pas une commande.','Vérifiez l’onglet d’étape sélectionné, cliquez sur '+k('Toutes')+', puis cherchez par numéro ou par téléphone du client.'],
    [['produits'],'Un produit ne doit plus être vendu, mais je veux le garder.','Ouvrez sa fiche et cliquez sur '+k('Masquer')+'.'],
    [['commandes-gros'],'Je ne peux pas valider une commande en gros.','Le paiement du client n’est pas encore confirmé. Revenez plus tard.'],
    [['retrait'],'Pourquoi le bouton « Retirer » est grisé ?','Le solde de ce pays est vide, ou les opérateurs du pays ne versent pas dans cette devise.'],
    [null,'Les captures montrent des données différentes des miennes.','Elles viennent d’un environnement de démonstration. Les numéros, e-mails et clés y ont été masqués.']
   ].filter(f=>!f[0]||f[0].some(can)).map(f=>`<h3>${f[1]}</h3><p>${f[2]}</p>`).join('')}`}
 ]}
];

/* ======================================================================
   RENDU
   ====================================================================== */
function fig(key){
  const s = SHOTS[key];
  const marks = s.marks.map((m,i)=>`<div class="mk" data-i="${i}" style="left:${m[0]/W*100}%;top:${m[1]/H*100}%;width:${m[2]/W*100}%;height:${m[3]/H*100}%"><b>${i+1}</b></div>`).join('');
  const legend = s.marks.map((m,i)=>`<li data-i="${i}"><span class="n">${i+1}</span><div><strong>${m[4]}</strong><span>${m[5]}</span></div></li>`).join('');
  return `<figure class="shot" data-key="${key}">
    <div class="frame"><div class="chrome"><i></i><i></i><i></i><span>${URL_PREFIX}${s.url}</span></div>
    <div class="stage" role="button" tabindex="0" aria-label="Agrandir la capture : ${s.cap}"><img src="${IMG}${s.img}.webp" alt="${s.cap}" loading="lazy" width="1440" height="900">${marks}</div></div>
    <figcaption>${s.cap} Cliquez pour agrandir.</figcaption>
    <ol class="legend">${legend}</ol></figure>`;
}

function renderHome(){
  const cards = GROUPS.slice(1).map(g=>`<a href="#${g.pages[0].id}"><b>${g.name}</b><small>${g.pages.map(p=>p.title).join(' · ')}</small></a>`).join('');
  return `<div class="hero">
    <div>
      <span class="eyebrow">Guide d’utilisation · Back-office</span>
      <h1>Tout le back-office <em>ASSO</em>, expliqué écran par écran.</h1>
      <p>${IS_ADMIN?'Vous êtes administrateur : le guide couvre toutes les sections.':'Ce guide est adapté à votre compte : il ne montre que les sections qui vous sont ouvertes.'} Aucune connaissance technique n’est nécessaire : chaque écran est montré tel que vous le verrez, avec des repères numérotés.</p>
      <div class="meta"><span><b>${document.querySelectorAll ? FLAT.reduce((n,p)=>n+(p.body?(p.body().match(/class="shot"/g)||[]).length:0),0) : 0}</b> captures annotées</span><span><b>${FLAT.length}</b> fiches</span><span>Version d’octobre 2026</span></div>
    </div>
    <img class="logo" src="{{ asset('guide/logo.png') }}" alt="Logo ASSO">
  </div>
  <section class="doc" id="bienvenue" data-title="Bienvenue">
    <p class="group-label">Bien démarrer</p>
    <h2>Bienvenue</h2>
    <p class="lead">ASSO réunit des acheteurs, des vendeurs, des livreurs et des voyageurs DIASPO. Le back-office est l’endroit où l’équipe fait tourner la plateforme : vérifier les boutiques, suivre les commandes, régler les litiges, piloter l’argent.</p>
    <h3>Comment lire ce guide</h3>
    <ul>
      <li>Le <b>sommaire</b> à gauche suit l’ordre du menu du back-office.</li>
      <li>Chaque capture porte des <b>repères orange numérotés</b>. La légende sous l’image explique chaque repère ; survolez-la pour mettre le repère en évidence.</li>
      <li>Cliquez sur une capture pour l’<b>agrandir</b>.</li>
      <li>La <b>recherche</b> en haut trouve une fiche par mot-clé : « acompte », « IBAN », « retrait »…</li>
    </ul>
    <h3>Qui fait quoi</h3>
    ${table(['Rôle','Accès','Connexion'],[
      [pill('o','Administrateur'),'Tout le back-office, y compris paiements, gestionnaires et paramètres.','/admin/login'],
      [pill('g','Gestionnaire'),'Uniquement les sections cochées par l’administrateur.','/admin/gestionnaire/login']])}
    <h3>Les modules</h3>
    <div class="cards">${cards}</div>
    ${note('info','À propos des captures','Elles ont été prises sur un environnement de démonstration. Les numéros de téléphone, les e-mails personnels et les clés d’accès y sont masqués.')}
  </section>`;
}

GROUPS.forEach(g=>{g.pages=g.pages.filter(p=>can(p.id))});
for(let i=GROUPS.length-1;i>=0;i--){ if(!GROUPS[i].pages.length) GROUPS.splice(i,1); }

const FLAT = GROUPS.flatMap(g=>g.pages.map(p=>({...p,group:g.name})));
const content = document.getElementById('content');
let html = '';
FLAT.forEach((p,i)=>{
  if (p.render){ html += p.render(); return; }
  const prev = FLAT[i-1], next = FLAT[i+1];
  html += `<section class="doc" id="${p.id}" data-title="${p.title}">
    <p class="group-label">${p.group}</p>
    <h2>${p.title}</h2>
    <div class="path">${p.path.map(x=>`<span class="crumb">${x}</span>`).join('<span class="sep">›</span>')}</div>
    <p class="lead">${p.lead}</p>
    ${p.body()}
    <div class="pager">${prev?`<a href="#${prev.id}"><small>Précédent</small><b>${prev.title}</b></a>`:'<span></span>'}${next?`<a class="next" href="#${next.id}"><small>Suivant</small><b>${next.title}</b></a>`:'<span></span>'}</div>
  </section>`;
});
html += `<footer class="foot"><img src="{{ asset('guide/logo.png') }}" alt=""><span>ASSO · Guide du back-office</span><span>Octobre 2026</span><span>Une question sur ce guide : adressez-vous à l’administrateur de la plateforme.</span></footer>`;
content.innerHTML = html;

/* Sommaire */
const nav = document.getElementById('nav');
nav.innerHTML = GROUPS.map(g=>`<h4>${g.name}</h4>`+g.pages.map(p=>`<a href="#${p.id}" data-id="${p.id}"><span class="dot"></span>${p.title}</a>`).join('')).join('');

/* Sur cette page : les sous-titres de la section active */
const toc = document.getElementById('toc');
function buildToc(sec){
  const hs=[...sec.querySelectorAll('h3')];
  hs.forEach((h,i)=>{ if(!h.id) h.id = sec.id+'-'+(i+1); });
  toc.innerHTML = `<h5>Sur cette page</h5><a href="#${sec.id}" class="on">${sec.dataset.title}</a>`+hs.map(h=>`<a href="#${h.id}">${h.textContent}</a>`).join('');
}

/* Suivi de la section visible */
const sections=[...document.querySelectorAll('section.doc')];
let current=null;
function setActive(id){
  if(id===current) return; current=id;
  nav.querySelectorAll('a').forEach(a=>a.classList.toggle('on',a.dataset.id===id));
  const sec=document.getElementById(id); if(sec) buildToc(sec);
  const a=nav.querySelector(`a[data-id="${id}"]`);
  if(a){const r=a.getBoundingClientRect(),n=nav.getBoundingClientRect(); if(r.top<n.top+40||r.bottom>n.bottom-40) a.scrollIntoView({block:'center'});}
}
function onScroll(){
  const y=window.innerHeight*0.25; let id=sections[0].id;
  for(const s of sections){ if(s.getBoundingClientRect().top<=y) id=s.id; else break; }
  setActive(id);
}
window.addEventListener('scroll',onScroll,{passive:true}); onScroll();

/* Légende ↔ repère */
function link(fig){
  const stage=fig.querySelector('.stage');
  fig.querySelectorAll('.legend li').forEach(li=>{
    const on=()=>{stage.classList.add('focus');stage.querySelector(`.mk[data-i="${li.dataset.i}"]`).classList.add('hl');li.classList.add('hl')};
    const off=()=>{stage.classList.remove('focus');stage.querySelectorAll('.mk').forEach(m=>m.classList.remove('hl'));li.classList.remove('hl')};
    li.addEventListener('mouseenter',on); li.addEventListener('mouseleave',off);
    li.addEventListener('click',()=>{ if(li.classList.contains('hl')) off(); else { fig.querySelectorAll('.legend li.hl').forEach(x=>x.dispatchEvent(new Event('mouseleave'))); on(); } });
  });
}
document.querySelectorAll('figure.shot').forEach(link);

/* Visionneuse */
const lb=document.getElementById('lb');
function openLb(fig){
  const s=SHOTS[fig.dataset.key];
  lb.innerHTML=`<button class="iconbtn close" aria-label="Fermer"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg></button><div>${fig.querySelector('.stage').outerHTML}<p>${s.cap} Échap ou clic pour fermer.</p></div>`;
  lb.hidden=false; lb.querySelector('.close').focus();
}
const closeLb=()=>{lb.hidden=true;lb.innerHTML=''};
lb.addEventListener('click',closeLb);
document.querySelectorAll('figure.shot .stage').forEach(st=>{
  st.addEventListener('click',()=>openLb(st.closest('figure')));
  st.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();openLb(st.closest('figure'))}});
});

/* Recherche */
const q=document.getElementById('q'), res=document.getElementById('results');
const norm=t=>t.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'');
const INDEX=sections.map(s=>({id:s.id,title:s.dataset.title,group:(s.querySelector('.group-label')||{}).textContent||'',text:norm(s.textContent)}));
let sel=0;
function search(){
  const v=norm(q.value.trim());
  if(!v){res.hidden=true;return}
  const words=v.split(/\s+/);
  const hits=INDEX.map(e=>{const t=norm(e.title);let sc=0;for(const w of words){if(t.includes(w))sc+=5;else if(e.text.includes(w))sc+=1;else return null}return{...e,sc}}).filter(Boolean).sort((a,b)=>b.sc-a.sc).slice(0,8);
  sel=0;
  res.innerHTML=hits.length?hits.map((h,i)=>`<a href="#${h.id}" class="${i===0?'sel':''}">${h.title}<small>${h.group}</small></a>`).join(''):`<div class="none">Aucun résultat pour « ${q.value.replace(/</g,'&lt;')} ». Essayez un mot plus court.</div>`;
  res.hidden=false;
}
q.addEventListener('input',search);
q.addEventListener('keydown',e=>{
  const items=[...res.querySelectorAll('a')];
  if(e.key==='ArrowDown'||e.key==='ArrowUp'){e.preventDefault();if(!items.length)return;sel=(sel+(e.key==='ArrowDown'?1:-1)+items.length)%items.length;items.forEach((a,i)=>a.classList.toggle('sel',i===sel))}
  if(e.key==='Enter'&&items[sel]){e.preventDefault();location.hash=items[sel].getAttribute('href');res.hidden=true;q.blur()}
  if(e.key==='Escape'){res.hidden=true;q.blur()}
});
res.addEventListener('click',()=>{res.hidden=true});
document.addEventListener('click',e=>{if(!e.target.closest('.search'))res.hidden=true});
document.addEventListener('keydown',e=>{
  if(e.key==='Escape'&&!lb.hidden)closeLb();
  if(e.key==='/'&&document.activeElement!==q){e.preventDefault();q.focus()}
});

/* Menu mobile */
const scrim=document.getElementById('scrim');
const toggleNav=o=>{nav.classList.toggle('open',o);scrim.classList.toggle('open',o)};
document.getElementById('menuBtn').addEventListener('click',()=>toggleNav(!nav.classList.contains('open')));
scrim.addEventListener('click',()=>toggleNav(false));
nav.addEventListener('click',e=>{if(e.target.closest('a'))toggleNav(false)});

/* Thème */
const root=document.documentElement;
try{const t=localStorage.getItem('asso-guide-theme'); if(t) root.dataset.theme=t;}catch(e){}
document.getElementById('themeBtn').addEventListener('click',()=>{
  const dark=root.dataset.theme?root.dataset.theme==='dark':matchMedia('(prefers-color-scheme: dark)').matches;
  root.dataset.theme=dark?'light':'dark';
  try{localStorage.setItem('asso-guide-theme',root.dataset.theme)}catch(e){}
});

/* Arrivée par un lien direct */
if(location.hash){const go=()=>{const t=document.getElementById(location.hash.slice(1)); if(t) t.scrollIntoView({behavior:'instant'});}; setTimeout(go,50); window.addEventListener('load',go);}
</script>
</body>
</html>
