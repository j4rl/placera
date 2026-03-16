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
        $action = (string)($_POST['action'] ?? '');
        $db = plc_db();

        if ($action === 'register') {
            $username = trim((string)($_POST['username'] ?? ''));
            $fullName = trim((string)($_POST['full_name'] ?? ''));
            $emailRaw = trim((string)($_POST['email'] ?? ''));
            $email = function_exists('mb_strtolower') ? mb_strtolower($emailRaw) : strtolower($emailRaw);
            $password = (string)($_POST['password'] ?? '');
            $password2 = (string)($_POST['password2'] ?? '');
            $fullNameLen = function_exists('mb_strlen') ? mb_strlen($fullName) : strlen($fullName);

            if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
                $err = 'Användarnamn måste vara 3-50 tecken (A-Z, 0-9, _, -, .).';
            } elseif ($fullNameLen < 2) {
                $err = 'Ange ditt riktiga namn.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $err = 'Ogiltig e-postadress.';
            } elseif (strlen($password) < 8) {
                $err = 'Lösenord måste vara minst 8 tecken.';
            } elseif (!hash_equals($password, $password2)) {
                $err = 'Lösenorden matchar inte.';
            } else {
                $stmt = $db->prepare('SELECT id FROM plc_users WHERE username = ? OR email = ? LIMIT 1');
                $stmt->bind_param('ss', $username, $email);
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_assoc();
                if ($exists) {
                    $err = 'Användarnamn eller e-post finns redan.';
                } else {
                    $countRes = $db->query('SELECT COUNT(*) AS c FROM plc_users');
                    $countRow = $countRes->fetch_assoc();
                    $isFirst = ((int)($countRow['c'] ?? 0) === 0);

                    $role = $isFirst ? 'admin' : 'teacher';
                    $status = $isFirst ? 'approved' : 'pending';
                    $approvedAt = $isFirst ? date('Y-m-d H:i:s') : null;
                    $hash = password_hash($password, PASSWORD_DEFAULT);

                    $stmt = $db->prepare(
                        'INSERT INTO plc_users (username, full_name, email, password_hash, role, status, approved_by_user_id, approved_at)
                         VALUES (?, ?, ?, ?, ?, ?, NULL, ?)'
                    );
                    $stmt->bind_param('sssssss', $username, $fullName, $email, $hash, $role, $status, $approvedAt);
                    $stmt->execute();
                    $newId = (int)$db->insert_id;

                    if ($isFirst) {
                        plc_login_user($newId);
                        plc_redirect('app.php');
                    }
                    $ok = 'Ansökan skickad. En admin behöver godkänna ditt konto innan inloggning.';
                }
            }
        } elseif ($action === 'login') {
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
    :root{--bg:#f4f4f0;--surface:#fff;--surface2:#f0f0eb;--border:#d0d0c8;--text:#1a1a1f;--muted:#666670;--accent:#5a8a10;--danger:#b53636}
    *{box-sizing:border-box} body{margin:0;background:var(--bg);color:var(--text);font-family:'Poppins',system-ui,sans-serif}
    .wrap{min-height:100vh;display:grid;place-items:center;padding:24px}
    .card{width:min(920px,100%);display:grid;grid-template-columns:1.1fr .9fr;background:var(--surface);border:1px solid var(--border);border-radius:14px;overflow:hidden}
    .hero{padding:34px;background:linear-gradient(145deg,#f8f8f4,#ecece5)}
    .hero h1{margin:0 0 10px;font-size:2rem}
    .hero p{margin:0;color:var(--muted);line-height:1.5}
    .panel{padding:28px}
    h2{margin:0 0 14px;font-size:1.1rem}
    form{margin-bottom:20px}
    .fg{margin-bottom:10px}
    label{display:block;font-size:.74rem;color:var(--muted);margin-bottom:4px}
    input{width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:var(--surface2)}
    button{border:0;border-radius:8px;padding:10px 14px;font-weight:600;cursor:pointer}
    .btn-primary{background:var(--accent);color:#111;width:100%}
    .line{height:1px;background:var(--border);margin:16px 0}
    .msg{padding:10px 12px;border-radius:8px;font-size:.85rem;margin-bottom:12px}
    .msg.err{background:#fdecec;border:1px solid #e4b1b1;color:#7f1f1f}
    .msg.ok{background:#ecf8df;border:1px solid #b9d78f;color:#365d0a}
    .small{font-size:.78rem;color:var(--muted)}
    @media (max-width:900px){.card{grid-template-columns:1fr}}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div class="hero">
        <h1>Placera</h1>
        <p>Verktyg för klassplacering. Logga in eller skapa en ansökan. Nya användare måste godkännas av admin innan de får tillgång.</p>
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
          <input type="hidden" name="action" value="login">
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

        <div class="line"></div>

        <h2>Skicka ansökan</h2>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="action" value="register">
          <div class="fg">
            <label>Användarnamn</label>
            <input type="text" name="username" required>
          </div>
          <div class="fg">
            <label>Riktigt namn</label>
            <input type="text" name="full_name" required>
          </div>
          <div class="fg">
            <label>E-post</label>
            <input type="email" name="email" required>
          </div>
          <div class="fg">
            <label>Lösenord</label>
            <input type="password" name="password" required minlength="8">
          </div>
          <div class="fg">
            <label>Bekräfta lösenord</label>
            <input type="password" name="password2" required minlength="8">
          </div>
          <button class="btn-primary" type="submit">Skicka ansökan</button>
        </form>
        <p class="small">Första registrerade användaren blir admin automatiskt.</p>
      </div>
    </div>
  </div>
</body>
</html>
