<?php
/**
 * calculator/admin/index.php — PRIVATE ADMIN VIEW
 * Session-gated: redirects to login.php if not authenticated.
 * Loads live pricing from data/pricing.json and injects into JS.
 * Save button POSTs to ../api/save-pricing.php (session-gated endpoint).
 * Claude script analyser proxied through ../api/claude-proxy.php (API key stays server-side).
 */

session_start();
require_once __DIR__ . '/../db.php';

// Require admin session — redirect to login if not set
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

// Load current pricing from TiDB Cloud with fallback
$serverPricing = null;
$hasServerData = false;
try {
    $db = getDbConnection();
    $stmt = $db->query("SELECT `config_json` FROM `pricing_config` ORDER BY `id` DESC LIMIT 1");
    $json = $stmt->fetchColumn();
    if ($json) {
        $decoded = json_decode($json, true);
        if ($decoded && json_last_error() === JSON_ERROR_NONE) {
            $serverPricing = $decoded;
            foreach (['social','ugc','dvc','brand','cgi','anime','scripting','addons'] as $cat) {
                if (!empty($serverPricing[$cat])) { $hasServerData = true; break; }
            }
        }
    }
} catch (Exception $e) {
    error_log("Database pricing load failed in admin, falling back to file: " . $e->getMessage());
    $pricingFile = __DIR__ . '/../data/pricing.json';
    if (file_exists($pricingFile)) {
        $json = @file_get_contents($pricingFile);
        if ($json) {
            $decoded = json_decode($json, true);
            if ($decoded && json_last_error() === JSON_ERROR_NONE) {
                $serverPricing = $decoded;
                foreach (['social','ugc','dvc','brand','cgi','anime','scripting','addons'] as $cat) {
                    if (!empty($serverPricing[$cat])) { $hasServerData = true; break; }
                }
            }
        }
    }
}

// Load tools data
$toolsData = null;
if ($serverPricing && !empty($serverPricing['__tools'])) {
    $toolsData = $serverPricing['__tools'];
}

$savedAt = $serverPricing['__saved_at'] ?? null;
$savedLabel = $savedAt ? 'Saved ' . date('d M, H:i', strtotime($savedAt)) : 'Using default prices';

