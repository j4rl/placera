<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/password_reset.php';

if (plc_current_user()) {
    plc_redirect('app.php');
}

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$err = '';
$ok = '';

if ($token === '') {
    $err = 'Ogiltig eller saknad återställningslänk.';
}

$db = plc_db();
plc_ensure_multischool_schema($db);
$resetRow = $token !== '' ? plc_password_reset_find_active_by_token($db, $token) : null;
if (!$resetRow && $err === '') {
    $err = 'Återställningslänken är ogiltig eller har gått ut.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $err === '' && $resetRow) {
    $csrf = (string)($_POST['_csrf'] ?? '');
    $sessionCsrf = (string)($_SESSION['plc_csrf'] ?? '');
    if ($sessionCsrf === '' || $csrf === '' || !hash_equals($sessionCsrf, $csrf)) {
        $err = 'Ogiltig begäran. Ladda om sidan och försök igen.';
    } else {
        $password = (string)($_POST['password'] ?? '');
        $password2 = (string)($_POST['password2'] ?? '');
        if (strlen($password) < 8) {
            $err = 'Lösenord måste vara minst 8 tecken.';
        } elseif (!hash_equals($password, $password2)) {
            $err = 'Lösenorden matchar inte.';
        } else {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $okReset = plc_password_reset_use($db, (int)$resetRow['id'], (int)$resetRow['user_id'], $passwordHash);
            if ($okReset) {
                plc_redirect('index.php?reset=1');
            }
            $err = 'Kunde inte återställa lösenordet.';
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
  <title>Placera - Återställ lösenord</title>
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
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1>Återställ lösenord</h1>
      <p>Välj ett nytt lösenord för ditt konto.</p>
      <?php if ($err !== ''): ?>
        <div class="msg err"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <?php if ($ok !== ''): ?>
        <div class="msg ok"><?= htmlspecialchars($ok, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <?php if ($err === '' && $resetRow): ?>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
        <div class="fg">
          <label>Nytt lösenord</label>
          <input type="password" name="password" required minlength="8">
        </div>
        <div class="fg">
          <label>Bekräfta nytt lösenord</label>
          <input type="password" name="password2" required minlength="8">
        </div>
        <button type="submit">Spara nytt lösenord</button>
      </form>
      <?php endif; ?>

      <a class="back" href="index.php">← Tillbaka till inloggning</a>
    </div>
  </div>
</body>
</html>
