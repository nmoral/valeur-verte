/* Carte de validation par département (#224). Les données sont injectées par le
   serveur dans un bloc JSON — aucun appel réseau, aucune dépendance. */
const MD = JSON.parse(document.getElementById("carte-donnees").textContent);
const F = MD.feats;
const lerp=(a,b,t)=>Math.round(a+(b-a)*t);
const hx=h=>[parseInt(h.slice(1,3),16),parseInt(h.slice(3,5),16),parseInt(h.slice(5,7),16)];
const mix=(c1,c2,t)=>{const a=hx(c1),b=hx(c2);return `rgb(${lerp(a[0],b[0],t)},${lerp(a[1],b[1],t)},${lerp(a[2],b[2],t)})`};
const TEAL="#0f6e6e",AMBER="#d99a2b",CLAY="#b8442f",PALE="#e8f1f0";
function scaleBad(t){t=Math.max(0,Math.min(1,t));return t<.5?mix(TEAL,AMBER,t*2):mix(AMBER,CLAY,(t-.5)*2)}
function scaleSeq(t){t=Math.max(0,Math.min(1,t));return mix(PALE,TEAL,t)}

const MED_EC = MD.med.ec, MED_CB = MD.med.cb;
const cumulEB=m=>Math.round(((1+m.gain/100)*(1+m.gainCB/100)-1)*100);

const METRICS={
 gain:{dom:[5,36],bad:false,get:m=>m?m.gain:null,fmt:v=>(v>0?"+":"")+v+" %",
   lo:"+5 %",hi:"+36 %",
   titleHi:"Où atteindre C rapporte le plus",titleLo:"Où atteindre C rapporte le moins",
   noteHi:"Départements où la rénovation de E vers C ajoute le plus de valeur.",
   noteLo:"Départements où l'étiquette pèse le moins sur le prix."},
 cb:{dom:[0,15],bad:false,get:m=>m&&m.gainCB!=null?m.gainCB:null,fmt:v=>(v>0?"+":"")+v+" %",
   lo:"+0 %",hi:"+15 %",
   titleHi:"Où le cran vers B rapporte le plus",titleLo:"Où le cran vers B rapporte le moins",
   noteHi:"Supplément de valeur du dernier cran, de C à B, là où assez de maisons l'atteignent.",
   noteLo:"Départements où passer de C à B n'ajoute presque rien de plus que C."},
 solid:{dom:[0,60],bad:true,get:m=>m?m.dept:null,fmt:v=>v+" %",
   lo:"mesure fine",hi:"mesure large",
   titleHi:"Où la mesure est la plus large",titleLo:"Où la mesure est la plus fine",
   noteHi:"Part des zones estimées à l'échelle du département faute de ventes proches.",
   noteLo:"Départements où presque tout est mesuré localement."}
};
let cur="gain", desc=true;

const svg=document.getElementById("map"),tip=document.getElementById("tip");
const paths={};
/* Lien vers la page du département (#391), seulement s'il est mesuré (a une page). */
const lienDept=f=>(f.m&&f.slug)?"/valeur-verte/"+f.slug:null;
F.forEach(f=>{
 const p=document.createElementNS("http://www.w3.org/2000/svg","path");
 p.setAttribute("d",f.path);p.dataset.d=f.dept;paths[f.dept]=p;
 p.addEventListener("mousemove",e=>showTip(e,f));
 p.addEventListener("mouseenter",()=>{svg.classList.add("dim");p.classList.add("hl")});
 p.addEventListener("mouseleave",()=>{svg.classList.remove("dim");p.classList.remove("hl");tip.hidden=true});
 const href=lienDept(f);
 if(href){
  p.style.cursor="pointer";
  p.setAttribute("role","link");
  p.setAttribute("aria-label","Voir la valeur verte "+f.nom);
  p.addEventListener("click",()=>{window.location.href=href});
 }
 svg.appendChild(p)});

function colorFor(mm){const M=METRICS[cur],v=M.get(mm);
 if(v==null)return "#cfd8da";
 const t=(v-M.dom[0])/(M.dom[1]-M.dom[0]);
 return M.bad?scaleBad(t):scaleSeq(t)}

