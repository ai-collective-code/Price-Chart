<?php
/**
 * calculator/index.php — PUBLIC CLIENT VIEW
 *
 * Shows a lead capture form on first visit.
 * After the client fills their details, shows the rate card.
 * Lead is saved to data/leads.csv and emailed to admin.
 */

session_start();
require_once __DIR__ . '/db.php';

/* ── LEAD GATE ── */
// Check if this visitor has already submitted details (session or 30-day cookie)
$leadOk = !empty($_SESSION['aic_lead_ok']) || !empty($_COOKIE['aic_lead_ok']);
$leadError = '';

if (!$leadOk && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lead_name'])) {
    $name    = trim($_POST['lead_name']    ?? '');
    $email   = trim($_POST['lead_email']   ?? '');
    $company = trim($_POST['lead_company'] ?? '');
    $phone   = trim($_POST['lead_phone']   ?? '');
    $looking = trim($_POST['lead_looking'] ?? '');

    if (!$name || !$email || !$company) {
        $leadError = 'Please fill in your name, email address, and company name.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $leadError = 'Please enter a valid email address.';
    } else {
        // ── Save lead to Database (with local CSV fallback) ───────────────
        try {
            $db = getDbConnection();
            $stmt = $db->prepare("INSERT INTO `leads` (`date`, `name`, `email`, `company`, `phone`, `looking_for`, `ip_address`) 
                                  VALUES (:date, :name, :email, :company, :phone, :looking, :ip)");
            $stmt->execute([
                ':date'    => date('Y-m-d H:i:s'),
                ':name'    => $name,
                ':email'   => $email,
                ':company' => $company,
                ':phone'   => $phone ?: null,
                ':looking' => $looking ?: null,
                ':ip'      => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (Exception $e) {
            error_log("Database lead save failed, falling back to CSV: " . $e->getMessage());
            
            $leadFile = __DIR__ . '/data/leads.csv';
            if (!file_exists($leadFile)) {
                file_put_contents($leadFile, '"Date","Name","Email","Company","Phone","Looking For","IP"' . "\n", LOCK_EX);
            }
            $esc = fn($v) => '"' . str_replace('"', '""', $v) . '"';
            $row = implode(',', [
                $esc(date('Y-m-d H:i:s')),
                $esc($name), $esc($email), $esc($company),
                $esc($phone), $esc($looking),
                $esc($_SERVER['REMOTE_ADDR'] ?? ''),
            ]) . "\n";
            file_put_contents($leadFile, $row, FILE_APPEND | LOCK_EX);
        }

        // ── Send email notification to admin ─────────────────────────────
        $to      = 'debojit@aicollective.agency';
        $subject = "New Rate Card Lead: {$name} from {$company}";
        $body    = "A new lead just viewed your Rate Card.\n\n"
                 . "Name:        {$name}\n"
                 . "Email:       {$email}\n"
                 . "Company:     {$company}\n"
                 . "Phone:       " . ($phone ?: '—') . "\n"
                 . "Looking for: " . ($looking ?: '—') . "\n\n"
                 . "Time: " . date('d M Y, H:i') . " IST\n";
        $headers = "From: AIC Rate Card <noreply@bcfworks.com>\r\nReply-To: {$email}";
        @mail($to, $subject, $body, $headers);

        // ── Mark as done (session + 30-day cookie) ────────────────────────
        $_SESSION['aic_lead_ok'] = true;
        setcookie('aic_lead_ok', '1', time() + 30 * 24 * 3600, '/');
        $leadOk = true;

        // Redirect to self (PRG pattern — prevents form resubmit on refresh)
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
}

/* ── PRICING DATA ── */
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
    error_log("Database pricing load failed, falling back to file: " . $e->getMessage());
    $pricingFile  = __DIR__ . '/data/pricing.json';
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AIC — Production Rate Card</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#0D0D0D; --s1:#141414; --s2:#1A1A1A; --s3:#222;
  --border:rgba(255,255,255,0.07); --border2:rgba(255,255,255,0.12);
  --text:#E8E8E8; --muted:#666; --muted2:#444;
  --accent:#FF00AA; --accent2:#FF5C35; --accent3:#FF00AA;
  --green:#FF00AA; --amber:#FFB300; --red:#FF4444; --white:#fff;
  --mono:'IBM Plex Mono',monospace; --sans:'DM Sans',sans-serif; --display:'Syne',sans-serif;
}
*{box-sizing:border-box;margin:0;padding:0}
html{font-size:13px}
body{background:var(--bg);color:var(--text);font-family:var(--sans);min-height:100vh}

/* ── LEAD FORM PAGE ── */
.lead-page{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.lead-card{width:100%;max-width:480px;background:var(--s1);border:1px solid var(--border);border-radius:16px;padding:40px 36px}
.lead-logo{font-family:var(--display);font-size:20px;font-weight:700;color:var(--white);margin-bottom:4px}
.lead-logo span{color:var(--accent)}
.lead-tagline{font-size:11px;color:var(--muted);font-family:var(--mono);margin-bottom:28px}
.lead-headline{font-family:var(--display);font-size:22px;font-weight:700;color:var(--white);line-height:1.3;margin-bottom:8px}
.lead-headline span{color:var(--accent)}
.lead-sub{font-size:12px;color:var(--muted);line-height:1.7;margin-bottom:28px}
.lead-divider{height:1px;background:var(--border);margin-bottom:24px}
.lfield{margin-bottom:16px}
.lfield label{display:block;font-size:10px;color:var(--muted);font-family:var(--mono);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
.lfield input,.lfield select{width:100%;padding:11px 14px;background:var(--s3);border:1px solid var(--border);border-radius:8px;color:var(--text);font-size:13px;font-family:var(--sans);outline:none;transition:border-color .15s}
.lfield input:focus,.lfield select:focus{border-color:var(--accent)}
.lfield input::placeholder{color:var(--muted2)}
.lfield .optional{font-size:10px;color:var(--muted2);margin-left:4px;font-style:italic}
.lead-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.lead-btn{width:100%;padding:14px;background:var(--accent);color:#000;border:none;border-radius:8px;font-family:var(--display);font-size:13px;font-weight:700;letter-spacing:.5px;cursor:pointer;transition:opacity .2s;margin-top:6px;display:flex;align-items:center;justify-content:center;gap:8px}
.lead-btn:hover{opacity:.85}
.lead-btn svg{width:16px;height:16px}
.lead-error{background:rgba(255,68,68,.08);border:1px solid rgba(255,68,68,.2);color:var(--red);border-radius:6px;padding:10px 14px;font-size:12px;margin-bottom:16px;font-family:var(--mono)}
.lead-privacy{font-size:10px;color:var(--muted2);text-align:center;margin-top:14px;font-family:var(--mono);line-height:1.6}
.lead-footer-strip{margin-top:24px;padding-top:20px;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;font-size:10px;color:var(--muted);font-family:var(--mono)}
.lead-trust{display:flex;gap:16px}
.lead-trust-item{display:flex;align-items:center;gap:5px}
.lead-trust-dot{width:6px;height:6px;border-radius:50%;background:var(--green);flex-shrink:0}

/* ── CLIENT RATE CARD ── */
.client-shell{max-width:900px;margin:0 auto;padding:0 20px 60px}
.client-header{padding:36px 0 28px;border-bottom:1px solid var(--border);margin-bottom:32px;display:flex;justify-content:space-between;align-items:flex-end}
.client-logo{font-family:var(--display);font-size:22px;font-weight:700;color:var(--white)}.client-logo span{color:var(--accent)}
.client-tagline{font-size:11px;color:var(--muted);font-family:var(--mono);text-align:right;line-height:1.8}
.client-tagline strong{color:var(--accent3)}
.cat-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:24px}
.cat-tab{padding:7px 16px;border-radius:20px;border:1px solid var(--border);font-size:11px;color:var(--muted);cursor:pointer;background:transparent;transition:all .15s;font-family:var(--sans)}
.cat-tab:hover{color:var(--text);border-color:var(--border2)}
.cat-tab.active{background:var(--accent);color:#000;border-color:var(--accent);font-weight:600}
.format-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;margin-bottom:32px}
.fmt-card{background:var(--s2);border:1px solid var(--border);border-radius:10px;padding:16px;cursor:pointer;transition:all .2s;position:relative}
.fmt-card:hover{border-color:var(--border2);background:var(--s3)}
.fmt-card.selected{border-color:var(--accent);background:rgba(255,0,170,0.08)}
.fmt-card-name{font-size:13px;font-weight:500;color:var(--white);margin-bottom:4px}
.fmt-card-sub{font-size:11px;color:var(--muted);margin-bottom:10px;line-height:1.4}
.fmt-card-price{font-family:var(--mono);font-size:16px;font-weight:500;color:var(--accent)}
.fmt-card-slab{font-size:10px;color:var(--muted);font-family:var(--mono);margin-top:2px}
.fmt-card-tools{font-size:10px;color:var(--muted2);font-style:italic;margin-top:6px;border-top:1px solid var(--border);padding-top:6px}
.fmt-card-check{position:absolute;top:12px;right:12px;width:18px;height:18px;border-radius:50%;background:var(--accent);display:none;align-items:center;justify-content:center;font-size:10px;color:#000;font-weight:700}
.fmt-card.selected .fmt-card-check{display:none}
.quote-builder{background:var(--s1);border:1px solid var(--border);border-radius:12px;padding:24px;margin-bottom:24px}
.qb-title{font-family:var(--display);font-size:15px;font-weight:600;color:var(--white);margin-bottom:18px}
.qb-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:14px}
.qb-grid2{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-bottom:14px}
.qb-field label{display:block;font-size:10px;color:var(--muted);font-family:var(--mono);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px}
.qb-field select,.qb-field input[type=number]{width:100%;padding:8px 10px;background:var(--s3);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:12px;font-family:var(--sans);outline:none}
.qb-field select:focus,.qb-field input:focus{border-color:var(--accent2)}
.addon-row{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:14px}
.c-tog{padding:5px 12px;border-radius:20px;border:1px solid var(--border);font-size:11px;color:var(--muted);cursor:pointer;background:transparent;transition:all .15s}
.c-tog.on{background:rgba(255,92,53,0.12);color:var(--accent2);border-color:var(--accent2)}
.result-card{background:var(--s2);border:1px solid var(--border);border-radius:10px;padding:20px}
.r-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
.r-title{font-family:var(--display);font-size:14px;font-weight:600;color:var(--white)}
.r-line{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--border);font-size:12px}
.r-line:last-of-type{border-bottom:none}
.r-lab{color:var(--muted)}
.r-val{font-family:var(--mono);color:var(--text)}
.r-total{display:flex;justify-content:space-between;align-items:baseline;margin-top:14px;padding-top:14px;border-top:1px solid var(--border2)}
.r-total-lab{font-family:var(--display);font-size:16px;font-weight:600;color:var(--white)}
.r-total-val{font-family:var(--mono);font-size:26px;font-weight:500;color:var(--accent)}
.r-gst{display:flex;justify-content:space-between;font-size:11px;color:var(--muted);font-family:var(--mono);margin-top:6px}
.r-metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:14px}
.r-met{background:var(--s3);border-radius:6px;padding:10px;text-align:center}
.r-met-val{font-family:var(--mono);font-size:15px;font-weight:500;color:var(--white)}
.r-met-lab{font-size:10px;color:var(--muted);margin-top:3px}
.moq-banner{border-radius:8px;padding:11px 14px;font-size:12px;line-height:1.5;margin-top:12px}
.moq-ok{background:rgba(255,0,170,.06);border:1px solid rgba(255,0,170,.15);color:var(--green)}
.moq-fail{background:rgba(255,68,68,.06);border:1px solid rgba(255,68,68,.15);color:var(--red)}
.contact-strip{margin-top:40px;padding:20px 24px;background:var(--s2);border:1px solid var(--border);border-radius:10px;display:flex;justify-content:space-between;align-items:center}
.contact-strip-left{font-family:var(--display);font-size:13px;font-weight:600;color:var(--white)}
.contact-strip-left span{color:var(--accent)}
.contact-strip-right{font-size:11px;color:var(--muted);text-align:right;line-height:2;font-family:var(--mono)}
.contact-strip-right a{color:var(--accent3);text-decoration:none}
::-webkit-scrollbar{width:4px;height:4px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--muted2);border-radius:2px}

/* ── CART COMPONENT ── */
.cart-box {
  display: inline-block;
  margin-top: 12px;
  background: var(--accent);
  color: #000;
  padding: 10px 16px;
  border-radius: 8px;
  font-family: var(--display);
  font-weight: 700;
  cursor: pointer;
  text-align: center;
  box-shadow: 0 4px 16px rgba(255,0,170,0.2);
  display: none;
  transition: transform 0.2s;
}
.cart-box:hover { transform: translateY(-2px); }
.cart-count { font-size: 16px; margin-right: 4px; }
.cart-total { font-family: var(--mono); font-size: 13px; font-weight: 500; display: block; margin-top: 4px; opacity: 0.8; }
.page-back {
  display: inline-block;
  margin-bottom: 24px;
  padding: 8px 16px;
  background: var(--s2);
  border: 1px solid var(--border);
  border-radius: 8px;
  color: var(--white);
  cursor: pointer;
  font-family: var(--sans);
  font-size: 12px;
  transition: all 0.2s;
}
.page-back:hover { background: var(--s3); border-color: var(--border2); }

/* ── MOBILE RESPONSIVE ── */
@media(max-width:768px){
  html{font-size:12px}
  .lead-card{padding:28px 20px;max-width:100%}
  .lead-logo{font-size:18px}
  .lead-headline{font-size:18px}
  .lead-row{grid-template-columns:1fr}
  .client-shell{padding:0 16px 40px}
  .client-header{flex-direction:column;align-items:flex-start;margin-bottom:20px;padding:20px 0}
  .client-tagline{text-align:left;margin-top:8px}
  .format-grid{grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;margin-bottom:20px}
  .qb-grid{grid-template-columns:1fr;gap:10px;margin-bottom:10px}
  .qb-grid2{grid-template-columns:1fr;gap:10px;margin-bottom:10px}
  .contact-strip{flex-direction:column;gap:14px;align-items:flex-start;padding:16px 20px}
  .contact-strip-right{text-align:left}
  .r-metrics{grid-template-columns:repeat(2,1fr);gap:8px}
  .cat-tabs{gap:6px}
  .cat-tab{padding:6px 12px;font-size:10px}
}

@media(max-width:480px){
  html{font-size:11px}
  .lead-card{padding:20px 16px;border-radius:12px}
  .lead-logo{font-size:16px;margin-bottom:2px}
  .lead-headline{font-size:16px;margin-bottom:6px}
  .lead-sub{font-size:11px}
  .lead-row{gap:10px}
  .lfield input,.lfield select{padding:9px 10px;font-size:12px}
  .lead-btn{padding:12px;font-size:12px;margin-top:4px}
  .client-shell{padding:0 12px 30px;max-width:100%}
  .client-header{padding:16px 0;margin-bottom:16px}
  .client-logo{font-size:18px}
  .client-tagline{font-size:10px;line-height:1.6}
  .format-grid{grid-template-columns:1fr;gap:8px;margin-bottom:16px}
  .fmt-card{padding:12px;border-radius:8px}
  .fmt-card-name{font-size:12px}
  .fmt-card-sub{font-size:10px}
  .fmt-card-price{font-size:14px}
  .quote-builder{padding:16px;border-radius:10px;margin-bottom:16px}
  .qb-title{font-size:13px;margin-bottom:12px}
  .qb-grid,.qb-grid2{gap:8px;margin-bottom:8px}
  .qb-field label{font-size:9px;margin-bottom:4px}
  .qb-field select,.qb-field input{padding:7px 8px;font-size:11px}
  .addon-row{gap:5px;margin-bottom:10px}
  .c-tog{padding:4px 10px;font-size:10px}
  .result-card{padding:14px;border-radius:8px}
  .r-title{font-size:12px}
  .r-line,.r-gst{font-size:11px;padding:5px 0}
  .r-total{margin-top:10px;padding-top:10px}
  .r-total-lab{font-size:14px}
  .r-total-val{font-size:20px}
  .r-metrics{grid-template-columns:repeat(2,1fr);gap:6px;margin-top:10px}
  .r-met{padding:8px;border-radius:5px}
  .r-met-val{font-size:13px}
  .r-met-lab{font-size:9px}
  .moq-banner{font-size:11px;padding:8px 10px;line-height:1.4}
  .contact-strip{margin-top:24px;padding:14px 16px;border-radius:8px}
  .contact-strip-left{font-size:12px}
  .contact-strip-right{font-size:10px;line-height:1.8}
}
</style>
</head>
<body>

<?php if (!$leadOk): ?>
<!-- ═══════════════════════ LEAD CAPTURE FORM ═══════════════════════ -->
<div class="lead-page">
  <div class="lead-card">

    <div class="lead-logo">BHARAT CONTENT <span>FIREWORKS</span></div>
    <div class="lead-tagline">100% AI Production Studio</div>

    <div class="lead-headline">Get instant pricing<br>for <span>AI-native video</span> production.</div>
    <div class="lead-sub">
      Tell us a bit about yourself and your company to unlock our full production rate card &mdash; with live quote builder, format guide, and pricing for 60+ AI video formats.
    </div>

    <div class="lead-divider"></div>

    <?php if ($leadError): ?>
    <div class="lead-error"><?= htmlspecialchars($leadError) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
      <div class="lead-row">
        <div class="lfield">
          <label>Your Name</label>
          <input type="text" name="lead_name" placeholder="Priya Sharma" required
                 value="<?= htmlspecialchars($_POST['lead_name'] ?? '') ?>">
        </div>
        <div class="lfield">
          <label>Company / Brand</label>
          <input type="text" name="lead_company" placeholder="Acme Brands" required
                 value="<?= htmlspecialchars($_POST['lead_company'] ?? '') ?>">
        </div>
      </div>
      <div class="lead-row">
        <div class="lfield">
          <label>Work Email</label>
          <input type="email" name="lead_email" placeholder="priya@acmebrands.com" required
                 value="<?= htmlspecialchars($_POST['lead_email'] ?? '') ?>">
        </div>
        <div class="lfield">
          <label>Phone <span class="optional">(optional)</span></label>
          <input type="tel" name="lead_phone" placeholder="+91 98765 43210"
                 value="<?= htmlspecialchars($_POST['lead_phone'] ?? '') ?>">
        </div>
      </div>
      <div class="lfield">
        <label>What are you looking for? <span class="optional">(optional)</span></label>
        <select name="lead_looking">
          <option value="" <?= empty($_POST['lead_looking']) ? 'selected' : '' ?>>Select a category&hellip;</option>
          <option value="Social Media Content" <?= ($_POST['lead_looking']??'')==='Social Media Content' ? 'selected':'' ?>>Social Media Content (Reels, Shorts)</option>
          <option value="UGC / Avatar Ads"     <?= ($_POST['lead_looking']??'')==='UGC / Avatar Ads'     ? 'selected':'' ?>>UGC / Avatar Ads</option>
          <option value="DVC / Ad Film"         <?= ($_POST['lead_looking']??'')==='DVC / Ad Film'         ? 'selected':'' ?>>DVC / Ad Film</option>
          <option value="Brand Film"            <?= ($_POST['lead_looking']??'')==='Brand Film'            ? 'selected':'' ?>>Brand Film (2&ndash;5 min)</option>
          <option value="CGI / Product Viz"     <?= ($_POST['lead_looking']??'')==='CGI / Product Viz'     ? 'selected':'' ?>>CGI / Product Visualisation</option>
          <option value="Anime / Stylised"      <?= ($_POST['lead_looking']??'')==='Anime / Stylised'      ? 'selected':'' ?>>Anime / Stylised Animation</option>
          <option value="Not sure yet"          <?= ($_POST['lead_looking']??'')==='Not sure yet'          ? 'selected':'' ?>>Not sure yet &mdash; exploring</option>
        </select>
      </div>

      <button type="submit" class="lead-btn">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
        View Rate Card &amp; Pricing
      </button>
    </form>

    <div class="lead-privacy">&#128274; Your details are only shared with BHARAT CONTENT FIREWORKS.<br>We won't spam or sell your information.</div>

    <div class="lead-footer-strip">
      <div class="lead-trust">
        <div class="lead-trust-item"><div class="lead-trust-dot"></div>60+ AI formats</div>
        <div class="lead-trust-item"><div class="lead-trust-dot"></div>Instant quote builder</div>
        <div class="lead-trust-item"><div class="lead-trust-dot"></div>Live pricing</div>
      </div>
      <div>April 2026</div>
    </div>

  </div>
</div>

<?php else: ?>
<!-- ═══════════════════════ RATE CARD (after lead submitted) ═══════════════════════ -->

<script>
const SERVER_PRICING = <?= json_encode($serverPricing ?? new stdClass()) ?>;
const HAS_SERVER_DATA = <?= $hasServerData ? 'true' : 'false' ?>;
</script>

<div class="client-shell">
  <div id="page-grid">
    <div class="client-header">
      <div>
        <div class="client-logo">BHARAT CONTENT <span>FIREWORKS</span></div>
        <div style="font-size:11px;color:var(--muted);margin-top:4px;font-family:var(--mono)">100% AI Production Studio &mdash; Indicative Production, great card</div>
      </div>
      <div class="client-tagline">
        Production Rate Card &mdash; April 2026<br>
        All prices exclude 18% GST<br>
        <strong>debojit@aicollective.agency</strong><br>
        <div class="cart-box" id="cart-box" onclick="toggleCartPage()">
          <div><span class="cart-count" id="cart-count">0</span> items</div>
          <span class="cart-total" id="cart-total">₹0</span>
        </div>
      </div>
    </div>

    <div class="cat-tabs" id="cat-tabs">
      <button class="cat-tab active" onclick="filterCat(this,'all')">All Formats</button>
      <button class="cat-tab" onclick="filterCat(this,'social')">Social</button>
      <button class="cat-tab" onclick="filterCat(this,'ugc')">UGC</button>
      <button class="cat-tab" onclick="filterCat(this,'dvc')">DVC / Films</button>
    </div>

    <div class="format-grid" id="format-grid"></div>
  </div>

  <div class="quote-builder" id="quote-builder" style="display:none; margin-top: 36px; background: transparent; border: none; padding: 0;">
    <button class="page-back" onclick="toggleCartPage()">← Back to Formats</button>
    <div id="qb-items-container"></div>
    <div id="client-result" style="margin-top:16px"></div>
  </div>

  <div class="contact-strip">
    <div class="contact-strip-left">Ready to produce? <span>Let&rsquo;s talk.</span></div>
    <div class="contact-strip-right">
      <a href="mailto:debojit@aicollective.agency">debojit@aicollective.agency</a><br>
      BHARAT CONTENT FIREWORKS (AI Collective PVT. Ltd)<br>
      <a href="https://bcfworks.com/" target="_blank">bcfworks.com</a>
    </div>
  </div>
</div>

<script>
/* ═══ DEFAULTS ═══ */
const DEFAULTS = {
  social:[
    {id:'social_starter',name:'Social Starter',cat:'social',sub:'Short-form reel, single concept',tools:'Kling / Runway',base:8000,slabPct:20,toolCost:500},
    {id:'social_standard',name:'Social Standard',cat:'social',sub:'Multi-scene reel with VO',tools:'Runway + ElevenLabs',base:18000,slabPct:25,toolCost:1200},
    {id:'social_premium',name:'Social Premium',cat:'social',sub:'Cinematic reel, branded look',tools:'Higgsfield + ElevenLabs',base:35000,slabPct:30,toolCost:2500},
  ],
  ugc:[
    {id:'ugc_basic',name:'UGC \u2014 Basic Avatar',cat:'ugc',sub:'Single avatar, scripted VO',tools:'HeyGen',base:2000,slabPct:null,toolCost:800},
    {id:'ugc_custom',name:'UGC \u2014 Custom Clone',cat:'ugc',sub:'Brand-trained avatar + script',tools:'HeyGen Enterprise',base:25000,slabPct:25,toolCost:2000},
    {id:'ugc_multilang',name:'UGC \u2014 Multi-Language',cat:'ugc',sub:'5-language avatar pack',tools:'HeyGen + ElevenLabs',base:45000,slabPct:null,toolCost:3500},
    {id:'ugc_ab',name:'UGC \u2014 A/B Creative Pack',cat:'ugc',sub:'3 creative variants, same brief',tools:'HeyGen + Runway',base:30000,slabPct:null,toolCost:2500},
  ],
  dvc:[
    {id:'dvc_core',name:'DVC/Films',cat:'dvc',sub:'(Call to discuss) 30\u201360s ad film',tools:'Runway + ElevenLabs',base:55000,slabPct:35,toolCost:4000},
  ],
  brand:[], cgi:[], anime:[], scripting:[],
  addons:[
    {id:'addon_format_11',name:'Format Add: 9:16 + 1:1',cat:'addons',sub:'Additional aspect ratio cut',tools:'DaVinci / CapCut',base:2000,slabPct:null,toolCost:100},
    {id:'addon_format_all',name:'Full 3-format Pack',cat:'addons',sub:'9:16 + 1:1 + 16:9',tools:'DaVinci / CapCut',base:5000,slabPct:null,toolCost:200},
    {id:'addon_4k',name:'4K Upscale',cat:'addons',sub:'Topaz AI upscale to 4K',tools:'Topaz Video AI',base:15000,slabPct:null,toolCost:500},
    {id:'addon_music_suno',name:'Original Music \u2014 Suno',cat:'addons',sub:'AI-composed original score',tools:'Suno AI',base:4000,slabPct:null,toolCost:200},
    {id:'addon_cutdown',name:'Social Cut-down Pack',cat:'addons',sub:'15s + 30s + 6s bumper',tools:'DaVinci Resolve',base:18000,slabPct:null,toolCost:500},
    {id:'addon_avatar',name:'Custom Avatar Training',cat:'addons',sub:'One-time digital twin creation',tools:'HeyGen / Synthesia Enterprise',base:20000,slabPct:null,toolCost:5000},
    {id:'addon_ideation',name:'Campaign Ideation Sprint',cat:'addons',sub:'3 territories + rationale',tools:'Claude + Human',base:35000,slabPct:null,toolCost:500},
  ]
};

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

let P = loadPricing();

/* ═══ HELPERS ═══ */
const inr = n => '\u20B9' + Math.round(n).toLocaleString('en-IN');

function slabInr(r) { 
  if (r.id === 'ugc_basic') return 500;
  return r.slabPct != null ? Math.round(r.base * 0.30) : 0; 
}
function allRows() { return Object.values(P).flat(); }
function findRow(id) { return allRows().find(r => r.id === id); }
function volDisc(q) { if(q>=40)return .30;if(q>=21)return .25;if(q>=11)return .18;if(q>=6)return .12;if(q>=3)return .05;return 0; }

/* ═══ CLIENT VIEW ═══ */
let selectedFormats = [];
let itemSettings = {};
let isCartPage = false;

function toggleCartPage() {
  if (selectedFormats.length === 0 && !isCartPage) return;
  isCartPage = !isCartPage;
  document.getElementById('page-grid').style.display = isCartPage ? 'none' : 'block';
  document.getElementById('quote-builder').style.display = isCartPage ? 'block' : 'none';
  if (isCartPage) {
    renderQuoteBuilder();
    buildClientQuote();
  }
  window.scrollTo({top: 0, behavior: 'smooth'});
}

function updateCartBox() {
  const cb = document.getElementById('cart-box');
  if (selectedFormats.length === 0) {
    cb.style.display = 'none';
    if (isCartPage) toggleCartPage();
  } else {
    cb.style.display = 'inline-block';
    document.getElementById('cart-count').innerText = selectedFormats.length;
    let total = 0;
    selectedFormats.forEach(id => {
      const r = findRow(id);
      if(r) {
        if(r.id === 'ugc_basic') total += 2000;
        else total += r.base;
      }
    });
    document.getElementById('cart-total').innerText = 'Base: ' + inr(total);
  }
}

function renderFormatGrid(catFilter='all') {
  const grid = document.getElementById('format-grid'); if (!grid) return;
  const prodCats = ['social','ugc','dvc'];
  let html = '';
  for (const cat of prodCats) {
    if (catFilter !== 'all' && cat !== catFilter) continue;
    (P[cat] || []).forEach(r => {
      const sel = selectedFormats.includes(r.id) ? 'selected' : '';
      let slabText = r.slabPct != null ? '+ 30% per additional 30s' : '';
      if (r.id === 'ugc_basic') slabText = '+ ₹500 per additional 30s';

      html += `<div class="fmt-card ${sel}" data-id="${r.id}" onclick="selectFmt('${r.id}')">
        <div class="fmt-card-check">\u2713</div>
        <div class="fmt-card-name">${r.name}</div>
        <div class="fmt-card-sub">${r.sub}</div>
        <div class="fmt-card-price">${inr(r.base)}${r.id==='ugc_basic' ? ' (60s base)' : ''}</div>
        <div class="fmt-card-slab">${slabText}</div>
        <div class="fmt-card-tools">${r.tools}</div>
      </div>`;
    });
  }
  grid.innerHTML = html || '<div style="color:var(--muted);padding:20px;font-size:13px">No formats in this category.</div>';
}

function filterCat(btn, cat) {
  document.querySelectorAll('.cat-tab').forEach(t => t.classList.remove('active'));
  btn.classList.add('active');
  renderFormatGrid(cat);
}

function selectFmt(id) {
  if (selectedFormats.includes(id)) {
    selectedFormats = selectedFormats.filter(f => f !== id);
    delete itemSettings[id];
    document.querySelector(`.fmt-card[data-id="${id}"]`)?.classList.remove('selected');
  } else {
    selectedFormats.push(id);
    itemSettings[id] = { dur: 0, voice: 0, langs: 0, aspect: 0, qty: 1, addons: [] };
    document.querySelector(`.fmt-card[data-id="${id}"]`)?.classList.add('selected');
  }
  updateCartBox();
}

function updateItemSetting(id, field, val) {
  if (itemSettings[id]) {
    itemSettings[id][field] = val;
    buildClientQuote();
  }
}

function toggleItemAddon(id, v, el) {
  const st = itemSettings[id];
  if (st.addons.includes(v)) {
    st.addons = st.addons.filter(x => x !== v);
    el.classList.remove('on');
  } else {
    st.addons.push(v);
    el.classList.add('on');
  }
  buildClientQuote();
}

const clientAddons = [
  {l:'DVC Scripting (+₹18K)', v:18000}, {l:'Social Script (+₹8K)', v:8000},
  {l:'Original Music (+₹4K)', v:4000},  {l:'Cut-down Pack (+₹18K)', v:18000},
  {l:'4K Upscale (+₹15K)',    v:15000}, {l:'VFX Pack 5 shots (+₹35K)', v:35000},
];

function renderQuoteBuilder() {
  const container = document.getElementById('qb-items-container');
  if (!container) return;
  
  if (selectedFormats.length === 0) {
    container.innerHTML = '';
    return;
  }
  
  let html = '';
  selectedFormats.forEach(id => {
    const row = findRow(id);
    if (!row) return;
    const st = itemSettings[id];
    
    html += `
    <div style="background: var(--s1); border: 1px solid var(--border); border-radius: 12px; padding: 24px; margin-bottom: 24px;">
      <div class="qb-title">${row.name}</div>
      <div class="qb-grid">
        <div class="qb-field"><label>Duration</label>
          <select onchange="updateItemSetting('${id}', 'dur', parseInt(this.value))">
            <option value="0" ${st.dur===0?'selected':''}>30 seconds</option>
            <option value="1" ${st.dur===1?'selected':''}>60 seconds (1 min)</option>
            <option value="2" ${st.dur===2?'selected':''}>90 seconds</option>
            <option value="3" ${st.dur===3?'selected':''}>120 seconds</option>
          </select>
        </div>
        <div class="qb-field"><label>Voiceover</label>
          <select onchange="updateItemSetting('${id}', 'voice', parseInt(this.value))">
            <option value="0" ${st.voice===0?'selected':''}>AI voice (included)</option>
            <option value="8000" ${st.voice===8000?'selected':''}>Human VO — Hindi (₹8K base)</option>
            <option value="10000" ${st.voice===10000?'selected':''}>Human VO — English (₹10K base)</option>
            <option value="8001" ${st.voice===8001?'selected':''}>Human VO — Regional (₹8K base)</option>
          </select>
        </div>
        <div class="qb-field"><label>Language variants</label>
          <select onchange="updateItemSetting('${id}', 'langs', parseInt(this.value))">
            <option value="0" ${st.langs===0?'selected':''}>Master only</option>
            <option value="6000" ${st.langs===6000?'selected':''}>+1 language</option>
            <option value="11000" ${st.langs===11000?'selected':''}>+2 languages</option>
            <option value="16000" ${st.langs===16000?'selected':''}>+3 languages</option>
            <option value="20000" ${st.langs===20000?'selected':''}>+4 languages</option>
            <option value="28000" ${st.langs===28000?'selected':''}>+5–8 languages</option>
          </select>
        </div>
      </div>
      <div class="qb-grid2">
        <div class="qb-field"><label>Aspect ratio delivery</label>
          <select onchange="updateItemSetting('${id}', 'aspect', parseInt(this.value))">
            <option value="0" ${st.aspect===0?'selected':''}>9:16 only</option>
            <option value="2000" ${st.aspect===2000?'selected':''}>9:16 + 1:1 (+₹2K)</option>
            <option value="5000" ${st.aspect===5000?'selected':''}>Full pack — 9:16 + 1:1 + 16:9 (+₹5K)</option>
          </select>
        </div>
        <div class="qb-field"><label>Quantity</label>
          <input type="number" value="${st.qty}" min="1" max="100" oninput="updateItemSetting('${id}', 'qty', parseInt(this.value) || 1)"/>
        </div>
      </div>
      <div style="font-size:10px;color:var(--muted);font-family:var(--mono);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px">Optional add-ons</div>
      <div class="addon-row">
        ${clientAddons.map((a) => `
          <div class="c-tog ${st.addons.includes(a.v) ? 'on' : ''}" 
               onclick="toggleItemAddon('${id}', ${a.v}, this)">
            ${a.l}
          </div>
        `).join('')}
      </div>
    </div>`;
  });
  
  container.innerHTML = html;
}

function buildClientQuote() {
  if (selectedFormats.length === 0) return;
  
  let lines = [];
  let totalQty = 0;
  let totalPv = 0;

  selectedFormats.forEach(id => {
    const row = findRow(id);
    if (!row) return;
    const st = itemSettings[id];
    const ds = st.dur;
    const qty = st.qty;
    totalQty += qty;
    
    const dl = ['30s','60s','90s','120s'][ds];
    
    let itemBaseCost = row.base;
    if (row.id === 'ugc_basic') {
      if (ds === 0) itemBaseCost = 1500;
      else if (ds === 1) itemBaseCost = 2000;
      else if (ds === 2) itemBaseCost = 2500;
      else if (ds === 3) itemBaseCost = 3000;
    } else {
      const si = slabInr(row) * ds;
      itemBaseCost = row.base + si;
    }
    
    let vBase = st.voice === 8001 ? 8000 : st.voice;
    let voiceCost = 0;
    if (vBase > 0) {
       voiceCost = vBase + (Math.round(vBase * 0.30) * ds);
    }
    
    let addonsTotal = 0;
    st.addons.forEach(a => addonsTotal += a);
    
    let singleCost = itemBaseCost + voiceCost + st.langs + st.aspect + addonsTotal;
    let lineTotal = singleCost * qty;
    
    lines.push([`${row.name} (${dl})${qty > 1 ? ` x${qty}` : ''}`, lineTotal]);
    totalPv += lineTotal;
  });

  const disc = volDisc(totalQty);
  const pv   = totalPv * (1 - disc);
  const tot  = pv; 
  const gst  = tot * 1.18;
  const moq  = tot >= 250000;
  
  if (disc > 0) {
    lines.push(['Volume discount (' + Math.round(disc*100) + '%)', -Math.round(totalPv*disc)]);
  }

  const el = document.getElementById('client-result'); if (!el) return;
  el.innerHTML = `<div class="result-card">
    <div class="r-head"><div class="r-title">Your indicative quote</div></div>
    ${lines.map(([l,v]) => {
      const col = v<0 ? 'color:var(--green)' : '';
      const d   = v<0 ? '\u2212' + inr(-v) : inr(v);
      return `<div class="r-line"><span class="r-lab">${l}</span><span class="r-val" style="${col}">${d}</span></div>`;
    }).join('')}
    <div class="r-total"><div class="r-total-lab">Total (ex GST)</div><div class="r-total-val">${inr(tot)}</div></div>
    <div class="r-gst"><span>Incl. 18% GST</span><span>${inr(gst)}</span></div>
    <div class="r-metrics">
      <div class="r-met"><div class="r-met-val">${inr(tot/totalQty)}</div><div class="r-met-lab">avg per video</div></div>
      <div class="r-met"><div class="r-met-val">${disc>0?Math.round(disc*100)+'%':'None'}</div><div class="r-met-lab">volume disc.</div></div>
      <div class="r-met"><div class="r-met-val">7 days</div><div class="r-met-lab">delivery</div></div>
    </div>
    <div class="moq-banner ${moq?'moq-ok':'moq-fail'}" style="margin-top:12px">
      ${moq ? '\u2713 Minimum ₹2,50,000 per Purchase Order. All productions must be consumed within 90 days of PO date. Unused production value does not carry forward beyond this window.'
             : '\u2717 Minimum ₹2,50,000 per Purchase Order. All productions must be consumed within 90 days of PO date. Unused production value does not carry forward beyond this window.'}
    </div>
  </div>`;
}

renderFormatGrid('all');
</script>

<?php endif; ?>
</body>
</html>
