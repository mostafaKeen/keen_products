import{c}from"./button-D4ZGD3WH.js";/**
 * @license lucide-react v0.475.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const p=[["path",{d:"M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z",key:"1c8476"}],["path",{d:"M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7",key:"1ydtos"}],["path",{d:"M7 3v4a1 1 0 0 0 1 1h7",key:"t51u73"}]],l=c("Save",p);/**
 * @license lucide-react v0.475.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const u=[["path",{d:"M3 6h18",key:"d0wm0j"}],["path",{d:"M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6",key:"4alrt4"}],["path",{d:"M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2",key:"v07s0e"}],["line",{x1:"10",x2:"10",y1:"11",y2:"17",key:"1uufr5"}],["line",{x1:"14",x2:"14",y1:"11",y2:"17",key:"xtxkd"}]],y=c("Trash2",u);async function f(n,a,t,s="POST"){const r={Accept:"application/json",Authorization:`Bearer ${a}`},i={method:s,headers:r};t!==void 0&&s!=="GET"&&(r["Content-Type"]="application/json",i.body=JSON.stringify(t));const o=await fetch(n,i),e=await o.json().catch(()=>({}));if(!o.ok){const d=e&&(e.message||e.error)||`Request failed with status ${o.status}`;throw Object.assign(new Error(String(d)),{status:o.status,payload:e})}return e}function w(n="https://api.bitrix24.com/api/v1/"){return typeof window>"u"?Promise.resolve(!1):window.BX24?Promise.resolve(!0):window.parent===window?Promise.resolve(!1):new Promise(a=>{const t=document.createElement("script");t.src=n,t.async=!0,t.onload=()=>a(!!window.BX24),t.onerror=()=>a(!1),document.head.appendChild(t)})}export{l as S,y as T,f as b,w as l};
