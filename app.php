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
$isSiteAdmin = (($user['role'] ?? '') === 'admin');
$manageViewLabel = $isSiteAdmin ? 'Admin' : 'Hantera';
$managePanelLabel = $isSiteAdmin ? 'Administration' : 'Hantera';
?>
<!DOCTYPE html>
<html lang="sv">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gruppplacering</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body>

<header>
  <div class="logo">Grupp<span>placering</span></div>
  <nav class="main-nav">
    <button type="button" class="nav-btn active" onclick="showView('home')">Placera</button>
    <button type="button" class="nav-btn" onclick="showView('saved')">Placeringar</button>
    <button type="button" class="nav-btn" onclick="showView('admin')"><?= htmlspecialchars($manageViewLabel, ENT_QUOTES, 'UTF-8') ?></button>
    <button type="button" class="nav-btn" onclick="showView('about')">Om</button>
  </nav>
  <div class="header-right">
    <div class="user-pill" id="user-pill">
      <button type="button" class="user-meta-btn" onclick="showView('profile')" title="Min profil" aria-label="Öppna min profil">
        <span class="user-name" id="user-name-text"><?= htmlspecialchars((string)$user['full_name'], ENT_QUOTES, 'UTF-8') ?></span>
        <span class="user-role" id="user-role-text"><?= htmlspecialchars((string)$user['role'], ENT_QUOTES, 'UTF-8') ?></span>
      </button>
      <a class="btn btn-secondary btn-sm" href="logout.php">Logga ut</a>
    </div>
    <div class="theme-toggle" id="theme-toggle">
      <button type="button" class="theme-opt" onclick="setTheme('light')" title="Ljust" aria-label="Ljust tema">☀️</button>
      <button type="button" class="theme-opt" onclick="setTheme('auto')" title="Auto" aria-label="Automatiskt tema">⚙️</button>
      <button type="button" class="theme-opt" onclick="setTheme('dark')" title="Mörkt" aria-label="Mörkt tema">🌙</button>
    </div>
  </div>
</header>

<main>

<!-- HOME -->
<div class="view active" id="home-view">
  <div class="home-hero">
    <div class="section-title">Slumpa <span style="color:var(--accent)">platser</span></div>
    <p class="muted" style="font-size:.84rem">Välj grupp och sal, sedan fixar vi resten.</p>
    <div class="sel-grid">
      <div>
        <div class="sel-label">Grupp</div>
        <div id="class-sel"></div>
      </div>
      <div>
        <div class="sel-label">Sal</div>
        <div id="room-sel"></div>
      </div>
    </div>
    <button type="button" class="shuffle-btn" id="shuffle-btn" onclick="doShuffle()" disabled>⚡ Slumpa placeringar</button>
  </div>
</div>

<!-- RESULT -->
<div class="view" id="result-view">
  <div class="flex gap3" style="align-items:center;margin-bottom:22px;flex-wrap:wrap">
    <button type="button" class="btn btn-secondary" id="res-back-btn" onclick="goBackFromResult()">← Tillbaka</button>
    <div>
      <div class="section-title" style="font-size:1.5rem;margin-bottom:0" id="res-title"></div>
      <div class="muted" style="font-size:.78rem" id="res-sub"></div>
    </div>
    <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap">
      <button type="button" class="btn btn-secondary btn-sm" id="res-reshuffle-btn" onclick="doShuffle(true)">↺ Slumpa om</button>
      <button type="button" class="btn btn-primary btn-sm" id="save-placement-btn" onclick="openSaveModal()">💾 Spara placering</button>
      <button type="button" class="btn btn-secondary btn-sm" id="print-btn" onclick="printDirect()">🖨 Skriv ut direkt</button>
      <button type="button" class="btn btn-secondary btn-sm" id="pdf-btn" onclick="exportPDF()">📄 Ladda ner PDF</button>
    </div>
  </div>
  <div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px">
      <div class="card-title" style="margin:0">Klassrumslayout</div>
      <button type="button" class="btn btn-secondary btn-sm" id="edit-mode-btn" onclick="toggleEditMode()">✏️ Redigera placering</button>
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