// Load leads from TiDB Cloud with CSV fallback
$leads = [];
try {
    $db = getDbConnection();
    $stmt = $db->query("SELECT `date`, `name`, `email`, `company`, `phone`, `looking_for` as `looking` FROM `leads` ORDER BY `date` DESC");
    $leads = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Database leads load failed in admin, falling back to CSV: " . $e->getMessage());
    $leadsFile = __DIR__ . '/../data/leads.csv';
    if (file_exists($leadsFile)) {
        $rows = array_map('str_getcsv', file($leadsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        array_shift($rows); // remove header row
        foreach (array_reverse($rows) as $r) { // newest first
            if (count($r) >= 6) {
                $leads[] = [
                    'date'    => $r[0] ?? '',
                    'name'    => $r[1] ?? '',
                    'email'   => $r[2] ?? '',
                    'company' => $r[3] ?? '',
                    'phone'   => $r[4] ?? '',
                    'looking' => $r[5] ?? '',
                ];
            }
        }
    }
}
$leadCount = count($leads);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AIC Admin — Rate Card Engine</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#0D0D0D; --s1:#141414; --s2:#1A1A1A; --s3:#222;
  --border:rgba(255,255,255,0.07); --border2:rgba(255,255,255,0.12);
  --text:#E8E8E8; --muted:#666; --muted2:#444;
  --accent:#C8FF00; --accent2:#FF5C35; --accent3:#35C8FF;
  --green:#00E676; --amber:#FFB300; --red:#FF4444; --white:#fff;
  --mono:'IBM Plex Mono',monospace; --sans:'DM Sans',sans-serif; --display:'Syne',sans-serif;
}
*{box-sizing:border-box;margin:0;padding:0}
html{font-size:13px}
body{background:var(--bg);color:var(--text);font-family:var(--sans);min-height:100vh}

/* ── ADMIN SHELL ── */
.shell{display:grid;grid-template-columns:220px 1fr;min-height:100vh}
.sidebar{background:var(--s1);border-right:1px solid var(--border);padding:28px 0;position:sticky;top:0;height:100vh;overflow-y:auto;display:flex;flex-direction:column}
.logo-block{padding:0 20px 24px;border-bottom:1px solid var(--border);margin-bottom:20px}
.logo-eyebrow{font-family:var(--mono);font-size:9px;letter-spacing:2px;color:var(--muted);text-transform:uppercase;margin-bottom:4px}
.logo-text{font-family:var(--display);font-size:16px;font-weight:700;color:var(--white);letter-spacing:.5px}
.logo-text span{color:var(--accent)}
.nav-group{margin-bottom:6px}
.nav-label{font-size:9px;letter-spacing:2px;text-transform:uppercase;color:var(--muted2);padding:8px 20px 4px;font-family:var(--mono)}
.nav-item{display:flex;align-items:center;gap:8px;padding:8px 20px;font-size:12px;color:var(--muted);cursor:pointer;border-left:2px solid transparent;transition:all .15s}
.nav-item:hover{color:var(--text);background:var(--s2)}
.nav-item.active{color:var(--accent);border-left-color:var(--accent);background:rgba(200,255,0,.04)}
.nav-dot{width:6px;height:6px;border-radius:50%;background:var(--muted2);flex-shrink:0}
.nav-item.active .nav-dot{background:var(--accent)}
.sidebar-footer{margin-top:auto;padding:16px 20px;border-top:1px solid var(--border)}
.save-all-btn{width:100%;padding:10px;background:var(--accent);color:#000;border:none;border-radius:6px;font-family:var(--display);font-size:12px;font-weight:600;letter-spacing:.5px;cursor:pointer;transition:opacity .2s}
.save-all-btn:hover{opacity:.85}
.last-saved{font-size:10px;color:var(--muted);text-align:center;margin-top:8px;font-family:var(--mono)}
.logout-link{display:block;text-align:center;margin-top:8px;font-size:10px;color:var(--muted);font-family:var(--mono);text-decoration:none}
.logout-link:hover{color:var(--red)}
.main{overflow-y:auto}
.topbar{padding:20px 32px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;background:var(--s1);position:sticky;top:0;z-index:10}
.topbar-title{font-family:var(--display);font-size:15px;font-weight:600;color:var(--white)}
.topbar-sub{font-size:11px;color:var(--muted);margin-top:2px;font-family:var(--mono)}
.tab-strip{display:flex;gap:0}
.tab-btn{padding:7px 16px;font-size:11px;letter-spacing:.5px;border:1px solid var(--border);background:transparent;color:var(--muted);cursor:pointer;transition:all .15s}
.tab-btn:first-child{border-radius:6px 0 0 6px}
.tab-btn:last-child{border-radius:0 6px 6px 0}
.tab-btn+.tab-btn{border-left:none}
.tab-btn.active{background:var(--accent);color:#000;border-color:var(--accent);font-weight:600}
.pane{display:none;padding:28px 32px}
.pane.active{display:block}
.summary-row{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:28px}
.sum-card{background:var(--s2);border:1px solid var(--border);border-radius:8px;padding:14px 16px}
.sum-label{font-size:10px;color:var(--muted);letter-spacing:.5px;margin-bottom:6px;font-family:var(--mono)}
.sum-val{font-size:20px;font-weight:600;font-family:var(--display);color:var(--white)}
.sum-val.green{color:var(--green)}.sum-val.amber{color:var(--amber)}.sum-val.red{color:var(--red)}
.sum-sub{font-size:10px;color:var(--muted);margin-top:4px;font-family:var(--mono)}
.sec-head{display:flex;align-items:center;gap:10px;margin:24px 0 14px}
.sec-label{font-family:var(--display);font-size:13px;font-weight:600;color:var(--white)}
.sec-pill{font-size:9px;padding:2px 8px;border-radius:20px;font-family:var(--mono);letter-spacing:.5px;text-transform:uppercase;font-weight:500}
.pill-social{background:#0D2010;color:#5FD068;border:1px solid #1A4020}
.pill-ugc{background:#2A1008;color:#FF7A5C;border:1px solid #4A2010}
.pill-dvc{background:#08102A;color:#7A8CFF;border:1px solid #10204A}
.pill-cgi{background:#2A2008;color:#FFD468;border:1px solid #4A3810}
.pill-anime{background:#20082A;color:#D468FF;border:1px solid #38104A}
.pill-tools{background:#082A2A;color:var(--accent3);border:1px solid #104A4A}
.pill-script{background:#08202A;color:#68D4FF;border:1px solid #103848}
.tbl-wrap{border:1px solid var(--border);border-radius:8px;overflow:hidden;margin-bottom:4px}
.ptable{width:100%;border-collapse:collapse}
.ptable thead tr{background:var(--s3)}
.ptable thead th{padding:10px 12px;text-align:left;font-size:9px;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);font-family:var(--mono);font-weight:500;border-bottom:1px solid var(--border2)}
.ptable thead th.r{text-align:right}
.ptable tbody tr{border-bottom:1px solid var(--border);transition:background .1s}
.ptable tbody tr:last-child{border-bottom:none}
.ptable tbody tr:hover{background:rgba(255,255,255,.02)}
.ptable td{padding:10px 12px;vertical-align:middle}
.row-name{font-size:12px;color:var(--white);font-weight:500}
.row-sub{font-size:10px;color:var(--muted);margin-top:2px}
.tools-tag{font-size:10px;color:var(--muted);font-family:var(--mono);font-style:italic}
.price-input{background:var(--s3);border:1px solid var(--border);border-radius:5px;padding:5px 8px;font-family:var(--mono);font-size:12px;color:var(--accent);width:110px;text-align:right;outline:none;transition:border-color .15s}
.price-input:focus{border-color:var(--accent)}
.price-input.slab{color:var(--amber);width:70px}
.price-input.cost{color:var(--accent3)}
.margin-cell{font-family:var(--mono);font-size:11px;text-align:right;min-width:60px}
.m-hi{color:var(--green)}.m-mid{color:var(--amber)}.m-lo{color:var(--red)}
.margin-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px}
.margin-card{background:var(--s2);border:1px solid var(--border);border-radius:8px;padding:16px}
.margin-card-title{font-size:11px;color:var(--muted);margin-bottom:10px;font-family:var(--mono)}
.margin-bar-wrap{margin-bottom:8px}
.margin-bar-label{display:flex;justify-content:space-between;font-size:11px;color:var(--text);margin-bottom:4px}
.margin-bar-track{height:6px;background:var(--s3);border-radius:3px;overflow:hidden}
.margin-bar-fill{height:100%;border-radius:3px;transition:width .3s}
.calc-shell{display:grid;grid-template-columns:360px 1fr;gap:24px}
.calc-form{background:var(--s2);border:1px solid var(--border);border-radius:10px;padding:20px}
.field{margin-bottom:14px}
.field label{display:block;font-size:10px;color:var(--muted);letter-spacing:.5px;margin-bottom:5px;font-family:var(--mono);text-transform:uppercase}
.field select,.field input[type=number]{width:100%;padding:8px 10px;background:var(--s3);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:12px;font-family:var(--sans);outline:none}
.togrow{display:flex;flex-wrap:wrap;gap:6px}
.ctog{padding:4px 10px;border-radius:20px;border:1px solid var(--border);font-size:11px;color:var(--muted);cursor:pointer;background:transparent;transition:all .15s;font-family:var(--sans)}
.ctog.on{background:rgba(255,92,53,.12);color:var(--accent2);border-color:var(--accent2)}
.quote-card{background:var(--s2);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:16px}
.q-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}
.q-title{font-family:var(--display);font-size:14px;font-weight:600;color:var(--white)}
.q-line{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border);font-size:12px}
.q-line:last-of-type{border-bottom:none}
.q-lab{color:var(--muted)}.q-val{font-family:var(--mono);color:var(--text)}
.q-total{display:flex;justify-content:space-between;padding-top:12px;margin-top:4px;border-top:1px solid var(--border2)}
.q-total-lab{font-family:var(--display);font-size:16px;font-weight:600;color:var(--white)}
.q-total-val{font-family:var(--mono);font-size:22px;font-weight:500;color:var(--accent)}
.q-gst{display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-top:6px;font-family:var(--mono)}
.metrics-4{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:14px}
.met{background:var(--s3);border-radius:6px;padding:10px;text-align:center}
.met-val{font-family:var(--mono);font-size:16px;font-weight:500;color:var(--white)}
.met-lab{font-size:10px;color:var(--muted);margin-top:3px}
.calc-section-title{font-family:var(--display);font-size:11px;font-weight:600;color:var(--muted);letter-spacing:1px;text-transform:uppercase;margin:14px 0 8px}
.tip-wrap{position:relative;display:inline-block}
.tip-icon{display:inline-flex;align-items:center;justify-content:center;width:14px;height:14px;border-radius:50%;background:var(--s3);border:1px solid var(--border2);font-size:9px;color:var(--muted);cursor:help;margin-left:5px;vertical-align:middle;flex-shrink:0;font-family:var(--mono);font-style:italic;font-weight:600}
.tip-box{display:none;position:absolute;bottom:calc(100% + 8px);left:50%;transform:translateX(-50%);background:#1E1E1E;border:1px solid var(--border2);border-radius:6px;padding:8px 11px;font-size:11px;color:var(--text);line-height:1.5;width:220px;z-index:999;pointer-events:none;font-family:var(--sans);font-style:normal;text-align:left;box-shadow:0 8px 24px rgba(0,0,0,.5)}
.tip-box::after{content:'';position:absolute;top:100%;left:50%;transform:translateX(-50%);border:5px solid transparent;border-top-color:var(--border2)}
.tip-wrap:hover .tip-box{display:block}
.analyser-shell{display:grid;grid-template-columns:1fr 1fr;gap:20px}
.analyser-input-card,.analyser-result{background:var(--s2);border:1px solid var(--border);border-radius:10px;padding:20px}
.analyser-title{font-family:var(--display);font-size:14px;font-weight:600;color:var(--white);margin-bottom:4px}
.analyser-sub{font-size:11px;color:var(--muted);margin-bottom:14px;line-height:1.5}
.script-textarea{width:100%;min-height:160px;background:var(--s3);border:1px solid var(--border);border-radius:6px;padding:12px;font-family:var(--mono);font-size:11px;color:var(--text);resize:vertical;outline:none;line-height:1.6;transition:border-color .15s}
.script-textarea:focus{border-color:var(--accent2)}
.script-textarea::placeholder{color:var(--muted2)}
.ref-row{display:flex;gap:8px;margin:10px 0;flex-wrap:wrap}
.ref-tog{padding:5px 12px;border-radius:20px;border:1px solid var(--border);background:transparent;font-size:11px;color:var(--muted);cursor:pointer;font-family:var(--sans);transition:all .15s}
.ref-tog.on{background:rgba(200,255,0,.1);color:var(--accent);border-color:var(--accent)}
.analyse-btn{width:100%;padding:11px;background:var(--accent2);color:#fff;border:none;border-radius:6px;font-family:var(--display);font-size:13px;font-weight:600;cursor:pointer;transition:opacity .2s;margin-top:12px}
.analyse-btn:hover{opacity:.85}
.analyse-btn:disabled{opacity:.4;cursor:not-allowed}
.analyser-result{max-height:80vh;overflow-y:auto}
.shot-card{background:var(--s3);border:1px solid var(--border);border-radius:8px;padding:12px 14px;margin-bottom:10px}
.shot-num{font-family:var(--mono);font-size:9px;color:var(--muted);letter-spacing:1px;margin-bottom:4px}
.shot-desc{font-size:12px;color:var(--text);margin-bottom:8px;line-height:1.5}
.shot-tags{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:8px}
.shot-tag{padding:2px 8px;border-radius:20px;font-size:10px;font-family:var(--mono);font-weight:500}
.tag-format{background:#08102A;color:#7A8CFF;border:1px solid #10204A}
.tag-tool{background:#082A2A;color:var(--accent3);border:1px solid #104A4A}
.shot-price-row{display:flex;justify-content:space-between;align-items:center;padding-top:8px;border-top:1px solid var(--border);font-family:var(--mono);font-size:11px}
.shot-price-label{color:var(--muted)}
.shot-price-input{background:var(--s2);border:1px solid var(--border);border-radius:4px;padding:3px 7px;font-family:var(--mono);font-size:11px;color:var(--accent);width:90px;text-align:right;outline:none}
.analyser-total{background:var(--s1);border:1px solid var(--border2);border-radius:8px;padding:14px 16px;margin-top:14px;position:sticky;bottom:0}
.analyser-total-row{display:flex;justify-content:space-between;margin-bottom:6px;font-size:12px}
.analyser-total-val{font-family:var(--mono);color:var(--accent);font-size:20px;font-weight:500}
.thinking-dots{display:inline-flex;gap:4px;align-items:center}
.thinking-dots span{width:6px;height:6px;border-radius:50%;background:var(--accent2);animation:blink 1.2s infinite}
.thinking-dots span:nth-child(2){animation-delay:.2s}
.thinking-dots span:nth-child(3){animation-delay:.4s}
@keyframes blink{0%,80%,100%{opacity:.2}40%{opacity:1}}
.notif{position:fixed;bottom:24px;right:24px;background:var(--accent);color:#000;padding:10px 20px;border-radius:8px;font-family:var(--display);font-size:12px;font-weight:600;transform:translateY(80px);opacity:0;transition:all .3s;z-index:9999}
.notif.show{transform:translateY(0);opacity:1}
.sync-badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:9px;font-family:var(--mono);font-weight:500;background:rgba(0,230,118,.1);color:var(--green);border:1px solid rgba(0,230,118,.2);margin-left:8px;vertical-align:middle}
::-webkit-scrollbar{width:4px;height:4px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--muted2);border-radius:2px}
</style>
</head>
<body>

<div class="notif" id="notif"></div>

<!-- PHP injects current live pricing before any JS runs -->
<script>
const SERVER_PRICING = <?= json_encode($serverPricing ?? new stdClass()) ?>;
const TOOLS_DATA_SERVER = <?= json_encode($toolsData ?? null) ?>;
const HAS_SERVER_DATA = <?= $hasServerData ? 'true' : 'false' ?>;
</script>

<!-- ═══════════════════════ ADMIN VIEW ═══════════════════════ -->
<div class="shell">
  <div class="sidebar">
    <div class="logo-block">
      <div class="logo-eyebrow">Admin Panel</div>
      <div class="logo-text">AI <span>Collective</span></div>
    </div>
    <div class="nav-group">
      <div class="nav-label">Pricing</div>
      <div class="nav-item active" onclick="sideNav(this,'overview')"><div class="nav-dot"></div>Overview</div>
      <div class="nav-item" onclick="sideNav(this,'social')"><div class="nav-dot"></div>Social &amp; UGC</div>
      <div class="nav-item" onclick="sideNav(this,'dvc')"><div class="nav-dot"></div>DVCs &amp; Brand Films</div>
      <div class="nav-item" onclick="sideNav(this,'cgi')"><div class="nav-dot"></div>CGI Realistic</div>
      <div class="nav-item" onclick="sideNav(this,'anime')"><div class="nav-dot"></div>CGI Stylised / Anime</div>
      <div class="nav-item" onclick="sideNav(this,'scripting')"><div class="nav-dot"></div>Scripting Fees</div>
      <div class="nav-item" onclick="sideNav(this,'addons')"><div class="nav-dot"></div>Add-ons &amp; Extras</div>
    </div>
    <div class="nav-group">
      <div class="nav-label">Costs &amp; Margins</div>
      <div class="nav-item" onclick="sideNav(this,'tools')"><div class="nav-dot"></div>Tool Subscriptions</div>
      <div class="nav-item" onclick="sideNav(this,'margins')"><div class="nav-dot"></div>Margin Analysis</div>
    </div>
    <div class="nav-group">
      <div class="nav-label">Client Tools</div>
      <div class="nav-item" onclick="sideNav(this,'calculator')"><div class="nav-dot"></div>Quote Calculator</div>
      <div class="nav-item" onclick="sideNav(this,'analyser')"><div class="nav-dot"></div>Script &rarr; Rate Card</div>
    </div>
    <div class="nav-group">
      <div class="nav-label">CRM</div>
      <div class="nav-item" onclick="sideNav(this,'leads')" style="justify-content:space-between">
        <span style="display:flex;align-items:center;gap:8px"><div class="nav-dot"></div>Leads</span>
        <?php if ($leadCount > 0): ?>
        <span style="background:var(--accent);color:#000;font-family:var(--mono);font-size:9px;font-weight:700;padding:1px 7px;border-radius:20px"><?= $leadCount ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="sidebar-footer">
      <button class="save-all-btn" onclick="saveAll()">Save &amp; Publish Prices</button>
      <div class="last-saved" id="last-saved-text"><?= htmlspecialchars($savedLabel) ?></div>
      <a href="logout.php" class="logout-link">&#8592; Logout</a>
    </div>
  </div>

  <div class="main">
    <div class="topbar">
      <div>
        <div class="topbar-title" id="pane-title">Overview — Pricing Engine</div>
        <div class="topbar-sub">Changes sync to Client View on Save <span class="sync-badge" id="sync-badge">SYNCED</span></div>
      </div>
      <div style="display:flex;align-items:center;gap:16px">
        <a href="../" style="font-size:11px;color:var(--muted);text-decoration:none;font-family:var(--mono)" target="_blank">&#8594; View Client</a>
        <div class="tab-strip">
          <button class="tab-btn active" onclick="setView(this,'pricing')">Pricing</button>
          <button class="tab-btn" onclick="setView(this,'margins')">Margins</button>
        </div>
      </div>
    </div>

    <div class="pane active" id="pane-overview">
      <div class="summary-row" id="overview-cards"></div>
      <div class="sec-head"><div class="sec-label">Gross margin by category</div></div>
      <div class="margin-grid" id="margin-overview-grid"></div>
    </div>

    <div class="pane" id="pane-social">
      <div class="sec-head"><div class="sec-label">Social Content</div><span class="sec-pill pill-social">Social</span></div>
      <div class="tbl-wrap" id="tbl-social"></div>
      <div class="sec-head" style="margin-top:20px"><div class="sec-label">UGC-Style Ads</div><span class="sec-pill pill-ugc">UGC</span></div>
      <div class="tbl-wrap" id="tbl-ugc"></div>
    </div>

    <div class="pane" id="pane-dvc">
      <div class="sec-head"><div class="sec-label">Digital Video Commercials — 100% AI</div><span class="sec-pill pill-dvc">DVC</span></div>
      <div class="tbl-wrap" id="tbl-dvc"></div>
      <div class="sec-head" style="margin-top:20px"><div class="sec-label">Hybrid &amp; Brand Films</div><span class="sec-pill pill-dvc">Brand</span></div>
      <div class="tbl-wrap" id="tbl-brand"></div>
    </div>

    <div class="pane" id="pane-cgi">
      <div class="sec-head"><div class="sec-label">CGI — Photo-Realistic</div><span class="sec-pill pill-cgi">CGI</span></div>
      <div class="tbl-wrap" id="tbl-cgi"></div>
    </div>

    <div class="pane" id="pane-anime">
      <div class="sec-head"><div class="sec-label">Anime &amp; Stylised</div><span class="sec-pill pill-anime">Anime</span></div>
      <div class="tbl-wrap" id="tbl-anime"></div>
    </div>

    <div class="pane" id="pane-scripting">
      <div class="sec-head"><div class="sec-label">Scripting &amp; Creative Fees</div><span class="sec-pill pill-script">Script</span></div>
      <div class="tbl-wrap" id="tbl-scripting"></div>
    </div>

    <div class="pane" id="pane-addons">
      <div class="sec-head"><div class="sec-label">Add-ons, Extras &amp; Surcharges</div><span class="sec-pill pill-tools">Extras</span></div>
      <div class="tbl-wrap" id="tbl-addons"></div>
    </div>

    <div class="pane" id="pane-tools">
      <div class="sec-head"><div class="sec-label">Tool Subscription Costs</div><span class="sec-pill pill-tools">Monthly</span></div>
      <p style="font-size:11px;color:var(--muted);margin-bottom:14px;font-family:var(--mono)">USD/INR &asymp; 84. Edit any line to adjust.</p>
      <div class="tbl-wrap" id="tbl-tools"></div>
      <div class="sum-card" style="margin-top:16px;max-width:320px">
        <div class="sum-label">Total tool burn / month</div>
        <div class="sum-val green" id="total-tool-cost">&mdash;</div>
        <div class="sum-sub">Before manpower &amp; overheads</div>
      </div>
    </div>

    <div class="pane" id="pane-margins">
      <div class="sec-head"><div class="sec-label">Margin Analysis — Per Format</div></div>
      <p style="font-size:11px;color:var(--muted);margin-bottom:14px;font-family:var(--mono)">Gross margin = (Price &minus; tool cost) / Price. Excludes manpower, PM, infra.</p>
      <div class="tbl-wrap" id="tbl-margins"></div>
    </div>

    <div class="pane" id="pane-calculator">
      <div class="calc-shell">
        <div class="calc-form">
          <div class="calc-section-title">Production</div>
          <div class="field"><label>Format</label><select id="c-format" onchange="calcQuote()"><option value="">&mdash; select &mdash;</option></select></div>
          <div class="field"><label>Duration</label><select id="c-dur" onchange="calcQuote()"><option value="1">30s</option><option value="2">60s</option><option value="3">90s</option><option value="4">120s</option></select></div>
          <div class="calc-section-title">Voice &amp; Language</div>
          <div class="field"><label>Voiceover</label><select id="c-voice" onchange="calcQuote()"><option value="0">AI voice (included)</option><option value="5000">Human VO &mdash; Hindi</option><option value="8000">Human VO &mdash; English</option><option value="7000">Human VO &mdash; Regional</option></select></div>
          <div class="field"><label>Language adaptations</label><select id="c-langs" onchange="calcQuote()"><option value="0">Master only</option><option value="6000">+1 lang</option><option value="11000">+2 langs</option><option value="16000">+3 langs</option><option value="20000">+4 langs</option><option value="28000">+5&ndash;8 langs</option></select></div>
          <div class="field"><label>Lip-sync</label><select id="c-lipsync" onchange="calcQuote()"><option value="0">No</option><option value="10000">Yes (+&#8377;10K)</option></select></div>
          <div class="calc-section-title">Output</div>
          <div class="field"><label>Aspect ratio</label><select id="c-aspect" onchange="calcQuote()"><option value="0">9:16 only</option><option value="2000">9:16 + 1:1</option><option value="5000">Full 3-format</option></select></div>
          <div class="field"><label>Resolution</label><select id="c-res" onchange="calcQuote()"><option value="0">1080p</option><option value="15000">4K (+&#8377;15K)</option></select></div>
          <div class="calc-section-title">Add-ons</div>
          <div class="togrow" id="c-addons"></div>
          <div class="calc-section-title">Order</div>
          <div class="field"><label>Quantity</label><input type="number" id="c-qty" min="1" max="200" value="1" oninput="calcQuote()"/></div>
          <div class="field"><label>Delivery</label><select id="c-rush" onchange="calcQuote()"><option value="1">Standard 7 days</option><option value="1.3">Rush 3 days (+30%)</option><option value="1.6">Same day (+60%)</option></select></div>
          <div class="field"><label>Extra revision rounds</label><select id="c-changes" onchange="calcQuote()"><option value="0">None</option><option value="1">+1</option><option value="2">+2</option><option value="3">+3</option></select></div>
        </div>
        <div><div class="quote-card" id="quote-output"><div style="text-align:center;padding:40px 0;color:var(--muted)">Select a format to generate quote</div></div></div>
      </div>
    </div>

    <div class="pane" id="pane-analyser">
      <div class="analyser-shell">
        <div class="analyser-input-card">
          <div class="analyser-title">Script &rarr; Indicative Rate Card</div>
          <div class="analyser-sub">Paste a script or shot list. Claude detects each scene and maps it to an AIC format with indicative pricing. All prices editable before sharing.</div>
          <label style="font-size:10px;color:var(--muted);font-family:var(--mono);text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:6px">Script / shot list</label>
          <textarea class="script-textarea" id="sa-script" placeholder="SHOT 1 — ECU product on marble surface, light rays&#10;SHOT 2 — Woman in saree walking through field, golden hour&#10;SHOT 3 — Product 360 rotation, CGI&#10;VO: 'Experience the difference...'&#10;&#10;Or paste a full script — shots will be auto-detected."></textarea>
          <label style="font-size:10px;color:var(--muted);font-family:var(--mono);text-transform:uppercase;letter-spacing:.5px;display:block;margin:12px 0 6px">Reference provided by client</label>
          <div class="ref-row" id="sa-reftype">
            <div class="ref-tog on" data-val="none">No reference</div>
            <div class="ref-tog" data-val="ai_ref">AI video ref</div>
            <div class="ref-tog" data-val="trad_ref">Traditional film ref</div>
            <div class="ref-tog" data-val="storyboard">Storyboard</div>
            <div class="ref-tog" data-val="mood">Mood board</div>
          </div>
          <label style="font-size:10px;color:var(--muted);font-family:var(--mono);text-transform:uppercase;letter-spacing:.5px;display:block;margin:12px 0 6px">Master duration</label>
          <select id="sa-duration" style="width:100%;padding:7px 10px;background:var(--s3);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:12px;font-family:var(--sans);outline:none;margin-bottom:8px">
            <option value="30">30 seconds</option>
            <option value="45">45 seconds</option>
            <option value="60">60 seconds</option>
            <option value="90">90 seconds</option>
            <option value="120">2 minutes</option>
            <option value="0">Auto-detect</option>
          </select>
          <label style="font-size:10px;color:var(--muted);font-family:var(--mono);text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:6px">Output format</label>
          <div class="ref-row" id="sa-outputfmt">
            <div class="ref-tog on" data-val="dvc">DVC / Ad Film</div>
            <div class="ref-tog" data-val="social">Social / Reel</div>
            <div class="ref-tog" data-val="brand">Brand Film</div>
            <div class="ref-tog" data-val="cgi">CGI Heavy</div>
          </div>
          <button class="analyse-btn" id="sa-btn" onclick="analyseScript()">Analyse Script &amp; Generate Rate Card</button>
          <div style="font-size:10px;color:var(--muted2);margin-top:8px;font-family:var(--mono)">Powered by Claude AI &mdash; indicative, edit before sharing</div>
        </div>
        <div class="analyser-result" id="sa-result">
          <div style="color:var(--muted);font-size:12px;font-family:var(--mono);padding:20px 0">Paste a script on the left and click Analyse.</div>
        </div>
      </div>
    </div>

    <!-- ═══ LEADS PANE ═══ -->
    <div class="pane" id="pane-leads" style="padding:28px 32px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
        <div>
          <div style="font-family:var(--display);font-size:15px;font-weight:600;color:var(--white)">Leads from Rate Card</div>
          <div style="font-size:11px;color:var(--muted);font-family:var(--mono);margin-top:3px">Clients who filled the form before viewing pricing</div>
        </div>
        <div style="display:flex;gap:10px;align-items:center">
          <span style="font-family:var(--mono);font-size:11px;color:var(--muted)"><?= $leadCount ?> total lead<?= $leadCount !== 1 ? 's' : '' ?></span>
          <a href="../api/export-leads.php" style="padding:7px 14px;background:var(--s3);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:11px;text-decoration:none;font-family:var(--mono)">&#8595; Export CSV</a>
        </div>
      </div>

      <?php if (empty($leads)): ?>
      <div style="background:var(--s2);border:1px solid var(--border);border-radius:10px;padding:48px;text-align:center">
        <div style="font-size:28px;margin-bottom:12px">&#128203;</div>
        <div style="font-family:var(--display);font-size:14px;color:var(--white);margin-bottom:6px">No leads yet</div>
        <div style="font-size:11px;color:var(--muted);font-family:var(--mono)">When clients fill the form on the Rate Card page, they'll appear here.</div>
      </div>
      <?php else: ?>
      <div style="border:1px solid var(--border);border-radius:8px;overflow:hidden">
        <table style="width:100%;border-collapse:collapse">
          <thead>
            <tr style="background:var(--s3)">
              <th style="padding:10px 14px;text-align:left;font-size:9px;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);font-family:var(--mono);border-bottom:1px solid var(--border2)">Date</th>
              <th style="padding:10px 14px;text-align:left;font-size:9px;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);font-family:var(--mono);border-bottom:1px solid var(--border2)">Name</th>
              <th style="padding:10px 14px;text-align:left;font-size:9px;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);font-family:var(--mono);border-bottom:1px solid var(--border2)">Email</th>
              <th style="padding:10px 14px;text-align:left;font-size:9px;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);font-family:var(--mono);border-bottom:1px solid var(--border2)">Company</th>
              <th style="padding:10px 14px;text-align:left;font-size:9px;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);font-family:var(--mono);border-bottom:1px solid var(--border2)">Phone</th>
              <th style="padding:10px 14px;text-align:left;font-size:9px;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);font-family:var(--mono);border-bottom:1px solid var(--border2)">Looking For</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($leads as $i => $lead): ?>
            <tr style="border-bottom:1px solid var(--border);<?= $i === 0 ? 'background:rgba(200,255,0,0.02)' : '' ?>">
              <td style="padding:10px 14px;font-size:11px;color:var(--muted);font-family:var(--mono);white-space:nowrap"><?= htmlspecialchars(date('d M, H:i', strtotime($lead['date']))) ?></td>
              <td style="padding:10px 14px">
                <div style="font-size:12px;font-weight:500;color:var(--white)"><?= htmlspecialchars($lead['name']) ?></div>
              </td>
              <td style="padding:10px 14px">
                <a href="mailto:<?= htmlspecialchars($lead['email']) ?>" style="font-size:11px;color:var(--accent3);font-family:var(--mono);text-decoration:none"><?= htmlspecialchars($lead['email']) ?></a>
              </td>
              <td style="padding:10px 14px;font-size:12px;color:var(--text)"><?= htmlspecialchars($lead['company']) ?></td>
              <td style="padding:10px 14px;font-size:11px;color:var(--muted);font-family:var(--mono)"><?= htmlspecialchars($lead['phone'] ?: '—') ?></td>
              <td style="padding:10px 14px">
                <?php if ($lead['looking']): ?>
                <span style="padding:2px 8px;border-radius:20px;font-size:10px;font-family:var(--mono);background:rgba(200,255,0,.08);color:var(--accent);border:1px solid rgba(200,255,0,.15)"><?= htmlspecialchars($lead['looking']) ?></span>
                <?php else: ?>
                <span style="color:var(--muted2);font-size:11px">—</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- /.main -->
</div><!-- /.shell -->

<script>
/* ═══ DEFAULT PRICING DATA ═══ */
const DEFAULTS = {
  social:[
    {id:'social_starter',name:'Social Starter',cat:'social',sub:'Short-form reel, single concept',tools:'Kling / Runway',base:8000,slabPct:20,toolCost:500,desc:'Best for quick product reveals or single-message reels.'},
    {id:'social_standard',name:'Social Standard',cat:'social',sub:'Multi-scene reel with VO',tools:'Runway + ElevenLabs',base:18000,slabPct:25,toolCost:1200,desc:'Narrative flow with AI voiceover and 2–3 visual concepts.'},
    {id:'social_premium',name:'Social Premium',cat:'social',sub:'Cinematic reel, branded look',tools:'Higgsfield + ElevenLabs',base:35000,slabPct:30,toolCost:2500,desc:'Premium brand aesthetic — motion-matched, colour-graded.'},
  ],
  ugc:[
    {id:'ugc_basic',name:'UGC — Basic Avatar',cat:'ugc',sub:'Single avatar, scripted VO',tools:'HeyGen',base:12000,slabPct:20,toolCost:800,desc:'AI avatar delivering a scripted message, one language.'},
    {id:'ugc_custom',name:'UGC — Custom Clone',cat:'ugc',sub:'Brand-trained avatar + script',tools:'HeyGen Enterprise',base:25000,slabPct:25,toolCost:2000,desc:'One-time avatar training + scripted delivery, up to 3 languages.'},
    {id:'ugc_multilang',name:'UGC — Multi-Language',cat:'ugc',sub:'5-language avatar pack',tools:'HeyGen + ElevenLabs',base:45000,slabPct:null,toolCost:3500,desc:'Single shoot, 5 language variants with lip-sync.'},
    {id:'ugc_ab',name:'UGC — A/B Creative Pack',cat:'ugc',sub:'3 creative variants, same brief',tools:'HeyGen + Runway',base:30000,slabPct:null,toolCost:2500,desc:'Three distinct creative takes for split-testing.'},
  ],
  dvc:[
    {id:'dvc_core',name:'DVC Core',cat:'dvc',sub:'30–60s ad film, AI-native',tools:'Runway + ElevenLabs',base:55000,slabPct:35,toolCost:4000,desc:'Clean 30s AI ad film with VO, music and colour grade.'},
    {id:'dvc_standard',name:'DVC Standard',cat:'dvc',sub:'Full AI production, 60–90s',tools:'Higgsfield + Runway + ElevenLabs',base:90000,slabPct:30,toolCost:7000,desc:'Full-length AI ad with human VO option, 3 deliverables.'},
    {id:'dvc_premium',name:'DVC Premium',cat:'dvc',sub:'Flagship 90–120s, multi-scene',tools:'Higgsfield + Sora + ElevenLabs',base:150000,slabPct:25,toolCost:12000,desc:'Flagship AI commercial, multi-scene, human VO included.'},
    {id:'brand_standard',name:'Brand Film — Standard',cat:'brand',sub:'2–3 min narrative brand film',tools:'Higgsfield + Runway + DaVinci',base:350000,slabPct:20,toolCost:25000,desc:'Full narrative brand film, AI + human hybrid post.'},
    {id:'brand_premium',name:'Brand Film — Premium',cat:'brand',sub:'3–5 min flagship production',tools:'Higgsfield + Sora + DaVinci',base:600000,slabPct:20,toolCost:40000,desc:'Flagship production, festival-grade finishing, full crew.'},
  ],
  brand:[],
  cgi:[
    {id:'cgi_product_std',name:'CGI Product — Standard',cat:'cgi',sub:'Photo-real pack shot / hero shot',tools:'Midjourney + Runway',base:45000,slabPct:30,toolCost:3500,desc:'3–5 product angles, AI-rendered, retouched.'},
    {id:'cgi_product_prem',name:'CGI Product — Premium',cat:'cgi',sub:'360° animated product film',tools:'Veo 3 + Midjourney',base:120000,slabPct:25,toolCost:10000,desc:'Full 360° animated product with environment, 15–30s.'},
    {id:'cgi_human',name:'CGI Human Integration',cat:'cgi',sub:'Photorealistic human + environment',tools:'Higgsfield + Midjourney',base:150000,slabPct:25,toolCost:15000,desc:'AI human actor in photorealistic environment.'},
    {id:'cgi_full',name:'Full CGI World',cat:'cgi',sub:'Fully rendered CGI environment',tools:'Veo 3 + Midjourney + DaVinci',base:450000,slabPct:20,toolCost:35000,desc:'Entirely AI-generated world, product hero, lighting, render.'},
  ],
  anime:[
    {id:'anime_std',name:'Anime Standard',cat:'anime',sub:'Anime-style brand reel',tools:'Midjourney + Runway',base:70000,slabPct:30,toolCost:5000,desc:'Anime aesthetic brand reel, 30–60s, coloured and scored.'},
    {id:'anime_prem',name:'Anime Premium',cat:'anime',sub:'Full animated short, bespoke style',tools:'Midjourney + Sora + DaVinci',base:140000,slabPct:25,toolCost:10000,desc:'Premium anime short, custom style guide, original music.'},
    {id:'folk_art',name:'Folk Art / Kalamkari',cat:'anime',sub:'Indian folk art motion style',tools:'Midjourney + Runway',base:85000,slabPct:30,toolCost:6000,desc:'Motion-animated Indian folk art style, brand storytelling.'},
    {id:'kinetic_typ',name:'Kinetic Typography',cat:'anime',sub:'High-motion text-driven visual',tools:'After Effects / CapCut Pro',base:25000,slabPct:20,toolCost:1500,desc:'Animated type-driven film, brand manifesto style.'},
  ],
  scripting:[
    {id:'script_social',name:'Social Media Script',cat:'scripting',sub:'Single reel / 15–30s concept',tools:'Claude + Human',base:8000,slabPct:null,toolCost:200},
    {id:'script_dvc',name:'DVC / Ad Film Script',cat:'scripting',sub:'30–90s narrative script',tools:'Claude + Human',base:18000,slabPct:null,toolCost:300},
    {id:'script_brand',name:'Brand Film Script',cat:'scripting',sub:'2–5 min narrative, brand voice',tools:'Claude + Human',base:35000,slabPct:null,toolCost:500},
    {id:'script_campaign',name:'Campaign Script Pack',cat:'scripting',sub:'Hero + 3 cutdowns + social',tools:'Claude + Human',base:65000,slabPct:null,toolCost:800},
  ],
  addons:[
    {id:'addon_format_11',name:'Format Add: 9:16 + 1:1',cat:'addons',sub:'Additional aspect ratio cut',tools:'DaVinci / CapCut',base:2000,slabPct:null,toolCost:100},
    {id:'addon_format_all',name:'Full 3-format Pack',cat:'addons',sub:'9:16 + 1:1 + 16:9',tools:'DaVinci / CapCut',base:5000,slabPct:null,toolCost:200},
    {id:'addon_4k',name:'4K Upscale',cat:'addons',sub:'Topaz AI upscale to 4K',tools:'Topaz Video AI',base:15000,slabPct:null,toolCost:500},
    {id:'addon_kinetic',name:'Kinetic Typography',cat:'addons',sub:'Custom animated text overlays',tools:'After Effects / CapCut Pro',base:3000,slabPct:null,toolCost:300},
    {id:'addon_cutdown',name:'Social Cut-down Pack',cat:'addons',sub:'15s + 30s + 6s bumper from master',tools:'DaVinci Resolve',base:18000,slabPct:null,toolCost:500},
    {id:'addon_music_suno',name:'Original Music — Suno',cat:'addons',sub:'AI-composed original score',tools:'Suno AI',base:4000,slabPct:null,toolCost:200},
    {id:'addon_music_bespoke',name:'Bespoke Music Composition',cat:'addons',sub:'Human + AI hybrid composition',tools:'Suno + Human composer',base:8000,slabPct:null,toolCost:1000},
    {id:'addon_avatar',name:'Custom Avatar Training',cat:'addons',sub:'One-time digital twin creation',tools:'HeyGen / Synthesia Enterprise',base:20000,slabPct:null,toolCost:5000},
    {id:'addon_ideation',name:'Campaign Ideation Sprint',cat:'addons',sub:'3 territories + rationale',tools:'Claude + Human',base:35000,slabPct:null,toolCost:500},
  ]
};

const DEFAULT_TOOLS = [
  {name:'Higgsfield AI',plan:'Ultra (Training phase)',inr:8400,use:'DVCs, Brand Films, CGI Humans'},
  {name:'Runway Gen-4',plan:'Pro ($76/mo)',inr:6400,use:'DVCs, Hybrid, CGI, Anime'},
  {name:'Kling AI 2.0',plan:'Standard ($10/mo)',inr:840,use:'Social, DVC Core'},
  {name:'Sora 2 API',plan:'Pay-per-use ($0.10/s)',inr:0,use:'Premium DVCs, CGI (billed per job)'},
  {name:'Veo 3 (Google)',plan:'Pay-per-use ($30/min)',inr:0,use:'CGI, Architecture (billed per job)'},
  {name:'ElevenLabs',plan:'Creator ($22/mo)',inr:1850,use:'VO all formats, multilingual'},
  {name:'HeyGen',plan:'Business ($89/mo)',inr:7480,use:'UGC avatars, custom clones'},
  {name:'Midjourney v7',plan:'Pro ($60/mo)',inr:5040,use:'CGI refs, Anime, Folk Art'},
  {name:'Suno AI',plan:'Pro ($8/mo)',inr:672,use:'Original music, brand films'},
  {name:'Topaz Video AI',plan:'Perpetual + cloud',inr:2500,use:'4K upscale, CGI finishing'},
  {name:'DaVinci Resolve',plan:'Studio (one-time)',inr:1200,use:'All post-production, editing'},
  {name:'CapCut Pro',plan:'Team ($8/mo)',inr:672,use:'Social reels, quick edits'},
  {name:'Claude API',plan:'Pay-per-use (scripting)',inr:800,use:'Script generation, visual briefs'},
  {name:'n8n (self-hosted)',plan:'Cloud Starter ($20/mo)',inr:1680,use:'Automation, delivery pipelines'},
];

/* ═══ LOAD PRICING (server-first, defaults fallback) ═══ */
function loadPricing() {
  if (HAS_SERVER_DATA && SERVER_PRICING) {
    const merged = {};
    for (const cat of Object.keys(DEFAULTS)) {
      merged[cat] = DEFAULTS[cat].map(def => {
        const s = (SERVER_PRICING[cat] || []).find(r => r.id === def.id);
        return s ? {...def, base: s.base, slabPct: s.slabPct, toolCost: s.toolCost} : {...def};
      });
    }
    return merged;
  }
  return JSON.parse(JSON.stringify(DEFAULTS));
}

function loadTools() {
  if (TOOLS_DATA_SERVER) return JSON.parse(JSON.stringify(TOOLS_DATA_SERVER));
  return JSON.parse(JSON.stringify(DEFAULT_TOOLS));
}

let P = loadPricing();
let T = loadTools();

/* ═══ HELPERS ═══ */
const inr = n => '\u20B9' + Math.round(n).toLocaleString('en-IN');
const pct = n => Math.round(n) + '%';
function margin(base,cost) { return base ? ((base-cost)/base)*100 : 0; }
function mClass(m) { return m>=80?'m-hi':m>=60?'m-mid':'m-lo'; }
function mColor(m) { return m>=80?'var(--green)':m>=60?'var(--amber)':'var(--red)'; }
function slabInr(r) { return r.slabPct!=null ? Math.round(r.base*r.slabPct/100) : 0; }
function allRows() { return Object.values(P).flat(); }
function findRow(id) { return allRows().find(r => r.id === id); }
function volDisc(q) { if(q>=40)return .30;if(q>=21)return .25;if(q>=11)return .18;if(q>=6)return .12;if(q>=3)return .05;return 0; }
const chgRate = id => ({social_starter:3000,social_standard:3000,social_premium:3000,ugc_basic:3000,ugc_custom:5000,ugc_multilang:5000,ugc_ab:3000,dvc_core:8000,dvc_standard:8000,dvc_premium:15000,brand_standard:15000,brand_premium:20000}[id]||8000);

/* ═══ ADMIN — RENDER TABLE ═══ */
function renderTable(cid,rows,showSlab=true) {
  const c = document.getElementById(cid); if(!c) return;
  let h = `<table class="ptable"><thead><tr>
    <th style="width:210px">Format</th><th>Tools</th>
    <th class="r" style="width:130px">Base price (30s)</th>
    ${showSlab?'<th class="r" style="width:90px">Slab %</th><th class="r" style="width:110px">+30s (auto)</th>':'<th></th><th></th>'}
    <th class="r" style="width:120px">Tool cost/job</th>
    <th class="r" style="width:88px">Gross %</th>
  </tr></thead><tbody>`;
  rows.forEach(r => {
    const hasSlab = showSlab && r.slabPct!=null;
    const si = hasSlab ? slabInr(r) : null;
    const m = margin(r.base,r.toolCost);
    const tip = r.desc ? `<span class="tip-wrap"><span class="tip-icon">i</span><span class="tip-box">${r.desc}</span></span>` : '';
    h += `<tr>
      <td><div class="row-name">${r.name}${tip}</div><div class="row-sub">${r.sub}</div></td>
      <td><div class="tools-tag">${r.tools}</div></td>
      <td><input class="price-input" value="${r.base}" onchange="updatePrice('${r.id}','base',this.value)"></td>
      ${showSlab?`
      <td>${hasSlab?`<input class="price-input slab" value="${r.slabPct}" onchange="updatePrice('${r.id}','slabPct',this.value)"><span style="font-size:10px;color:var(--muted);margin-left:2px">%</span>`:`<span style="color:var(--muted2);font-family:var(--mono);font-size:11px">N/A</span>`}</td>
      <td style="text-align:right;font-family:var(--mono);font-size:12px;color:var(--amber)" id="slab-inr-${r.id}">${hasSlab?inr(si):'&mdash;'}</td>`:'<td></td><td></td>'}
      <td><input class="price-input cost" value="${r.toolCost}" onchange="updatePrice('${r.id}','toolCost',this.value)"></td>
      <td class="margin-cell ${mClass(m)}" id="margin-${r.id}">${pct(m)}</td>
    </tr>`;
  });
  h += '</tbody></table>'; c.innerHTML = h;
}

function updatePrice(id,field,val) {
  const v = parseFloat(val)||0;
  const row = findRow(id); if(!row) return;
  row[field] = v;
  const se = document.getElementById('slab-inr-'+id);
  if(se && row.slabPct!=null) se.textContent = inr(slabInr(row));
  const me = document.getElementById('margin-'+id);
  if(me) { const m = margin(row.base,row.toolCost); me.textContent=pct(m); me.className='margin-cell '+mClass(m); }
  updateOverview();
  triggerAutoSave();
}

/* ═══ TOOLS TABLE ═══ */
function renderTools() {
  const c = document.getElementById('tbl-tools'); if(!c) return;
  let h = `<table class="ptable"><thead><tr><th>Tool</th><th>Plan</th><th>Use</th><th class="r" style="width:140px">Monthly (INR)</th></tr></thead><tbody>`;
  let tot = 0;
  T.forEach((t,i) => {
    tot += t.inr;
    h += `<tr><td><div class="row-name">${t.name}</div></td><td><div class="tools-tag">${t.plan}</div></td><td><div class="row-sub">${t.use}</div></td>
    <td><input class="price-input cost" value="${t.inr}" onchange="T[${i}].inr=parseFloat(this.value)||0;updateToolTotal();triggerAutoSave()"></td></tr>`;
  });
  h += '</tbody></table>'; c.innerHTML = h;
  document.getElementById('total-tool-cost').textContent = inr(tot);
}
function updateToolTotal() { document.getElementById('total-tool-cost').textContent = inr(T.reduce((a,t)=>a+t.inr,0)); }

/* ═══ OVERVIEW ═══ */
function updateOverview() {
  const rows = allRows();
  const avg = rows.reduce((a,r)=>a+margin(r.base,r.toolCost),0)/rows.length;
  const toolTot = T.reduce((a,t)=>a+t.inr,0);
  const oc = document.getElementById('overview-cards');
  if(oc) oc.innerHTML = [
    {l:'SKUs / formats',v:rows.length,c:'',s:'across all categories'},
    {l:'Avg gross margin',v:pct(avg),c:avg>=80?'green':avg>=65?'amber':'red',s:'excl. manpower'},
    {l:'Price range',v:inr(Math.min(...rows.map(r=>r.base)))+' &ndash; '+inr(Math.max(...rows.map(r=>r.base))).replace('&#8377;',''),c:'',s:'base 30s'},
    {l:'Tool burn / month',v:inr(toolTot),c:'amber',s:'fixed subscriptions'},
    {l:'Min deal (MOQ)',v:inr(250000),c:'',s:'&#8377;2.5L floor'},
  ].map(c=>`<div class="sum-card"><div class="sum-label">${c.l}</div><div class="sum-val ${c.c}">${c.v}</div><div class="sum-sub">${c.s}</div></div>`).join('');
  const cats = [
    {l:'Social + UGC',rows:[...P.social,...P.ugc],col:'#5FD068'},
    {l:'DVCs + Brand Films',rows:[...P.dvc,...P.brand],col:'#7A8CFF'},
    {l:'CGI Realistic',rows:P.cgi,col:'#FFD468'},
    {l:'Anime + Stylised',rows:P.anime,col:'#D468FF'},
  ];
  const mg = document.getElementById('margin-overview-grid');
  if(mg) mg.innerHTML = cats.map(cat => {
    const am = cat.rows.reduce((a,r)=>a+margin(r.base,r.toolCost),0)/cat.rows.length;
    return `<div class="margin-card"><div class="margin-card-title">${cat.l} &mdash; avg ${pct(am)}</div>${
      cat.rows.map(r=>{const m=margin(r.base,r.toolCost);return`<div class="margin-bar-wrap"><div class="margin-bar-label"><span>${r.name}</span><span style="color:${mColor(m)};font-family:var(--mono)">${pct(m)}</span></div><div class="margin-bar-track"><div class="margin-bar-fill" style="width:${Math.min(100,m)}%;background:${mColor(m)}"></div></div></div>`;}).join('')
    }</div>`;}).join('');
  renderMarginsTable();
}

function renderMarginsTable() {
  const c = document.getElementById('tbl-margins'); if(!c) return;
  const catMap = {social:'Social',ugc:'UGC',dvc:'DVC',brand:'Brand Film',cgi:'CGI',anime:'Anime/Styled',scripting:'Scripting',addons:'Add-ons'};
  let h = `<table class="ptable"><thead><tr><th>Format</th><th>Category</th><th class="r">Client price</th><th class="r">Tool cost</th><th class="r">Gross profit</th><th class="r">Gross %</th></tr></thead><tbody>`;
  for(const[k,l] of Object.entries(catMap)){(P[k]||[]).forEach(r=>{const m=margin(r.base,r.toolCost);h+=`<tr><td><div class="row-name">${r.name}</div></td><td><div class="tools-tag">${l}</div></td><td class="margin-cell" style="color:var(--accent)">${inr(r.base)}</td><td class="margin-cell" style="color:var(--accent3)">${inr(r.toolCost)}</td><td class="margin-cell ${mClass(m)}">${inr(r.base-r.toolCost)}</td><td class="margin-cell ${mClass(m)}">${pct(m)}</td></tr>`;});}
  h += '</tbody></table>'; c.innerHTML = h;
}

/* ═══ ADMIN CALCULATOR ═══ */
const addonList = [
  {l:'Script &mdash; Social (&#8377;8K)',v:8000},{l:'Script &mdash; DVC (&#8377;18K)',v:18000},
  {l:'Campaign Script (&#8377;65K)',v:65000},{l:'Cut-down Pack (&#8377;18K)',v:18000},
  {l:'Original Music (&#8377;4K)',v:4000},{l:'VFX Pack 5 shots (&#8377;35K)',v:35000},
  {l:'Custom Avatar (&#8377;20K)',v:20000},{l:'Ideation Sprint (&#8377;35K)',v:35000},
];

function buildCalcDropdown() {
  const sel = document.getElementById('c-format'); if(!sel) return;
  const groups = [['Social',P.social],['UGC',P.ugc],['DVC',P.dvc],['Brand Film',P.brand],['CGI Realistic',P.cgi],['Anime / Stylised',P.anime]];
  sel.innerHTML = '<option value="">&mdash; select format &mdash;</option>';
  groups.forEach(([l,rows]) => { const og = document.createElement('optgroup'); og.label=l; rows.forEach(r=>{const o=document.createElement('option');o.value=r.id;o.textContent=r.name;og.appendChild(o);}); sel.appendChild(og); });
  const ae = document.getElementById('c-addons'); if(!ae) return;
  ae.innerHTML = '';
  addonList.forEach(a => { const d=document.createElement('div');d.className='ctog';d.dataset.val=a.v;d.innerHTML=a.l;d.onclick=()=>{d.classList.toggle('on');calcQuote();};ae.appendChild(d); });
}

function calcQuote() {
  const fid = document.getElementById('c-format').value;
  const el = document.getElementById('quote-output'); if(!el) return;
  if(!fid){el.innerHTML='<div style="text-align:center;padding:40px 0;color:var(--muted)">Select a format to generate quote</div>';return;}
  const row = findRow(fid); if(!row) return;
  const ds = parseInt(document.getElementById('c-dur').value)-1;
  const voice = parseInt(document.getElementById('c-voice').value);
  const langs = parseInt(document.getElementById('c-langs').value);
  const lip = parseInt(document.getElementById('c-lipsync').value);
  const asp = parseInt(document.getElementById('c-aspect').value);
  const res = parseInt(document.getElementById('c-res').value);
  const qty = Math.max(1,parseInt(document.getElementById('c-qty').value)||1);
  const rush = parseFloat(document.getElementById('c-rush').value);
  const chg = parseInt(document.getElementById('c-changes').value);
  let addons = 0; document.querySelectorAll('#c-addons .ctog.on').forEach(t=>addons+=parseInt(t.dataset.val));
  const si = slabInr(row)*ds;
  const cf = chg*chgRate(fid);
  const raw = (row.base+si+voice+langs+lip+asp+res+addons+cf)*rush;
  const disc = volDisc(qty);
  const pv = raw*(1-disc); const tot = pv*qty; const gst = tot*1.18;
  const tc = row.toolCost*qty; const gm = margin(tot,tc);
  const dl = ['30s','60s','90s','120s'][ds]; const rl = rush===1?'7 days':rush===1.3?'3 days':'Same day';
  let lines = [];
  lines.push([row.name+' ('+dl+')',row.base+si]);
  if(ds>0&&slabInr(row)>0)lines.push(['  &rarr; +30s slab &times;'+ds+' @ '+row.slabPct+'% = '+inr(slabInr(row))+'/slab',0]);
  if(voice>0)lines.push(['Human voiceover',voice]); if(langs>0)lines.push(['Language adaptations',langs]);
  if(lip>0)lines.push(['Lip-sync',lip]); if(asp>0)lines.push(['Aspect ratio pack',asp]);
  if(res>0)lines.push(['4K upscale',res]); if(addons>0)lines.push(['Add-ons',addons]);
  if(cf>0)lines.push(['Extra revisions &times;'+chg,cf]); if(rush>1)lines.push(['Rush surcharge',Math.round(raw-raw/rush)]);
  if(disc>0)lines.push(['Volume discount ('+Math.round(disc*100)+'%)',-Math.round(raw*disc*qty)]);
  const lh = lines.map(([l,v]) => {
    if(v===0&&l.includes('&rarr;'))return`<div class="q-line" style="padding:3px 0"><span class="q-lab" style="font-size:10px;color:var(--muted2);font-family:var(--mono)">${l}</span><span></span></div>`;
    const col=v<0?'color:var(--green)':'';const dsp=v<0?'&minus;'+inr(-v):inr(v);
    return`<div class="q-line"><span class="q-lab">${l}</span><span class="q-val" style="${col}">${dsp}</span></div>`;
  }).join('');
  const ms = tot>=250000?'ok':tot>=175000?'warn':'fail';
  const mt = tot>=250000?'&#10003; Meets &#8377;2.5L floor.':`Gap: ${inr(250000-tot)} &mdash; need ${Math.ceil(250000/pv)} videos at this rate.`;
  el.innerHTML = `<div class="q-head"><div class="q-title">Quote Summary</div><span class="mpill ${ms==='ok'?'moq-ok':ms==='warn'?'moq-warn':'moq-fail'}">${pct(gm)} margin</span></div>
  ${lh}
  <div class="q-total"><div class="q-total-lab">Total (ex GST) &mdash; ${qty} video${qty>1?'s':''}</div><div class="q-total-val">${inr(tot)}</div></div>
  <div class="q-gst"><span>Incl. 18% GST</span><span>${inr(gst)}</span></div>
  <div class="metrics-4" style="margin-top:16px">
    <div class="met"><div class="met-val">${inr(pv)}</div><div class="met-lab">per video</div></div>
    <div class="met"><div class="met-val">${inr(pv/(([30,60,90,120][ds])/10))}</div><div class="met-lab">per 10 sec</div></div>
    <div class="met"><div class="met-val">${inr(tc)}</div><div class="met-lab">tool cost est.</div></div>
    <div class="met"><div class="met-val">${rl}</div><div class="met-lab">delivery</div></div>
  </div>
  <div class="moq-banner ${ms}" style="margin-top:14px">${mt}</div>`;
}

/* ═══ SCRIPT ANALYSER (proxy through server — API key stays hidden) ═══ */
document.addEventListener('click', e => {
  if(e.target.classList.contains('ref-tog')) {
    const p = e.target.closest('.ref-row');
    p.querySelectorAll('.ref-tog').forEach(t => t.classList.remove('on'));
    e.target.classList.add('on');
  }
});

async function analyseScript() {
  const script = document.getElementById('sa-script').value.trim();
  if(!script) { alert('Please paste a script first.'); return; }
  const refType = document.querySelector('#sa-reftype .ref-tog.on')?.dataset.val || 'none';
  const outFmt  = document.querySelector('#sa-outputfmt .ref-tog.on')?.dataset.val || 'dvc';
  const duration = document.getElementById('sa-duration').value;
  const btn    = document.getElementById('sa-btn');
  const result = document.getElementById('sa-result');
  btn.disabled = true; btn.textContent = 'Analysing\u2026';
  result.innerHTML = `<div style="text-align:center;padding:60px 20px"><div class="thinking-dots"><span></span><span></span><span></span></div><div style="font-size:12px;color:var(--muted);margin-top:16px;font-family:var(--mono)">Detecting shots and mapping to AIC formats&hellip;</div></div>`;
  try {
    // POST to our server-side proxy — API key never leaves the server
    const resp = await fetch('../api/claude-proxy.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ script_text: script, ref_type: refType, output_fmt: outFmt, duration: duration })
    });
    const data = await resp.json();
    if (!resp.ok) throw new Error(data.error || 'API error ' + resp.status);
    const raw = data.content?.find(b => b.type === 'text')?.text || '';
    const parsed = JSON.parse(raw.replace(/```json|```/g,'').trim());
    renderAnalyserResult(parsed);
  } catch(err) {
    result.innerHTML = `<div style="padding:20px;color:var(--red);font-family:var(--mono);font-size:11px">Error: ${err.message}</div>`;
  } finally {
    btn.disabled = false; btn.textContent = 'Analyse Script & Generate Rate Card';
  }
}

function renderAnalyserResult(data) {
  const result = document.getElementById('sa-result');
  const {shots,summary} = data;
  const cc = {low:'#5FD068',medium:'#FFD468',high:'var(--accent2)','very high':'var(--red)'};
  const sh = shots.map((s,i) => `<div class="shot-card">
    <div class="shot-num">SHOT ${s.shot_num} &mdash; ${s.duration_sec}s &mdash; ${s.complexity.toUpperCase()}</div>
    <div class="shot-desc">${s.description}</div>
    <div class="shot-tags"><span class="shot-tag tag-format">${s.format}</span><span class="shot-tag tag-tool">${s.tool}</span><span class="shot-tag" style="background:#1A0808;color:${cc[s.complexity]||'var(--text)'};border:1px solid rgba(255,255,255,.08)">${s.complexity}</span></div>
    <div style="font-size:10px;color:var(--muted);margin-bottom:8px;line-height:1.4;font-style:italic">${s.reasoning}</div>
    <div class="shot-price-row"><span class="shot-price-label">Indicative price</span><input class="shot-price-input" value="${s.indicative_price}" oninput="recalcAT()"/></div>
  </div>`).join('');
  const mok = summary.moq_met;
  result.innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px"><div style="font-family:var(--display);font-size:14px;font-weight:600;color:var(--white)">${summary.total_shots} shots &mdash; ${summary.master_duration_sec}s master</div><span class="shot-tag tag-format">${summary.dominant_format}</span></div>
  ${sh}
  <div class="analyser-total" id="at-block">
    <div class="analyser-total-row"><span style="color:var(--muted)">Production subtotal</span><span id="at-prod" style="font-family:var(--mono)">${inr(summary.total_production)}</span></div>
    <div class="analyser-total-row"><span style="color:var(--muted)">Scripting fee</span><span style="font-family:var(--mono)">${inr(summary.scripting_fee)}</span></div>
    <div class="analyser-total-row" style="margin-top:8px;padding-top:8px;border-top:1px solid var(--border)"><span style="font-size:13px;font-weight:500;color:var(--white)">Total (ex GST)</span><span class="analyser-total-val" id="at-total">${inr(summary.total_with_scripting)}</span></div>
    <div class="analyser-total-row"><span style="font-size:11px;color:var(--muted)">Incl. 18% GST</span><span id="at-gst" style="font-family:var(--mono);font-size:12px;color:var(--muted)">${inr(summary.gst_total)}</span></div>
    <div style="margin-top:10px;padding:8px 10px;border-radius:6px;font-size:11px;${mok?'color:var(--green)':'color:var(--red)'};background:rgba(255,255,255,.03);border:1px solid var(--border)">${mok?'&#10003; Meets &#8377;2.5L MOQ floor':'&#9888; Below &#8377;2.5L MOQ &mdash; upgrade format or add scripting'}</div>
    ${summary.notes?`<div style="margin-top:8px;font-size:10px;color:var(--muted);font-style:italic;line-height:1.5">${summary.notes}</div>`:''}
  </div>`;
  window._atScriptFee = summary.scripting_fee;
}

function recalcAT() {
  let prod = 0; document.querySelectorAll('.shot-price-input').forEach(e=>prod+=parseFloat(e.value)||0);
  const tot = prod+(window._atScriptFee||0); const gst = Math.round(tot*1.18);
  const ep=document.getElementById('at-prod');const et=document.getElementById('at-total');const eg=document.getElementById('at-gst');
  if(ep)ep.textContent=inr(prod);if(et)et.textContent=inr(tot);if(eg)eg.textContent=inr(gst);
}

/* ═══ ADMIN NAV ═══ */
const paneMap = {overview:'Overview — Pricing Engine',social:'Social & UGC',dvc:'DVCs & Brand Films',cgi:'CGI Realistic',anime:'Anime & Stylised',scripting:'Scripting Fees',addons:'Add-ons & Extras',tools:'Tool Subscriptions',margins:'Margin Analysis',calculator:'Quote Calculator',analyser:'Script → Rate Card',leads:'Leads — Rate Card Visitors'};
function sideNav(el,id) {
  document.querySelectorAll('.nav-item').forEach(n=>n.classList.remove('active'));
  el.classList.add('active');
  document.querySelectorAll('.pane').forEach(p=>p.classList.remove('active'));
  document.getElementById('pane-'+id).classList.add('active');
  document.getElementById('pane-title').textContent = paneMap[id]||id;
  if(id==='calculator') calcQuote();
}
function setView(btn,id) { document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active')); btn.classList.add('active'); }

/* ═══ SAVE & SYNC (posts to server — no localStorage) ═══ */
let _dirty = false;
let _autoSaveTimer = null;

function markUnsaved() {
  _dirty = true;
  const b = document.getElementById('sync-badge');
  if(b){b.textContent='UNSAVED';b.style.background='rgba(255,179,0,.1)';b.style.color='var(--amber)';b.style.borderColor='rgba(255,179,0,.2)';}
}

// Auto-save: fires 1.5s after the last change — no Save button needed
function triggerAutoSave() {
  markUnsaved();
  clearTimeout(_autoSaveTimer);
  const b = document.getElementById('sync-badge');
  if(b){b.textContent='SAVING\u2026';b.style.background='rgba(255,179,0,.1)';b.style.color='var(--amber)';b.style.borderColor='rgba(255,179,0,.2)';}
  _autoSaveTimer = setTimeout(() => saveAll(true), 1500);
}

function savePricing(callback) {
  // Build the payload (only mutable price fields)
  const toSave = {};
  for (const cat of Object.keys(P)) {
    toSave[cat] = P[cat].map(r => ({id:r.id, base:r.base, slabPct:r.slabPct, toolCost:r.toolCost}));
  }
  toSave['__tools'] = T; // also persist tool costs
  fetch('../api/save-pricing.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify(toSave)
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      if (callback) callback(data);
    } else {
      alert('Save failed: ' + (data.error || 'Unknown error'));
    }
  })
  .catch(err => alert('Save error: ' + err.message));
}

function saveAll(silent = false) {
  savePricing(data => {
    _dirty = false;
    const now = new Date();
    document.getElementById('last-saved-text').textContent = 'Saved ' + now.toLocaleTimeString('en-IN',{hour:'2-digit',minute:'2-digit'});
    const b = document.getElementById('sync-badge');
    if(b){b.textContent='SYNCED';b.style.background='rgba(0,230,118,.1)';b.style.color='var(--green)';b.style.borderColor='rgba(0,230,118,.2)';}
    if (!silent) {
      // Only show toast notification on manual saves (button click)
      const n = document.getElementById('notif');
      n.textContent = 'Prices saved & synced to Client View \u2713';
      n.classList.add('show');
      setTimeout(() => n.classList.remove('show'), 2500);
    }
  });
}

/* ═══ INIT ═══ */
function init() {
  renderTable('tbl-social',P.social); renderTable('tbl-ugc',P.ugc);
  renderTable('tbl-dvc',P.dvc); renderTable('tbl-brand',P.brand);
  renderTable('tbl-cgi',P.cgi); renderTable('tbl-anime',P.anime);
  renderTable('tbl-scripting',P.scripting,false); renderTable('tbl-addons',P.addons,false);
  renderTools(); updateOverview(); buildCalcDropdown();
}

init();
</script>
</body>
</html>
