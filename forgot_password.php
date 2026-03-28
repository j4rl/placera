<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/password_reset.php';

if (plc_current_user()) {
    plc_redirect('app.php');
}

$msg = '';
$err = '';
$debugResetLink = '';

function plc_is_local_request(): bool
{
    $serverName = strtolower((string)($_SERVER['SERVER_NAME'] ?? ''));
    $remoteAddr = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return in_array($serverName, ['localhost', '127.0.0.1'], true)
        || in_array($remoteAddr, ['127.0.0.1', '::1'], true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['_csrf'] ?? '');
    $sessionCsrf = (string)($_SESSION['plc_csrf'] ?? '');
    if ($sessionCsrf === '' || $csrf === '' || !hash_equals($sessionCsrf, $csrf)) {
        $err = 'Ogiltig begäran. Ladda om sidan och försök igen.';
    } else {
        $identityRaw = trim((string)($_POST['identity'] ?? ''));
        $identity = function_exists('mb_strtolower') ? mb_strtolower($identityRaw) : strtolower($identityRaw);

        if ($identity === '') {
            $err = 'Ange användarnamn eller e-post.';
        } else {
            $db = plc_db();
            plc_ensure_multischool_schema($db);
            $stmt = $db->prepare(
                'SELECT u.id, u.username, u.email, u.status, u.role, u.school_id, s.status AS school_status
                 FROM plc_users u
                 LEFT JOIN plc_schools s ON s.id = u.school_id
                 WHERE LOWER(u.username) = LOWER(?) OR LOWER(u.email) = LOWER(?)
                 LIMIT 1'
            );
            $stmt->bind_param('ss', $identity, $identity);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) {
                $status = (string)($row['status'] ?? '');
                $role = (string)($row['role'] ?? '');
                $schoolId = (int)($row['school_id'] ?? 0);
                $schoolStatus = (string)($row['school_status'] ?? '');
                $eligible = $status === 'approved'
                    && ($role === 'superadmin' || ($schoolId > 0 && $schoolStatus === 'approved'));
                if ($eligible) {
                    $token = plc_password_reset_create($db, (int)$row['id'], 60);
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
                    $base = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')), '/\\');
                    $path = ($base === '' || $base === '/') ? '' : $base;
                    $resetUrl = $scheme . '://' . $host . $path . '/reset_password.php?token=' . rawurlencode($token);
                    $mailTo = trim((string)($row['email'] ?? ''));
                    if ($mailTo !== '' && filter_var($mailTo, FILTER_VALIDATE_EMAIL)) {
                        $subject = 'Placera - Återställ lösenord';
                        $body = "Hej,\n\nDu begärde återställning av lösenord i Placera.\n"
                            . "Öppna länken nedan för att välja nytt lösenord (giltig i 60 minuter):\n\n"
                            . $resetUrl . "\n\n"
                            . "Om du inte begärde detta kan du ignorera mailet.";
                        @mail($mailTo, $subject, $body);
                    }
                    if (plc_is_local_request()) {
                        $debugResetLink = $resetUrl;
                    }
                }
            }
            $msg = 'Om kontot finns får du instruktioner för återställning av lösenord.';
        }
    }
}

$csrf = plc_csrf_token();
?>
<!DOCTYPE html>
<html lang="sv">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Placera - Glömt lösenord</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root{color-scheme:light dark;--bg:#f4f4f0;--surface:#fff;--surface2:#f0f0eb;--border:#d0d0c8;--text:#1a1a1f;--muted:#666670;--accent:#5a8a10}
    @media (prefers-color-scheme: dark){:root{--bg:#0f0f11;--surface:#1a1a1f;--surface2:#242429;--border:#3a3a44;--text:#f0f0f5;--muted:#a0a0b2;--accent:#c8f060}}
    *{box-sizing:border-box}
    body{margin:0;background:var(--bg);color:var(--text);font-family:'Poppins',system-ui,sans-serif}
    .wrap{min-height:100vh;display:grid;place-items:center;padding:24px}
    .card{width:min(560px,100%);background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:28px}
    h1{margin:0 0 8px;font-size:1.5rem}
    p{margin:0 0 16px;color:var(--muted)}
    .fg{margin-bottom:10px}
    label{display:block;font-size:.74rem;color:var(--muted);margin-bottom:4px}
    input{width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:var(--surface2);color:var(--text)}
    button{border:0;border-radius:8px;padding:10px 14px;font-weight:600;cursor:pointer;background:var(--accent);color:#111;width:100%}
    .msg{padding:10px 12px;border-radius:8px;font-size:.85rem;margin-bottom:12px}
    .msg.err{background:#fdecec;border:1px solid #e4b1b1;color:#7f1f1f}
    .msg.ok{background:#ecf8df;border:1px solid #b9d78f;color:#365d0a}
    .back{display:inline-block;margin-top:12px;color:var(--accent);text-decoration:none;font-weight:600}
    .back:hover{text-decoration:underline}
    .debug{margin-top:12px;padding:10px 12px;border-radius:8px;background:#fff7d6;border:1px solid #e5d28a;color:#6a5600;word-break:break-all}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1>Glömt lösenord</h1>
      <p>Ange användarnamn eller e-post så skickas en återställningslänk om kontot finns.</p>
      <?php if ($err !== ''): ?>
        <div class="msg err"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <?php if ($msg !== ''): ?>
        <div class="msg ok"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <div class="fg">
          <label>Användarnamn eller e-post</label>
          <input type="text" name="identity" required>
        </div>
        <button type="submit">Skicka återställning</button>
      </form>
      <?php if ($debugResetLink !== ''): ?>
        <div class="debug">
          Lokal utvecklingslänk (visas endast lokalt):<br>
          <a href="<?= htmlspecialchars($debugResetLink, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($debugResetLink, ENT_QUOTES, 'UTF-8') ?></a>
        </div>
      <?php endif; ?>
      <a class="back" href="index.php">← Tillbaka till inloggning</a>
    </div>
  </div>
</body>
</html>
