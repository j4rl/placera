<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (plc_current_user()) {
    plc_redirect('app.php');
}

$err = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['_csrf'] ?? '');
    if (!hash_equals((string)($_SESSION['plc_csrf'] ?? ''), $csrf)) {
        $err = 'Ogiltig begäran. Ladda om sidan och försök igen.';
    } else {
        $db = plc_db();
        $identity = trim((string)($_POST['identity'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        $stmt = $db->prepare('SELECT id, password_hash, status FROM plc_users WHERE username = ? OR email = ? LIMIT 1');
        $stmt->bind_param('ss', $identity, $identity);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row || !password_verify($password, (string)$row['password_hash'])) {
            $err = 'Fel inloggningsuppgifter.';
        } else {
            $status = (string)$row['status'];
            if ($status === 'pending') {
                $err = 'Kontot väntar på admin-godkännande.';
            } elseif ($status === 'rejected') {
                $err = 'Ansökan har avslagits.';
            } elseif ($status === 'disabled') {
                $err = 'Kontot är avstängt.';
            } else {
                plc_login_user((int)$row['id']);
                plc_redirect('app.php');
            }
        }
    }
}

$registered = isset($_GET['registered']) && $_GET['registered'] === '1';
if ($registered) {
    $ok = 'Ansökan skickad. En admin behöver godkänna ditt konto innan inloggning.';
}

$csrf = plc_csrf_token();
?>
<!DOCTYPE html>
<html lang="sv">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Placera - Logga in</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root{color-scheme:light dark;--bg:#f4f4f0;--surface:#fff;--surface2:#f0f0eb;--hero1:#f8f8f4;--hero2:#ecece5;--border:#d0d0c8;--text:#1a1a1f;--muted:#666670;--accent:#5a8a10}
    @media (prefers-color-scheme: dark){
      :root{--bg:#0f0f11;--surface:#1a1a1f;--surface2:#242429;--hero1:#1f1f25;--hero2:#17171c;--border:#3a3a44;--text:#f0f0f5;--muted:#a0a0b2;--accent:#c8f060}
    }
    *{box-sizing:border-box} body{margin:0;background:var(--bg);color:var(--text);font-family:'Poppins',system-ui,sans-serif}
    .wrap{min-height:100vh;display:grid;place-items:center;padding:24px}
    .card{width:min(920px,100%);display:grid;grid-template-columns:1.1fr .9fr;background:var(--surface);border:1px solid var(--border);border-radius:14px;overflow:hidden}
    .hero{padding:34px;background:linear-gradient(145deg,var(--hero1),var(--hero2))}
    .hero h1{margin:0 0 10px;font-size:2rem}
    .hero p{margin:0;color:var(--muted);line-height:1.5}
    .panel{padding:28px}
    h2{margin:0 0 14px;font-size:1.1rem}
    form{margin-bottom:14px}
    .fg{margin-bottom:10px}
    label{display:block;font-size:.74rem;color:var(--muted);margin-bottom:4px}
    input{width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:var(--surface2);color:var(--text)}
    button{border:0;border-radius:8px;padding:10px 14px;font-weight:600;cursor:pointer}
    .btn-primary{background:var(--accent);color:#111;width:100%}
    .msg{padding:10px 12px;border-radius:8px;font-size:.85rem;margin-bottom:12px}
    .msg.err{background:#fdecec;border:1px solid #e4b1b1;color:#7f1f1f}
    .msg.ok{background:#ecf8df;border:1px solid #b9d78f;color:#365d0a}
    .small{font-size:.78rem;color:var(--muted);margin:0}
    .register-link{display:inline-block;margin-top:8px;color:var(--accent);font-weight:600;text-decoration:none}
    .register-link:hover{text-decoration:underline}
    @media (max-width:900px){.card{grid-template-columns:1fr}}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div class="hero">
        <h1>Placera</h1>
        <p>Placera är ett verktyg för lärare som gör det snabbt att skapa, slumpa, justera och skriva ut klassrumsplaceringar.</p><br><br>
        <p><em>Skapat av Charlie Jarl <strong>&copy;j4rl</strong></em></p>
      </div>
      <div class="panel">
        <?php if ($err !== ''): ?>
          <div class="msg err"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($ok !== ''): ?>
          <div class="msg ok"><?= htmlspecialchars($ok, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <h2>Logga in</h2>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
          <div class="fg">
            <label>Användarnamn eller e-post</label>
            <input type="text" name="identity" required>
          </div>
          <div class="fg">
            <label>Lösenord</label>
            <input type="password" name="password" required>
          </div>
          <button class="btn-primary" type="submit">Logga in</button>
        </form>
        <p class="small">Saknar du konto?</p>
        <a class="register-link" href="register.php">Skicka registreringsansökan</a>
      </div>
    </div>
  </div>
</body>
</html>
