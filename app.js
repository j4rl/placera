// ═══════════════ DB ═══════════════
const APP_BOOT=window.APP_BOOT||{};
const API_HEADERS={
  'Content-Type':'application/json',
  'X-CSRF-Token':APP_BOOT.csrf||''
};
let currentUser=APP_BOOT.user||null;
let isSiteAdmin=(currentUser?.role||'')==='admin';
let serverState={rooms:[],classes:[],saved:[]};
let persistQueue=Promise.resolve();

function cloneDeep(v){
  try{return structuredClone(v)}catch{return JSON.parse(JSON.stringify(v))}
}

async function apiRequest(url,opts={}){
  const cfg={credentials:'same-origin',...opts};
  if(cfg.body&&!cfg.headers)cfg.headers=API_HEADERS;
  else if(cfg.body)cfg.headers={...API_HEADERS,...cfg.headers};
  const res=await fetch(url,cfg);
  let data=null;
  try{data=await res.json()}catch{data=null}
  if(!res.ok){
    const err=new Error(data?.error||('HTTP '+res.status));
    err.data=data;
    throw err;
  }
  return data||{};
}

function queuePersist(key,val){
  if(!['rooms','classes','saved'].includes(key))return;
  const payload=cloneDeep(val);
  persistQueue=persistQueue.then(async()=>{
    const res=await apiRequest('api/state.php',{method:'POST',body:JSON.stringify({key,value:payload})});
    if(res?.ok&&res.key===key&&Array.isArray(res.items)){
      serverState[key]=res.items;
      if(key==='rooms'){renderHome();if(adminIn)renderRoomList();}
      if(key==='classes'){renderHome();if(adminIn)renderClassList();}
      if(key==='saved')renderSavedList();
    }
  }).catch(err=>{
    console.error(err);
    showToast('Kunde inte spara till servern.');
  });
}

const DB={
  get(k,d){
    if(k==='theme'){
      try{
        const v=localStorage.getItem('plc_theme');
        return v||d;
      }catch{return d}
    }
    return Object.prototype.hasOwnProperty.call(serverState,k)?cloneDeep(serverState[k]):d;
  },
  set(k,v){
    if(k==='theme'){
      try{localStorage.setItem('plc_theme',String(v))}catch{}
      return;
    }
    serverState[k]=cloneDeep(v);
    queuePersist(k,serverState[k]);
  }
};
const getRooms=()=>DB.get('rooms',[]);
const getClasses=()=>DB.get('classes',[]);
const setRooms=r=>DB.set('rooms',r);
const setClasses=c=>DB.set('classes',c);

// ═══════════════ STATE ═══════════════
let selClass=null,selRoom=null;
let editingRoomId=null,editingClassId=null;
let adminIn=false;
// editorDesks: [{id, x, y, rotation, inPool}]
let editorDesks=[];
let selectedId=null;
let shuffleResult=null;
let editorBindingsReady=false;
let editorDeskNumById=new Map();
let editorDeskIdSeq=0;
let selInfoRaf=0;

const EDITOR_DW=80;
const EDITOR_DH=50;
const EDITOR_TOP_OFFSET=56;
const EDITOR_SIDE_PAD=16;

function updateUserBadge(){
  const nameEl=document.getElementById('user-name-text');
  const roleEl=document.getElementById('user-role-text');
  if(nameEl)nameEl.textContent=currentUser?.fullName||'';
  if(roleEl)roleEl.textContent=currentUser?.role||'';
}

// ═══════════════ NAV ═══════════════
function showView(n){
  document.querySelectorAll('.view').forEach(v=>v.classList.remove('active'));
  document.querySelectorAll('.nav-btn').forEach(b=>b.classList.remove('active'));
  const views=['home','saved','admin'];
  document.getElementById(n+'-view').classList.add('active');
  const idx=views.indexOf(n);
  if(idx>=0)document.querySelectorAll('.nav-btn')[idx].classList.add('active');
  if(n==='home')renderHome();
  if(n==='saved')renderSavedList();
  if(n==='profile')renderProfile();
  if(n==='admin')renderAdmin();
}

// ═══════════════ HOME ═══════════════
function renderHome(){
  const rooms=getRooms(),classes=getClasses();
  const cs=document.getElementById('class-sel');
  const rs=document.getElementById('room-sel');
  cs.innerHTML=classes.length
    ?classes.map(c=>`<div class="pick-btn ${selClass===c.id?'sel':''}" onclick="pickC('${c.id}')">${escH(c.name)}<div class="sub">${c.students.length} elever</div></div>`).join('')
    :`<div class="empty"><div class="ico">👥</div><p>Inga klasser tillagda</p></div>`;
  rs.innerHTML=rooms.length
    ?rooms.map(r=>`<div class="pick-btn ${selRoom===r.id?'sel':''}" onclick="pickR('${r.id}')">${escH(r.name)}<div class="sub">${r.desks.filter(d=>!d.inPool).length} bänkar</div></div>`).join('')
    :`<div class="empty"><div class="ico">🏫</div><p>Inga salar tillagda</p></div>`;
  document.getElementById('shuffle-btn').disabled=!(selClass&&selRoom);
}
function pickC(id){selClass=id;renderHome()}
function pickR(id){selRoom=id;renderHome()}

// ═══════════════ SHUFFLE ═══════════════
function doShuffle(){
  // If called from result view with a valid room+class in shuffleResult, re-use those
  const roomId=shuffleResult?.room?.id||selRoom;
  const clsId=shuffleResult?.cls?.id||selClass;
  const room=getRooms().find(r=>r.id===roomId);
  const cls=getClasses().find(c=>c.id===clsId);
  if(!room||!cls)return;
  const placed=room.desks.filter(d=>!d.inPool);
  const students=[...cls.students];
  fisher(students);
  const pairs=placed.map((d,i)=>({desk:d,student:students[i]||null}));
  shuffleResult={room,cls,pairs};
  renderResult(false);
}
function fisher(a){for(let i=a.length-1;i>0;i--){const j=Math.floor(Math.random()*(i+1));[a[i],a[j]]=[a[j],a[i]]}}

// ── drag-swap state ──
let dragPairIdx=null;
let ghost=null;

let resultFromSaved=false;
let editMode=false;
function goBackFromResult(){showView(resultFromSaved?'saved':'home');}

function renderResult(fromSaved){
  resultFromSaved=!!fromSaved;
  editMode=false;
  updateEditModeUI();
  const {room,cls,pairs,savedName}=shuffleResult;
  document.getElementById('res-title').textContent=savedName||`${room.name} — ${cls.name}`;
  document.getElementById('res-sub').textContent=
    `${pairs.filter(p=>p.student).length} av ${cls.students.length||pairs.filter(p=>p.student).length} elever placerade • ${pairs.length} bänkar`
    +(fromSaved?' · Sparad placering':'');

  // Show/hide re-shuffle button depending on whether original class+room exist
  const canReshuffle=!!(shuffleResult.cls.students&&shuffleResult.room.desks);
  document.getElementById('res-reshuffle-btn').style.display=canReshuffle?'':'none';

  const canvas=document.getElementById('result-canvas');
  const pad=24;
  let minX=Infinity,minY=Infinity,maxX=-Infinity,maxY=-Infinity;
  pairs.forEach(p=>{
    minX=Math.min(minX,p.desk.x);minY=Math.min(minY,p.desk.y);
    maxX=Math.max(maxX,p.desk.x+80);maxY=Math.max(maxY,p.desk.y+50);
  });
  canvas.style.width=(maxX-minX+pad*2)+'px';
  canvas.style.height=(maxY-minY+pad*2)+'px';
  canvas.innerHTML='';

  pairs.forEach((p,i)=>{
    const el=document.createElement('div');
    const r=p.desk.rotation||0;
    el.className='res-desk'+(p.student?'':' empty-d');
    el.dataset.idx=i;
    el.style.cssText=`left:${p.desk.x-minX+pad}px;top:${p.desk.y-minY+pad}px;transform:rotate(${r}deg);--r:${r}deg;animation-delay:${i*.025}s`;
    el.innerHTML=`<span class="dnum">${i+1}</span>${fmtName(p.student)}`;
    // drag events attached dynamically when edit mode is toggled
    canvas.appendChild(el);
  });

  renderResultList();

  showView('result');
  document.getElementById('result-view').classList.add('active');
  document.getElementById('home-view').classList.remove('active');
  if(!fromSaved)confetti();
}

function toggleEditMode(){
  editMode=!editMode;
  updateEditModeUI();
}

