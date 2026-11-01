/* =========================================================
   הגדרות – לשנות כאן את פרטי הקשר שלכם
   ========================================================= */
// פרטי הקשר שמורים מקודדים כדי שלא יופיעו כטקסט באתר ולא ייקלטו בקלות ע"י בוטים.
// לשינוי: הריצו בקונסול של הדפדפן  btoa([..."972XXXXXXXXX"].reverse().join(""))  והדביקו כאן.
const CONFIG = {
  wa: "MzY4MzkzOTQ1Mjc5",
  em: "bW9jLmxpYW1nQDgxbXl5aGg=",
};
const reveal = s => [...atob(s)].reverse().join("");

/* ===== Nav ===== */
const nav = document.querySelector(".nav");
const burger = document.getElementById("burger");
const navLinks = document.getElementById("navLinks");
window.addEventListener("scroll", () => nav.classList.toggle("scrolled", window.scrollY > 20), { passive: true });
burger.addEventListener("click", () => {
  burger.classList.toggle("open");
  navLinks.classList.toggle("open");
});
navLinks.querySelectorAll("a").forEach(a => a.addEventListener("click", () => {
  burger.classList.remove("open");
  navLinks.classList.remove("open");
}));

/* ===== Contact links (נבנים רק בלחיצה – לא מוצגים בדף) ===== */
const waUrl = text => `https://wa.me/${reveal(CONFIG.wa)}?text=${encodeURIComponent(text)}`;
const mailUrl = (subject, body) =>
  `mailto:${reveal(CONFIG.em)}?subject=${encodeURIComponent(subject)}` + (body ? `&body=${encodeURIComponent(body)}` : "");
const HELLO = "היי, הגעתי מהאתר ואשמח לשמוע על הקמת מערכת טלפונית";
["waLink", "waFloat"].forEach(id => document.getElementById(id).addEventListener("click", e => {
  e.preventDefault();
  window.open(waUrl(HELLO), "_blank", "noopener");
}));
document.getElementById("mailLink").addEventListener("click", e => {
  e.preventDefault();
  window.location.href = mailUrl("פנייה מהאתר");
});
document.getElementById("year").textContent = new Date().getFullYear();

/* ===== Reveal on scroll ===== */
const io = new IntersectionObserver(entries => {
  entries.forEach(e => {
    if (e.isIntersecting) {
      e.target.classList.add("in");
      e.target.querySelectorAll("[data-count]").forEach(countUp);
      if (e.target.matches("[data-count]")) countUp(e.target);
      io.unobserve(e.target);
    }
  });
}, { threshold: 0.15 });
document.querySelectorAll(".reveal").forEach((el, i) => {
  el.style.transitionDelay = `${(i % 4) * 70}ms`;
  io.observe(el);
});

function countUp(el) {
  if (el.dataset.done) return;
  el.dataset.done = 1;
  const target = +el.dataset.count;
  const suffix = el.dataset.suffix || "";
  if (!target) { el.textContent = "0" + suffix; return; }
  const dur = 1600, t0 = performance.now();
  const step = t => {
    const p = Math.min((t - t0) / dur, 1);
    const v = Math.round(target * (1 - Math.pow(1 - p, 3)));
    el.textContent = v.toLocaleString("he-IL") + suffix;
    if (p < 1) requestAnimationFrame(step);
  };
  requestAnimationFrame(step);
}

