<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
$user = plc_require_login(false);
$boot = [
  'csrf' => plc_csrf_token(),
  'user' => [
    'id' => (int)$user['id'],
    'username' => $user['username'],
    'fullName' => $user['full_name'],
    'email' => $user['email'],
    'role' => $user['role'],
  ],
];
?>
<!DOCTYPE html>
<html lang="sv">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Klassplacering</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body>

<header>
  <div class="logo">Klass<span>placering</span></div>
  <nav>
    <button class="nav-btn active" onclick="showView('home')">Placera</button>
    <button class="nav-btn" onclick="showView('saved')">Placeringar</button>
    <button class="nav-btn" onclick="showView('admin')">Admin</button>
  </nav>
  <div class="user-pill" id="user-pill">
    <div class="user-meta">
      <div class="user-name"><?= htmlspecialchars((string)$user['full_name'], ENT_QUOTES, 'UTF-8') ?></div>
      <div class="user-role"><?= htmlspecialchars((string)$user['role'], ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <a class="btn btn-secondary btn-sm" href="logout.php">Logga ut</a>
  </div>
  <div class="theme-toggle" id="theme-toggle">
    <button class="theme-opt" onclick="setTheme('light')" title="Ljust">☀️</button>
    <button class="theme-opt" onclick="setTheme('auto')" title="Auto">⚙️</button>
    <button class="theme-opt" onclick="setTheme('dark')" title="Mörkt">🌙</button>
  </div>
</header>

<main>

<!-- HOME -->
<div class="view active" id="home-view">
  <div class="home-hero">
    <div class="section-title">Slumpa <span style="color:var(--accent)">platser</span></div>
    <p class="muted" style="font-size:.84rem">Välj klass och sal, sedan fixar vi resten.</p>
    <div class="sel-grid">
      <div>
        <div class="sel-label">Klass</div>
        <div id="class-sel"></div>
      </div>
      <div>
        <div class="sel-label">Sal</div>
        <div id="room-sel"></div>
      </div>
    </div>
    <button class="shuffle-btn" id="shuffle-btn" onclick="doShuffle()" disabled>⚡ Slumpa placeringar</button>
  </div>
</div>

<!-- RESULT -->
<div class="view" id="result-view">
  <div class="flex gap3" style="align-items:center;margin-bottom:22px;flex-wrap:wrap">
    <button class="btn btn-secondary" id="res-back-btn" onclick="goBackFromResult()">← Tillbaka</button>
    <div>
      <div class="section-title" style="font-size:1.5rem;margin-bottom:0" id="res-title"></div>
      <div class="muted" style="font-size:.78rem" id="res-sub"></div>
    </div>
    <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn btn-secondary btn-sm" id="res-reshuffle-btn" onclick="doShuffle()">↺ Slumpa om</button>
      <button class="btn btn-primary btn-sm" onclick="openSaveModal()">💾 Spara placering</button>
      <button class="btn btn-secondary btn-sm" id="print-btn" onclick="printDirect()">🖨 Skriv ut direkt</button>
      <button class="btn btn-secondary btn-sm" id="pdf-btn" onclick="exportPDF()">📄 Ladda ner PDF</button>
    </div>
  </div>
  <div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px">
      <div class="card-title" style="margin:0">Klassrumslayout</div>
      <button class="btn btn-secondary btn-sm" id="edit-mode-btn" onclick="toggleEditMode()">✏️ Redigera placering</button>
    </div>
    <div id="result-layout-print-block" style="background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:24px;overflow:auto">
      <div class="board-bar">Tavla / Whiteboard</div>
      <div id="result-canvas" style="position:relative"></div>
    </div>
  </div>
  <div class="card" style="margin-top:14px">
    <div class="card-title">Alla placeringar</div>
    <div class="result-list" id="result-list"></div>
  </div>
</div>

<!-- SAVED PLACEMENTS VIEW -->
<div class="view" id="saved-view">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px">
    <div>
      <div class="section-title">Sparade placeringar</div>
      <p class="muted" style="font-size:.82rem">Klicka på en placering för att öppna och justera den.</p>
    </div>
  </div>
  <div id="saved-list"></div>
</div>

<!-- ADMIN -->
<div class="view" id="admin-view">
  <div id="admin-login-screen" class="admin-login">
    <div class="section-title">Admin</div>
    <p class="muted mt2 mb2" style="font-size:.82rem">Endast användare med admin-roll kan öppna denna vy.</p>
  </div>
  <div id="admin-panel" style="display:none">
    <div class="flex gap4" style="align-items:center;justify-content:space-between;margin-bottom:22px;flex-wrap:wrap">
      <div><div class="section-title">Administration</div></div>
      <button class="btn btn-secondary" onclick="adminLogout()">Logga ut konto</button>
    </div>
    <div class="atabs">
      <button class="atab active" onclick="aTab('rooms')">Salar</button>
      <button class="atab" onclick="aTab('classes')">Klasser</button>
      <button class="atab" onclick="aTab('settings')">Inställningar</button>
    </div>
    <div class="asec active" id="asec-rooms">
      <div class="flex gap3" style="align-items:center;justify-content:space-between;margin-bottom:14px">
        <span class="card-title" style="margin:0">Sparade salar</span>
        <button class="btn btn-primary btn-sm" onclick="openEditor(null)">+ Ny sal</button>
      </div>
      <div id="room-list"></div>
    </div>
    <div class="asec" id="asec-classes">
      <div class="flex gap3" style="align-items:center;justify-content:space-between;margin-bottom:14px">
        <span class="card-title" style="margin:0">Sparade klasser</span>
        <button class="btn btn-primary btn-sm" onclick="openClassModal(null)">+ Ny klass</button>
      </div>
      <div id="class-list"></div>
    </div>
    <div class="asec" id="asec-settings">
      <div class="card">
        <div class="card-title">Användaransökningar</div>
        <p class="hint" style="margin-bottom:10px">Godkänn nya lärare och hantera kontostatus.</p>
        <div id="pending-users-list"></div>
      </div>
      <div class="card" style="margin-top:14px">
        <div class="card-title">Kontaktuppgifter</div>
        <p class="muted" style="font-size:.82rem">Alla användare har användarnamn, riktigt namn och e-post för intern kontakt och spårbarhet.</p>
      </div>
    </div>
  </div>
</div>

</main>

<!-- ROOM EDITOR MODAL -->
<div class="overlay hidden" id="editor-modal">
<div class="modal" style="width:min(97vw,1020px)">
  <button class="modal-close" onclick="closeModal('editor-modal')">✕</button>
  <div class="modal-title" id="editor-title">Ny sal</div>

  <div style="display:grid;grid-template-columns:1fr auto;gap:12px;max-width:100%;margin-bottom:14px">
    <div class="fg" style="margin:0">
      <label>Salens namn</label>
      <input type="text" id="editor-room-name" placeholder="t.ex. Sal 202, Matte-salen…">
    </div>
  </div>

  <!-- toolbar -->
  <div class="editor-toolbar">
    <div class="editor-toolbar-group">
      <label class="editor-toolbar-label" for="desk-count-input">Antal bänkar:</label>
      <input type="number" id="desk-count-input" value="20" min="1" max="60" style="width:66px">
      <button class="btn btn-secondary btn-sm" onclick="applyCount()">Tillämpa</button>
    </div>
    <div class="editor-toolbar-sep"></div>
    <div class="editor-toolbar-group">
      <label class="editor-toolbar-label" for="layout-preset">Grundlayout:</label>
      <select id="layout-preset" style="width:114px">
        <option value="2-3-2">2-3-2</option>
        <option value="2-2-2">2-2-2</option>
        <option value="3-3">3-3</option>
        <option value="4-4">4-4</option>
      </select>
      <label class="editor-toolbar-label" for="layout-rows">Rader:</label>
      <input type="number" id="layout-rows" value="5" min="1" max="12" style="width:64px">
      <label class="editor-check">
        <input type="checkbox" id="layout-fit-count" checked>
        Anpassa antal
      </label>
      <button class="btn btn-secondary btn-sm" onclick="applyPresetLayout()">⬚ Skapa grundlayout</button>
    </div>
    <div class="editor-toolbar-sep"></div>
    <button class="btn btn-secondary btn-sm" onclick="autoArrange()">⊞ Auto-arrangera</button>
    <button class="btn btn-secondary btn-sm" onclick="clearCanvas()">⊡ Rensa canvas</button>
    <span class="hint editor-toolbar-hint">Dra bänkar från poolen till canvasen • Klicka för att markera • Rotera med ↻</span>
  </div>

  <div class="editor-wrap">
    <!-- sidebar -->
    <div class="editor-sidebar">
      <div class="side-box">
        <div class="side-title">Bänkpool <span id="pool-count" style="color:var(--muted);font-weight:400">(0)</span></div>
        <p class="hint mb2">Dra ut till canvasen</p>
        <div class="desk-pool" id="desk-pool"></div>
      </div>
      <div class="side-box">
        <div class="side-title">Markerad bänk</div>
        <div id="sel-info" class="muted" style="font-size:.75rem">Klicka på en bänk på canvasen</div>
        <div id="sel-ctrl" style="display:none">
          <div class="fg mt2">
            <label>Rotation</label>
            <input type="range" id="rot-range" min="-180" max="180" step="5" value="0" oninput="applyRotation(this.value)" style="padding:0;height:28px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px">
              <span class="hint" style="margin:0" id="rot-val">0°</span>
              <button class="btn btn-secondary btn-sm" onclick="applyRotation(0);document.getElementById('rot-range').value=0">Reset</button>
            </div>
          </div>
          <button class="btn btn-danger btn-sm" style="width:100%;justify-content:center" onclick="deleteSelected()">Flytta till pool</button>
        </div>
      </div>
    </div>

    <!-- canvas -->
    <div class="canvas-outer" id="canvas-outer">
      <div class="board-label-editor">Tavla / Whiteboard</div>
      <div id="editor-canvas"></div>
    </div>
  </div>

  <div class="flex gap2" style="justify-content:flex-end;margin-top:16px">
    <button class="btn btn-secondary" onclick="closeModal('editor-modal')">Avbryt</button>
    <button class="btn btn-primary" onclick="saveRoom()">💾 Spara sal</button>
  </div>
</div>
</div>

<!-- CLASS MODAL -->
<div class="overlay hidden" id="class-modal">
<div class="modal" style="width:min(96vw,480px)">
  <button class="modal-close" onclick="closeModal('class-modal')">✕</button>
  <div class="modal-title" id="class-modal-title">Ny klass</div>
  <div class="fg"><label>Klassens namn</label><input type="text" id="class-name-in" placeholder="t.ex. 9B, NA22A…"></div>
  <div class="fg">
    <label>Elever (en per rad)</label>
    <textarea id="class-students-in" style="height:200px;resize:vertical" placeholder="Anna Andersson&#10;Erik Eriksson&#10;Sara Svensson&#10;…"></textarea>
    <p class="hint">Klistra in en lista eller skriv ett namn per rad.</p>
  </div>
  <div class="flex gap2" style="justify-content:flex-end;margin-top:14px">
    <button class="btn btn-secondary" onclick="closeModal('class-modal')">Avbryt</button>
    <button class="btn btn-primary" onclick="saveClass()">Spara klass</button>
  </div>
</div>
</div>

<!-- SAVE PLACEMENT MODAL -->
<div class="overlay hidden" id="save-modal">
<div class="modal" style="width:min(96vw,440px)">
  <button class="modal-close" onclick="closeModal('save-modal')">✕</button>
  <div class="modal-title">Spara placering</div>
  <div class="fg">
    <label>Namn på placering</label>
    <input type="text" id="save-name-input" placeholder="t.ex. Måndag v.12, Prov NA22A…">
    <p class="hint">Lämna tomt för att använda datum och tid automatiskt.</p>
  </div>
  <div class="flex gap2" style="justify-content:flex-end;margin-top:14px">
    <button class="btn btn-secondary" onclick="closeModal('save-modal')">Avbryt</button>
    <button class="btn btn-primary" onclick="confirmSavePlacement()">💾 Spara</button>
  </div>
</div>
</div>

<div class="save-toast" id="save-toast"></div>

<div class="confetti-wrap" id="cfWrap"></div>

<script>
window.APP_BOOT=<?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="app.js"></script>
</body>
</html>