function updateEditModeUI(){
  const btn=document.getElementById('edit-mode-btn');
  const canvas=document.getElementById('result-canvas');
  if(!btn||!canvas)return;
  if(editMode){
    btn.textContent='✓ Klar';
    btn.classList.remove('btn-secondary');btn.classList.add('btn-primary');
    // attach drag to all student desks
    canvas.querySelectorAll('.res-desk:not(.empty-d)').forEach(el=>{
      const i=parseInt(el.dataset.idx);
      el.classList.add('draggable');
      el._mdHandler=e=>startSwapDrag(e,i,el,canvas);
      el._tsHandler=e=>startSwapTouch(e,i,el,canvas);
      el.addEventListener('mousedown',el._mdHandler);
      el.addEventListener('touchstart',el._tsHandler,{passive:false});
    });
  } else {
    btn.textContent='✏️ Redigera placering';
    btn.classList.remove('btn-primary');btn.classList.add('btn-secondary');
    // detach drag from all desks
    canvas.querySelectorAll('.res-desk').forEach(el=>{
      el.classList.remove('draggable','dragging','drop-target');
      if(el._mdHandler){el.removeEventListener('mousedown',el._mdHandler);delete el._mdHandler}
      if(el._tsHandler){el.removeEventListener('touchstart',el._tsHandler);delete el._tsHandler}
    });
  }
}


function renderResultList(){
  const {pairs}=shuffleResult;
  const list=document.getElementById('result-list');
  list.innerHTML='';
  pairs.forEach((p,i)=>{
    if(!p.student)return;
    const el=document.createElement('div');
    el.className='result-item';
    el.dataset.idx=i;
    el.innerHTML=`<div class="snum">Bänk ${i+1}</div>${escH(p.student)}`;
    list.appendChild(el);
  });
}

// ─── SWAP DRAG (mouse) ────────────────────────────────────────────────────
function startSwapDrag(e,idx,el,canvas){
  e.preventDefault();
  dragPairIdx=idx;
  el.classList.add('dragging');

  // create floating ghost
  ghost=document.createElement('div');
  ghost.id='drag-ghost';
  ghost.innerHTML=fmtName(shuffleResult.pairs[idx].student);
  document.body.appendChild(ghost);
  moveGhost(e.clientX,e.clientY);

  function onMove(ev){
    moveGhost(ev.clientX,ev.clientY);
    highlightTarget(ev.clientX,ev.clientY,canvas);
  }
  function onUp(ev){
    document.removeEventListener('mousemove',onMove);
    document.removeEventListener('mouseup',onUp);
    finishSwap(ev.clientX,ev.clientY,canvas,el);
  }
  document.addEventListener('mousemove',onMove);
  document.addEventListener('mouseup',onUp);
}

// ─── SWAP DRAG (touch) ───────────────────────────────────────────────────
function startSwapTouch(e,idx,el,canvas){
  e.preventDefault();
  dragPairIdx=idx;
  el.classList.add('dragging');

  ghost=document.createElement('div');
  ghost.id='drag-ghost';
  ghost.innerHTML=fmtName(shuffleResult.pairs[idx].student);
  document.body.appendChild(ghost);
  const t=e.touches[0];
  moveGhost(t.clientX,t.clientY);

  function onMove(ev){
    ev.preventDefault();
    const tc=ev.touches[0];
    moveGhost(tc.clientX,tc.clientY);
    highlightTarget(tc.clientX,tc.clientY,canvas);
  }
  function onEnd(ev){
    document.removeEventListener('touchmove',onMove);
    document.removeEventListener('touchend',onEnd);
    const tc=ev.changedTouches[0];
    finishSwap(tc.clientX,tc.clientY,canvas,el);
  }
  document.addEventListener('touchmove',onMove,{passive:false});
  document.addEventListener('touchend',onEnd);
}

function moveGhost(cx,cy){
  if(!ghost)return;
  ghost.style.left=(cx-40)+'px';
  ghost.style.top=(cy-25)+'px';
}

function highlightTarget(cx,cy,canvas){
  // clear all highlights
  canvas.querySelectorAll('.res-desk').forEach(d=>d.classList.remove('drop-target'));
  const target=deskAtPoint(cx,cy,canvas);
  if(target&&parseInt(target.dataset.idx)!==dragPairIdx)
    target.classList.add('drop-target');
}

function deskAtPoint(cx,cy,canvas){
  // find topmost res-desk element at pointer
  const els=document.elementsFromPoint(cx,cy);
  return els.find(e=>e.classList.contains('res-desk')&&!e.classList.contains('dragging'))||null;
}

function finishSwap(cx,cy,canvas,srcEl){
  // clean up ghost
  if(ghost){ghost.remove();ghost=null}
  canvas.querySelectorAll('.res-desk').forEach(d=>d.classList.remove('drop-target'));
  srcEl.classList.remove('dragging');

  if(dragPairIdx===null)return;
  const target=deskAtPoint(cx,cy,canvas);
  if(target){
    const targetIdx=parseInt(target.dataset.idx);
    if(targetIdx!==dragPairIdx){
      performSwap(dragPairIdx,targetIdx,canvas);
    }
  }
  dragPairIdx=null;
}

function performSwap(idxA,idxB,canvas){
  const {pairs}=shuffleResult;
  // swap students
  const tmp=pairs[idxA].student;
  pairs[idxA].student=pairs[idxB].student;
  pairs[idxB].student=tmp;

  // update DOM for both desks
  [idxA,idxB].forEach(idx=>{
    const el=canvas.querySelector(`.res-desk[data-idx="${idx}"]`);
    if(!el)return;
    const p=pairs[idx];
    el.className='res-desk'+(p.student?'':' empty-d')+' swapped';
    el.innerHTML=`<span class="dnum">${idx+1}</span>${fmtName(p.student)}`;
    // re-attach drag events only if still in edit mode
    el.classList.remove('draggable');
    if(el._mdHandler){el.removeEventListener('mousedown',el._mdHandler);delete el._mdHandler}
    if(el._tsHandler){el.removeEventListener('touchstart',el._tsHandler);delete el._tsHandler}
    if(p.student&&editMode){
      el.classList.add('draggable');
      el.style.cursor='grab';
      el._mdHandler=e=>startSwapDrag(e,idx,el,canvas);
      el._tsHandler=e=>startSwapTouch(e,idx,el,canvas);
      el.addEventListener('mousedown',el._mdHandler);
      el.addEventListener('touchstart',el._tsHandler,{passive:false});
    } else {
      el.style.cursor='default';
    }
    // remove animation class after it plays
    setTimeout(()=>el.classList.remove('swapped'),450);
  });

  // refresh side list
  renderResultList();
}

// ═══════════════ SAVED PLACEMENTS ═══════════════
function getSaved(){return DB.get('saved',[]);}
function setSaved(s){DB.set('saved',s);}

function openSaveModal(){
  if(!shuffleResult)return;
  const {room,cls,savedName}=shuffleResult;
  const now=new Date();
  const dateStr=now.toLocaleDateString('sv-SE',{weekday:'short',day:'numeric',month:'short'});
  const defaultName=savedName||(cls.name&&room.name?`${cls.name} — ${room.name} (${dateStr})`:`Placering ${dateStr}`);
  document.getElementById('save-name-input').value=defaultName;
  openModal('save-modal');
  setTimeout(()=>{const inp=document.getElementById('save-name-input');inp.focus();inp.select();},80);
}

function confirmSavePlacement(){
  if(!shuffleResult)return;
  const {room,cls,pairs,savedId}=shuffleResult;
  const rawName=document.getElementById('save-name-input').value.trim();
  const now=new Date();
  const autoName=`${cls.name||'Klass'} — ${room.name||'Sal'} (${now.toLocaleDateString('sv-SE',{day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'})})`;
  const name=rawName||autoName;

  const saved=getSaved();
  const entry={
    id:savedId||'pl_'+Date.now(),
    name,
    roomId:room.id||null,
    classId:cls.id||null,
    roomName:room.name,
    className:cls.name,
    savedAt:now.toISOString(),
    pairs:pairs.map(p=>({
      student:p.student,
      desk:{x:p.desk.x,y:p.desk.y,rotation:p.desk.rotation||0}
    }))
  };

  if(savedId){
    // overwrite existing
    const idx=saved.findIndex(p=>p.id===savedId);
    if(idx>=0){saved[idx]=entry}else{saved.unshift(entry)}
  } else {
    saved.unshift(entry);
  }
  shuffleResult.savedId=entry.id;
  shuffleResult.savedName=name;
  setSaved(saved);
  closeModal('save-modal');
  showToast('✓ Placering sparad!');
}

function showToast(msg){
  const t=document.getElementById('save-toast');
  t.textContent=msg;
  t.classList.add('show');
  setTimeout(()=>t.classList.remove('show'),2800);
}

