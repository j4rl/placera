<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/crypto.php';
require_once __DIR__ . '/includes/twofa.php';

if (plc_current_user()) {
    plc_redirect('app.php');
}

function plc_clear_2fa_enroll_session(): void
{
    unset($_SESSION['plc_2fa_enroll_user_id'], $_SESSION['plc_2fa_enroll_time'], $_SESSION['plc_2fa_enroll_secret']);
}

$pendingUserId = isset($_SESSION['plc_2fa_enroll_user_id']) ? (int)$_SESSION['plc_2fa_enroll_user_id'] : 0;
$pendingAt = isset($_SESSION['plc_2fa_enroll_time']) ? (int)$_SESSION['plc_2fa_enroll_time'] : 0;
if ($pendingUserId <= 0 || $pendingAt <= 0 || ($pendingAt + (15 * 60)) < time()) {
    plc_clear_2fa_enroll_session();
    plc_redirect('index.php');
}

$db = plc_db();
plc_ensure_multischool_schema($db);

$stmt = $db->prepare(
    'SELECT u.id, u.username, u.email, u.full_name, u.status, u.twofa_enabled,
            s.status AS school_status, s.require_2fa
     FROM plc_users u
     LEFT JOIN plc_schools s ON s.id = u.school_id
     WHERE u.id = ?
     LIMIT 1'
);
$stmt->bind_param('i', $pendingUserId);
$stmt->execute();
$userRow = $stmt->get_result()->fetch_assoc();

if (
    !$userRow ||
    (string)($userRow['status'] ?? '') !== 'approved' ||
    (string)($userRow['school_status'] ?? '') !== 'approved' ||
    (int)($userRow['require_2fa'] ?? 0) !== 1 ||
    (int)($userRow['twofa_enabled'] ?? 0) === 1
) {
    plc_clear_2fa_enroll_session();
    plc_redirect('index.php');
}

$err = '';
$setupSecret = isset($_SESSION['plc_2fa_enroll_secret']) ? (string)$_SESSION['plc_2fa_enroll_secret'] : '';
if ($setupSecret === '') {
    $setupSecret = plc_twofa_generate_secret();
    $_SESSION['plc_2fa_enroll_secret'] = $setupSecret;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['_csrf'] ?? '');
    $sessionCsrf = (string)($_SESSION['plc_csrf'] ?? '');
    if ($sessionCsrf === '' || $csrf === '' || !hash_equals($sessionCsrf, $csrf)) {
        $err = 'Ogiltig begäran. Ladda om sidan och försök igen.';
    } else {
        $action = (string)($_POST['action'] ?? 'confirm');
        if ($action === 'regenerate') {
            $setupSecret = plc_twofa_generate_secret();
            $_SESSION['plc_2fa_enroll_secret'] = $setupSecret;
        } elseif ($action === 'confirm') {
            $code = (string)($_POST['code'] ?? '');
            if (!plc_twofa_verify_code($setupSecret, $code)) {
                $err = 'Fel verifieringskod. Försök igen.';
            } else {
                $encryptedSecret = plc_encrypt_text($setupSecret);
                $enabled = 1;
                $stmt = $db->prepare(
                    'UPDATE plc_users
                     SET twofa_enabled = ?, twofa_secret = ?, twofa_enabled_at = NOW(), updated_at = NOW()
                     WHERE id = ?'
                );
                $stmt->bind_param('isi', $enabled, $encryptedSecret, $pendingUserId);
                $stmt->execute();

                plc_clear_2fa_enroll_session();
                plc_login_user($pendingUserId);
                plc_redirect('app.php');
            }
        } else {
            plc_clear_2fa_enroll_session();
            plc_redirect('index.php');
        }
    }
}

$csrf = plc_csrf_token();
$accountLabel = trim((string)$userRow['email']) !== '' ? (string)$userRow['email'] : (string)$userRow['username'];
$otpauthUri = plc_twofa_otpauth_uri('Placera', $accountLabel, $setupSecret);
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . rawurlencode($otpauthUri);
?>
<!DOCTYPE html>
<html lang="sv">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Placera - Aktivera 2FA</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root{color-scheme:light dark;--bg:#f4f4f0;--surface:#fff;--surface2:#f0f0eb;--border:#d0d0c8;--text:#1a1a1f;--muted:#666670;--accent:#5a8a10}
    @media (prefers-color-scheme: dark){:root{--bg:#0f0f11;--surface:#1a1a1f;--surface2:#242429;--border:#3a3a44;--text:#f0f0f5;--muted:#a0a0b2;--accent:#c8f060}}
    *{box-sizing:border-box}
    body{margin:0;background:var(--bg);color:var(--text);font-family:'Poppins',system-ui,sans-serif}
    .wrap{min-height:100vh;display:grid;place-items:center;padding:24px}
    .card{width:min(620px,100%);background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:28px}
    h1{margin:0 0 8px;font-size:1.5rem}
    p{margin:0 0 14px;color:var(--muted)}
    .fg{margin-bottom:12px}
    label{display:block;font-size:.74rem;color:var(--muted);margin-bottom:4px}
    input{width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:var(--surface2);color:var(--text)}
    .btn{border:0;border-radius:8px;padding:10px 14px;font-weight:600;cursor:pointer}
    .btn-primary{background:var(--accent);color:#111}
    .btn-secondary{background:var(--surface2);color:var(--text);border:1px solid var(--border)}
    .row{display:flex;gap:10px;flex-wrap:wrap}
    .msg{padding:10px 12px;border-radius:8px;font-size:.85rem;margin-bottom:12px;background:#fdecec;border:1px solid #e4b1b1;color:#7f1f1f}
    .qr{display:flex;justify-content:center;margin-bottom:12px}
    .hint{font-size:.78rem;color:var(--muted)}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1>Aktivera 2FA</h1>
      <p>Din skola kräver tvåfaktorsautentisering. Slutför detta steg för att logga in.</p>
      <?php if ($err !== ''): ?>
        <div class="msg"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <div class="qr">
        <img src="<?= htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8') ?>" alt="QR-kod för 2FA">
      </div>
      <div class="fg">
        <label>Hemlig nyckel</label>
        <input type="text" readonly value="<?= htmlspecialchars($setupSecret, ENT_QUOTES, 'UTF-8') ?>">
        <p class="hint">Om QR inte fungerar, skriv in nyckeln manuellt i din autentiseringsapp.</p>
      </div>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="confirm">
        <div class="fg">
          <label>Verifieringskod (6 siffror)</label>
          <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required>
        </div>
        <div class="row">
          <button class="btn btn-primary" type="submit">Bekräfta och logga in</button>
        </div>
      </form>
      <form method="post" style="margin-top:10px">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="regenerate">
        <button class="btn btn-secondary" type="submit">Generera ny nyckel</button>
      </form>
    </div>
  </div>
</body>
</html>