<!-- ABOUT -->
<div class="view" id="about-view">
  <div class="about-wrap">
    <div class="section-title">Om Placera</div>
    <div class="card about-copy">
      <h2>Placera</h2>
      <p>Placera är ett digitalt verktyg för klassrumsplacering. Appen hjälper lärare att snabbt skapa, slumpa, justera, spara och skriva ut elevplaceringar i olika salar.</p>

      <h3>Vem appen är till för</h3>
      <ul>
        <li>Lärare som behöver skapa och hantera sittplatser på ett snabbt och tydligt sätt.</li>
        <li>Arbetslag och ämneslag som vill kunna återanvända salar och placeringar.</li>
        <li>Skoladministratörer som vill styra vilka användare som får tillgång till systemet.</li>
      </ul>

      <h3>Vad appen gör</h3>
      <ul>
        <li>Hanterar grupper och elevlistor.</li>
        <li>Hanterar salar och bänkplaceringar via visuell editor.</li>
        <li>Slumpar placeringar automatiskt utifrån vald grupp och sal.</li>
        <li>Låter användaren finjustera placeringar manuellt.</li>
        <li>Sparar placeringar för senare användning.</li>
        <li>Ger möjlighet till direktutskrift och nedladdning som PDF.</li>
      </ul>

      <h3>Användar- och säkerhetsflöde</h3>
      <ul>
        <li>Nya användare skickar en ansökan om konto.</li>
        <li>Admin godkänner eller avslår ansökningar.</li>
        <li>Godkända användare kan logga in och använda verktyget.</li>
        <li>Systemet sparar vem som skapat eller uppdaterat salar och placeringar, samt när det gjordes.</li>
        <li>Elevnamn i grupper och sparade placeringar krypteras i databasen.</li>
      </ul>
      <h3>Vem ligger bakom?</h3>
      <p>Placera är byggd av Charlie Jarl – på fritiden. <br>
      Tycker du den är bra? Säg det högt. Dela den. Använd den.<br>
      Den är gratis av en anledning: bra saker ska inte gömmas bakom betalväggar.<br>
      Men – vill du visa uppskattning på riktigt, så uppskattas en donation enormt. <br>
      Helt frivilligt. Helt upp till dig. <br>
      Skanna PayPal-koden nedan, om du vill, och betala det du tycker det är värt.</p>
        <div style="margin-top:16px;width:100%;display:flex;justify-content:center">
          <img src="includes/paypal_qr.png" alt="PayPal QR-kod" style="width:150px;height:auto">
        </div>
    </div>
  </div>
</div>

<!-- PROFILE -->
<div class="view" id="profile-view">
  <div style="max-width:660px;margin:0 auto">
    <div class="section-title">Min profil</div>
    <p class="muted" style="font-size:.84rem;margin-bottom:14px">Redigera dina uppgifter. Klicka på ditt namn i headern för att komma hit.</p>
    <div class="card">
      <div class="card-title">Profiluppgifter</div>
      <div class="fg">
        <label>Användarnamn</label>
        <input type="text" id="profile-username" autocomplete="username" readonly>
        <p class="hint">Användarnamn kan inte ändras.</p>
      </div>
      <div class="fg">
        <label>Riktigt namn</label>
        <input type="text" id="profile-fullname" autocomplete="name">
      </div>
      <div class="fg">
        <label>E-post</label>
        <input type="email" id="profile-email" autocomplete="email">
      </div>
      <hr>
      <div class="card-title" style="margin-top:0">Byt lösenord (valfritt)</div>
      <div class="fg">
        <label>Nytt lösenord</label>
        <input type="password" id="profile-password1" autocomplete="new-password">
      </div>
      <div class="fg">
        <label>Bekräfta nytt lösenord</label>
        <input type="password" id="profile-password2" autocomplete="new-password">
      </div>
      <div class="flex gap2" style="justify-content:flex-end;margin-top:12px">
        <button type="button" class="btn btn-secondary" onclick="renderProfile()">Återställ</button>
        <button type="button" class="btn btn-primary" onclick="saveProfile()">Spara profil</button>
      </div>
    </div>
  </div>
</div>