/* ===== Hero phone animation ===== */
(() => {
  const timer = document.getElementById("callTimer");
  let s = 0;
  setInterval(() => {
    s = (s + 1) % 3600;
    timer.textContent = `${String(Math.floor(s / 60)).padStart(2, "0")}:${String(s % 60).padStart(2, "0")}`;
  }, 1000);

  const lines = [
    { t: '"שלום וברוכים הבאים! לשמיעת העדכונים הקישו 1..."', k: null },
    { t: '"להזמנת מוצרים הקישו 2, לתרומה הקישו 3"', k: "2" },
    { t: '"בחרתם הזמנה. הקישו את מספר המוצר וסולמית"', k: "4" },
    { t: '"כמה יחידות? הקישו כמות וסולמית"', k: "#" },
    { t: '"תודה! ההזמנה נקלטה ונשלחה בוואטסאפ ✅"', k: "1" },
  ];
  const bubble = document.getElementById("heroBubble");
  const keys = [...document.querySelectorAll(".mini-pad span")];
  let i = 0;
  setInterval(() => {
    i = (i + 1) % lines.length;
    const L = lines[i];
    if (L.k) {
      const key = keys.find(k => k.textContent === L.k);
      if (key) { key.classList.add("hit"); setTimeout(() => key.classList.remove("hit"), 350); }
    }
    bubble.style.opacity = 0;
    setTimeout(() => { bubble.textContent = L.t; bubble.style.opacity = 1; }, 300);
  }, 3200);
})();

/* ===== Ideas filter ===== */
document.getElementById("filters").addEventListener("click", e => {
  const btn = e.target.closest(".chip");
  if (!btn) return;
  document.querySelectorAll(".chip").forEach(c => c.classList.toggle("active", c === btn));
  const f = btn.dataset.filter;
  document.querySelectorAll(".idea").forEach(card => {
    card.classList.toggle("hide", f !== "all" && card.dataset.cat !== f);
  });
});

/* ===== IVR Simulator ===== */
const TREE = {
  name: "תפריט ראשי",
  say: "שלום וברוכים הבאים למערכת קו חכם! לעדכונים הקישו 1, להזמנות 2, לזימון תור 3, לסקר 4, לתרומה 5, לנציג 0.",
  opts: {
    "1": {
      name: "עדכונים",
      say: "קו העדכונים. לחדשות היום הקישו 1, לזמני פעילות 2, לחזרה הקישו כוכבית.",
      opts: {
        "1": { name: "חדשות היום", say: "🔊 מושמעת הקלטת העדכון היומי... (מתעדכנת אוטומטית כל בוקר)", end: true },
        "2": { name: "זמני פעילות", say: "🕘 אנחנו זמינים בימים א'-ה' 9:00-18:00. המערכת עצמה עונה 24/7.", end: true },
      },
    },
    "2": {
      name: "הזמנות",
      say: "מערכת ההזמנות. לחלות הקישו 1, ליין הקישו 2, לסלסלת שבת הקישו 3.",
      opts: {
        "1": { name: "חלות", say: "✅ נוספו 2 חלות לסל. ההזמנה נשלחה לבית העסק בוואטסאפ. תודה!", end: true },
        "2": { name: "יין", say: "✅ נוסף בקבוק יין לסל. תקבלו SMS עם אישור ומועד משלוח.", end: true },
        "3": { name: "סלסלת שבת", say: "✅ סלסלת שבת הוזמנה. לתשלום מאובטח באשראי – המערכת תעביר אתכם עכשיו.", end: true },
      },
    },
    "3": {
      name: "זימון תור",
      say: "התור הפנוי הקרוב: מחר ב-10:30. לאישור הקישו 1, לתור אחר הקישו 2.",
      opts: {
        "1": { name: "אישור", say: "📅 התור נקבע למחר ב-10:30. תזכורת תישלח שעה לפני.", end: true },
        "2": { name: "תור אחר", say: "📅 התור הבא: יום רביעי ב-14:00 – נקבע בהצלחה!", end: true },
      },
    },
    "4": {
      name: "סקר",
      say: "כמה הייתם מרוצים מהשירות? הקישו 1 עד 5.",
      opts: Object.fromEntries(["1", "2", "3", "4", "5"].map(n => [n, { name: `דירוג ${n}`, say: `🗳️ הצבעתם ${n}. תודה! התוצאות מתעדכנות בדשבורד בזמן אמת.`, end: true }])),
    },
    "5": {
      name: "תרומה",
      say: "לתרומה של 18 ₪ הקישו 1, 36 ₪ הקישו 2, 100 ₪ הקישו 3.",
      opts: {
        "1": { name: "18 ₪", say: "💳 מעבירים לסליקה מאובטחת של 18 ₪. קבלה תישלח אוטומטית.", end: true },
        "2": { name: "36 ₪", say: "💳 מעבירים לסליקה מאובטחת של 36 ₪. קבלה תישלח אוטומטית.", end: true },
        "3": { name: "100 ₪", say: "💳 מעבירים לסליקה מאובטחת של 100 ₪. תודה רבה!", end: true },
      },
    },
    "0": { name: "נציג", say: "🎵 אתם ממתינים לנציג. מקומכם בתור: 1. (או שהבוט הקולי עונה מיד 🤖)", end: true },
  },
};

