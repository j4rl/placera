const assert=require('assert');
const fs=require('fs');
const vm=require('vm');
const source=fs.readFileSync('app.js','utf8');
function functionSource(name){
  const start=source.indexOf('function '+name+'(');
  assert(start>=0,name);
  return source.slice(start,source.indexOf('\n}',start)+2);
}
const canvas={offsetWidth:700,offsetHeight:500,
  style:{setProperty(name,value){this[name]=value;}},
  getBoundingClientRect:()=>({left:10,top:20}),
  classList:{toggle(name,on){this[name]=on;}}};
const elements={
  'editor-canvas':canvas,'desk-scale':{value:'0.7'},'editor-grid':{checked:false},
  'editor-snap':{checked:true},
  'desk-count-input':{value:20},'layout-preset':{value:'3-3-3'},
  'layout-rows':{value:5},'layout-fit-count':{checked:true},
  'print-teacher-perspective':{checked:false},'res-title':{textContent:'Test'},'res-sub':{textContent:'Test'}
};
let pdf='';
const listeners={};
const context=vm.createContext({assert,TextEncoder,
  document:{getElementById:id=>elements[id],createElement:()=>({click(){}}),
    addEventListener(name,handler){listeners[name]=handler;},removeEventListener(name){delete listeners[name];}},
  listeners,
  Blob:class {constructor(parts){pdf=parts.join('');}},URL:{createObjectURL(){return 'test';},revokeObjectURL(){}},setTimeout(){},
  errors:[],renders:0,getPDF:()=>pdf
});
vm.runInContext(`
  const EDITOR_DW=80,EDITOR_DH=50,EDITOR_TOP_OFFSET=56,EDITOR_SIDE_PAD=16,EDITOR_GRID_SIZE=12;
  let editorDeskScale=1,editorDesks=[],selectedId=null,editorDeskIdSeq=0,shuffleResult=null;
  function clamp(v,min,max){return Math.max(min,Math.min(max,v));}
  function renderEditor(){renders++;}
  function notifyError(message){errors.push(message);}
  function showToast(){}
  function updateSelInfo(){}
  ${['getDeskScale','applyDeskDimensions','normalizeEditorDesk','makeEditorDesk','getEditorCanvasSize','setDeskCount','applyDeskScale','autoArrange','applyPresetLayout','updateEditorGrid','snapEditorDeskPosition','mouseDragDesk','touchDragDesk','buildPDF'].map(functionSource).join('\n')}
  assert.equal(getDeskScale({}),1);
  assert.equal(normalizeEditorDesk({scale:0.7}).scale,0.7);
  assert.equal(getDeskScale({scale:0}),1);
  setDeskCount(20);
  const before=JSON.stringify(editorDesks);
  applyPresetLayout();
  assert.equal(errors.length,1,'3-3-3 at 100% must be rejected on a 700px canvas');
  assert.equal(JSON.stringify(editorDesks),before,'Rejected presets must leave desks unchanged');
  errors.length=0;
  applyDeskScale();
  applyPresetLayout();
  assert.equal(errors.length,0);
  assert.equal(editorDesks.length,45);
  assert(editorDesks.every(d=>!d.inPool&&d.scale===0.7));
  for(const d of editorDesks){
    assert(d.x>=0&&d.x+56<=700);
    assert(d.y>=56&&d.y+35<=500);
  }
  for(let i=0;i<editorDesks.length;i++)for(let j=i+1;j<editorDesks.length;j++){
    const a=editorDesks[i],b=editorDesks[j];
    assert(a.x+56<=b.x||b.x+56<=a.x||a.y+35<=b.y||b.y+35<=a.y,'Desks must not overlap');
  }
  document.getElementById('layout-rows').value=12;
  document.getElementById('layout-preset').value='2-2';
  const valid=JSON.stringify(editorDesks);
  applyPresetLayout();
  assert.equal(JSON.stringify(editorDesks),valid,'Too many rows must leave layout unchanged');
  document.getElementById('layout-rows').value=5;
  document.getElementById('layout-preset').value='3-3-3';
  document.getElementById('layout-fit-count').checked=false;
  setDeskCount(20);
  applyPresetLayout();
  assert.equal(editorDesks.length,20);
  assert(editorDesks.every(d=>!d.inPool));
  editorDesks[0].x=699;editorDesks[0].y=499;
  document.getElementById('desk-scale').value='1';
  applyDeskScale();
  assert.equal(editorDesks[0].x,620);assert.equal(editorDesks[0].y,450);
  document.getElementById('desk-scale').value='0.7';applyDeskScale();
  autoArrange();
  assert(editorDesks.every(d=>d.x+56<=700&&d.y+35<=500));
  setDeskCount(21);assert.equal(editorDesks[20].scale,0.7);
  const el={style:{setProperty(name,value){this[name]=value;}}};
  applyDeskDimensions(el,editorDesks[0]);
  assert.equal(el.style.width,'56px');assert.equal(el.style.height,'35px');
  updateEditorGrid();assert.equal(document.getElementById('editor-canvas').classList['show-grid'],false);
  const canvas=document.getElementById('editor-canvas');
  const dragged={id:'dragged',x:48,y:72,scale:0.7};
  const draggedEl={style:{}};
  mouseDragDesk({clientX:63,clientY:97},dragged,draggedEl,canvas);
  listeners.mousemove({clientX:80,clientY:113});
  assert.equal(dragged.x,60);assert.equal(dragged.y,84);
  assert.equal(draggedEl.style.left,'60px');
  listeners.mousemove({clientX:999,clientY:999});
  assert.equal(dragged.x%12,0);assert.equal(dragged.y%12,0);
  assert(dragged.x+56<=700&&dragged.y+35<=500,'Snap at edges must stay in bounds');
  listeners.mouseup();assert(!listeners.mousemove);
  dragged.x=48;dragged.y=72;
  touchDragDesk({touches:[{clientX:63,clientY:97}]},dragged,draggedEl,canvas);
  listeners.touchmove({preventDefault(){},touches:[{clientX:80,clientY:113}]});
  assert.equal(dragged.x,60);assert.equal(dragged.y,84);
  listeners.touchend();assert(!listeners.touchmove);
  snapEditorDeskPosition(dragged,-50,-10,canvas);
  assert.equal(dragged.x,0);assert.equal(dragged.y,0);
  dragged.scale=1;snapEditorDeskPosition(dragged,999,999,canvas);
  assert(dragged.x+80<=700&&dragged.y+50<=500);
  document.getElementById('editor-grid').checked=true;updateEditorGrid();
  assert.equal(document.getElementById('editor-canvas').classList['show-grid'],true);
  document.getElementById('editor-snap').checked=false;
  dragged.x=48;dragged.y=72;
  mouseDragDesk({clientX:63,clientY:97},dragged,draggedEl,canvas);
  listeners.mousemove({clientX:80,clientY:113});
  assert.equal(dragged.x,65);assert.equal(dragged.y,88,'Free movement must work with the grid visible');
  listeners.mouseup();
  document.getElementById('editor-grid').checked=false;updateEditorGrid();
  touchDragDesk({touches:[{clientX:80,clientY:113}]},dragged,draggedEl,canvas);
  listeners.touchmove({preventDefault(){},touches:[{clientX:83,clientY:117}]});
  assert.equal(dragged.x,68);assert.equal(dragged.y,92,'Free touch movement must work with the grid hidden');
  listeners.touchend();
  document.getElementById('editor-snap').checked=true;
  snapEditorDeskPosition(dragged,68,92,canvas);
  assert.equal(dragged.x,72);assert.equal(dragged.y,96,'Snapping can be re-enabled');
  shuffleResult={pairs:[{student:'Test Student',desk:{x:0,y:60,rotation:0,scale:0.7}}]};
  buildPDF();
  assert(getPDF().includes('67.20 42.00 re B Q'),'PDF must use 70% desk dimensions');
  document.getElementById('print-teacher-perspective').checked=true;buildPDF();
  assert(getPDF().includes('67.20 42.00 re B Q'),'Teacher PDF must retain scaled dimensions');
  assert(!getPDF().includes('NaN'));
`,context);
console.log('Classroom editor checks passed: sizing, preset fit, no overlap, unchanged rejected layouts, auto-arrange, hidden-grid mouse/touch snapping, grid toggle and PDF export.');