function paint(){
 const M=METRICS[cur];
 F.forEach(f=>paths[f.dept].setAttribute("fill",colorFor(f.m)));
 document.getElementById("legend").innerHTML=
  `<span>${M.lo}</span><div class="legbar" style="background:linear-gradient(90deg,${M.bad?TEAL+","+AMBER+","+CLAY:PALE+","+TEAL})"></div><span>${M.hi}</span>`;
 const arr=F.filter(f=>f.m&&M.get(f.m)!=null).slice()
   .sort((a,b)=>desc?M.get(b.m)-M.get(a.m):M.get(a.m)-M.get(b.m));
 document.getElementById("ptitle").textContent=desc?M.titleHi:M.titleLo;
 document.getElementById("pnote").textContent=desc?M.noteHi:M.noteLo;
 document.getElementById("flip").textContent=desc?"Voir l'autre extrémité":"Revenir au classement principal";
 document.getElementById("rank").innerHTML=arr.slice(0,10).map(f=>{
  const v=M.get(f.m),t=(v-M.dom[0])/(M.dom[1]-M.dom[0]);
  const c=M.bad?scaleBad(t):scaleSeq(t),dark=(!M.bad&&t<.45);
  const href=lienDept(f);
  const inner=`<span class="rc" style="background:${c};${dark?"color:#182226":""}">${f.dept}</span>`+
   `<span class="rn">${f.nom} <small>${f.m.n} zones</small></span>`+
   `<span class="rvv">${M.fmt(v)}</span>`;
  return href?`<li><a class="rlink" href="${href}">${inner}</a></li>`:`<li>${inner}</li>`}).join("")}

function steps(ec,eb){
 const mx=Math.max(eb,1),h=v=>Math.round(6+34*v/mx);
 return `<div class="steps">
  <div class="st"><i style="height:${h(ec)}px"></i><b>+${ec} %</b><u>de E à C</u></div>
  <div class="st"><i class="hi" style="height:${h(eb)}px"></i><b>+${eb} %</b><u>jusqu'à B</u></div>
 </div>`}

function showTip(e,f){
 const m=f.m;tip.hidden=false;
 let body;
 if(m&&m.gain!=null&&m.gainCB!=null){
  body=steps(m.gain,cumulEB(m))+
   `<div class="row"><span>le cran de plus, C→B</span><b>+${m.gainCB} %</b></div>
    <div class="row" style="border-top:0;margin-top:0;padding-top:2px"><span>zones mesurées</span><b>${m.n}</b></div>`;
 }else if(m&&m.gain!=null){
  body=`<div class="row" style="border-top:0"><span>de E à C</span><b>+${m.gain} %</b></div>
    <div class="row"><span>cran vers B</span><b>non mesuré ici</b></div>
    <div class="row"><span>zones mesurées</span><b>${m.n}</b></div>`;
 }else{
  body=`<div class="row" style="border-top:0"><span>Hors périmètre</span><b>—</b></div>`;
 }
 tip.innerHTML=`<h3>${f.nom}<span>${f.dept}</span></h3>`+body;
 const r=svg.getBoundingClientRect();
 let x=e.clientX-r.left+14,y=e.clientY-r.top+14;
 if(x>r.width-228)x=e.clientX-r.left-tip.offsetWidth-14;
 tip.style.left=x+"px";tip.style.top=y+"px"}

document.getElementById("seg").addEventListener("click",e=>{
 const b=e.target.closest("button");if(!b)return;
 cur=b.dataset.m;desc=true;
 [...e.currentTarget.children].forEach(c=>c.classList.toggle("on",c===b));paint()});
document.getElementById("flip").addEventListener("click",()=>{desc=!desc;paint()});

const norm=s=>s.toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g,"");
document.getElementById("q").addEventListener("input",e=>{
 const q=norm(e.target.value.trim()),box=document.getElementById("qres");
 if(q.length<2){box.hidden=true;return}
 const f=F.find(x=>x.m&&(x.dept===q||norm(x.nom).startsWith(q)));
 box.hidden=false;
 if(!f){box.innerHTML=`<div class="qn">Aucun résultat</div><div class="qm" style="border-top:0">Département non couvert, ou orthographe différente.</div>`;return}
 const ec=f.m.gain,cb=f.m.gainCB,href=lienDept(f);
 const contenu=`<div class="qn">${f.nom}<span>${f.dept}</span></div>`+
  `<div class="qsteps">
     <div class="qs"><b>+${ec} %</b><u>de E à C</u></div>
     <div class="qs"><b>${cb!=null?"+"+cb+" %":"—"}</b><u>cran vers B</u></div>
   </div>`+
  `<div class="qm">${cb!=null?`Le cran vers B n'ajoute que ${cb} points de plus`:`Le niveau B n'est pas mesurable ici`} · médiane nationale E→C ${MED_EC} %${MED_CB!=null?", C→B "+MED_CB+" %":""}</div>`+
  (href?`<div class="qgo">Voir la page de ${f.nom} →</div>`:"");
 box.innerHTML=href?`<a class="qlink" href="${href}">${contenu}</a>`:contenu});


paint();
