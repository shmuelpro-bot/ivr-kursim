const { chromium } = require('playwright'); const slides = require('./slides.js'); const fs=require('fs');
const css = `@import url('https://fonts.googleapis.com/css2?family=Heebo:wght@400;600;800&display=swap');
html,body{margin:0;width:1280px;height:720px;background:transparent;font-family:Heebo,'FreeSans',sans-serif;direction:rtl;color:#f3f1ea}
.wrap{position:absolute;inset:0;padding:64px 88px;display:flex;flex-direction:column;justify-content:center;background:linear-gradient(90deg,rgba(8,12,16,.55),rgba(8,12,16,.86) 70%)}
.k{color:#f2c230;font-weight:600;font-size:26px;letter-spacing:.5px;margin-bottom:10px}
.t{font-weight:800;font-size:52px;line-height:1.15;margin-bottom:26px}
ul{margin:0;padding:0 28px 0 0;list-style:none} li{font-size:28px;line-height:1.45;margin:6px 0;position:relative} li:before{content:'';position:absolute;right:-26px;top:.62em;width:10px;height:10px;background:#f2c230;border-radius:2px}
.big{align-items:center;text-align:center;background:rgba(8,12,16,.6)} .big .t{font-size:68px} .big ul{padding:0} .big li{font-size:32px;color:#d9d4c7} .big li:before{display:none}
.end .mail{direction:ltr;font-size:44px;font-weight:600;color:#f2c230;margin:18px 0}`;
(async()=>{ const b=await chromium.launch(); const p=await b.newPage({viewport:{width:1280,height:720}});
 const all=[...slides,{end:true}];
 for (let i=0;i<all.length;i++){ const s=all[i]; let html;
  if(s.end) html=`<div class="wrap big end"><div class="t">רוצים לשאול או להגיב?</div><div style="font-size:30px">כתבו לי במייל</div><div class="mail">hhyym189@gmail.com</div><div style="font-size:28px;color:#d9d4c7">ואם אהבתם, תעשו מנוי לערוץ</div></div>`;
  else html=`<div class="wrap ${s.big?'big':''}">${s.k?`<div class="k">${s.k}</div>`:''}<div class="t">${s.t}</div><ul>${s.b.map(x=>`<li>${x}</li>`).join('')}</ul></div>`;
  await p.setContent(`<style>${css}</style>${html}`,{waitUntil:'networkidle'}); await p.evaluate(()=>document.fonts.ready);
  await p.screenshot({path:`slide${String(i).padStart(2,'0')}.png`,omitBackground:true}); }
 await b.close(); console.log(all.length,'slides'); })();
