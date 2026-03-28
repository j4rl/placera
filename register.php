<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (plc_current_user()) {
    plc_redirect('app.php');
}

$err = '';
$ok = '';
$old = [
    'username' => '',
    'full_name' => '',
    'email' => '',
    'school_name' => '',
    'account_type' => 'teacher',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['_csrf'] ?? '');
    $sessionCsrf = (string)($_SESSION['plc_csrf'] ?? '');
    if ($sessionCsrf === '' || $csrf === '' || !hash_equals($sessionCsrf, $csrf)) {
        $err = 'Ogiltig begäran. Ladda om sidan och försök igen.';
    } else {
        $db = plc_db();
        plc_ensure_multischool_schema($db);

        $username = trim((string)($_POST['username'] ?? ''));
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $emailRaw = trim((string)($_POST['email'] ?? ''));
        $email = function_exists('mb_strtolower') ? mb_strtolower($emailRaw) : strtolower($emailRaw);
        $schoolName = trim((string)($_POST['school_name'] ?? ''));
        $accountType = trim((string)($_POST['account_type'] ?? 'teacher'));
        $password = (string)($_POST['password'] ?? '');
        $password2 = (string)($_POST['password2'] ?? '');
        $fullNameLen = function_exists('mb_strlen') ? mb_strlen($fullName) : strlen($fullName);
        $schoolNameLen = function_exists('mb_strlen') ? mb_strlen($schoolName) : strlen($schoolName);

        $old = [
            'username' => $username,
            'full_name' => $fullName,
            'email' => $emailRaw,
            'school_name' => $schoolName,
            'account_type' => $accountType,
        ];

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

                if (!$isFirst && !in_array($accountType, ['teacher', 'school_admin'], true)) {
                    $accountType = 'teacher';
                }
                if (!$isFirst && $schoolNameLen < 2) {
                    $err = 'Ange skolans namn.';
                } elseif (!$isFirst && $schoolNameLen > 190) {
                    $err = 'Skolans namn är för långt.';
                }

                if ($err === '') {
                    $schoolId = null;
                    if (!$isFirst) {
                        $stmt = $db->prepare(
                            'SELECT id
                             FROM plc_schools
                             WHERE LOWER(name) = LOWER(?)
                             LIMIT 1'
                        );
                        $stmt->bind_param('s', $schoolName);
                        $stmt->execute();
                        $school = $stmt->get_result()->fetch_assoc();
                        if ($school) {
                            $schoolId = (int)$school['id'];
                        } else {
                            $schoolStatus = 'pending';
                            $stmt = $db->prepare(
                                'INSERT INTO plc_schools (name, status, approved_by_user_id, approved_at)
                                 VALUES (?, ?, NULL, NULL)'
                            );
                            $stmt->bind_param('ss', $schoolName, $schoolStatus);
                            $stmt->execute();
                            $schoolId = (int)$db->insert_id;
                        }
                    }

                    $role = $isFirst ? 'superadmin' : $accountType;
                    $status = $isFirst ? 'approved' : 'pending';
                    $approvedAt = $isFirst ? date('Y-m-d H:i:s') : null;
                    $hash = password_hash($password, PASSWORD_DEFAULT);

                    if ($isFirst) {
                        $stmt = $db->prepare(
                            'INSERT INTO plc_users (
                                school_id, username, full_name, email, password_hash, role, status, approved_by_user_id, approved_at
                             ) VALUES (NULL, ?, ?, ?, ?, ?, ?, NULL, ?)'
                        );
                        $stmt->bind_param('sssssss', $username, $fullName, $email, $hash, $role, $status, $approvedAt);
                    } else {
                        $stmt = $db->prepare(
                            'INSERT INTO plc_users (
                                school_id, username, full_name, email, password_hash, role, status, approved_by_user_id, approved_at
                             ) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?)'
                        );
                        $stmt->bind_param('isssssss', $schoolId, $username, $fullName, $email, $hash, $role, $status, $approvedAt);
                    }
                    $stmt->execute();
                    $newId = (int)$db->insert_id;

                    if ($isFirst) {
                        plc_login_user($newId);
                        plc_redirect('app.php');
                    }
                    plc_redirect('index.php?registered=1');
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
  <title>Placera - Registrering</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root{color-scheme:light dark;--bg:#f4f4f0;--surface:#fff;--surface2:#f0f0eb;--border:#d0d0c8;--text:#1a1a1f;--muted:#666670;--accent:#5a8a10}
    @media (prefers-color-scheme: dark){
      :root{--bg:#0f0f11;--surface:#1a1a1f;--surface2:#242429;--border:#3a3a44;--text:#f0f0f5;--muted:#a0a0b2;--accent:#c8f060}
    }
    *{box-sizing:border-box}
    body{margin:0;background:var(--bg);color:var(--text);font-family:'Poppins',system-ui,sans-serif}
    .wrap{min-height:100vh;display:grid;place-items:center;padding:24px}
    .card{width:min(560px,100%);background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:28px}
    h1{margin:0 0 8px;font-size:1.5rem}
    p{margin:0 0 16px;color:var(--muted)}
    .fg{margin-bottom:10px}
    label{display:block;font-size:.74rem;color:var(--muted);margin-bottom:4px}
    input,select{width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:var(--surface2);color:var(--text)}
    button{border:0;border-radius:8px;padding:10px 14px;font-weight:600;cursor:pointer;background:var(--accent);color:#111;width:100%}
    .msg{padding:10px 12px;border-radius:8px;font-size:.85rem;margin-bottom:12px}
    .msg.err{background:#fdecec;border:1px solid #e4b1b1;color:#7f1f1f}
    .back{display:inline-block;margin-top:12px;color:var(--accent);text-decoration:none;font-weight:600}
    .back:hover{text-decoration:underline}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1>Skicka registreringsansökan</h1>
      <p>Ansökan granskas innan kontot aktiveras.</p>
      <?php if ($err !== ''): ?>
        <div class="msg err"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <?php if ($ok !== ''): ?>
        <div class="msg ok"><?= htmlspecialchars($ok, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <div class="fg">
          <label>Användarnamn</label>
          <input type="text" name="username" value="<?= htmlspecialchars($old['username'], ENT_QUOTES, 'UTF-8') ?>" required>
        </div>
        <div class="fg">
          <label>Riktigt namn</label>
          <input type="text" name="full_name" value="<?= htmlspecialchars($old['full_name'], ENT_QUOTES, 'UTF-8') ?>" required>
        </div>
        <div class="fg">
          <label>E-post</label>
          <input type="email" name="email" value="<?= htmlspecialchars($old['email'], ENT_QUOTES, 'UTF-8') ?>" required>
        </div>
        <div class="fg">
          <label>Skola</label>
          <input type="text" name="school_name" value="<?= htmlspecialchars($old['school_name'], ENT_QUOTES, 'UTF-8') ?>" required>
        </div>
        <div class="fg">
          <label>Kontotyp</label>
          <select name="account_type">
            <option value="teacher" <?= $old['account_type'] === 'teacher' ? 'selected' : '' ?>>Lärare</option>
            <option value="school_admin" <?= $old['account_type'] === 'school_admin' ? 'selected' : '' ?>>Skoladmin</option>
          </select>
        </div>
        <div class="fg">
          <label>Lösenord</label>
          <input type="password" name="password" required minlength="8">
        </div>
        <div class="fg">
          <label>Bekräfta lösenord</label>
          <input type="password" name="password2" required minlength="8">
        </div>
        <button type="submit">Skicka ansökan</button>
      </form>
      <a class="back" href="index.php">Tillbaka till inloggning</a>
    </div>
  </div>
</body>
</html>
