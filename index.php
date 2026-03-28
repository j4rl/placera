<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/crypto.php';
require_once __DIR__ . '/includes/twofa.php';

function plc_login_throttle_key(string $identity): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $normalizedIdentity = function_exists('mb_strtolower')
        ? mb_strtolower(trim($identity))
        : strtolower(trim($identity));
    return hash('sha256', $ip . '|' . $normalizedIdentity);
}

function plc_login_read_throttle_bucket(): array
{
    $bucket = $_SESSION['plc_login_throttle'] ?? [];
    return is_array($bucket) ? $bucket : [];
}

function plc_login_cleanup_throttle_bucket(array $bucket, int $now): array
{
    $maxAge = 60 * 60;
    foreach ($bucket as $key => $entry) {
        if (!is_array($entry)) {
            unset($bucket[$key]);
            continue;
        }
        $last = (int)($entry['last'] ?? 0);
        $lockUntil = (int)($entry['lock_until'] ?? 0);
        if ($last > 0 && ($last + $maxAge) < $now && $lockUntil < $now) {
            unset($bucket[$key]);
        }
    }
    return $bucket;
}

function plc_login_wait_seconds(string $key): int
{
    $now = time();
    $bucket = plc_login_cleanup_throttle_bucket(plc_login_read_throttle_bucket(), $now);
    $_SESSION['plc_login_throttle'] = $bucket;
    $entry = $bucket[$key] ?? null;
    if (!is_array($entry)) {
        return 0;
    }
    $lockUntil = (int)($entry['lock_until'] ?? 0);
    return $lockUntil > $now ? ($lockUntil - $now) : 0;
}

function plc_login_register_failure(string $key): int
{
    $now = time();
    $window = 15 * 60;
    $bucket = plc_login_cleanup_throttle_bucket(plc_login_read_throttle_bucket(), $now);
    $entry = $bucket[$key] ?? [
        'count' => 0,
        'first' => $now,
        'last' => $now,
        'lock_until' => 0,
    ];
    if (!is_array($entry)) {
        $entry = [
            'count' => 0,
            'first' => $now,
            'last' => $now,
            'lock_until' => 0,
        ];
    }

    $first = (int)($entry['first'] ?? $now);
    if (($first + $window) < $now) {
        $entry['count'] = 0;
        $entry['first'] = $now;
    }

    $entry['count'] = (int)($entry['count'] ?? 0) + 1;
    $entry['last'] = $now;

    $wait = 0;
    if ($entry['count'] >= 5) {
        $penaltyStep = min(6, $entry['count'] - 5);
        $wait = (int)min(300, 5 * (2 ** max(0, $penaltyStep - 1)));
        $entry['lock_until'] = $now + $wait;
    } else {
        $entry['lock_until'] = 0;
    }

    $bucket[$key] = $entry;
    $_SESSION['plc_login_throttle'] = $bucket;
    return $wait;
}

function plc_login_clear_throttle(string $key): void
{
    $bucket = plc_login_read_throttle_bucket();
    unset($bucket[$key]);
    $_SESSION['plc_login_throttle'] = $bucket;
}

function plc_login_slowdown(): void
{
    usleep(random_int(150000, 320000));
}

function plc_clear_login_2fa_state(): void
{
    unset($_SESSION['plc_2fa_login_user_id'], $_SESSION['plc_2fa_login_time'], $_SESSION['plc_2fa_login_throttle_key']);
}

function plc_clear_enroll_2fa_state(): void
{
    unset($_SESSION['plc_2fa_enroll_user_id'], $_SESSION['plc_2fa_enroll_time'], $_SESSION['plc_2fa_enroll_secret']);
}

if (plc_current_user()) {
    plc_redirect('app.php');
}

$err = '';
$ok = '';

if (isset($_GET['cancel2fa']) && $_GET['cancel2fa'] === '1') {
    plc_clear_login_2fa_state();
    plc_redirect('index.php');
}

