const { chromium } = require('playwright'); const fs=require('fs');
const bg='data:image/jpeg;base64,'+fs.readFileSync('thumb_bg.jpg').toString('base64');
const html=`<style>@import url('https://fonts.googleapis.com/css2?family=Heebo:wght@800;900&display=swap');
html,body{margin:0;background:#000;font-family:Heebo,sans-serif}.f{position:relative;width:1280px;height:720px;overflow:hidden}
.bg{position:absolute;left:-470px;top:-40px;width:1472px;height:828px;background:url(${bg}) center/cover;filter:contrast(1.15) saturate(.7) brightness(.95);-webkit-mask-image:linear-gradient(90deg,#000 55%,transparent 68%)}
.shade{position:absolute;inset:0;background:linear-gradient(270deg,rgba(0,0,0,.92) 0%,rgba(0,0,0,.75) 42%,rgba(0,0,0,0) 70%)}
.txt{position:absolute;right:56px;top:70px;width:620px;color:#fff;direction:rtl;text-align:right}
.a{font-weight:900;font-size:118px;line-height:.98;text-shadow:0 6px 24px #000}
.a span{color:#f2c230}
.b{margin-top:26px;display:inline-block;background:#d81f26;color:#fff;font-weight:900;font-size:64px;padding:4px 26px 10px;border-radius:10px;transform:rotate(-2deg)}
.c{margin-top:26px;font-weight:800;font-size:40px;color:#e8e4da;text-shadow:0 3px 12px #000}</style>
<div class=f><div class=bg></div><div class=shade></div><div class=txt><div class=a>סרט אימה<br>שלם עם <span>AI</span></div><div class=b>ב-12 דולר</div><div class=c>מהתסריט ועד הסרטון</div></div></div>`;
(async()=>{const b=await chromium.launch();const p=await b.newPage({viewport:{width:1280,height:720}});await p.setContent(html,{waitUntil:'networkidle'});await p.evaluate(()=>document.fonts.ready);await p.screenshot({path:'../YouTube_Thumbnail.jpg',type:'jpeg',quality:92});await b.close();})();
