// ═══════════════ DB ═══════════════
const APP_BOOT=window.APP_BOOT||{};
const API_HEADERS={
  'Content-Type':'application/json',
  'X-CSRF-Token':APP_BOOT.csrf||''
};
let currentUser=APP_BOOT.user||null;
let isSuperAdmin=(currentUser?.role||'')==='superadmin';
let isSiteAdmin=isSuperAdmin||(currentUser?.role||'')==='school_admin';
let serverState={rooms:[],classes:[],saved:[]};
let persistQueue=Promise.resolve();
let teacherPlacementSelection={roomIds:[],classIds:[]};
let teacherSelectionPersistQueue=Promise.resolve();
let twofaProfileState=null;
let schoolSecurityState=null;
const STATE_KEYS=['rooms','classes','saved'];
const colorSchemeQuery=window.matchMedia('(prefers-color-scheme: dark)');
const reducedMotionQuery=window.matchMedia('(prefers-reduced-motion: reduce)');

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

function renderStateKey(key){
  if(key==='rooms'){renderHome();if(adminIn)renderRoomList();return;}
  if(key==='classes'){renderHome();if(adminIn)renderClassList();return;}
  if(key==='saved')renderSavedList();
}

function setStateCollection(key,items){
  if(!STATE_KEYS.includes(key))return;
  serverState[key]=Array.isArray(items)?cloneDeep(items):[];
  renderStateKey(key);
}

function upsertStateItem(key,item){
  if(!STATE_KEYS.includes(key)||!item?.id)return;
  const items=Array.isArray(serverState[key])?[...serverState[key]]:[];
  const nextItem=cloneDeep(item);
  const idx=items.findIndex(entry=>entry.id===nextItem.id);
  if(idx>=0)items[idx]=nextItem;
  else items.unshift(nextItem);
  serverState[key]=items;
  renderStateKey(key);
}

function deleteStateItem(key,id){
  if(!STATE_KEYS.includes(key)||!id)return;
  const items=Array.isArray(serverState[key])?serverState[key]:[];
  serverState[key]=items.filter(entry=>entry.id!==id);
  renderStateKey(key);
}

function applyPersistResponse(key,res){
  if(!res?.ok||res.key!==key)return;
  if(Array.isArray(res.items)){
    setStateCollection(key,res.items);
    return;
  }
  if(res.item){
    upsertStateItem(key,res.item);
    return;
  }
  if(res.deletedId){
    deleteStateItem(key,res.deletedId);
  }
}

function queuePersist(payload){
  if(!STATE_KEYS.includes(payload?.key))return Promise.resolve();
  persistQueue=persistQueue.catch(()=>null).then(async()=>{
    const res=await apiRequest('api/state.php',{method:'POST',body:JSON.stringify(payload)});
    applyPersistResponse(payload.key,res);
    return res;
  });
  return persistQueue.catch(err=>{
    console.error(err);
    showToast('Kunde inte spara till servern.');
    throw err;
  });
}

function queuePersistReplace(key,val){
  return queuePersist({key,action:'replace',value:cloneDeep(val)});
}

function queuePersistUpsert(key,item){
  return queuePersist({key,action:'upsert',item:cloneDeep(item)});
}

function queuePersistDelete(key,id){
  return queuePersist({key,action:'delete',id:String(id)});
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
    queuePersistReplace(k,serverState[k]).catch(()=>{});
  }
};
const getRooms=()=>DB.get('rooms',[]);
const getClasses=()=>DB.get('classes',[]);
const setRooms=r=>DB.set('rooms',r);
const setClasses=c=>DB.set('classes',c);
const setSaved=s=>DB.set('saved',s);

function countPlacedDesks(room){
  const desks=Array.isArray(room?.desks)?room.desks:[];
  let total=0;
  for(const desk of desks){
    if(desk&&!desk.inPool)total++;
  }
  return total;
}

function normalizeVisibility(raw){
  return String(raw||'').toLowerCase()==='private'?'private':'shared';
}

function canEditEntity(item){
  if(!item)return false;
  if(typeof item.editable==='boolean')return item.editable;
  const ownerId=Number(item.ownerUserId);
  if(Number.isFinite(ownerId)&&ownerId>0)return ownerId===Number(currentUser?.id);
  return true;
}

function entityOwnerText(item){
  const ownerName=String(item?.ownerName||item?.createdByName||'').trim();
  if(!ownerName)return '';
  if(Number(item?.ownerUserId)===Number(currentUser?.id))return 'Min';
  return ownerName;
}

function isForbiddenError(err){
  return String(err?.data?.error||'')==='forbidden';
}

function backendMessage(err,fallback=''){
  const msg=String(err?.data?.message||'').trim();
  return msg||fallback;
}

function usageByOthersText(item){
  if(normalizeVisibility(item?.visibility)==='private')return '';
  const cnt=Number(item?.usedByOthersCount||0);
  if(!canEditEntity(item)||cnt<=0)return '';
  return cnt===1?' · används av 1 annan':' · används av '+cnt+' andra';
}

function canEditSavedPlacement(item){
  return canEditEntity(item);
}

function buildSavedDomKey(ownerUserId,id){
  const own=String(Number(ownerUserId||0));
  const safeId=String(id||'').replace(/[^A-Za-z0-9._-]/g,'_');
  return own+'_'+safeId;
}

function isTeacherUser(){
  return (currentUser?.role||'')==='teacher';
}

function isSchoolAdminUser(){
  return (currentUser?.role||'')==='school_admin';
}

function isSuperAdminUser(){
  return (currentUser?.role||'')==='superadmin';
}

function roleLabel(role){
  if(role==='superadmin')return'Superadmin';
  if(role==='school_admin')return'Skoladmin';
  return'Lärare';
}

function getManageViewLabel(){
  return isSiteAdmin?'Admin':'Hantera';
}

function normalizeSelectionIds(ids){
  const out=[];
  const seen=new Set();
  if(!Array.isArray(ids))return out;
  for(const raw of ids){
    const id=String(raw||'').trim();
    if(!id||seen.has(id))continue;
    seen.add(id);
    out.push(id);
  }
  return out;
}

function setTeacherPlacementSelection(sel,{render=true}={}){
  teacherPlacementSelection={
    roomIds:normalizeSelectionIds(sel?.roomIds),
    classIds:normalizeSelectionIds(sel?.classIds)
  };
  if(!render)return;
  renderHome();
  if(adminIn){
    renderRoomList();
    renderClassList();
  }
}

function getPlacementRooms(){
  const rooms=getRooms();
  if(!isTeacherUser())return rooms;
  const allowed=new Set(teacherPlacementSelection.roomIds);
  return rooms.filter(r=>allowed.has(r.id));
}

function getPlacementClasses(){
  const classes=getClasses();
  if(!isTeacherUser())return classes;
  const allowed=new Set(teacherPlacementSelection.classIds);
  return classes.filter(c=>allowed.has(c.id));
}

async function loadTeacherPlacementSelection(){
  if(!isTeacherUser()){
    setTeacherPlacementSelection({roomIds:[],classIds:[]},{render:false});
    return;
  }
  const data=await apiRequest('api/teacher_selection.php');
  setTeacherPlacementSelection({
    roomIds:data?.roomIds||[],
    classIds:data?.classIds||[]
  },{render:false});
}

function queueTeacherSelectionSave(next){
  const payload={
    roomIds:normalizeSelectionIds(next?.roomIds),
    classIds:normalizeSelectionIds(next?.classIds)
  };
  teacherSelectionPersistQueue=teacherSelectionPersistQueue.catch(()=>null).then(async()=>{
    return apiRequest('api/teacher_selection.php',{method:'POST',body:JSON.stringify(payload)});
  });
  return teacherSelectionPersistQueue;
}