<!-- ADMIN -->
<div class="view" id="admin-view">
  <div id="admin-login-screen" class="admin-login">
    <div class="section-title"><?= htmlspecialchars($manageViewLabel, ENT_QUOTES, 'UTF-8') ?></div>
    <p class="muted mt2 mb2" style="font-size:.82rem">Hantera grupper, salar och användare utifrån din roll.</p>
  </div>
  <div id="admin-panel" style="display:none">
    <div class="flex gap4" style="align-items:center;justify-content:space-between;margin-bottom:22px;flex-wrap:wrap">
      <div><div class="section-title"><?= htmlspecialchars($managePanelLabel, ENT_QUOTES, 'UTF-8') ?></div></div>
      <button type="button" class="btn btn-secondary" onclick="adminLogout()">Logga ut konto</button>
    </div>
    <div class="atabs">
      <button type="button" class="atab active" onclick="aTab('rooms')">Salar</button>
      <button type="button" class="atab" onclick="aTab('classes')">Grupper</button>
      <button type="button" class="atab" onclick="aTab('users')">Användare</button>
    </div>
    <div class="asec active" id="asec-rooms">
      <div class="flex gap3" style="align-items:center;justify-content:space-between;margin-bottom:14px">
        <span class="card-title" style="margin:0">Sparade salar</span>
        <button type="button" class="btn btn-primary btn-sm" onclick="openEditor(null)">+ Ny sal</button>
      </div>
      <div id="room-list"></div>
    </div>
    <div class="asec" id="asec-classes">
      <div class="flex gap3" style="align-items:center;justify-content:space-between;margin-bottom:14px">
        <span class="card-title" style="margin:0">Sparade grupper</span>
        <button type="button" class="btn btn-primary btn-sm" onclick="openClassModal(null)">+ Ny grupp</button>
      </div>
      <div id="class-list"></div>
    </div>
    <div class="asec" id="asec-users">
      <div class="card">
        <div class="card-title">Användaransökningar</div>
        <p class="hint" style="margin-bottom:10px">Godkänn eller avslå nya registreringar.</p>
        <div id="pending-users-list"></div>
      </div>
      <div class="card" style="margin-top:14px">
        <div class="card-title">Befintliga användare</div>
        <p class="hint" style="margin-bottom:10px">Hantera roller och kontostatus för godkända användare.</p>
        <div id="existing-users-list"></div>
      </div>
    </div>
  </div>
</div>

</main>

<!-- ADMIN USER MODAL -->
<div class="overlay hidden" id="admin-user-modal">
<div class="modal" style="width:min(96vw,520px)">
  <button type="button" class="modal-close" onclick="closeModal('admin-user-modal')" aria-label="Stäng dialog">✕</button>
  <div class="modal-title">Redigera användare</div>
  <input type="hidden" id="admin-user-id">
  <div class="fg">
    <label>Användarnamn</label>
    <input type="text" id="admin-user-username" autocomplete="off">
  </div>
  <div class="fg">
    <label>Riktigt namn</label>
    <input type="text" id="admin-user-fullname" autocomplete="name">
  </div>
  <div class="fg">
    <label>E-post</label>
    <input type="email" id="admin-user-email" autocomplete="email">
  </div>
  <div class="flex gap2">
    <div class="fg" style="flex:1">
      <label>Roll</label>
      <select id="admin-user-role">
        <option value="teacher">Lärare</option>
        <option value="admin">Admin</option>
      </select>
    </div>
    <div class="fg" style="flex:1">
      <label>Status</label>
      <select id="admin-user-status">
        <option value="pending">Väntande</option>
        <option value="approved">Godkänd</option>
        <option value="disabled">Inaktiverad</option>
        <option value="rejected">Avslagen</option>
      </select>
    </div>
  </div>
  <hr>
  <div class="card-title" style="margin-top:0">Byt lösenord (valfritt)</div>
  <div class="fg">
    <label>Nytt lösenord</label>
    <input type="password" id="admin-user-password1" autocomplete="new-password">
  </div>
  <div class="fg">
    <label>Bekräfta nytt lösenord</label>
    <input type="password" id="admin-user-password2" autocomplete="new-password">
  </div>
  <div class="flex gap2" style="justify-content:flex-end;margin-top:14px">
    <button type="button" class="btn btn-secondary" onclick="closeModal('admin-user-modal')">Avbryt</button>
    <button type="button" class="btn btn-primary" onclick="saveAdminUserEdit()">Spara användare</button>
  </div>
</div>
</div>

