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

const MED_EC = MD.med.ec, MED_ED = MD.med.ed;

const METRICS={
 delta:{dom:[0,21],bad:false,get:m=>m&&m.gain!=null&&m.gainED!=null?m.gain-m.gainED:null,
   fmt:v=>(v>0?"+":"")+v+" pts",lo:"0 pt",hi:"21 pts",
   titleHi:"Là où s'arrêter à D coûte le plus cher",
   titleLo:"Là où le palier C change le moins",
   noteHi:"Points de valeur perdus en s'arrêtant à la classe D plutôt que d'atteindre C.",
   noteLo:"Départements où D et C se valorisent presque pareil."},
 gain:{dom:[5,36],bad:false,get:m=>m?m.gain:null,fmt:v=>(v>0?"+":"")+v+" %",
   lo:"+5 %",hi:"+36 %",
   titleHi:"Le premium le plus élevé",titleLo:"Le premium le plus faible",
   noteHi:"Départements où la rénovation de E vers C ajoute le plus de valeur.",
   noteLo:"Départements où l'étiquette pèse le moins sur le prix."},
 solid:{dom:[0,60],bad:true,get:m=>m?m.dept:null,fmt:v=>v+" %",
   lo:"mesure fine",hi:"mesure large",
   titleHi:"Où la mesure est la plus large",titleLo:"Où la mesure est la plus fine",
   noteHi:"Part des zones estimées à l'échelle du département faute de ventes proches.",
   noteLo:"Départements où presque tout est mesuré localement."}
};
let cur="delta", desc=true;

const svg=document.getElementById("map"),tip=document.getElementById("tip");
const paths={};
F.forEach(f=>{
 const p=document.createElementNS("http://www.w3.org/2000/svg","path");
 p.setAttribute("d",f.path);p.dataset.d=f.dept;paths[f.dept]=p;
 p.addEventListener("mousemove",e=>showTip(e,f));
 p.addEventListener("mouseenter",()=>{svg.classList.add("dim");p.classList.add("hl")});
 p.addEventListener("mouseleave",()=>{svg.classList.remove("dim");p.classList.remove("hl");tip.hidden=true});
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
  return `<li><span class="rc" style="background:${c};${dark?"color:#182226":""}">${f.dept}</span>`+
   `<span class="rn">${f.nom} <small>${f.m.n} zones</small></span>`+
   `<span class="rvv">${M.fmt(v)}</span></li>`}).join("")}

function steps(ed,ec){
 const mx=Math.max(ec,1),h=v=>Math.round(6+34*v/mx);
 return `<div class="steps">
  <div class="st"><i style="height:${h(ed)}px"></i><b>+${ed} %</b><u>vers D</u></div>
  <div class="st"><i class="hi" style="height:${h(ec)}px"></i><b>+${ec} %</b><u>vers C</u></div>
 </div>`}

function showTip(e,f){
 const m=f.m;tip.hidden=false;
 tip.innerHTML=`<h3>${f.nom}<span>${f.dept}</span></h3>`+(m&&m.gain!=null&&m.gainED!=null?
  steps(m.gainED,m.gain)+
  `<div class="row"><span>écart entre les deux</span><b>${m.gain-m.gainED>0?"+":""}${m.gain-m.gainED} pts</b></div>
   <div class="row" style="border-top:0;margin-top:0;padding-top:2px"><span>zones mesurées</span><b>${m.n}</b></div>`
  :`<div class="row" style="border-top:0"><span>Hors périmètre</span><b>—</b></div>`);
 const r=svg.getBoundingClientRect();
 let x=e.clientX-r.left+14,y=e.clientY-r.top+14;
 if(x>r.width-228)x=e.clientX-r.left-tip.offsetWidth-14;
 tip.style.left=x+"px";tip.style.top=y+"px"}

document.getElementById("seg").addEventListener("click",e=>{
 const b=e.target.closest("button");if(!b)return;
 cur=b.dataset.m;desc=true;
 [...e.currentTarget.children].forEach(c=>c.classList.toggle("on",c===b));paint()});
document.getElementById("flip").addEventListener("click",()=>{desc=!desc;paint()});

const norm=s=>s.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g,"");
document.getElementById("q").addEventListener("input",e=>{
 const q=norm(e.target.value.trim()),box=document.getElementById("qres");
 if(q.length<2){box.hidden=true;return}
 const f=F.find(x=>x.m&&(x.dept===q||norm(x.nom).startsWith(q)));
 box.hidden=false;
 if(!f){box.innerHTML=`<div class="qn">Aucun résultat</div><div class="qm" style="border-top:0">Département non couvert, ou orthographe différente.</div>`;return}
 const ec=f.m.gain,ed=f.m.gainED,d=ec-ed;
 box.innerHTML=`<div class="qn">${f.nom}<span>${f.dept}</span></div>`+
  `<div class="qsteps">
     <div class="qs"><b>+${ed} %</b><u>vers D</u></div>
     <div class="qs"><b>+${ec} %</b><u>vers C</u></div>
   </div>`+
  `<div class="qm">Aller jusqu'à C rapporte ${d} points de plus · médiane nationale ${MED_ED} % et ${MED_EC} %</div>`});


paint();