async function saveTeacherPlacementSelection(next){
  if(!isTeacherUser())return;
  const prev=cloneDeep(teacherPlacementSelection);
  setTeacherPlacementSelection(next);
  try{
    const res=await queueTeacherSelectionSave(next);
    setTeacherPlacementSelection({
      roomIds:res?.roomIds||teacherPlacementSelection.roomIds,
      classIds:res?.classIds||teacherPlacementSelection.classIds
    });
  }catch(e){
    console.error(e);
    setTeacherPlacementSelection(prev);
    notifyError('Kunde inte spara urvalet för placering.');
  }
}

function isTeacherRoomSelected(id){
  return teacherPlacementSelection.roomIds.includes(String(id));
}

function isTeacherClassSelected(id){
  return teacherPlacementSelection.classIds.includes(String(id));
}

function renderTeacherPlacementToggle(kind,id,isSelected){
  const safeId=String(id||'').replace(/\\/g,'\\\\').replace(/'/g,"\\'");
  const onChangeFn=kind==='room'?'toggleTeacherRoomSelection':'toggleTeacherClassSelection';
  const label='Använd i placering';
  return `<label class="admin-pick-check">
    <input class="admin-pick-check-input" type="checkbox" ${isSelected?'checked':''} onchange="${onChangeFn}('${safeId}',this.checked)">
    <span class="admin-pick-check-pill"><span class="admin-pick-check-icon" aria-hidden="true">✓</span>${label}</span>
  </label>`;
}

async function toggleTeacherRoomSelection(id,checked){
  if(!isTeacherUser())return;
  const next={
    roomIds:[...teacherPlacementSelection.roomIds],
    classIds:[...teacherPlacementSelection.classIds]
  };
  const set=new Set(next.roomIds);
  if(checked)set.add(String(id));
  else set.delete(String(id));
  next.roomIds=[...set];
  await saveTeacherPlacementSelection(next);
}

async function toggleTeacherClassSelection(id,checked){
  if(!isTeacherUser())return;
  const next={
    roomIds:[...teacherPlacementSelection.roomIds],
    classIds:[...teacherPlacementSelection.classIds]
  };
  const set=new Set(next.classIds);
  if(checked)set.add(String(id));
  else set.delete(String(id));
  next.classIds=[...set];
  await saveTeacherPlacementSelection(next);
}

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
  if(roleEl)roleEl.textContent=currentUser?.roleLabel||roleLabel(currentUser?.role||'');
}

// ═══════════════ NAV ═══════════════
function showView(n){
  adminIn=(n==='admin');
  document.querySelectorAll('.view').forEach(v=>v.classList.remove('active'));
  document.querySelectorAll('.nav-btn').forEach(b=>b.classList.remove('active'));
  const viewEl=document.getElementById(n+'-view');
  if(viewEl)viewEl.classList.add('active');
  const navBtn=document.querySelector(`.nav-btn[data-view="${n}"]`);
  if(navBtn)navBtn.classList.add('active');
  if(n==='home')renderHome();
  if(n==='saved')renderSavedList();
  if(n==='profile')renderProfile();
  if(n==='admin')renderAdmin();
}

// ═══════════════ HOME ═══════════════
function renderHome(){
  const allRooms=getRooms(),allClasses=getClasses();
  const rooms=getPlacementRooms(),classes=getPlacementClasses();
  const cs=document.getElementById('class-sel');
  const rs=document.getElementById('room-sel');

  if(selClass&&!classes.some(c=>c.id===selClass))selClass=null;
  if(selRoom&&!rooms.some(r=>r.id===selRoom))selRoom=null;

  const selectedRoom=rooms.find(r=>r.id===selRoom)||null;
  cs.innerHTML=classes.length
    ?classes.map(c=>{
      const ownerTxt=entityOwnerText(c);
      const visTxt=normalizeVisibility(c.visibility)==='private'?' · Egen':'';
      const sub=`${c.students.length} elever${visTxt}${ownerTxt?` · ${ownerTxt}`:''}`;
      return`<button type="button" class="pick-btn ${selClass===c.id?'sel':''}" aria-pressed="${selClass===c.id?'true':'false'}" onclick="pickC('${c.id}')">${escH(c.name)}<div class="sub">${escH(sub)}</div></button>`;
    }).join('')
    :isTeacherUser()&&allClasses.length
      ?`<div class="empty"><div class="ico">👥</div><p>Inga grupper valda. Gå till ${escH(getManageViewLabel())} och kryssa i.</p></div>`
      :`<div class="empty"><div class="ico">👥</div><p>Inga grupper tillagda</p></div>`;
  rs.innerHTML=rooms.length
    ?rooms.map(r=>{
      const ownerTxt=entityOwnerText(r);
      const visTxt=normalizeVisibility(r.visibility)==='private'?' · Egen':'';
      const sub=`${countPlacedDesks(r)} bänkar${visTxt}${ownerTxt?` · ${ownerTxt}`:''}`;
      return`<div class="pick-room-wrap">
        <button type="button" class="pick-btn pick-room-btn ${selRoom===r.id?'sel':''}" aria-pressed="${selRoom===r.id?'true':'false'}" onclick="pickR('${r.id}')">${escH(r.name)}<div class="sub">${escH(sub)}</div></button>
        <button type="button" class="pick-preview-btn pick-preview-btn-inline" onclick="openRoomPreview('${r.id}')" aria-label="Förhandsvisa sal" title="Förhandsvisa sal">👁</button>
      </div>`;
    }).join('')
    :isTeacherUser()&&allRooms.length
      ?`<div class="empty"><div class="ico">🏫</div><p>Inga salar valda. Gå till ${escH(getManageViewLabel())} och kryssa i.</p></div>`
      :`<div class="empty"><div class="ico">🏫</div><p>Inga salar tillagda</p></div>`;
  document.getElementById('shuffle-btn').disabled=!(selClass&&selRoom&&selectedRoom?.desks?.some(d=>!d.inPool));
}
function pickC(id){selClass=id;renderHome()}
function pickR(id){selRoom=id;renderHome()}