<!-- ROOM EDITOR MODAL -->
<div class="overlay hidden" id="editor-modal">
<div class="modal" style="width:min(97vw,1020px)">
  <button type="button" class="modal-close" onclick="closeModal('editor-modal')" aria-label="Stäng dialog">✕</button>
  <div class="modal-title" id="editor-title">Ny sal</div>

  <div style="display:grid;grid-template-columns:minmax(220px,1fr) minmax(170px,220px);gap:12px;max-width:100%;margin-bottom:14px">
    <div class="fg" style="margin:0">
      <label>Salens namn</label>
      <input type="text" id="editor-room-name" placeholder="t.ex. Sal 202, Matte-salen…">
    </div>
    <div class="fg" style="margin:0">
      <label>Delning</label>
      <select id="editor-room-visibility">
        <option value="shared">Delad</option>
        <option value="private">Egen</option>
      </select>
    </div>
  </div>

  <!-- toolbar -->
  <div class="editor-toolbar">
    <div class="editor-toolbar-group">
      <label class="editor-toolbar-label" for="desk-count-input">Antal bänkar:</label>
      <input type="number" id="desk-count-input" value="20" min="1" max="60" style="width:66px">
      <button type="button" class="btn btn-secondary btn-sm" onclick="applyCount()">Tillämpa</button>
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
      <button type="button" class="btn btn-secondary btn-sm" onclick="applyPresetLayout()">⬚ Skapa grundlayout</button>
    </div>
    <div class="editor-toolbar-sep"></div>
    <button type="button" class="btn btn-secondary btn-sm" onclick="autoArrange()">⊞ Auto-arrangera</button>
    <button type="button" class="btn btn-secondary btn-sm" onclick="clearCanvas()">⊡ Rensa canvas</button>
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
              <button type="button" class="btn btn-secondary btn-sm" onclick="applyRotation(0);document.getElementById('rot-range').value=0">Reset</button>
            </div>
          </div>
          <button type="button" class="btn btn-danger btn-sm" style="width:100%;justify-content:center" onclick="deleteSelected()">Flytta till pool</button>
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
    <button type="button" class="btn btn-secondary" onclick="closeModal('editor-modal')">Avbryt</button>
    <button type="button" class="btn btn-primary" onclick="saveRoom()">💾 Spara sal</button>
  </div>
</div>
</div>

<!-- ROOM PREVIEW MODAL -->
<div class="overlay hidden" id="room-preview-modal">
<div class="modal room-preview-modal">
  <button type="button" class="modal-close" onclick="closeModal('room-preview-modal')" aria-label="Stäng dialog">✕</button>
  <div class="modal-title" id="room-preview-title">Förhandsgranskning av sal</div>
  <p class="hint" id="room-preview-meta" style="margin-bottom:10px"></p>
  <div class="room-preview-wrap">
    <div class="room-preview-board" id="room-preview-board">Tavla / Whiteboard</div>
    <div id="room-preview-canvas"></div>
  </div>
  <div class="flex gap2" style="justify-content:flex-end;margin-top:14px">
    <button type="button" class="btn btn-secondary" onclick="closeModal('room-preview-modal')">Stäng</button>
  </div>
</div>
</div>

<!-- CLASS MODAL -->
<div class="overlay hidden" id="class-modal">
<div class="modal" style="width:min(96vw,480px)">
  <button type="button" class="modal-close" onclick="closeModal('class-modal')" aria-label="Stäng dialog">✕</button>
  <div class="modal-title" id="class-modal-title">Ny grupp</div>
  <div style="display:grid;grid-template-columns:minmax(180px,1fr) minmax(150px,190px);gap:12px">
    <div class="fg"><label>Gruppens namn</label><input type="text" id="class-name-in" placeholder="t.ex. 9B, NA22A…"></div>
    <div class="fg">
      <label>Delning</label>
      <select id="class-visibility-in">
        <option value="shared">Delad</option>
        <option value="private">Egen</option>
      </select>
    </div>
  </div>
  <div class="fg">
    <label>Elever (en per rad)</label>
    <textarea id="class-students-in" style="height:200px;resize:vertical" placeholder="Anna Andersson&#10;Erik Eriksson&#10;Sara Svensson&#10;…"></textarea>
    <p class="hint">Klistra in en lista eller skriv ett namn per rad.</p>
  </div>
  <div class="flex gap2" style="justify-content:flex-end;margin-top:14px">
    <button type="button" class="btn btn-secondary" onclick="closeModal('class-modal')">Avbryt</button>
    <button type="button" class="btn btn-primary" onclick="saveClass()">Spara grupp</button>
  </div>
</div>
</div>

<!-- SAVE PLACEMENT MODAL -->
<div class="overlay hidden" id="save-modal">
<div class="modal" style="width:min(96vw,440px)">
  <button type="button" class="modal-close" onclick="closeModal('save-modal')" aria-label="Stäng dialog">✕</button>
  <div class="modal-title">Spara placering</div>
  <div class="fg">
    <label>Namn på placering</label>
    <input type="text" id="save-name-input" placeholder="t.ex. Måndag v.12, Prov NA22A…">
    <p class="hint">Lämna tomt för att använda datum och tid automatiskt.</p>
  </div>
  <div class="flex gap2" style="justify-content:flex-end;margin-top:14px">
    <button type="button" class="btn btn-secondary" onclick="closeModal('save-modal')">Avbryt</button>
    <button type="button" class="btn btn-primary" onclick="confirmSavePlacement()">💾 Spara</button>
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