function renderSavedList(){
  const saved=getSaved();
  const el=document.getElementById('saved-list');
  if(!saved.length){
    el.innerHTML=`<div class="empty" style="padding:60px 20px"><div class="ico">📋</div><p>Inga sparade placeringar ännu.<br>Slumpa en placering och tryck <strong style="color:var(--accent)">Spara placering</strong>.</p></div>`;
    return;
  }
  el.innerHTML=saved.map(p=>{
    const d=new Date(p.savedAt);
    const dateStr=d.toLocaleDateString('sv-SE',{weekday:'long',day:'numeric',month:'long',hour:'2-digit',minute:'2-digit'});
    const count=p.pairs.filter(x=>x.student).length;
    const byMeta=p.createdByName?` · skapad av ${escH(p.createdByName)}`:'';
    const createdMeta=p.createdAt?` · ${escH(fmtMetaDate(p.createdAt))}`:'';
    return`<div class="placement-item" id="pi_${p.id}" onclick="openSavedPlacement('${p.id}')">
      <div class="pi-main">
        <div class="pi-name">${escH(p.name)}</div>
        <div class="pi-meta">${escH(p.className)} · ${escH(p.roomName)} · ${count} elever · ${dateStr}${byMeta}${createdMeta}</div>
      </div>
      <div class="pi-actions">
        <button class="btn btn-secondary btn-sm" onclick="event.stopPropagation();openSavedPlacement('${p.id}')">Öppna</button>
        <button class="btn btn-danger btn-sm" onclick="event.stopPropagation();confirmDeleteSaved('${p.id}')">🗑 Ta bort</button>
      </div>
    </div>
    <div class="delete-confirm hidden" id="dc_${p.id}">
      <span>Är du säker?</span>
      <button class="btn btn-danger btn-sm" onclick="deleteSaved('${p.id}')">Ja, radera</button>
      <button class="btn btn-secondary btn-sm" onclick="cancelDelete('${p.id}')">Avbryt</button>
    </div>`;
  }).join('');
}

function openSavedPlacement(id){
  const entry=getSaved().find(p=>p.id===id);
  if(!entry)return;
  // reconstruct a shuffleResult-like object from the saved entry
  // We don't have the original room/class objects, so we build minimal proxies
  shuffleResult={
    room:{name:entry.roomName,id:null},
    cls:{name:entry.className,id:null},
    pairs:entry.pairs.map((p,i)=>({
      student:p.student,
      desk:{x:p.desk.x,y:p.desk.y,rotation:p.desk.rotation||0}
    })),
    savedId:entry.id,
    savedName:entry.name
  };
  // Flag that this came from a saved placement — we keep the class/room ids
  // for re-shuffle if available
  const cls=(entry.classId?getClasses().find(c=>c.id===entry.classId):null)||getClasses().find(c=>c.name===entry.className);
  const room=(entry.roomId?getRooms().find(r=>r.id===entry.roomId):null)||getRooms().find(r=>r.name===entry.roomName);
  if(cls)shuffleResult.cls={...shuffleResult.cls,...cls};
  if(room)shuffleResult.room={...shuffleResult.room,...room};

  renderResult(true); // true = from saved, skip confetti
}

function confirmDeleteSaved(id){
  // hide any other open confirmations first
  document.querySelectorAll('.delete-confirm').forEach(el=>{
    if(el.id!=='dc_'+id)el.classList.add('hidden');
  });
  document.getElementById('dc_'+id)?.classList.toggle('hidden');
}
function cancelDelete(id){
  document.getElementById('dc_'+id)?.classList.add('hidden');
}
function deleteSaved(id){
  setSaved(getSaved().filter(p=>p.id!==id));
  renderSavedList();
}

// ═══════════════ PROFILE ═══════════════
function renderProfile(){
  if(!currentUser)return;
  const u=document.getElementById('profile-username');
  const f=document.getElementById('profile-fullname');
  const e=document.getElementById('profile-email');
  const p1=document.getElementById('profile-password1');
  const p2=document.getElementById('profile-password2');
  if(u)u.value=currentUser.username||'';
  if(f)f.value=currentUser.fullName||'';
  if(e)e.value=currentUser.email||'';
  if(p1)p1.value='';
  if(p2)p2.value='';
}

async function saveProfile(){
  const fullName=(document.getElementById('profile-fullname')?.value||'').trim();
  const email=(document.getElementById('profile-email')?.value||'').trim();
  const password=(document.getElementById('profile-password1')?.value||'');
  const password2=(document.getElementById('profile-password2')?.value||'');

  if(fullName.length<2){alert('Ange ditt riktiga namn.');return}
  if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){alert('Ogiltig e-postadress.');return}
  if(password!==''&&password.length<8){alert('Nytt lösenord måste vara minst 8 tecken.');return}
  if(password!==password2){alert('Lösenorden matchar inte.');return}

  try{
    const res=await apiRequest('api/profile.php',{
      method:'POST',
      body:JSON.stringify({fullName,email,password,password2})
    });
    if(!res?.ok||!res.user){
      alert('Kunde inte spara profil.');
      return;
    }
    currentUser=res.user;
    isSiteAdmin=(currentUser?.role||'')==='admin';
    updateUserBadge();
    renderProfile();
    showToast('✓ Profil sparad!');
  }catch(e){
    console.error(e);
    alert(e?.data?.message||'Kunde inte spara profil.');
  }
}

// ═══════════════ CONFETTI ═══════════════
function confetti(){
  const w=document.getElementById('cfWrap');w.innerHTML='';
  const c=['#c8f060','#60c8f0','#f060c8','#f0c860','#fff'];
  for(let i=0;i<60;i++){
    const p=document.createElement('div');p.className='cp';
    p.style.cssText=`left:${Math.random()*100}vw;background:${c[i%c.length]};animation-duration:${1.4+Math.random()*2}s;animation-delay:${Math.random()*.7}s`;
    w.appendChild(p);setTimeout(()=>p.remove(),3600);
  }
}

// ═══════════════ ADMIN ═══════════════
let adminUsersCache=[];
let teacherDirectory=[];
function checkPw(){renderAdmin()}
function adminLogout(){
  window.location.href='logout.php';
}
function aTab(t){
  const tabs=['rooms','classes','users'];
  document.querySelectorAll('.atab').forEach((b,i)=>b.classList.toggle('active',tabs[i]===t));
  document.querySelectorAll('.asec').forEach(s=>s.classList.remove('active'));
  document.getElementById('asec-'+t).classList.add('active');
  if(t==='users'&&isSiteAdmin)renderAdminUsersSection();
}
function renderAdmin(){
  const login=document.getElementById('admin-login-screen');
  const panel=document.getElementById('admin-panel');
  if(!isSiteAdmin){
    adminIn=false;
    login.style.display='block';
    panel.style.display='none';
    loadTeacherDirectory().then(()=>{
      const rows=teacherDirectory.map(u=>`<div class="list-item"><div><div class="li-name">${escH(u.fullName)}</div><div class="li-sub">${escH(u.email)} · @${escH(u.username)} · ${escH(u.role)}</div></div></div>`).join('');
      login.innerHTML=`<div class="section-title">Lärarkatalog</div>
      <p class="muted mt2 mb2" style="font-size:.82rem">Kontaktuppgifter till godkända användare.</p>
      <div style="text-align:left">${rows||'<p class="muted">Inga användare hittades.</p>'}</div>`;
    });
    return;
  }
  adminIn=true;
  login.style.display='none';
  panel.style.display='block';
  renderRoomList();
  renderClassList();
  loadAdminUsers().then(renderAdminUsersSection);
}