// ═══════════════ SHUFFLE ═══════════════
function doShuffle(useResultSelection=false){
  // Home shuffle should always use current picks. Result "reshuffle" can opt in to current result ids.
  const roomId=useResultSelection?(shuffleResult?.room?.id||selRoom):selRoom;
  const clsId=useResultSelection?(shuffleResult?.cls?.id||selClass):selClass;
  const room=getPlacementRooms().find(r=>r.id===roomId);
  const cls=getPlacementClasses().find(c=>c.id===clsId);
  if(!room||!cls){
    if(isTeacherUser())showToast(`Välj grupp och sal i ${getManageViewLabel()} först.`);
    return;
  }
  const placed=room.desks.filter(d=>!d.inPool);
  if(!placed.length){
    showToast('Vald sal har inga placerade bänkar ännu.');
    return;
  }
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
let dropTargetEl=null;

let resultFromSaved=false;
let editMode=false;
function goBackFromResult(){showView(resultFromSaved?'saved':'home');}

function renderResult(fromSaved){
  resultFromSaved=!!fromSaved;
  editMode=false;
  updateEditModeUI();
  const {room,cls,pairs,savedName}=shuffleResult;
  const isReadOnlySaved=!!(fromSaved&&shuffleResult?.savedEditable===false);
  const saveBtn=document.getElementById('save-placement-btn');
  const editBtn=document.getElementById('edit-mode-btn');
  if(saveBtn){
    saveBtn.textContent=isReadOnlySaved?'💾 Spara som ny':'💾 Spara placering';
  }
  if(editBtn){
    editBtn.disabled=isReadOnlySaved;
    editBtn.title=isReadOnlySaved?'Skrivskyddad placering':'';
  }
  document.getElementById('res-title').textContent=savedName||`${room.name} — ${cls.name}`;
  document.getElementById('res-sub').textContent=
    `${pairs.filter(p=>p.student).length} av ${cls.students.length||pairs.filter(p=>p.student).length} elever placerade • ${pairs.length} bänkar`
    +(fromSaved?' · Sparad placering':'');

  // Show/hide re-shuffle button depending on whether original class+room exist
  const canReshuffle=!!(shuffleResult.cls.students&&shuffleResult.room.desks);
  document.getElementById('res-reshuffle-btn').style.display=canReshuffle?'':'none';

  const canvas=document.getElementById('result-canvas');
  if(!pairs.length){
    canvas.style.width='100%';
    canvas.style.height='180px';
    canvas.innerHTML='<div class="empty" style="height:100%;display:grid;place-items:center"><div><div class="ico">🪑</div><p>Den här placeringen innehåller inga placerade bänkar.</p></div></div>';
    renderResultList();
    showView('result');
    document.getElementById('result-view').classList.add('active');
    document.getElementById('home-view').classList.remove('active');
    return;
  }
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
  if(shuffleResult?.savedEditable===false){
    showToast('Placeringen är skrivskyddad.');
    return;
  }
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
    if(dropTargetEl){
      dropTargetEl.classList.remove('drop-target');
      dropTargetEl=null;
    }
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
  const target=deskAtPoint(cx,cy,canvas);
  const validTarget=(target&&parseInt(target.dataset.idx)!==dragPairIdx)?target:null;
  if(validTarget===dropTargetEl)return;
  if(dropTargetEl)dropTargetEl.classList.remove('drop-target');
  dropTargetEl=validTarget;
  if(dropTargetEl)dropTargetEl.classList.add('drop-target');
}

function deskAtPoint(cx,cy,canvas){
  // find topmost res-desk element at pointer
  const els=document.elementsFromPoint(cx,cy);
  return els.find(e=>e.classList.contains('res-desk')&&!e.classList.contains('dragging'))||null;
}

function finishSwap(cx,cy,canvas,srcEl){
  // clean up ghost
  if(ghost){ghost.remove();ghost=null}
  if(dropTargetEl){
    dropTargetEl.classList.remove('drop-target');
    dropTargetEl=null;
  }
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

async function confirmSavePlacement(){
  if(!shuffleResult)return;
  const {room,cls,pairs,savedId}=shuffleResult;
  const saveAsCopy=shuffleResult?.savedEditable===false;
  const rawName=document.getElementById('save-name-input').value.trim();
  const now=new Date();
  const autoName=`${cls.name||'Grupp'} — ${room.name||'Sal'} (${now.toLocaleDateString('sv-SE',{day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'})})`;
  const name=rawName||autoName;

  const entry={
    id:(!saveAsCopy&&savedId)?savedId:'pl_'+Date.now(),
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

  try{
    const res=await queuePersistUpsert('saved',entry);
    const persistedId=res?.item?.id||entry.id;
    shuffleResult.savedId=persistedId;
    shuffleResult.savedName=name;
    shuffleResult.savedEditable=true;
    closeModal('save-modal');
    showToast(saveAsCopy?'✓ Sparad som ny kopia!':'✓ Placering sparad!');
  }catch(e){
    console.error(e);
    if(isForbiddenError(e)){
      notifyError(e?.data?.message||'Du kan bara ändra dina egna placeringar.');
      return;
    }
    notifyError('Kunde inte spara placeringen.');
  }
}

function showToast(msg){
  const t=document.getElementById('save-toast');
  t.textContent=msg;
  t.classList.add('show');
  setTimeout(()=>t.classList.remove('show'),2800);
}

function notifyError(msg){
  showToast('⚠ '+msg);
}

function renderSavedList(){
  const saved=getSaved();
  const el=document.getElementById('saved-list');
  if(!saved.length){
    el.innerHTML=`<div class="empty" style="padding:60px 20px"><div class="ico">📋</div><p>Inga sparade placeringar ännu.<br>Slumpa en placering och tryck <strong style="color:var(--accent)">Spara placering</strong>.</p></div>`;
    return;
  }
  el.innerHTML=saved.map(p=>{
    const ownerUserId=Number(p.ownerUserId||0);
    const canEdit=canEditSavedPlacement(p);
    const domKey=buildSavedDomKey(ownerUserId,p.id);
    const safeId=String(p.id||'').replace(/\\/g,'\\\\').replace(/'/g,"\\'");
    const d=new Date(p.savedAt);
    const dateStr=Number.isNaN(d.getTime())
      ?'okänt datum'
      :d.toLocaleDateString('sv-SE',{weekday:'long',day:'numeric',month:'long',hour:'2-digit',minute:'2-digit'});
    const count=Array.isArray(p.pairs)?p.pairs.reduce((sum,pair)=>sum+(pair?.student?1:0),0):0;
    const byMeta=p.createdByName?` · skapad av ${escH(p.createdByName)}`:'';
    const lockMeta=canEdit?'':' · skrivskyddad';
    const createdMeta=p.createdAt?` · ${escH(fmtMetaDate(p.createdAt))}`:'';
    const deleteBtn=canEdit
      ?`<button class="btn btn-danger btn-sm" onclick="event.stopPropagation();confirmDeleteSaved('${domKey}')">🗑 Ta bort</button>`
      :`<span class="hint">Skrivskyddad</span>`;
    const confirmRow=canEdit
      ?`<div class="delete-confirm hidden" id="dc_${domKey}">
      <span>Är du säker?</span>
      <button class="btn btn-danger btn-sm" onclick="deleteSaved(${ownerUserId},'${safeId}')">Ja, radera</button>
      <button class="btn btn-secondary btn-sm" onclick="cancelDelete('${domKey}')">Avbryt</button>
    </div>`
      :'';
    return`<div class="placement-item" id="pi_${domKey}" role="button" tabindex="0" onclick="openSavedPlacement(${ownerUserId},'${safeId}')" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openSavedPlacement(${ownerUserId},'${safeId}')}">
      <div class="pi-main">
        <div class="pi-name">${escH(p.name)}</div>
        <div class="pi-meta">${escH(p.className)} · ${escH(p.roomName)} · ${count} elever · ${dateStr}${byMeta}${lockMeta}${createdMeta}</div>
      </div>
      <div class="pi-actions">
        <button class="btn btn-secondary btn-sm" onclick="event.stopPropagation();openSavedPlacement(${ownerUserId},'${safeId}')">Öppna</button>
        ${deleteBtn}
      </div>
    </div>${confirmRow}`;
  }).join('');
}

function openSavedPlacement(ownerUserId,id){
  const entry=getSaved().find(p=>Number(p.ownerUserId||0)===Number(ownerUserId)&&p.id===id);
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
    savedName:entry.name,
    savedEditable:canEditSavedPlacement(entry),
    ownerUserId:Number(entry.ownerUserId||0)
  };
  // Flag that this came from a saved placement — we keep the class/room ids
  // for re-shuffle if available
  const cls=(entry.classId?getClasses().find(c=>c.id===entry.classId):null)||getClasses().find(c=>c.name===entry.className);
  const room=(entry.roomId?getRooms().find(r=>r.id===entry.roomId):null)||getRooms().find(r=>r.name===entry.roomName);
  if(cls)shuffleResult.cls={...shuffleResult.cls,...cls};
  if(room)shuffleResult.room={...shuffleResult.room,...room};

  renderResult(true); // true = from saved, skip confetti
}

function confirmDeleteSaved(domKey){
  // hide any other open confirmations first
  document.querySelectorAll('.delete-confirm').forEach(el=>{
    if(el.id!=='dc_'+domKey)el.classList.add('hidden');
  });
  document.getElementById('dc_'+domKey)?.classList.toggle('hidden');
}
function cancelDelete(domKey){
  document.getElementById('dc_'+domKey)?.classList.add('hidden');
}
async function deleteSaved(ownerUserId,id){
  if(Number(ownerUserId)!==Number(currentUser?.id)){
    notifyError('Du kan bara ta bort dina egna placeringar.');
    return;
  }
  try{
    await queuePersistDelete('saved',id);
    showToast('Placering borttagen.');
  }catch(e){
    console.error(e);
    if(isForbiddenError(e)){
      notifyError(e?.data?.message||'Du kan bara ta bort dina egna placeringar.');
      return;
    }
    notifyError('Kunde inte ta bort placeringen.');
  }
}

// ═══════════════ PROFILE ═══════════════
function renderProfile(){
  if(!currentUser)return;
  const u=document.getElementById('profile-username');
  const f=document.getElementById('profile-fullname');
  const e=document.getElementById('profile-email');
  const s=document.getElementById('profile-school');
  const p1=document.getElementById('profile-password1');
  const p2=document.getElementById('profile-password2');
  if(u)u.value=currentUser.username||'';
  if(f)f.value=currentUser.fullName||'';
  if(e)e.value=currentUser.email||'';
  if(s)s.value=currentUser.schoolName||'';
  if(p1)p1.value='';
  if(p2)p2.value='';
  void loadTwoFAState();
}

function renderTwoFAState(state){
  const statusEl=document.getElementById('profile-2fa-status');
  const setupWrap=document.getElementById('profile-2fa-setup-wrap');
  const codeWrap=document.getElementById('profile-2fa-code-wrap');
  const secretInput=document.getElementById('profile-2fa-secret');
  const codeInput=document.getElementById('profile-2fa-code');
  const startBtn=document.getElementById('profile-2fa-start-btn');
  const confirmBtn=document.getElementById('profile-2fa-confirm-btn');
  const cancelBtn=document.getElementById('profile-2fa-cancel-btn');
  const disableBtn=document.getElementById('profile-2fa-disable-btn');
  const backupWrap=document.getElementById('profile-backup-wrap');
  const backupStatusEl=document.getElementById('profile-backup-status');
  const backupCodesEl=document.getElementById('profile-backup-codes');
  if(!statusEl||!setupWrap||!codeWrap||!secretInput||!codeInput||!startBtn||!confirmBtn||!cancelBtn||!disableBtn)return;

  const enabled=!!state?.twofaEnabled;
  const required=!!state?.schoolRequire2FA;
  const inProgress=!!state?.setupInProgress;
  const setupSecret=String(state?.setupSecret||'');

  if(enabled){
    statusEl.textContent=required
      ?'2FA är aktiverad och krävs av din skola.'
      :'2FA är aktiverad på ditt konto.';
  }else{
    statusEl.textContent=required
      ?'Din skola kräver 2FA. Aktivera 2FA innan du loggar ut.'
      :'2FA är inte aktiverad.';
  }

  setupWrap.style.display=inProgress?'':'none';
  codeWrap.style.display=inProgress?'':'none';
  secretInput.value=inProgress?setupSecret:'';
  if(!inProgress)codeInput.value='';

  startBtn.style.display=!enabled&&!inProgress?'':'none';
  confirmBtn.style.display=inProgress?'':'none';
  cancelBtn.style.display=inProgress?'':'none';
  disableBtn.style.display=enabled&&!required?'':'none';

  if(backupWrap&&backupStatusEl&&backupCodesEl){
    backupWrap.style.display=enabled?'':'none';
    const remaining=Number(state?.backupCodesRemaining||0);
    backupStatusEl.textContent=`Backupkoder kvar: ${remaining}. Spara nya koder på en säker plats.`;
    const codes=Array.isArray(state?.backupCodes)?state.backupCodes:[];
    if(codes.length){
      backupCodesEl.style.display='';
      backupCodesEl.value=codes.join('\n');
    }else{
      backupCodesEl.style.display='none';
      backupCodesEl.value='';
    }
  }
}

async function loadTwoFAState(){
  const statusEl=document.getElementById('profile-2fa-status');
  if(!statusEl)return;
  try{
    const data=await apiRequest('api/twofa.php');
    twofaProfileState=data;
    currentUser={...currentUser,twofaEnabled:!!data?.twofaEnabled,schoolRequire2FA:!!data?.schoolRequire2FA};
    renderTwoFAState(data);
  }catch(e){
    console.error(e);
    statusEl.textContent='Kunde inte läsa 2FA-status.';
  }
}

async function startTwoFASetup(){
  try{
    const data=await apiRequest('api/twofa.php',{
      method:'POST',
      body:JSON.stringify({action:'start_setup'})
    });
    twofaProfileState=data;
    currentUser={...currentUser,twofaEnabled:!!data?.twofaEnabled,schoolRequire2FA:!!data?.schoolRequire2FA};
    renderTwoFAState(data);
    showToast('2FA-setup startad.');
  }catch(e){
    console.error(e);
    notifyError(e?.data?.message||'Kunde inte starta 2FA-setup.');
  }
}

async function confirmTwoFASetup(){
  const code=(document.getElementById('profile-2fa-code')?.value||'').trim();
  if(!/^\d{6}$/.test(code)){notifyError('Ange en 6-siffrig 2FA-kod.');return}
  try{
    const data=await apiRequest('api/twofa.php',{
      method:'POST',
      body:JSON.stringify({action:'confirm_setup',code})
    });
    twofaProfileState=data;
    currentUser={...currentUser,twofaEnabled:!!data?.twofaEnabled,schoolRequire2FA:!!data?.schoolRequire2FA};
    renderTwoFAState(data);
    updateUserBadge();
    showToast('2FA aktiverad.');
  }catch(e){
    console.error(e);
    notifyError(e?.data?.message||'Fel 2FA-kod.');
  }
}

async function cancelTwoFASetup(){
  try{
    const data=await apiRequest('api/twofa.php',{
      method:'POST',
      body:JSON.stringify({action:'cancel_setup'})
    });
    twofaProfileState=data;
    renderTwoFAState(data);
  }catch(e){
    console.error(e);
    notifyError('Kunde inte avbryta 2FA-setup.');
  }
}

async function disableTwoFA(){
  if(!confirm('Inaktivera 2FA på ditt konto?'))return;
  try{
    const data=await apiRequest('api/twofa.php',{
      method:'POST',
      body:JSON.stringify({action:'disable'})
    });
    twofaProfileState=data;
    currentUser={...currentUser,twofaEnabled:!!data?.twofaEnabled,schoolRequire2FA:!!data?.schoolRequire2FA};
    renderTwoFAState(data);
    updateUserBadge();
    showToast('2FA inaktiverad.');
  }catch(e){
    console.error(e);
    notifyError(e?.data?.message||'Kunde inte inaktivera 2FA.');
  }
}

async function generateBackupCodes(){
  if(!confirm('Skapa nya backupkoder? Gamla koder blir ogiltiga.'))return;
  try{
    const data=await apiRequest('api/twofa.php',{
      method:'POST',
      body:JSON.stringify({action:'generate_backup_codes'})
    });
    twofaProfileState=data;
    renderTwoFAState(data);
    showToast('Nya backupkoder skapade.');
  }catch(e){
    console.error(e);
    notifyError(e?.data?.message||'Kunde inte skapa backupkoder.');
  }
}

async function saveProfile(){
  const fullName=(document.getElementById('profile-fullname')?.value||'').trim();
  const email=(document.getElementById('profile-email')?.value||'').trim();
  const password=(document.getElementById('profile-password1')?.value||'');
  const password2=(document.getElementById('profile-password2')?.value||'');

  if(fullName.length<2){notifyError('Ange ditt riktiga namn.');return}
  if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){notifyError('Ogiltig e-postadress.');return}
  if(password!==''&&password.length<8){notifyError('Nytt lösenord måste vara minst 8 tecken.');return}
  if(password!==password2){notifyError('Lösenorden matchar inte.');return}

  try{
    const res=await apiRequest('api/profile.php',{
      method:'POST',
      body:JSON.stringify({fullName,email,password,password2})
    });
    if(!res?.ok||!res.user){
      notifyError('Kunde inte spara profil.');
      return;
    }
    currentUser=res.user;
    isSuperAdmin=(currentUser?.role||'')==='superadmin';
    isSiteAdmin=isSuperAdmin||(currentUser?.role||'')==='school_admin';
    updateUserBadge();
    renderProfile();
    showToast('✓ Profil sparad!');
  }catch(e){
    console.error(e);
    notifyError(e?.data?.message||'Kunde inte spara profil.');
  }
}

// ═══════════════ CONFETTI ═══════════════
function confetti(){
  if(reducedMotionQuery.matches)return;
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
function checkPw(){renderAdmin()}
function adminLogout(){
  window.location.href='logout.php';
}
function aTab(t){
  if(isSuperAdminUser())t='users';
  if(t==='users'&&!isSiteAdmin)t='rooms';
  const tabs=['rooms','classes','users'];
  document.querySelectorAll('.atab').forEach((b,i)=>b.classList.toggle('active',tabs[i]===t));
  document.querySelectorAll('.asec').forEach(s=>s.classList.remove('active'));
  document.getElementById('asec-'+t).classList.add('active');
  if(t==='users'&&isSiteAdmin)renderAdminUsersSection();
}
function renderAdmin(){
  const login=document.getElementById('admin-login-screen');
  const panel=document.getElementById('admin-panel');
  const tabs=document.querySelectorAll('.atab');
  const roomsTab=tabs[0]||null;
  const classesTab=tabs[1]||null;
  const usersTab=tabs[2]||null;
  const roomsSection=document.getElementById('asec-rooms');
  const classesSection=document.getElementById('asec-classes');
  const usersSection=document.getElementById('asec-users');

  login.style.display='none';
  panel.style.display='block';
  renderSchoolSecurityCard();

  if(isSuperAdminUser()){
    document.querySelectorAll('.atab').forEach((b,i)=>b.classList.toggle('active',i===2));
    if(roomsTab)roomsTab.style.display='none';
    if(classesTab)classesTab.style.display='none';
    if(usersTab)usersTab.style.display='';
    if(roomsSection){
      roomsSection.style.display='none';
      roomsSection.classList.remove('active');
    }
    if(classesSection){
      classesSection.style.display='none';
      classesSection.classList.remove('active');
    }
    if(usersSection){
      usersSection.style.display='';
      usersSection.classList.add('active');
    }
    loadAdminUsers().then(renderAdminUsersSection);
    return;
  }

  if(roomsTab)roomsTab.style.display='';
  if(classesTab)classesTab.style.display='';
  if(roomsSection)roomsSection.style.display='';
  if(classesSection)classesSection.style.display='';

  if(usersTab)usersTab.style.display=isSiteAdmin?'':'none';
  if(usersSection){
    usersSection.style.display=isSiteAdmin?'':'none';
    if(!isSiteAdmin)usersSection.classList.remove('active');
  }
  if(!isSiteAdmin&&usersTab?.classList.contains('active'))aTab('rooms');

  renderRoomList();
  renderClassList();
  if(isSiteAdmin){
    loadAdminUsers().then(renderAdminUsersSection);
  }
  if(isSchoolAdminUser()&&!isSuperAdminUser()){
    void loadSchoolSecuritySettings();
  }
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
    adminUsersCache=sortAdminUsers(Array.isArray(data?.users)?data.users:[]);
  }catch(e){
    console.error(e);
    showToast('Kunde inte hämta användaransökningar.');
  }
}

function renderSchoolSecurityCard(){
  const card=document.getElementById('school-security-card');
  const toggle=document.getElementById('school-require-2fa-toggle');
  if(!card||!toggle)return;
  if(isSchoolAdminUser()&&!isSuperAdminUser()){
    card.style.display='';
    toggle.checked=!!schoolSecurityState?.require2FA;
  }else{
    card.style.display='none';
  }
}

async function loadSchoolSecuritySettings(){
  if(!isSchoolAdminUser()||isSuperAdminUser())return;
  try{
    const data=await apiRequest('api/school_settings.php');
    schoolSecurityState=data;
    renderSchoolSecurityCard();
  }catch(e){
    console.error(e);
    showToast(e?.data?.message||'Kunde inte läsa skolinställningar.');
  }
}

async function saveSchoolSecuritySettings(){
  if(!isSchoolAdminUser()||isSuperAdminUser())return;
  const toggle=document.getElementById('school-require-2fa-toggle');
  if(!toggle)return;
  try{
    const data=await apiRequest('api/school_settings.php',{
      method:'POST',
      body:JSON.stringify({require2FA:!!toggle.checked})
    });
    schoolSecurityState=data;
    currentUser={...currentUser,schoolRequire2FA:!!data?.require2FA};
    renderSchoolSecurityCard();
    await loadTwoFAState();
    showToast('Skolinställning sparad.');
  }catch(e){
    console.error(e);
    showToast(e?.data?.message||'Kunde inte spara skolinställningen.');
    renderSchoolSecurityCard();
  }
}

function sortAdminUsers(users){
  const rank={pending:0,approved:1,disabled:2,rejected:3};
  return [...users].sort((a,b)=>{
    const rankDiff=(rank[a?.status]??99)-(rank[b?.status]??99);
    if(rankDiff!==0)return rankDiff;
    return String(a?.createdAt||'').localeCompare(String(b?.createdAt||''));
  });
}

function upsertAdminUserCache(user){
  if(!user?.id)return;
  const next=[...adminUsersCache];
  const idx=next.findIndex(entry=>Number(entry.id)===Number(user.id));
  if(idx>=0)next[idx]=user;
  else next.push(user);
  adminUsersCache=sortAdminUsers(next);
}

async function adminUserAction(action,userId,role='teacher',extra={}){
  try{
    const data=await apiRequest('api/admin_users.php',{method:'POST',body:JSON.stringify({action,userId,role,...extra})});
    if(data?.user)upsertAdminUserCache(data.user);
    if(action==='approve_school'&&!data?.user)await loadAdminUsers();
    renderAdminUsersSection();
    if(action==='reset_2fa')showToast('2FA återställd för användaren.');
  }catch(e){
    console.error(e);
    showToast(e?.data?.message||'Kunde inte uppdatera användare.');
  }
}

async function adminResetUserTwoFA(userId){
  const user=findAdminUserById(userId);
  if(!user)return;
  const displayName=(user.fullName||user.username||'användaren').trim();
  if(!confirm(`Återställ 2FA för ${displayName}? Användaren måste konfigurera 2FA på nytt.`))return;
  await adminUserAction('reset_2fa',userId);
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
  document.getElementById('admin-user-school-name').value=u.schoolName||'';
  document.getElementById('admin-user-school-status').value=u.schoolStatus||'pending';
  document.getElementById('admin-user-password1').value='';
  document.getElementById('admin-user-password2').value='';
  const roleSel=document.getElementById('admin-user-role');
  const statusSel=document.getElementById('admin-user-status');
  const schoolNameInput=document.getElementById('admin-user-school-name');
  const schoolStatusSel=document.getElementById('admin-user-school-status');
  if(roleSel){
    const canEditRole=isSuperAdminUser()&&!self;
    roleSel.disabled=!canEditRole;
    for(const opt of [...roleSel.options]){
      if(!isSuperAdminUser()&&opt.value!=='teacher'){
        opt.disabled=true;
      }else{
        opt.disabled=false;
      }
    }
  }
  if(statusSel)statusSel.disabled=self||(!isSuperAdminUser()&&u.role!=='teacher');
  if(schoolNameInput)schoolNameInput.disabled=!isSuperAdminUser()||u.role==='superadmin';
  if(schoolStatusSel)schoolStatusSel.disabled=!isSuperAdminUser()||u.role==='superadmin';
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
  const schoolName=(document.getElementById('admin-user-school-name').value||'').trim();
  const schoolStatus=(document.getElementById('admin-user-school-status').value||'pending').trim();
  const password=(document.getElementById('admin-user-password1').value||'');
  const password2=(document.getElementById('admin-user-password2').value||'');

  if(!/^[A-Za-z0-9_.-]{3,50}$/.test(username)){notifyError('Användarnamn måste vara 3-50 tecken (A-Z, 0-9, _, -, .).');return}
  if(fullName.length<2){notifyError('Ange riktigt namn.');return}
  if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){notifyError('Ogiltig e-postadress.');return}
  if(password!==''&&password.length<8){notifyError('Nytt lösenord måste vara minst 8 tecken.');return}
  if(password!==password2){notifyError('Lösenorden matchar inte.');return}

  try{
    const data=await apiRequest('api/admin_users.php',{
      method:'POST',
      body:JSON.stringify({action:'update_user',userId,username,fullName,email,role,status,password,password2,schoolName,schoolStatus})
    });
    if(data?.user)upsertAdminUserCache(data.user);
    const updated=data?.user||findAdminUserById(userId);
    if(updated&&Number(currentUser?.id)===userId){
      currentUser={
        ...currentUser,
        username:updated.username,
        fullName:updated.fullName,
        email:updated.email,
        role:updated.role,
        roleLabel:updated.roleLabel||roleLabel(updated.role),
        schoolId:updated.schoolId||0,
        schoolName:updated.schoolName||'',
        schoolStatus:updated.schoolStatus||'',
        twofaEnabled:!!updated.twofaEnabled,
      };
      isSuperAdmin=(currentUser?.role||'')==='superadmin';
      isSiteAdmin=isSuperAdmin||(currentUser?.role||'')==='school_admin';
      updateUserBadge();
      renderProfile();
    }
    renderAdminUsersSection();
    closeModal('admin-user-modal');
    showToast('✓ Användare uppdaterad');
  }catch(e){
    console.error(e);
    notifyError(e?.data?.message||'Kunde inte spara användaren.');
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
      const schoolMeta=u.schoolName?`${u.schoolName}${u.schoolStatus?` (${u.schoolStatus})`:''}`:'Ingen skola';
      const roleMeta=u.roleLabel||roleLabel(u.role);
      const twofaMeta=u.twofaEnabled?'2FA aktiv':'2FA ej aktiv';
      const meta=[u.email,roleMeta,schoolMeta,twofaMeta,created?`Ansökt ${created}`:''].filter(Boolean).join(' · ');
      const schoolApproveBtn=isSuperAdminUser()&&u.schoolId&&u.schoolStatus!=='approved'
        ?`<button class="btn btn-secondary btn-sm" onclick="adminUserAction('approve_school',${u.id},'teacher',{schoolId:${Number(u.schoolId)}})">Godkänn skola</button>`
        :'';
      const approveRole=(u.role==='school_admin'||u.role==='superadmin')?u.role:'teacher';
      const canApprove=isSuperAdminUser()||(isSchoolAdminUser()&&u.role==='teacher');
      const openBtn=(isSuperAdminUser()||u.role==='teacher')
        ?`<button class="btn btn-secondary btn-sm" onclick="openAdminUserModal(${u.id})">Öppna</button>`
        :'';
      return`<div class="list-item">
        <div>
          <div class="li-name">${escH(u.fullName)} <span class="hint" style="margin:0">(@${escH(u.username)})</span></div>
          <div class="li-sub">${escH(meta)}</div>
        </div>
        <div class="flex gap2">
          ${openBtn}
          ${schoolApproveBtn}
          ${canApprove?`<button class="btn btn-primary btn-sm" onclick="adminUserAction('approve',${u.id},'${approveRole}')">Godkänn</button><button class="btn btn-danger btn-sm" onclick="adminUserAction('reject',${u.id})">Avslå</button>`:''}
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
    const schoolApproval=fmtMetaDate(u.schoolApprovedAt);
    const roleText=u.roleLabel||roleLabel(u.role);
    const schoolAdminCanManage=isSchoolAdminUser()&&u.role==='teacher';
    const canManageActions=!self&&(isSuperAdminUser()||schoolAdminCanManage);
    const resetTwofaBtn=canManageActions&&u.twofaEnabled
      ?`<button class="btn btn-secondary btn-sm" onclick="adminResetUserTwoFA(${u.id})">Återställ 2FA</button>`
      :'';
    const meta=[
      u.email,
      u.schoolName?`Skola: ${u.schoolName}${u.schoolStatus?` (${u.schoolStatus})`:''}`:'',
      u.twofaEnabled?'2FA aktiv':'2FA ej aktiv',
      created?`Ansökt ${created}`:'',
      approved?`Godkänd ${approved}${u.approvedByName?` av ${u.approvedByName}`:''}`:'',
      schoolApproval?`Skola godkänd ${schoolApproval}${u.schoolApprovedByName?` av ${u.schoolApprovedByName}`:''}`:''
    ].filter(Boolean).join(' · ');
    const editBtn=(isSuperAdminUser()||schoolAdminCanManage)
      ?`<button class="btn btn-secondary btn-sm" onclick="openAdminUserModal(${u.id})">Öppna</button>`
      :'';
    let actions=editBtn;
    if(u.status==='approved'&&canManageActions){
      const disableBtn=`<button class="btn btn-danger btn-sm" onclick="adminUserAction('disable',${u.id})">Inaktivera</button>`;
      const schoolBtn=isSuperAdminUser()&&u.schoolId&&u.role!=='superadmin'&&u.schoolStatus!=='approved'
        ?`<button class="btn btn-secondary btn-sm" onclick="adminUserAction('approve_school',${u.id},'teacher',{schoolId:${Number(u.schoolId)}})">Godkänn skola</button>`
        :'';
      let roleBtn='';
      if(isSuperAdminUser()&&!self&&u.role!=='superadmin'){
        roleBtn=u.role==='school_admin'
          ?`<button class="btn btn-secondary btn-sm" onclick="adminUserAction('set_role',${u.id},'teacher')">Gör lärare</button>`
          :`<button class="btn btn-secondary btn-sm" onclick="adminUserAction('set_role',${u.id},'school_admin')">Gör skoladmin</button>`;
      }
      actions=`${editBtn}${schoolBtn}${roleBtn}${resetTwofaBtn}${disableBtn}`;
    }else if((u.status==='disabled'||u.status==='rejected')&&canManageActions){
      const schoolBtn=isSuperAdminUser()&&u.schoolId&&u.role!=='superadmin'&&u.schoolStatus!=='approved'
        ?`<button class="btn btn-secondary btn-sm" onclick="adminUserAction('approve_school',${u.id},'teacher',{schoolId:${Number(u.schoolId)}})">Godkänn skola</button>`
        :'';
      let roleBtn='';
      if(isSuperAdminUser()&&!self&&u.role!=='superadmin'){
        roleBtn=u.role==='school_admin'
          ?`<button class="btn btn-secondary btn-sm" onclick="adminUserAction('set_role',${u.id},'teacher')">Gör lärare</button>`
          :`<button class="btn btn-secondary btn-sm" onclick="adminUserAction('set_role',${u.id},'school_admin')">Gör skoladmin</button>`;
      }
      actions=`${editBtn}${schoolBtn}<button class="btn btn-secondary btn-sm" onclick="adminUserAction('enable',${u.id})">Aktivera</button>${roleBtn}${resetTwofaBtn}`;
    }else if(canManageActions&&resetTwofaBtn){
      actions=`${editBtn}${resetTwofaBtn}`;
    }
    return`<div class="list-item">
      <div>
        <div class="li-name">${escH(u.fullName)} <span class="hint" style="margin:0">(@${escH(u.username)})</span></div>
        <div class="li-sub">${escH(meta)} · Status: ${escH(u.status)} · Roll: ${escH(roleText)}</div>
      </div>
      <div class="flex gap2">${actions}</div>
    </div>`;
  }).join('');
}

function renderRoomList(){
  const rooms=getRooms(),el=document.getElementById('room-list');
  if(isSuperAdminUser()){
    el.innerHTML='<div class="empty"><div class="ico">👤</div><p>Superadmin hanterar inte salar.</p></div>';
    return;
  }
  const teacherNote=isTeacherUser()
    ?'<p class="hint admin-pick-note">Kryssa i vilka salar du vill kunna använda i Placera-vyn.</p>'
    :'';
  if(!rooms.length){el.innerHTML=`${teacherNote}<div class="empty"><div class="ico">🏫</div><p>Inga salar</p></div>`;return}
  const rows=rooms.map(r=>{
    const deskCount=countPlacedDesks(r);
    const editable=canEditEntity(r);
    const visibility=normalizeVisibility(r.visibility);
    const visibilityTxt=editable?` · ${visibility==='private'?'egen':'delad'}`:'';
    const ownBadge=visibility==='private'?'<span class="room-own-badge">EGEN</span>':'';
    const ownerTxt=entityOwnerText(r);
    const ownership=ownerTxt?(editable?' · min sal':` · ägs av ${escH(ownerTxt)}`):'';
    const usage=usageByOthersText(r);
    const pickToggle=isTeacherUser()
      ?renderTeacherPlacementToggle('room',r.id,isTeacherRoomSelected(r.id))
      :'';
    const previewBtn=`<button class="btn btn-secondary btn-sm" onclick="openRoomPreview('${r.id}')">👁 Förhandsvisa</button>`;
    const actions=editable
      ?`${previewBtn}<button class="btn btn-secondary btn-sm" onclick="openEditor('${r.id}')">Redigera</button>
      <button class="btn btn-danger btn-sm" onclick="deleteRoom('${r.id}')">✕</button>`
      :`${previewBtn}<span class="hint">Skrivskyddad</span>`;
    return`<div class="list-item">
    <div><div class="li-name">${escH(r.name)}${ownBadge}</div><div class="li-sub">${deskCount} bänkar placerade${visibilityTxt}${ownership}${usage}${r.createdAt?` · ${escH(fmtMetaDate(r.createdAt))}`:''}</div></div>
    <div class="flex gap2">${pickToggle}${actions}</div></div>`;
  }).join('');
  el.innerHTML=teacherNote+rows;
}

function openRoomPreview(id){
  const room=getRooms().find(r=>r.id===id)||null;
  if(!room){
    notifyError('Salen kunde inte hittas.');
    return;
  }
  const titleEl=document.getElementById('room-preview-title');
  if(titleEl)titleEl.textContent='Förhandsgranskning: '+room.name;
  renderRoomPreview(room);
  openModal('room-preview-modal');
}

function renderRoomPreview(room){
  const board=document.getElementById('room-preview-board');
  const canvas=document.getElementById('room-preview-canvas');
  const meta=document.getElementById('room-preview-meta');
  if(!board||!canvas||!meta)return;

  const desks=Array.isArray(room?.desks)?room.desks:[];
  const placed=desks.filter(d=>{
    if(!d||d.inPool)return false;
    return Number.isFinite(Number(d.x))&&Number.isFinite(Number(d.y));
  });
  const inPool=Math.max(0,desks.length-placed.length);
  meta.textContent=`${placed.length} placerade bänkar${inPool?` · ${inPool} i pool`:''}`;

  canvas.innerHTML='';
  if(!placed.length){
    board.style.width='100%';
    canvas.style.width='100%';
    canvas.style.height='220px';
    canvas.innerHTML='<div class="room-preview-empty">Inga placerade bänkar i layouten.</div>';
    return;
  }

  let minX=Infinity,minY=Infinity,maxX=-Infinity,maxY=-Infinity;
  placed.forEach(d=>{
    const x=Number(d.x)||0;
    const y=Number(d.y)||0;
    minX=Math.min(minX,x);
    minY=Math.min(minY,y);
    maxX=Math.max(maxX,x+EDITOR_DW);
    maxY=Math.max(maxY,y+EDITOR_DH);
  });

  const pad=22;
  const width=Math.max(280,Math.ceil((maxX-minX)+pad*2));
  const height=Math.max(240,Math.ceil((maxY-minY)+pad*2));
  board.style.width=width+'px';
  canvas.style.width=width+'px';
  canvas.style.height=height+'px';

  placed.forEach((d,i)=>{
    const x=Number(d.x)||0;
    const y=Number(d.y)||0;
    const rot=Number(d.rotation)||0;
    const el=document.createElement('div');
    el.className='room-preview-desk';
    el.style.left=(x-minX+pad)+'px';
    el.style.top=(y-minY+pad)+'px';
    el.style.transform='rotate('+rot+'deg)';
    el.innerHTML=`<span class="room-preview-num">${i+1}</span>`;
    canvas.appendChild(el);
  });
}

async function deleteRoom(id){
  const room=getRooms().find(r=>r.id===id)||null;
  if(room&&!canEditEntity(room)){
    notifyError('Du kan bara radera dina egna salar.');
    return;
  }
  if(!confirm('Radera denna sal?'))return;
  try{
    await queuePersistDelete('rooms',id);
    if(isTeacherUser()&&isTeacherRoomSelected(id)){
      void saveTeacherPlacementSelection({
        roomIds:teacherPlacementSelection.roomIds.filter(x=>x!==String(id)),
        classIds:[...teacherPlacementSelection.classIds]
      });
    }
    if(selRoom===id){
      selRoom=null;
      renderHome();
    }
    showToast('Sal borttagen.');
  }catch(e){
    console.error(e);
    if(isForbiddenError(e)){
      notifyError(e?.data?.message||'Du kan bara radera dina egna salar.');
      return;
    }
    notifyError('Kunde inte radera salen.');
  }
}

function renderClassList(){
  const classes=getClasses(),el=document.getElementById('class-list');
  if(isSuperAdminUser()){
    el.innerHTML='<div class="empty"><div class="ico">👤</div><p>Superadmin hanterar inte grupper.</p></div>';
    return;
  }
  const teacherNote=isTeacherUser()
    ?'<p class="hint admin-pick-note">Kryssa i vilka grupper du vill kunna använda i Placera-vyn.</p>'
    :'';
  if(!classes.length){el.innerHTML=`${teacherNote}<div class="empty"><div class="ico">👥</div><p>Inga grupper</p></div>`;return}
  const rows=classes.map(c=>{
    const editable=canEditEntity(c);
    const visibility=normalizeVisibility(c.visibility);
    const visibilityTxt=editable?` · ${visibility==='private'?'egen':'delad'}`:'';
    const ownBadge=visibility==='private'?'<span class="room-own-badge">EGEN</span>':'';
    const ownerTxt=entityOwnerText(c);
    const ownership=ownerTxt?(editable?' · min grupp':` · ägs av ${escH(ownerTxt)}`):'';
    const usage=usageByOthersText(c);
    const pickToggle=isTeacherUser()
      ?renderTeacherPlacementToggle('class',c.id,isTeacherClassSelected(c.id))
      :'';
    const actions=editable
      ?`<button class="btn btn-secondary btn-sm" onclick="openClassModal('${c.id}')">Redigera</button>
      <button class="btn btn-danger btn-sm" onclick="deleteClass('${c.id}')">✕</button>`
      :`<span class="hint">Skrivskyddad</span>`;
    return`<div class="list-item">
    <div><div class="li-name">${escH(c.name)}${ownBadge}</div><div class="li-sub">${c.students.length} elever${visibilityTxt}${ownership}${usage}${c.createdAt?` · ${escH(fmtMetaDate(c.createdAt))}`:''}</div></div>
    <div class="flex gap2">${pickToggle}${actions}</div></div>`;
  }).join('');
  el.innerHTML=teacherNote+rows;
}
function openClassModal(id){
  editingClassId=id;
  const cls=id?getClasses().find(c=>c.id===id):null;
  if(cls&&!canEditEntity(cls)){
    notifyError('Du kan bara redigera dina egna grupper.');
    return;
  }
  document.getElementById('class-modal-title').textContent=cls?'Redigera grupp':'Ny grupp';
  document.getElementById('class-name-in').value=cls?cls.name:'';
  document.getElementById('class-visibility-in').value=cls?normalizeVisibility(cls.visibility):'shared';
  document.getElementById('class-students-in').value=cls?cls.students.join('\n'):'';
  openModal('class-modal');
}
async function saveClass(){
  const name=document.getElementById('class-name-in').value.trim();
  const visibility=normalizeVisibility(document.getElementById('class-visibility-in').value);
  if(!name){notifyError('Ange ett namn.');return}
  const students=document.getElementById('class-students-in').value.split('\n').map(s=>s.trim()).filter(Boolean);
  if(!students.length){notifyError('Lägg till minst en elev.');return}
  const existing=editingClassId?getClasses().find(c=>c.id===editingClassId):null;
  if(existing&&!canEditEntity(existing)){
    notifyError('Du kan bara redigera dina egna grupper.');
    return;
  }
  const entry=existing
    ?{...existing,name,visibility,students}
    :{id:'cls_'+Date.now(),name,visibility,students};
  try{
    await queuePersistUpsert('classes',entry);
    closeModal('class-modal');
    showToast('Grupp sparad.');
  }catch(e){
    console.error(e);
    if(isForbiddenError(e)){
      notifyError(e?.data?.message||'Du kan bara ändra dina egna grupper.');
      return;
    }
    notifyError(backendMessage(e,'Kunde inte spara gruppen.'));
  }
}
async function deleteClass(id){
  const cls=getClasses().find(c=>c.id===id)||null;
  if(cls&&!canEditEntity(cls)){
    notifyError('Du kan bara radera dina egna grupper.');
    return;
  }
  if(!confirm('Radera grupp?'))return;
  try{
    await queuePersistDelete('classes',id);
    if(isTeacherUser()&&isTeacherClassSelected(id)){
      void saveTeacherPlacementSelection({
        roomIds:[...teacherPlacementSelection.roomIds],
        classIds:teacherPlacementSelection.classIds.filter(x=>x!==String(id))
      });
    }
    if(selClass===id){
      selClass=null;
      renderHome();
    }
    showToast('Grupp borttagen.');
  }catch(e){
    console.error(e);
    if(isForbiddenError(e)){
      notifyError(e?.data?.message||'Du kan bara radera dina egna grupper.');
      return;
    }
    notifyError('Kunde inte radera gruppen.');
  }
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
  if(room&&!canEditEntity(room)){
    notifyError('Du kan bara redigera dina egna salar.');
    return;
  }
  document.getElementById('editor-title').textContent=room?'Redigera sal':'Ny sal';
  document.getElementById('editor-room-name').value=room?room.name:'';
  document.getElementById('editor-room-visibility').value=room?normalizeVisibility(room.visibility):'shared';
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
    notifyError('Ogiltigt mönster.');
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
    notifyError('Mönstret är för brett för arbetsytan. Välj färre kolumner.');
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
async function saveRoom(){
  const name=document.getElementById('editor-room-name').value.trim();
  const visibility=normalizeVisibility(document.getElementById('editor-room-visibility').value);
  if(!name){notifyError('Ange ett namn för salen.');return}
  const existing=editingRoomId?getRooms().find(r=>r.id===editingRoomId):null;
  if(existing&&!canEditEntity(existing)){
    notifyError('Du kan bara redigera dina egna salar.');
    return;
  }
  const data=existing
    ?{...existing,name,visibility,desks:editorDesks.map(d=>({...d}))}
    :{id:'room_'+Date.now(),name,visibility,desks:editorDesks.map(d=>({...d}))};
  try{
    await queuePersistUpsert('rooms',data);
    closeModal('editor-modal');
    showToast('Sal sparad.');
  }catch(e){
    console.error(e);
    if(isForbiddenError(e)){
      notifyError(e?.data?.message||'Du kan bara ändra dina egna salar.');
      return;
    }
    notifyError('Kunde inte spara salen.');
  }
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
    catch(e){ console.error(e); notifyError('Kunde inte generera PDF: '+e.message); }
    finally{ btn.textContent=orig; btn.disabled=false; }
  },30);
}

function printDirect(){
  if(!shuffleResult?.pairs?.length){
    notifyError('Ingen placering att skriva ut ännu.');
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
  const footerText=`Placera - ${ds}`;
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

let modalReturnFocusEl=null;
function openModal(id){
  const overlay=document.getElementById(id);
  if(!overlay)return;
  modalReturnFocusEl=document.activeElement instanceof HTMLElement?document.activeElement:null;
  overlay.classList.remove('hidden');
  document.body.style.overflow='hidden';
  const focusTarget=overlay.querySelector('input,select,textarea,button,[tabindex]:not([tabindex="-1"])');
  if(focusTarget instanceof HTMLElement){
    requestAnimationFrame(()=>focusTarget.focus());
  }
}
function closeModal(id){
  const overlay=document.getElementById(id);
  if(!overlay)return;
  overlay.classList.add('hidden');
  const openOverlay=document.querySelector('.overlay:not(.hidden)');
  if(!openOverlay)document.body.style.overflow='';
  if(modalReturnFocusEl&&document.contains(modalReturnFocusEl)){
    modalReturnFocusEl.focus();
  }
  modalReturnFocusEl=null;
}
document.querySelectorAll('.overlay').forEach(o=>o.addEventListener('click',e=>{if(e.target===o)closeModal(o.id)}));
document.addEventListener('keydown',e=>{
  if(e.key!=='Escape')return;
  const openOverlay=document.querySelector('.overlay:not(.hidden)');
  if(openOverlay&&openOverlay.id)closeModal(openOverlay.id);
});

// ═══════════════ THEME ═══════════════
function setTheme(t){
  DB.set('theme',t);
  applyTheme(t);
  document.querySelectorAll('.theme-opt').forEach((b,i)=>{
    b.classList.toggle('active',['light','auto','dark'][i]===t);
  });
}
function applyTheme(t){
  const prefersDark=colorSchemeQuery.matches;
  const dark = t==='dark' || (t==='auto' && prefersDark);
  document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
}

// listen for system preference changes when in auto mode
if(typeof colorSchemeQuery.addEventListener==='function'){
  colorSchemeQuery.addEventListener('change',()=>{
    if(DB.get('theme','auto')==='auto') applyTheme('auto');
  });
}else if(typeof colorSchemeQuery.addListener==='function'){
  colorSchemeQuery.addListener(()=>{
    if(DB.get('theme','auto')==='auto') applyTheme('auto');
  });
}

// ═══════════════ INIT ═══════════════
async function refreshStateFromServer(){
  const data=await apiRequest('api/state.php');
  if(!data?.ok)throw new Error('state_load_failed');
  serverState.rooms=Array.isArray(data.rooms)?data.rooms:[];
  serverState.classes=Array.isArray(data.classes)?data.classes:[];
  serverState.saved=Array.isArray(data.saved)?data.saved:[];
  if(data.user){
    currentUser=data.user;
    isSuperAdmin=(currentUser?.role||'')==='superadmin';
    isSiteAdmin=isSuperAdmin||(currentUser?.role||'')==='school_admin';
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
    await loadTeacherPlacementSelection();
  }catch(e){
    console.error(e);
    notifyError('Kunde inte ladda data från servern. Kontrollera inloggning och databasanslutning.');
    setTeacherPlacementSelection({roomIds:[],classIds:[]},{render:false});
  }
  adminIn=false;
  if(isSuperAdminUser())showView('admin');
  else renderHome();
}

initApp();
