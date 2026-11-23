const { chromium } = require('playwright');
const html=`<style>@import url('https://fonts.googleapis.com/css2?family=Heebo:wght@700;900&display=swap');
html,body{margin:0;width:1080px;height:1920px;background:transparent;font-family:Heebo,sans-serif;direction:rtl;color:#fff;text-align:center}
.top{position:absolute;top:250px;left:0;right:0}
.a{font-weight:900;font-size:92px;line-height:1.05;text-shadow:0 6px 28px #000,0 0 8px #000}.a span{color:#f2c230}
.bot{position:absolute;top:1390px;left:90px;right:90px}
.b{display:inline-block;background:rgba(216,31,38,.95);font-weight:900;font-size:50px;padding:10px 34px 16px;border-radius:14px}
.c{margin-top:22px;font-weight:700;font-size:40px;color:#eee;text-shadow:0 3px 14px #000}</style>
<div class=top><div class=a>סרט אימה של דקה<br>נוצר כולו עם <span>AI</span></div></div>
<div class=bot><div class=b>ב-12 דולר בלבד</div><div class=c>איך עשיתי את זה? הסרטון המלא בערוץ</div></div>`;
(async()=>{const b=await chromium.launch();const p=await b.newPage({viewport:{width:1080,height:1920}});await p.setContent(html,{waitUntil:'networkidle'});await p.evaluate(()=>document.fonts.ready);await p.screenshot({path:'shorts_overlay.png',omitBackground:true});await b.close();})();