$twofaChallengeUserId = isset($_SESSION['plc_2fa_login_user_id']) ? (int)$_SESSION['plc_2fa_login_user_id'] : 0;
$twofaChallengeAt = isset($_SESSION['plc_2fa_login_time']) ? (int)$_SESSION['plc_2fa_login_time'] : 0;
$twofaChallengeActive = $twofaChallengeUserId > 0 && $twofaChallengeAt > 0 && ($twofaChallengeAt + (15 * 60)) >= time();
if (!$twofaChallengeActive) {
    plc_clear_login_2fa_state();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postStep = (string)($_POST['step'] ?? '');
    $csrf = (string)($_POST['_csrf'] ?? '');
    $sessionCsrf = (string)($_SESSION['plc_csrf'] ?? '');
    if ($sessionCsrf === '' || $csrf === '' || !hash_equals($sessionCsrf, $csrf)) {
        $err = 'Ogiltig begäran. Ladda om sidan och försök igen.';
    } elseif ($postStep === '2fa_verify') {
        if (!$twofaChallengeActive) {
            $err = '2FA-sessionen har gått ut. Logga in igen.';
            plc_clear_login_2fa_state();
        } else {
            $db = plc_db();
            plc_ensure_multischool_schema($db);
            $code = (string)($_POST['twofa_code'] ?? '');

            $stmt = $db->prepare(
                'SELECT u.id, u.password_hash, u.status, u.role, u.school_id, u.twofa_enabled, u.twofa_secret,
                        s.status AS school_status, s.require_2fa AS school_require_2fa
                 FROM plc_users u
                 LEFT JOIN plc_schools s ON s.id = u.school_id
                 WHERE u.id = ?
                 LIMIT 1'
            );
            $stmt->bind_param('i', $twofaChallengeUserId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if (!$row) {
                $err = 'Användaren hittades inte.';
                plc_clear_login_2fa_state();
            } else {
                $status = (string)($row['status'] ?? '');
                $role = (string)($row['role'] ?? '');
                $schoolId = (int)($row['school_id'] ?? 0);
                $schoolStatus = (string)($row['school_status'] ?? '');
                $schoolRequire2FA = (int)($row['school_require_2fa'] ?? 0) === 1;
                $twofaEnabled = (int)($row['twofa_enabled'] ?? 0) === 1;
                $requires2FA = $twofaEnabled || ($role !== 'superadmin' && $schoolRequire2FA);

                if ($status !== 'approved') {
                    $err = 'Kontot är inte längre aktivt.';
                    plc_clear_login_2fa_state();
                } elseif ($role !== 'superadmin' && ($schoolId <= 0 || $schoolStatus !== 'approved')) {
                    $err = 'Skolan är inte godkänd ännu.';
                    plc_clear_login_2fa_state();
                } elseif (!$requires2FA) {
                    plc_clear_login_2fa_state();
                    plc_login_user((int)$row['id']);
                    plc_redirect('app.php');
                } else {
                    $secretEncrypted = (string)($row['twofa_secret'] ?? '');
                    $secret = plc_decrypt_text($secretEncrypted);
                    if (!$twofaEnabled || $secret === '') {
                        $err = '2FA är inte korrekt konfigurerat. Kontakta skoladmin.';
                    } elseif (!plc_twofa_verify_code($secret, $code) && !plc_twofa_consume_backup_code($db, (int)$row['id'], $code)) {
                        plc_login_slowdown();
                        $throttleKey = (string)($_SESSION['plc_2fa_login_throttle_key'] ?? '');
                        if ($throttleKey !== '') {
                            $penalty = plc_login_register_failure($throttleKey);
                            $err = $penalty > 0
                                ? 'Fel 2FA-kod eller backupkod. Vänta ' . $penalty . ' sekunder och försök igen.'
                                : 'Fel 2FA-kod eller backupkod.';
                        } else {
                            $err = 'Fel 2FA-kod eller backupkod.';
                        }
                    } else {
                        $throttleKey = (string)($_SESSION['plc_2fa_login_throttle_key'] ?? '');
                        if ($throttleKey !== '') {
                            plc_login_clear_throttle($throttleKey);
                        }
                        plc_clear_login_2fa_state();
                        plc_login_user((int)$row['id']);
                        plc_redirect('app.php');
                    }
                }
            }
        }
    } else {
        plc_clear_login_2fa_state();
        plc_clear_enroll_2fa_state();
        $db = plc_db();
        plc_ensure_multischool_schema($db);
        $identity = trim((string)($_POST['identity'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $throttleKey = plc_login_throttle_key($identity);

        $wait = plc_login_wait_seconds($throttleKey);
        if ($wait > 0) {
            $err = 'För många inloggningsförsök. Vänta ' . $wait . ' sekunder och försök igen.';
        } else {
            $stmt = $db->prepare(
                'SELECT u.id, u.password_hash, u.status, u.role, u.school_id, u.twofa_enabled, u.twofa_secret,
                        s.status AS school_status, s.require_2fa AS school_require_2fa
                 FROM plc_users u
                 LEFT JOIN plc_schools s ON s.id = u.school_id
                 WHERE u.username = ? OR u.email = ?
                 LIMIT 1'
            );
            $stmt->bind_param('ss', $identity, $identity);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();

            if (!$row || !password_verify($password, (string)$row['password_hash'])) {
                plc_login_slowdown();
                $penalty = plc_login_register_failure($throttleKey);
                $err = $penalty > 0
                    ? 'För många inloggningsförsök. Vänta ' . $penalty . ' sekunder och försök igen.'
                    : 'Fel inloggningsuppgifter.';
            } else {
                $status = (string)$row['status'];
                if ($status === 'pending') {
                    plc_login_slowdown();
                    plc_login_register_failure($throttleKey);
                    $err = 'Kontot väntar på admin-godkännande.';
                } elseif ($status === 'rejected') {
                    plc_login_slowdown();
                    plc_login_register_failure($throttleKey);
                    $err = 'Ansökan har avslagits.';
                } elseif ($status === 'disabled') {
                    plc_login_slowdown();
                    plc_login_register_failure($throttleKey);
                    $err = 'Kontot är avstängt.';
                } else {
                    $role = (string)($row['role'] ?? '');
                    $schoolId = (int)($row['school_id'] ?? 0);
                    $schoolStatus = (string)($row['school_status'] ?? '');
                    $schoolRequire2FA = (int)($row['school_require_2fa'] ?? 0) === 1;
                    $twofaEnabled = (int)($row['twofa_enabled'] ?? 0) === 1;
                    if ($role !== 'superadmin' && ($schoolId <= 0 || $schoolStatus !== 'approved')) {
                        plc_login_slowdown();
                        plc_login_register_failure($throttleKey);
                        $err = 'Skolan är inte godkänd ännu.';
                    } else {
                        $requires2FA = $twofaEnabled || ($role !== 'superadmin' && $schoolRequire2FA);
                        if ($requires2FA) {
                            if ($twofaEnabled) {
                                $_SESSION['plc_2fa_login_user_id'] = (int)$row['id'];
                                $_SESSION['plc_2fa_login_time'] = time();
                                $_SESSION['plc_2fa_login_throttle_key'] = $throttleKey;
                                plc_redirect('index.php?step=2fa');
                            } else {
                                $_SESSION['plc_2fa_enroll_user_id'] = (int)$row['id'];
                                $_SESSION['plc_2fa_enroll_time'] = time();
                                plc_redirect('twofa_enroll.php');
                            }
                        } else {
                            plc_login_clear_throttle($throttleKey);
                            plc_login_user((int)$row['id']);
                            plc_redirect('app.php');
                        }
                    }
                }
            }
        }
    }
}

$registered = isset($_GET['registered']) && $_GET['registered'] === '1';
if ($registered) {
    $ok = 'Ansökan skickad. En admin behöver godkänna ditt konto innan inloggning.';
}
$resetDone = isset($_GET['reset']) && $_GET['reset'] === '1';
if ($resetDone) {
    $ok = 'Lösenordet är uppdaterat. Du kan nu logga in.';
}

$twofaChallengeUserId = isset($_SESSION['plc_2fa_login_user_id']) ? (int)$_SESSION['plc_2fa_login_user_id'] : 0;
$twofaChallengeAt = isset($_SESSION['plc_2fa_login_time']) ? (int)$_SESSION['plc_2fa_login_time'] : 0;
$showTwofaForm = $twofaChallengeUserId > 0 && $twofaChallengeAt > 0 && ($twofaChallengeAt + (15 * 60)) >= time();

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

        <?php if ($showTwofaForm): ?>
          <h2>Verifiera 2FA</h2>
          <form method="post">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="step" value="2fa_verify">
            <div class="fg">
              <label>2FA-kod eller backupkod</label>
              <input type="text" name="twofa_code" inputmode="numeric" autocomplete="one-time-code" required>
            </div>
            <button class="btn-primary" type="submit">Verifiera</button>
          </form>
          <a class="register-link" href="index.php?cancel2fa=1">Avbryt och börja om</a>
        <?php else: ?>
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
          <a class="register-link" href="forgot_password.php">Glömt lösenord?</a>
        <?php endif; ?>
        <p class="small">Saknar du konto?</p>
        <a class="register-link" href="register.php">Skicka registreringsansökan</a>
      </div>
    </div>
  </div>
</body>
</html>