const simLog = document.getElementById("simLog");
const simPath = document.getElementById("simPath");
const simOptions = document.getElementById("simOptions");
let stack = [];

function current() { return stack[stack.length - 1]; }
function addMsg(text, cls) {
  const d = document.createElement("div");
  d.className = `msg ${cls}`;
  d.textContent = text;
  simLog.appendChild(d);
  simLog.scrollTop = simLog.scrollHeight;
}
function renderNode() {
  const node = current();
  simPath.textContent = stack.map(n => n.name).join(" › ");
  addMsg(node.say, "sys");
  simOptions.innerHTML = "";
  const entries = node.end ? [] : Object.entries(node.opts);
  entries.forEach(([k, v]) => {
    const s = document.createElement("span");
    s.innerHTML = `<b>${k}</b> ${v.name}`;
    simOptions.appendChild(s);
  });
  if (stack.length > 1) simOptions.insertAdjacentHTML("beforeend", "<span><b>*</b> חזרה</span>");
  if (node.end) simOptions.insertAdjacentHTML("beforeend", "<span><b>#</b> לתפריט הראשי</span>");
}
function resetSim() {
  simLog.innerHTML = "";
  stack = [TREE];
  renderNode();
}
function press(k) {
  addMsg(k, "user");
  const node = current();
  if (k === "*" && stack.length > 1) { stack.pop(); renderNode(); return; }
  if (k === "#" && node.end) { stack = [TREE]; renderNode(); return; }
  const next = !node.end && node.opts[k];
  if (next) { stack.push(next); renderNode(); }
  else addMsg("הקשה לא חוקית, נסו שוב.", "sys");
}
document.getElementById("keypad").addEventListener("click", e => {
  const b = e.target.closest("button");
  if (!b) return;
  b.classList.add("press");
  setTimeout(() => b.classList.remove("press"), 120);
  press(b.dataset.k);
});
document.getElementById("simReset").addEventListener("click", resetSim);
resetSim();

/* ===== Contact form ===== */
const form = document.getElementById("contactForm");
const note = document.getElementById("formNote");
let via = "whatsapp";
form.querySelectorAll("button[type=submit]").forEach(b => b.addEventListener("click", () => { via = b.dataset.via; }));

form.addEventListener("submit", e => {
  e.preventDefault();
  const data = Object.fromEntries(new FormData(form));
  let ok = true;
  ["name", "phone"].forEach(n => {
    const field = form.querySelector(`[name=${n}]`).closest(".field");
    const bad = !data[n] || !data[n].trim() || (n === "phone" && data[n].replace(/\D/g, "").length < 9);
    field.classList.toggle("err", bad);
    if (bad) ok = false;
  });
  if (!ok) {
    note.textContent = "נא למלא שם ומספר טלפון תקין.";
    note.className = "form-note bad";
    return;
  }
  const text =
    `פנייה חדשה מהאתר 📞\n` +
    `שם: ${data.name}\n` +
    `טלפון: ${data.phone}\n` +
    `סוג מערכת: ${data.type}\n` +
    (data.message ? `פרטים: ${data.message}` : "");

  if (via === "email") {
    window.location.href = mailUrl("פנייה מהאתר – " + data.name, text);
  } else {
    window.open(waUrl(text), "_blank", "noopener");
  }
  note.textContent = "מעולה! ההודעה מוכנה לשליחה – נחזור אליכם בהקדם 🙌";
  note.className = "form-note";
  form.reset();
});