function fmtMetaDate(s){
  if(!s)return'';
  const d=new Date(s);
  if(Number.isNaN(d.getTime()))return'';
  return d.toLocaleDateString('sv-SE',{year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
}

async function loadAdminUsers(){
  if(!isSiteAdmin)return;
  try{
    const data=await apiRequest('api/admin_users.php');
    adminUsersCache=Array.isArray(data?.users)?data.users:[];
  }catch(e){
    console.error(e);
    showToast('Kunde inte hämta användaransökningar.');
  }
}

async function loadTeacherDirectory(){
  try{
    const data=await apiRequest('api/users.php');
    teacherDirectory=Array.isArray(data?.users)?data.users:[];
  }catch(e){
    console.error(e);
    teacherDirectory=[];
  }
}

async function adminUserAction(action,userId,role='teacher'){
  try{
    const data=await apiRequest('api/admin_users.php',{method:'POST',body:JSON.stringify({action,userId,role})});
    adminUsersCache=Array.isArray(data?.users)?data.users:[];
    renderAdminUsersSection();
  }catch(e){
    console.error(e);
    showToast('Kunde inte uppdatera användare.');
  }
}

function findAdminUserById(userId){
  return adminUsersCache.find(u=>Number(u.id)===Number(userId))||null;
}

function openAdminUserModal(userId){
  const u=findAdminUserById(userId);
  if(!u)return;
  const self=Number(u.id)===Number(currentUser?.id);
  document.getElementById('admin-user-id').value=String(u.id);
  document.getElementById('admin-user-username').value=u.username||'';
  document.getElementById('admin-user-fullname').value=u.fullName||'';
  document.getElementById('admin-user-email').value=u.email||'';
  document.getElementById('admin-user-role').value=u.role||'teacher';
  document.getElementById('admin-user-status').value=u.status||'approved';
  document.getElementById('admin-user-password1').value='';
  document.getElementById('admin-user-password2').value='';
  document.getElementById('admin-user-role').disabled=self;
  document.getElementById('admin-user-status').disabled=self;
  openModal('admin-user-modal');
}

async function saveAdminUserEdit(){
  const userId=parseInt(document.getElementById('admin-user-id').value,10)||0;
  if(userId<=0)return;
  const username=(document.getElementById('admin-user-username').value||'').trim();
  const fullName=(document.getElementById('admin-user-fullname').value||'').trim();
  const email=(document.getElementById('admin-user-email').value||'').trim();
  const role=(document.getElementById('admin-user-role').value||'teacher').trim();
  const status=(document.getElementById('admin-user-status').value||'approved').trim();
  const password=(document.getElementById('admin-user-password1').value||'');
  const password2=(document.getElementById('admin-user-password2').value||'');

  if(!/^[A-Za-z0-9_.-]{3,50}$/.test(username)){alert('Användarnamn måste vara 3-50 tecken (A-Z, 0-9, _, -, .).');return}
  if(fullName.length<2){alert('Ange riktigt namn.');return}
  if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){alert('Ogiltig e-postadress.');return}
  if(password!==''&&password.length<8){alert('Nytt lösenord måste vara minst 8 tecken.');return}
  if(password!==password2){alert('Lösenorden matchar inte.');return}

  try{
    const data=await apiRequest('api/admin_users.php',{
      method:'POST',
      body:JSON.stringify({action:'update_user',userId,username,fullName,email,role,status,password,password2})
    });
    adminUsersCache=Array.isArray(data?.users)?data.users:[];
    const updated=findAdminUserById(userId);
    if(updated&&Number(currentUser?.id)===userId){
      currentUser={...currentUser,username:updated.username,fullName:updated.fullName,email:updated.email,role:updated.role};
      isSiteAdmin=(currentUser?.role||'')==='admin';
      updateUserBadge();
      renderProfile();
    }
    renderAdminUsersSection();
    closeModal('admin-user-modal');
    showToast('✓ Användare uppdaterad');
  }catch(e){
    console.error(e);
    alert(e?.data?.message||'Kunde inte spara användaren.');
  }
}

function renderAdminUsersSection(){
  const pendingHost=document.getElementById('pending-users-list');
  const existingHost=document.getElementById('existing-users-list');
  if(!pendingHost||!existingHost)return;
  if(!isSiteAdmin){
    pendingHost.innerHTML='<p class="muted">Ingen behörighet.</p>';
    existingHost.innerHTML='<p class="muted">Ingen behörighet.</p>';
    return;
  }
  const pending=adminUsersCache.filter(u=>u.status==='pending');
  const existing=adminUsersCache.filter(u=>u.status!=='pending');

  if(!pending.length){
    pendingHost.innerHTML='<p class="muted">Inga väntande ansökningar.</p>';
  }else{
    pendingHost.innerHTML=pending.map(u=>{
      const created=fmtMetaDate(u.createdAt);
      const meta=[u.email,created?`Ansökt ${created}`:''].filter(Boolean).join(' · ');
      return`<div class="list-item">
        <div>
          <div class="li-name">${escH(u.fullName)} <span class="hint" style="margin:0">(@${escH(u.username)})</span></div>
          <div class="li-sub">${escH(meta)}</div>
        </div>
        <div class="flex gap2">
          <button class="btn btn-secondary btn-sm" onclick="openAdminUserModal(${u.id})">Öppna</button>
          <button class="btn btn-primary btn-sm" onclick="adminUserAction('approve',${u.id},'teacher')">Godkänn</button>
          <button class="btn btn-danger btn-sm" onclick="adminUserAction('reject',${u.id})">Avslå</button>
        </div>
      </div>`;
    }).join('');
  }

  if(!existing.length){
    existingHost.innerHTML='<p class="muted">Inga användare att visa.</p>';
    return;
  }

  existingHost.innerHTML=existing.map(u=>{
    const created=fmtMetaDate(u.createdAt);
    const approved=fmtMetaDate(u.approvedAt);
    const self=(u.id===Number(currentUser?.id));
    const meta=[
      u.email,
      created?`Ansökt ${created}`:'',
      approved?`Godkänd ${approved}${u.approvedByName?` av ${u.approvedByName}`:''}`:''
    ].filter(Boolean).join(' · ');
    const editBtn=`<button class="btn btn-secondary btn-sm" onclick="openAdminUserModal(${u.id})">Öppna</button>`;
    let actions=editBtn;
    if(u.status==='approved'&&!self){
      const roleBtn=u.role==='admin'
        ?`<button class="btn btn-secondary btn-sm" onclick="adminUserAction('set_role',${u.id},'teacher')">Gör lärare</button>`
        :`<button class="btn btn-secondary btn-sm" onclick="adminUserAction('set_role',${u.id},'admin')">Gör admin</button>`;
      actions=`${editBtn}${roleBtn}<button class="btn btn-danger btn-sm" onclick="adminUserAction('disable',${u.id})">Inaktivera</button>`;
    }else if(u.status==='disabled'||u.status==='rejected'){
      actions=`${editBtn}<button class="btn btn-secondary btn-sm" onclick="adminUserAction('enable',${u.id})">Aktivera</button>${
        !self&&u.role!=='admin'?`<button class="btn btn-secondary btn-sm" onclick="adminUserAction('set_role',${u.id},'admin')">Gör admin</button>`:''
      }`;
    }
    return`<div class="list-item">
      <div>
        <div class="li-name">${escH(u.fullName)} <span class="hint" style="margin:0">(@${escH(u.username)})</span></div>
        <div class="li-sub">${escH(meta)} · Status: ${escH(u.status)} · Roll: ${escH(u.role)}</div>
      </div>
      <div class="flex gap2">${actions}</div>
    </div>`;
  }).join('');
}

function renderRoomList(){
  const rooms=getRooms(),el=document.getElementById('room-list');
  if(!rooms.length){el.innerHTML=`<div class="empty"><div class="ico">🏫</div><p>Inga salar</p></div>`;return}
  el.innerHTML=rooms.map(r=>`<div class="list-item">
    <div><div class="li-name">${escH(r.name)}</div><div class="li-sub">${r.desks.filter(d=>!d.inPool).length} bänkar placerade${r.createdByName?` · skapad av ${escH(r.createdByName)}`:''}${r.createdAt?` · ${escH(fmtMetaDate(r.createdAt))}`:''}</div></div>
    <div class="flex gap2">
      <button class="btn btn-secondary btn-sm" onclick="openEditor('${r.id}')">Redigera</button>
      <button class="btn btn-danger btn-sm" onclick="deleteRoom('${r.id}')">✕</button>
    </div></div>`).join('');
}
function deleteRoom(id){
  if(!confirm('Radera denna sal?'))return;
  setRooms(getRooms().filter(r=>r.id!==id));
  if(selRoom===id)selRoom=null;
  renderRoomList();
}

function renderClassList(){
  const classes=getClasses(),el=document.getElementById('class-list');
  if(!classes.length){el.innerHTML=`<div class="empty"><div class="ico">👥</div><p>Inga klasser</p></div>`;return}
  el.innerHTML=classes.map(c=>`<div class="list-item">
    <div><div class="li-name">${escH(c.name)}</div><div class="li-sub">${c.students.length} elever${c.createdByName?` · skapad av ${escH(c.createdByName)}`:''}${c.createdAt?` · ${escH(fmtMetaDate(c.createdAt))}`:''}</div></div>
    <div class="flex gap2">
      <button class="btn btn-secondary btn-sm" onclick="openClassModal('${c.id}')">Redigera</button>
      <button class="btn btn-danger btn-sm" onclick="deleteClass('${c.id}')">✕</button>
    </div></div>`).join('');
}
function openClassModal(id){
  editingClassId=id;
  const cls=id?getClasses().find(c=>c.id===id):null;
  document.getElementById('class-modal-title').textContent=cls?'Redigera klass':'Ny klass';
  document.getElementById('class-name-in').value=cls?cls.name:'';
  document.getElementById('class-students-in').value=cls?cls.students.join('\n'):'';
  openModal('class-modal');
}
function saveClass(){
  const name=document.getElementById('class-name-in').value.trim();
  if(!name){alert('Ange ett namn');return}
  const students=document.getElementById('class-students-in').value.split('\n').map(s=>s.trim()).filter(Boolean);
  if(!students.length){alert('Lägg till minst en elev');return}
  const classes=getClasses();
  if(editingClassId){const i=classes.findIndex(c=>c.id===editingClassId);if(i>=0)classes[i]={...classes[i],name,students}}
  else classes.push({id:'cls_'+Date.now(),name,students});
  setClasses(classes);closeModal('class-modal');renderClassList();
}
function deleteClass(id){
  if(!confirm('Radera klass?'))return;
  setClasses(getClasses().filter(c=>c.id!==id));
  if(selClass===id)selClass=null;renderClassList();
}
function changePw(){showToast('Lösenord hanteras via inloggningsdelen.')}
function resetAll(){showToast('Global återställning är avstängd i serverläge.')}

// ══════════════════════════════════════════════
// ═══════════════ ROOM EDITOR ═══════════════
// ══════════════════════════════════════════════

function makeEditorDesk(){
  editorDeskIdSeq++;
  const id='d'+Date.now()+'_'+editorDeskIdSeq;
  return {id,x:60,y:60,rotation:0,inPool:true};
}

function normalizeEditorDesk(d){
  let id=d?.id;
  if(!id){
    editorDeskIdSeq++;
    id='d'+Date.now()+'_'+editorDeskIdSeq;
  }
  return {
    id,
    x:Number.isFinite(Number(d?.x))?Number(d.x):60,
    y:Number.isFinite(Number(d?.y))?Number(d.y):60,
    rotation:Number.isFinite(Number(d?.rotation))?Number(d.rotation):0,
    inPool:!!d?.inPool
  };
}

function getEditorCanvasSize(){
  const canvas=document.getElementById('editor-canvas');
  return {
    canvas,
    W:canvas?.offsetWidth||700,
    H:canvas?.offsetHeight||500
  };
}

function updateEditorDeskNumMap(){
  editorDeskNumById=new Map();
  editorDesks.forEach((d,i)=>editorDeskNumById.set(d.id,i+1));
}

function setDeskCount(cnt){
  const next=Math.max(1,Math.min(60,parseInt(cnt,10)||1));
  const cur=editorDesks.length;
  if(next>cur){
    for(let i=cur;i<next;i++)editorDesks.push(makeEditorDesk());
  }else if(next<cur){
    let rem=cur-next;
    for(let i=editorDesks.length-1;i>=0&&rem>0;i--){
      if(editorDesks[i].inPool){
        if(selectedId===editorDesks[i].id)selectedId=null;
        editorDesks.splice(i,1);
        rem--;
      }
    }
    for(let i=editorDesks.length-1;i>=0&&rem>0;i--){
      if(selectedId===editorDesks[i].id)selectedId=null;
      editorDesks.splice(i,1);
      rem--;
    }
  }
  document.getElementById('desk-count-input').value=editorDesks.length;
}

function renderEditor(){
  updateEditorDeskNumMap();
  renderEditorCanvas();
  renderPool();
  updateSelInfo();
}

function bindEditorInteractions(){
  if(editorBindingsReady)return;
  const canvas=document.getElementById('editor-canvas');
  const pool=document.getElementById('desk-pool');
  if(!canvas||!pool)return;

  canvas.addEventListener('dragover',e=>{
    e.preventDefault();
    canvas.classList.add('drag-over');
  });
  canvas.addEventListener('dragleave',()=>canvas.classList.remove('drag-over'));
  canvas.addEventListener('drop',e=>{
    e.preventDefault();
    canvas.classList.remove('drag-over');
    const id=e.dataTransfer.getData('desk_id');
    const desk=editorDesks.find(x=>x.id===id);
    if(!desk)return;
    const rect=canvas.getBoundingClientRect();
    desk.x=clamp(e.clientX-rect.left-EDITOR_DW/2,0,canvas.offsetWidth-EDITOR_DW);
    desk.y=clamp(e.clientY-rect.top-EDITOR_DH/2,0,canvas.offsetHeight-EDITOR_DH);
    desk.inPool=false;
    renderEditor();
    selectDesk(id);
  });
  canvas.addEventListener('click',e=>{
    if(e.target===canvas)deselect();
  });

  pool.addEventListener('dragover',e=>{
    e.preventDefault();
    pool.classList.add('drag-over');
  });
  pool.addEventListener('dragleave',()=>pool.classList.remove('drag-over'));
  pool.addEventListener('drop',e=>{
    e.preventDefault();
    pool.classList.remove('drag-over');
    const id=e.dataTransfer.getData('desk_id');
    const desk=editorDesks.find(x=>x.id===id);
    if(!desk)return;
    desk.inPool=true;
    if(selectedId===id)selectedId=null;
    renderEditor();
  });

  editorBindingsReady=true;
}

function openEditor(id){
  editingRoomId=id;
  const room=id?getRooms().find(r=>r.id===id):null;
  document.getElementById('editor-title').textContent=room?'Redigera sal':'Ny sal';
  document.getElementById('editor-room-name').value=room?room.name:'';
  selectedId=null;

  if(room){
    editorDesks=(room.desks||[]).map(normalizeEditorDesk);
    document.getElementById('desk-count-input').value=editorDesks.length;
  }else{
    editorDesks=[];
    for(let i=0;i<20;i++)editorDesks.push(makeEditorDesk());
    document.getElementById('desk-count-input').value=20;
    document.getElementById('layout-rows').value=5;
    document.getElementById('layout-preset').value='2-3-2';
    document.getElementById('layout-fit-count').checked=true;
  }

  openModal('editor-modal');
  requestAnimationFrame(()=>{
    bindEditorInteractions();
    renderEditor();
  });
}

function applyCount(){
  setDeskCount(document.getElementById('desk-count-input').value);
  renderEditor();
}

function autoArrange(){
  const {canvas,W,H}=getEditorCanvasSize();
  if(!canvas||!editorDesks.length)return;
  const total=editorDesks.length;
  const availW=Math.max(EDITOR_DW,W-EDITOR_SIDE_PAD*2);
  let cols=Math.max(1,Math.floor((availW+16)/(EDITOR_DW+16)));
  cols=Math.min(cols,total);
  const rows=Math.ceil(total/cols);
  const gapX=cols>1?clamp(Math.floor((availW-cols*EDITOR_DW)/(cols-1)),8,24):0;
  const availH=Math.max(EDITOR_DH,H-EDITOR_TOP_OFFSET-EDITOR_SIDE_PAD);
  const gapY=rows>1?clamp(Math.floor((availH-rows*EDITOR_DH)/(rows-1)),10,26):0;
  const totalW=cols*EDITOR_DW+(cols-1)*gapX;
  const totalH=rows*EDITOR_DH+(rows-1)*gapY;
  const startX=Math.max(0,Math.round((W-totalW)/2));
  const startY=clamp(Math.round((H-totalH)/2),EDITOR_TOP_OFFSET,Math.max(EDITOR_TOP_OFFSET,H-EDITOR_DH));

  editorDesks.forEach((d,i)=>{
    const row=Math.floor(i/cols);
    const col=i%cols;
    d.x=clamp(startX+col*(EDITOR_DW+gapX),0,W-EDITOR_DW);
    d.y=clamp(startY+row*(EDITOR_DH+gapY),0,H-EDITOR_DH);
    d.rotation=0;
    d.inPool=false;
  });
  renderEditor();
}

function applyPresetLayout(){
  const preset=(document.getElementById('layout-preset').value||'').trim();
  const blocks=preset.split('-').map(n=>parseInt(n,10)).filter(n=>Number.isFinite(n)&&n>0);
  const rows=Math.max(1,Math.min(12,parseInt(document.getElementById('layout-rows').value,10)||1));
  const fitCount=document.getElementById('layout-fit-count').checked;
  if(!blocks.length){
    alert('Ogiltigt mönster');
    return;
  }

  const colsPerRow=blocks.reduce((a,b)=>a+b,0);
  const slots=Math.min(60,colsPerRow*rows);
  document.getElementById('layout-rows').value=rows;

  if(fitCount)setDeskCount(slots);
  const placeCount=Math.min(editorDesks.length,slots);

  const {canvas,W,H}=getEditorCanvasSize();
  if(!canvas||!placeCount)return;
  if(colsPerRow*EDITOR_DW>W-8){
    alert('Mönstret är för brett för arbetsytan. Välj färre kolumner.');
    return;
  }

  const aisleCount=Math.max(0,blocks.length-1);
  const gapUnits=Math.max(1,(colsPerRow-1)+aisleCount);
  const availW=Math.max(0,W-EDITOR_SIDE_PAD*2-colsPerRow*EDITOR_DW);
  const gapX=clamp(Math.floor(availW/gapUnits),6,24);
  const totalW=colsPerRow*EDITOR_DW+(colsPerRow-1)*gapX+aisleCount*gapX;
  const startX=Math.max(0,Math.round((W-totalW)/2));

  const availH=Math.max(0,H-EDITOR_TOP_OFFSET-EDITOR_SIDE_PAD-rows*EDITOR_DH);
  const gapY=rows>1?clamp(Math.floor(availH/(rows-1)),10,26):0;
  const totalH=rows*EDITOR_DH+(rows-1)*gapY;
  const startY=clamp(Math.round((H-totalH)/2),EDITOR_TOP_OFFSET,Math.max(EDITOR_TOP_OFFSET,H-EDITOR_DH));

  const xPositions=[];
  let x=startX;
  blocks.forEach((block,bi)=>{
    for(let i=0;i<block;i++){
      xPositions.push(clamp(x,0,W-EDITOR_DW));
      x+=EDITOR_DW+gapX;
    }
    if(bi<blocks.length-1)x+=gapX;
  });

  editorDesks.forEach((d,i)=>{
    if(i<placeCount){
      const row=Math.floor(i/colsPerRow);
      const col=i%colsPerRow;
      d.x=xPositions[col];
      d.y=clamp(startY+row*(EDITOR_DH+gapY),0,H-EDITOR_DH);
      d.rotation=0;
      d.inPool=false;
    }else{
      d.inPool=true;
    }
  });

  renderEditor();
  showToast(`Layout ${blocks.join('-')} × ${rows} rader skapad`);
}

function clearCanvas(){
  editorDesks.forEach(d=>{d.inPool=true});
  selectedId=null;
  renderEditor();
}

// ─── POOL ──────────────────────────────────────
function renderPool(){
  const pool=document.getElementById('desk-pool');
  const poolDesks=editorDesks.filter(d=>d.inPool);
  document.getElementById('pool-count').textContent=`(${poolDesks.length})`;
  if(!poolDesks.length){
    pool.innerHTML='<span class="muted" style="font-size:.7rem">Alla bänkar placerade ✓</span>';
    return;
  }

  const frag=document.createDocumentFragment();
  poolDesks.forEach(d=>{
    const el=document.createElement('div');
    el.className='pool-desk';
    el.draggable=true;
    el.dataset.id=d.id;
    el.innerHTML=`🪑<span class="pool-num">${editorDeskNumById.get(d.id)||'?'}</span>`;
    el.addEventListener('dragstart',e=>{
      e.dataTransfer.setData('desk_id',d.id);
      e.dataTransfer.effectAllowed='move';
      setTimeout(()=>el.style.opacity='.4',0);
    });
    el.addEventListener('dragend',()=>el.style.opacity='1');
    el.addEventListener('touchstart',e=>startPoolTouchDrag(e,d),{passive:false});
    frag.appendChild(el);
  });
  pool.replaceChildren(frag);
}

// ─── CANVAS ────────────────────────────────────
function renderEditorCanvas(){
  const canvas=document.getElementById('editor-canvas');
  if(!canvas)return;
  const frag=document.createDocumentFragment();
  let hasSelected=false;

  editorDesks.forEach(d=>{
    if(d.inPool)return;
    if(d.id===selectedId)hasSelected=true;
    frag.appendChild(spawnDesk(d,canvas,editorDeskNumById.get(d.id)||'?'));
  });

  canvas.replaceChildren(frag);
  if(selectedId&&!hasSelected)selectedId=null;
}

function spawnDesk(d,canvas,num){
  const el=document.createElement('div');
  el.className='canvas-desk'+(d.id===selectedId?' selected':'');
  el.id='ced_'+d.id;
  el.style.left=d.x+'px';
  el.style.top=d.y+'px';
  el.style.transform=`rotate(${d.rotation||0}deg)`;
  el.innerHTML=`<span class="d-ico">🪑</span><span class="d-num">${num}</span>
    <div class="rot-handle" title="Rotera (dra)">↻</div>
    <div class="del-handle" title="Flytta till pool">✕</div>`;

  el.addEventListener('click',e=>{
    e.stopPropagation();
    selectDesk(d.id);
  });

  el.addEventListener('mousedown',e=>{
    if(e.target.classList.contains('rot-handle')||e.target.classList.contains('del-handle'))return;
    e.preventDefault();
    e.stopPropagation();
    selectDesk(d.id);
    mouseDragDesk(e,d,el,canvas);
  });

  el.addEventListener('touchstart',e=>{
    if(e.target.classList.contains('rot-handle')||e.target.classList.contains('del-handle'))return;
    e.preventDefault();
    e.stopPropagation();
    selectDesk(d.id);
    touchDragDesk(e,d,el,canvas);
  },{passive:false});

  el.querySelector('.rot-handle').addEventListener('mousedown',e=>{
    e.preventDefault();
    e.stopPropagation();
    mouseRotateDesk(e,d,el);
  });

  el.querySelector('.del-handle').addEventListener('click',e=>{
    e.stopPropagation();
    d.inPool=true;
    if(selectedId===d.id)selectedId=null;
    renderEditor();
  });

  return el;
}

// ─── DRAG (mouse) ──────────────────────────────
function mouseDragDesk(e,d,el,canvas){
  const rect=canvas.getBoundingClientRect();
  const offX=e.clientX-rect.left-d.x;
  const offY=e.clientY-rect.top-d.y;
  function move(ev){
    d.x=clamp(ev.clientX-rect.left-offX,0,canvas.offsetWidth-EDITOR_DW);
    d.y=clamp(ev.clientY-rect.top-offY,0,canvas.offsetHeight-EDITOR_DH);
    el.style.left=d.x+'px';
    el.style.top=d.y+'px';
    if(selectedId===d.id){
      if(selInfoRaf)return;
      selInfoRaf=requestAnimationFrame(()=>{selInfoRaf=0;updateSelInfo()});
    }
  }
  function up(){
    document.removeEventListener('mousemove',move);
    document.removeEventListener('mouseup',up);
    updateSelInfo();
  }
  document.addEventListener('mousemove',move);
  document.addEventListener('mouseup',up);
}

// ─── DRAG (touch - canvas desk) ───────────────
function touchDragDesk(e,d,el,canvas){
  const rect=canvas.getBoundingClientRect();
  const t=e.touches[0];
  const offX=t.clientX-rect.left-d.x;
  const offY=t.clientY-rect.top-d.y;
  function move(ev){
    ev.preventDefault();
    const tc=ev.touches[0];
    d.x=clamp(tc.clientX-rect.left-offX,0,canvas.offsetWidth-EDITOR_DW);
    d.y=clamp(tc.clientY-rect.top-offY,0,canvas.offsetHeight-EDITOR_DH);
    el.style.left=d.x+'px';
    el.style.top=d.y+'px';
    if(selectedId===d.id){
      if(selInfoRaf)return;
      selInfoRaf=requestAnimationFrame(()=>{selInfoRaf=0;updateSelInfo()});
    }
  }
  function end(){
    document.removeEventListener('touchmove',move);
    document.removeEventListener('touchend',end);
    updateSelInfo();
  }
  document.addEventListener('touchmove',move,{passive:false});
  document.addEventListener('touchend',end);
}

// ─── DRAG (touch - pool desk to canvas) ────────
function startPoolTouchDrag(e,d){
  e.preventDefault();
  const canvas=document.getElementById('editor-canvas');
  if(!canvas)return;

  const ghost=document.createElement('div');
  ghost.style.cssText='position:fixed;width:72px;height:50px;background:var(--surface);border:2px solid var(--accent2);border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:.9rem;pointer-events:none;z-index:9990;opacity:.8;transition:none';
  ghost.textContent='🪑';
  document.body.appendChild(ghost);

  function move(ev){
    ev.preventDefault();
    const tc=ev.touches[0];
    ghost.style.left=(tc.clientX-36)+'px';
    ghost.style.top=(tc.clientY-25)+'px';
  }
  function end(ev){
    document.removeEventListener('touchmove',move);
    document.removeEventListener('touchend',end);
    ghost.remove();
    const tc=ev.changedTouches[0];
    const rect=canvas.getBoundingClientRect();
    if(tc.clientX>=rect.left&&tc.clientX<=rect.right&&tc.clientY>=rect.top&&tc.clientY<=rect.bottom){
      d.x=clamp(tc.clientX-rect.left-EDITOR_DW/2,0,canvas.offsetWidth-EDITOR_DW);
      d.y=clamp(tc.clientY-rect.top-EDITOR_DH/2,0,canvas.offsetHeight-EDITOR_DH);
      d.inPool=false;
      renderEditor();
      selectDesk(d.id);
    }
  }
  document.addEventListener('touchmove',move,{passive:false});
  document.addEventListener('touchend',end);
}

// ─── ROTATE (mouse) ────────────────────────────
function mouseRotateDesk(e,d,el){
  const rect=el.getBoundingClientRect();
  const cx=rect.left+rect.width/2,cy=rect.top+rect.height/2;
  const startA=Math.atan2(e.clientY-cy,e.clientX-cx)*(180/Math.PI);
  const startR=d.rotation||0;
  function move(ev){
    const a=Math.atan2(ev.clientY-cy,ev.clientX-cx)*(180/Math.PI);
    let r=startR+(a-startA);
    r=Math.round(r/5)*5;
    d.rotation=r;
    el.style.transform=`rotate(${r}deg)`;
    const rr=document.getElementById('rot-range');
    if(rr)rr.value=r;
    const rv=document.getElementById('rot-val');
    if(rv)rv.textContent=r+'°';
  }
  function up(){
    document.removeEventListener('mousemove',move);
    document.removeEventListener('mouseup',up);
  }
  document.addEventListener('mousemove',move);
  document.addEventListener('mouseup',up);
}

// ─── SELECT ────────────────────────────────────
function selectDesk(id){
  selectedId=id;
  document.querySelectorAll('.canvas-desk').forEach(el=>el.classList.toggle('selected',el.id==='ced_'+id));
  updateSelInfo();
}

function deselect(){
  selectedId=null;
  document.querySelectorAll('.canvas-desk').forEach(el=>el.classList.remove('selected'));
  updateSelInfo();
}

function updateSelInfo(){
  const info=document.getElementById('sel-info');
  const ctrl=document.getElementById('sel-ctrl');
  if(!selectedId){
    info.textContent='Klicka på en bänk på canvasen';
    ctrl.style.display='none';
    return;
  }
  const d=editorDesks.find(x=>x.id===selectedId);
  if(!d||d.inPool){
    info.textContent='Klicka på en bänk';
    ctrl.style.display='none';
    return;
  }
  const num=editorDeskNumById.get(d.id)||'?';
  info.innerHTML=`<span style="color:var(--accent)">Bänk ${num}</span><br><span style="color:var(--muted)">x:${Math.round(d.x)} y:${Math.round(d.y)}</span>`;
  ctrl.style.display='block';
  document.getElementById('rot-range').value=d.rotation||0;
  document.getElementById('rot-val').textContent=(d.rotation||0)+'°';
}

function applyRotation(val){
  if(!selectedId)return;
  const d=editorDesks.find(x=>x.id===selectedId);
  if(!d)return;
  d.rotation=parseInt(val,10)||0;
  const el=document.getElementById('ced_'+d.id);
  if(el)el.style.transform=`rotate(${d.rotation}deg)`;
  document.getElementById('rot-val').textContent=d.rotation+'°';
}

function deleteSelected(){
  if(!selectedId)return;
  const d=editorDesks.find(x=>x.id===selectedId);
  if(!d)return;
  d.inPool=true;
  selectedId=null;
  renderEditor();
}

// ─── SAVE ROOM ─────────────────────────────────
function saveRoom(){
  const name=document.getElementById('editor-room-name').value.trim();
  if(!name){alert('Ange ett namn för salen');return}
  const rooms=getRooms();
  const data={id:editingRoomId||'room_'+Date.now(),name,desks:editorDesks.map(d=>({...d}))};
  if(editingRoomId){const i=rooms.findIndex(r=>r.id===editingRoomId);if(i>=0)rooms[i]=data;else rooms.push(data)}
  else rooms.push(data);
  setRooms(rooms);closeModal('editor-modal');renderRoomList();
}

// ═══════════════ PDF EXPORT ═══════════════
// Pure JS — no external libraries. Writes a minimal PDF-1.4 by hand.
function exportPDF(){
  const btn=document.getElementById('pdf-btn');
  const orig=btn.textContent;
  btn.textContent='⏳ Genererar…';
  btn.disabled=true;
  setTimeout(()=>{
    try{ buildPDF(); }
    catch(e){ console.error(e); alert('Kunde inte generera PDF: '+e.message); }
    finally{ btn.textContent=orig; btn.disabled=false; }
  },30);
}

function printDirect(){
  if(!shuffleResult?.pairs?.length){
    alert('Ingen placering att skriva ut ännu.');
    return;
  }
  const btn=document.getElementById('print-btn');
  const orig=btn?.textContent;
  if(btn){
    btn.textContent='⏳ Öppnar utskrift…';
    btn.disabled=true;
  }
  setTimeout(()=>{
    window.print();
    if(btn){
      btn.textContent=orig||'🖨 Skriv ut direkt';
      btn.disabled=false;
    }
  },30);
}

function buildPDF(){
  const {pairs}=shuffleResult;
  const title=document.getElementById('res-title').textContent;
  const sub=document.getElementById('res-sub').textContent;

  // A4 landscape in points (1pt = 1/72")
  const PW=841.89, PH=595.28, M=28;

  // Bounding box of desks
  const DW=80, DH=50;
  let minX=Infinity,minY=Infinity,maxX=-Infinity,maxY=-Infinity;
  pairs.forEach(p=>{
    minX=Math.min(minX,p.desk.x); minY=Math.min(minY,p.desk.y);
    maxX=Math.max(maxX,p.desk.x+DW); maxY=Math.max(maxY,p.desk.y+DH);
  });
  const pad=14, boardH_px=18;
  const layoutW=maxX-minX+pad*2;
  const layoutH=maxY-minY+pad*2+boardH_px+6;
  const titleH=34;
  const listW=120;
  const availW=PW-M*2-listW-10;
  const availH=PH-M-titleH-M;
  const scale=Math.min(availW/layoutW, availH/layoutH, 1.2);
  const drawW=layoutW*scale, drawH=layoutH*scale;
  const layoutX=M, layoutY=M+titleH;
  const listX=layoutX+drawW+12;

  // Helpers
  const winAnsiMap={
    '€':0x80,'‚':0x82,'ƒ':0x83,'„':0x84,'…':0x85,'†':0x86,'‡':0x87,'ˆ':0x88,'‰':0x89,
    'Š':0x8A,'‹':0x8B,'Œ':0x8C,'Ž':0x8E,'‘':0x91,'’':0x92,'“':0x93,'”':0x94,'•':0x95,
    '–':0x96,'—':0x97,'˜':0x98,'™':0x99,'š':0x9A,'›':0x9B,'œ':0x9C,'ž':0x9E,'Ÿ':0x9F
  };
  const esc=s=>{
    let out='';
    const src=String(s).replace(/\r?\n/g,' ');
    for(const ch of src){
      let code=ch.charCodeAt(0);
      if(winAnsiMap[ch]!==undefined)code=winAnsiMap[ch];
      else if(code>255)code=63; // '?'
      if(code===40||code===41||code===92){ // ( ) \
        out+='\\'+String.fromCharCode(code);
        continue;
      }
      if(code<32||code>126){
        out+='\\'+code.toString(8).padStart(3,'0');
      }else{
        out+=String.fromCharCode(code);
      }
    }
    return out;
  };
  const rgb=([r,g,b])=>`${(r/255).toFixed(3)} ${(g/255).toFixed(3)} ${(b/255).toFixed(3)}`;
  const fy=y=>PH-y; // flip to PDF coords

  // Always use light-friendly colors for print readability
  const textC=[26,26,31], mutedC=[120,120,130], deskBg=[232,245,208];
  const deskBd=[52,92,10], deskTxt=[28,58,8];
  const boardBg=[210,238,210], boardBd=[90,150,90], boardTxt=[30,80,30];
  const emptyBg=[240,240,238], emptyBd=[190,190,200];
  const pageBg=[250,250,248], panelBg=[242,242,238];

  function rect(x,y,w,h,fill,stroke,lw=0.6){
    return `q ${lw} w ${rgb(fill)} rg ${rgb(stroke)} RG `+
           `${x.toFixed(2)} ${y.toFixed(2)} ${w.toFixed(2)} ${h.toFixed(2)} re B Q\n`;
  }

  let s='';
  // Page background
  s+=`q ${rgb(pageBg)} rg 0 0 ${PW} ${PH} re f Q\n`;

  // Title
  s+=`BT /F1 13 Tf ${rgb(textC)} rg ${M} ${fy(M+13)} Td (${esc(title)}) Tj ET\n`;
  s+=`BT /F2 7.5 Tf ${rgb(mutedC)} rg ${M} ${fy(M+24)} Td (${esc(sub)}) Tj ET\n`;

  // Layout panel background
  s+=rect(layoutX-4, fy(layoutY+drawH+4), drawW+8, drawH+8, panelBg, emptyBd, 0.3);

  // Board bar
  const bh=boardH_px*scale;
  const bw=Math.min(160*scale, drawW*0.7);
  const bx=layoutX+(drawW-bw)/2;
  const by=layoutY;
  s+=rect(bx, fy(by+bh), bw, bh, boardBg, boardBd, 0.8);
  s+=`BT /F1 ${(5.5*scale).toFixed(1)} Tf ${rgb(boardTxt)} rg `+
     `${(bx+6*scale).toFixed(2)} ${fy(by+bh*0.68).toFixed(2)} Td (TAVLA / WHITEBOARD) Tj ET\n`;

  // Desks
  const deskOriginY=layoutY+bh+4*scale;
  pairs.forEach((p,i)=>{
    const dx=layoutX+(p.desk.x-minX+pad)*scale;
    const dy=deskOriginY+(p.desk.y-minY)*scale;
    const dw=DW*scale, dh=DH*scale;
    const rot=p.desk.rotation||0;
    const bg=p.student?deskBg:emptyBg;
    const bd=p.student?deskBd:emptyBd;
    const rad=rot*Math.PI/180;
    const cos=Math.cos(rad).toFixed(4), sin=Math.sin(rad).toFixed(4);
    const cx=(dx+dw/2).toFixed(2), cy=fy(dy+dh/2).toFixed(2);

    s+=`q ${cos} ${sin} ${(-Math.sin(rad)).toFixed(4)} ${cos} ${cx} ${cy} cm\n`;
    s+=rect(-dw/2,-dh/2,dw,dh,bg,bd,1.8);

    if(p.student){
      const name=p.student;
      const sp=name.indexOf(' ');
      const l1=sp>-1?name.slice(0,sp):name;
      const l2=sp>-1?name.slice(sp+1):'';
      const fs=Math.max(5.2, 6.8*scale).toFixed(1);
      const numFs=Math.max(3.5, 4*scale).toFixed(1);
      // Desk number top-right
      s+=`BT /F2 ${numFs} Tf ${rgb(mutedC)} rg ${(dw/2-6).toFixed(2)} ${(dh/2-5).toFixed(2)} Td (${i+1}) Tj ET\n`;
      // Name centered, two lines
      if(l2){
        s+=`BT /F1 ${fs} Tf ${rgb(deskTxt)} rg -${(l1.length*fs*0.28).toFixed(2)} ${(dh*0.12).toFixed(2)} Td (${esc(l1)}) Tj ET\n`;
        s+=`BT /F2 ${fs} Tf ${rgb(deskTxt)} rg -${(l2.length*fs*0.28).toFixed(2)} ${(-dh*0.12).toFixed(2)} Td (${esc(l2)}) Tj ET\n`;
      } else {
        s+=`BT /F1 ${fs} Tf ${rgb(deskTxt)} rg -${(l1.length*fs*0.28).toFixed(2)} ${(-dh*0.04).toFixed(2)} Td (${esc(l1)}) Tj ET\n`;
      }
    }
    s+='Q\n';
  });

  // Seated list on the right
  const seated=pairs.filter(p=>p.student);
  s+=`BT /F1 8 Tf ${rgb(textC)} rg ${listX.toFixed(2)} ${fy(layoutY+10).toFixed(2)} Td (${esc('Placeringar')}) Tj ET\n`;
  const rowH=10;
  seated.forEach((p,i)=>{
    const ly=layoutY+20+i*rowH;
    if(ly>layoutY+drawH+4)return;
    s+=`BT /F2 7 Tf ${rgb(mutedC)} rg ${listX.toFixed(2)} ${fy(ly).toFixed(2)} Td (${i+1}.) Tj ET\n`;
    s+=`BT /F2 7 Tf ${rgb(textC)} rg ${(listX+12).toFixed(2)} ${fy(ly).toFixed(2)} Td (${esc(p.student)}) Tj ET\n`;
  });

  // Footer
  const ds=new Date().toLocaleDateString('sv-SE');
  const footerText=`Klassplacering - ${ds}`;
  s+=`BT /F2 6 Tf ${rgb(mutedC)} rg ${M} ${fy(PH-7)} Td (${esc(footerText)}) Tj ET\n`;

  // ── Assemble PDF objects ──
  const sLen=new TextEncoder().encode(s).length;
  const f1=`<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>`;
  const f2=`<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>`;
  const res=`<< /Font << /F1 4 0 R /F2 5 0 R >> >>`;
  const page=`<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${PW.toFixed(2)} ${PH.toFixed(2)}] /Contents 3 0 R /Resources ${res} >>`;
  const pages=`<< /Type /Pages /Kids [1 0 R] /Count 1 >>`;
  const catalog=`<< /Type /Catalog /Pages 2 0 R >>`;
  const contentStream=`<< /Length ${sLen} >>\nstream\n${s}\nendstream`;

  const hdr='%PDF-1.4\n';
  let body='', xref=[0];
  [[1,page],[2,pages],[3,contentStream],[4,f1],[5,f2],[6,catalog]].forEach(([n,obj])=>{
    xref[n]=hdr.length+body.length;
    body+=`${n} 0 obj\n${obj}\nendobj\n`;
  });
  const xOff=hdr.length+body.length;
  let xt=`xref\n0 7\n0000000000 65535 f \n`;
  for(let i=1;i<=6;i++) xt+=`${String(xref[i]).padStart(10,'0')} 00000 n \n`;
  const trailer=`trailer\n<< /Size 7 /Root 6 0 R >>\nstartxref\n${xOff}\n%%EOF`;

  const blob=new Blob([hdr+body+xt+trailer],{type:'application/pdf'});
  const url=URL.createObjectURL(blob);
  const a=document.createElement('a');
  a.href=url;
  a.download=(title||'placering').replace(/[^\wåäöÅÄÖ _-]/g,'_').trim()+'.pdf';
  a.click();
  setTimeout(()=>URL.revokeObjectURL(url),2000);
}


function fmtName(s){
  if(!s)return'—';
  const sp=s.trim().indexOf(' ');
  if(sp===-1)return escH(s);
  return escH(s.slice(0,sp))+'<br>'+escH(s.slice(sp+1));
}
function clamp(v,a,b){return Math.max(a,Math.min(b,v))}
function escH(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}

function openModal(id){document.getElementById(id).classList.remove('hidden');document.body.style.overflow='hidden'}
function closeModal(id){document.getElementById(id).classList.add('hidden');document.body.style.overflow=''}
document.querySelectorAll('.overlay').forEach(o=>o.addEventListener('click',e=>{if(e.target===o)closeModal(o.id)}));

// ═══════════════ THEME ═══════════════
function setTheme(t){
  DB.set('theme',t);
  applyTheme(t);
  document.querySelectorAll('.theme-opt').forEach((b,i)=>{
    b.classList.toggle('active',['light','auto','dark'][i]===t);
  });
}
function applyTheme(t){
  const prefersDark=window.matchMedia('(prefers-color-scheme: dark)').matches;
  const dark = t==='dark' || (t==='auto' && prefersDark);
  document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
}

// listen for system preference changes when in auto mode
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change',()=>{
  if(DB.get('theme','auto')==='auto') applyTheme('auto');
});

// ═══════════════ INIT ═══════════════
async function refreshStateFromServer(){
  const data=await apiRequest('api/state.php');
  if(!data?.ok)throw new Error('state_load_failed');
  serverState.rooms=Array.isArray(data.rooms)?data.rooms:[];
  serverState.classes=Array.isArray(data.classes)?data.classes:[];
  serverState.saved=Array.isArray(data.saved)?data.saved:[];
  if(data.user){
    currentUser=data.user;
    isSiteAdmin=(currentUser?.role||'')==='admin';
    updateUserBadge();
  }
  if(isSiteAdmin)await loadAdminUsers();
}

async function initApp(){
  const savedTheme=DB.get('theme','auto');
  setTheme(savedTheme);
  updateUserBadge();
  try{
    await refreshStateFromServer();
  }catch(e){
    console.error(e);
    alert('Kunde inte ladda data från servern. Kontrollera inloggning och databasanslutning.');
  }
  adminIn=isSiteAdmin;
  renderHome();
}

initApp();
