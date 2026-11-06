/*
 * Hosted adapter: lets the tracker page (index.html) run on Hostinger.
 * It provides the same `claude.use("db" | "user" | "downloads")` interface the page
 * was written against, backed by the PHP + MySQL API in /api, plus a sign-in screen.
 */
(function () {
  "use strict";
  window.HOSTED = true;
  const API = "api/index.php";
  const POLL_MS = 20000;

  /* ---------- API ---------- */
  async function call(action, body) {
    let res, json = null;
    try {
      res = await fetch(API + "?a=" + action, {
        method: body ? "POST" : "GET",
        credentials: "same-origin",
        headers: body ? { "Content-Type": "application/json", "X-Req": "1" } : { "X-Req": "1" },
        body: body ? JSON.stringify(body) : undefined,
      });
      json = await res.json();
    } catch (_) {
      throw { code: "unavailable", message: "The server can't be reached. Check your connection." };
    }
    if (!res.ok || (json && json.error)) {
      const err = (json && json.error) || { code: "unavailable", message: "Server error." };
      if (res.status === 401 && action !== "login") { me = null; showAuth("login"); }
      throw err;
    }
    return json;
  }
  const genId = () => Date.now().toString(36) + Math.random().toString(36).slice(2, 10);

  /* ---------- local cache + listeners (mimics live snapshots) ---------- */
  let cache = null, me = null, timer = null, refreshing = null;
  const listeners = {};
  function snapshot(coll) {
    let rows = (cache && cache[coll]) || [];
    if (coll === "movements") rows = rows.slice().sort((a, b) => (b.at || 0) - (a.at || 0));
    return {
      docs: rows.map((r) => { const { id, ...rest } = r; return { id, exists: true, data: () => rest, metadata: { fromCache: false, hasPendingWrites: false } }; }),
      size: rows.length, empty: !rows.length,
      metadata: { fromCache: false, hasPendingWrites: false },
      docChanges: () => [],
    };
  }
  function emitAll() { for (const c in listeners) for (const fn of listeners[c]) fn(snapshot(c)); }
  async function refresh() {
    if (refreshing) return refreshing;
    refreshing = call("data").then((d) => { cache = d; emitAll(); }).finally(() => { refreshing = null; });
    return refreshing;
  }
  let soonT = null;
  function refreshSoon() { clearTimeout(soonT); soonT = setTimeout(() => refresh().catch(() => {}), 1500); }
  function localPut(coll, row, merge) {
    if (!cache) return;
    const list = cache[coll] || (cache[coll] = []);
    const i = list.findIndex((r) => r.id === row.id);
    if (i >= 0) list[i] = merge ? { ...list[i], ...row } : row; else list.push(row);
    emitAll();
  }
  function localDel(coll, id) {
    if (!cache || !cache[coll]) return;
    cache[coll] = cache[coll].filter((r) => r.id !== id);
    emitAll();
  }

  async function save(coll, id, data, mode) {
    const r = await call("save", { coll, id, data, mode });
    localPut(coll, r.row || { id, ...data }, mode === "update");
    refreshSoon();
  }
  function docRef(coll, id) {
    return {
      id, path: coll + "/" + id,
      set: (d) => save(coll, id, d, "set"),
      update: (d) => save(coll, id, d, "update"),
      delete: async () => { await call("delete", { coll, id }); localDel(coll, id); refreshSoon(); },
      get: async () => { const r = ((cache && cache[coll]) || []).find((x) => x.id === id); return { id, exists: !!r, data: () => r }; },
      onSnapshot: () => () => {},
    };
  }
  function collection(coll) {
    const q = {
      path: coll,
      where: () => q, orderBy: () => q, limit: () => q,
      onSnapshot(next) {
        (listeners[coll] = listeners[coll] || []).push(next);
        if (cache) setTimeout(() => next(snapshot(coll)), 0);
        return () => { listeners[coll] = listeners[coll].filter((f) => f !== next); };
      },
      get: async () => snapshot(coll),
      doc: (id) => docRef(coll, id || genId()),
      add: async (data) => { const id = genId(); await save(coll, id, data, "set"); return docRef(coll, id); },
    };
    return q;
  }
  const db = {
    collection,
    doc(path) { const p = String(path).split("/"); return docRef(p[0], p[1]); },
  };

  const user = {
    id: async () => (me ? me.id : null),
    me: async () => me,
    can: async (n) => (n === "data.write" ? !!me && me.role !== "Viewer" : null),
    canEdit: () => !!me && me.role === "Admin",
    isOwner: () => !!me && me.role === "Admin",
    profiles: async () => ({}),
  };
  const downloads = {
    async save({ filename, data }) {
      const blob = data instanceof Blob ? data : new Blob([data], { type: "text/csv;charset=utf-8" });
      const a = document.createElement("a");
      a.href = URL.createObjectURL(blob); a.download = filename;
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(a.href), 2000);
    },
  };

  let resolveReady;
  const ready = new Promise((r) => (resolveReady = r));
  window.claude = {
    use: async (name) => {
      if (name === "db") { await ready; return db; }
      if (name === "user") { await ready; return user; }
      if (name === "downloads") return downloads;
      return null;
    },
  };

  /* ---------- sign-in screen ---------- */
  const css = `
  .auth{position:fixed;inset:0;z-index:50;background:var(--paper);display:flex;align-items:center;justify-content:center;padding:20px;overflow-y:auto}
  .auth[hidden]{display:none}
  .auth form{background:var(--surface);border:1px solid var(--line);border-radius:16px;padding:26px 22px;width:100%;max-width:380px;display:flex;flex-direction:column;gap:14px;box-shadow:0 10px 30px rgba(0,0,0,.08)}
  .auth h1{font-size:24px}
  .auth p{margin:0;color:var(--muted);font-size:14px}
  .auth .btn{justify-content:center;padding:11px}
  .userchip{margin-top:auto;background:var(--paper);border:1px solid var(--line);border-radius:12px;padding:12px;display:flex;flex-direction:column;gap:10px;margin-left:4px;margin-right:4px}
  .uc-who{display:flex;align-items:center;gap:10px;min-width:0}
  .uc-av{width:38px;height:38px;border-radius:50%;background:var(--accent-soft);color:var(--accent);display:grid;place-items:center;font-weight:700;font-size:14px;flex:none}
  .uc-txt{display:flex;flex-direction:column;min-width:0}
  .uc-txt b{color:var(--ink);font-weight:600;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .uc-txt .r{font-size:12px;color:var(--muted)}
  .uc-out{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:9px;border:0;border-radius:8px;background:#d93c3c;color:#fff;font:inherit;font-weight:600;cursor:pointer}
  .uc-out:hover{background:#c22f2f}
  .uc-out svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
  .homeuser{display:none}
  @media (max-width:999px){.homeuser{display:flex;padding:4px 2px 0}}
  body.not-admin .pageadd, body.not-admin [data-editmaster], body.not-admin #btnBulkRooms, body.not-admin [data-editblock], body.not-admin [data-delmaster], body.not-admin [data-delblock], body.not-admin #aTypeAdd, body.not-admin #aVendorAdd, body.not-admin #btnAddMaster{display:none!important}
  `;
  const style = document.createElement("style");
  style.textContent = css;
  document.head.appendChild(style);

  function el(html) { const t = document.createElement("template"); t.innerHTML = html.trim(); return t.content.firstChild; }
  let auth;
  function buildAuth() {
    auth = el(`<div class="auth" id="auth" hidden>
      <form id="authForm" novalidate>
        <h1 id="authTitle">Sign in</h1>
        <p id="authText">Hostel Asset Movement Tracker</p>
        <div class="field" id="authNameF" hidden><label for="authName">Your name <span class="req">*</span></label><input id="authName" autocomplete="name"></div>
        <div class="field"><label for="authEmail">Email <span class="req">*</span></label><input id="authEmail" type="email" autocomplete="username" inputmode="email"></div>
        <div class="field"><label for="authPass">Password <span class="req">*</span></label><input id="authPass" type="password" autocomplete="current-password"></div>
        <div class="err" id="authErr"></div>
        <button class="btn primary" type="submit" id="authBtn">Sign in</button>
      </form></div>`);
    document.body.appendChild(auth);
    auth.querySelector("#authForm").addEventListener("submit", onAuthSubmit);
  }
  let authMode = "login";
  function showAuth(mode) {
    if (!auth) buildAuth();
    authMode = mode;
    const setup = mode === "setup";
    auth.querySelector("#authTitle").textContent = setup ? "Create the admin account" : "Sign in";
    auth.querySelector("#authText").textContent = setup
      ? "First-time setup. This account can add users, blocks and masters."
      : "Hostel Asset Movement Tracker";
    auth.querySelector("#authNameF").hidden = !setup;
    auth.querySelector("#authPass").autocomplete = setup ? "new-password" : "current-password";
    auth.querySelector("#authPass").placeholder = setup ? "At least 8 characters" : "";
    auth.querySelector("#authBtn").textContent = setup ? "Create account" : "Sign in";
    auth.querySelector("#authErr").textContent = "";
    auth.hidden = false;
    setTimeout(() => auth.querySelector(setup ? "#authName" : "#authEmail").focus(), 30);
  }
  async function onAuthSubmit(e) {
    e.preventDefault();
    const btn = auth.querySelector("#authBtn"), errEl = auth.querySelector("#authErr");
    const body = { email: auth.querySelector("#authEmail").value.trim(), password: auth.querySelector("#authPass").value };
    if (authMode === "setup") body.name = auth.querySelector("#authName").value.trim();
    btn.disabled = true; errEl.textContent = "";
    try {
      const r = await call(authMode === "setup" ? "setup" : "login", body);
      me = r.user;
      auth.querySelector("#authPass").value = "";
      await signedIn();
    } catch (err) {
      errEl.textContent = err.message || "Couldn't sign in.";
    } finally { btn.disabled = false; }
  }

  function userChips() {
    document.querySelectorAll(".userchip").forEach((n) => n.remove());
    if (!me) return;
    const html = `<div class="userchip"><div class="uc-who"><span class="uc-av"></span><span class="uc-txt"><b></b><span class="r"></span></span></div><button type="button" class="uc-out"><svg viewBox="0 0 24 24"><path d="M14 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h7a2 2 0 0 0 2-2v-3M9 12h12M18 9l3 3-3 3"/></svg>Log out</button></div>`;
    const side = document.querySelector(".side");
    for (const [parent, extra] of [[side, ""]]) {
      if (!parent) continue;
      const n = el(html); if (extra) n.classList.add(extra);
      n.querySelector("b").textContent = me.name;
      n.querySelector(".uc-av").textContent = (me.name || "?").split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join("");
      n.querySelector(".r").textContent = me.role || "";
      n.querySelector("button").addEventListener("click", logout);
      parent.appendChild(n);
    }
  }
  async function logout() {
    try { await call("logout", {}); } catch (_) {}
    location.reload();
  }

  async function signedIn() {
    if (auth) auth.hidden = true;
    document.body.classList.toggle("not-admin", !me || me.role !== "Admin");
    userChips();
    await refresh();
    resolveReady();
    clearInterval(timer);
    timer = setInterval(() => { if (document.visibilityState === "visible" && me) refresh().catch(() => {}); }, POLL_MS);
    document.addEventListener("visibilitychange", () => { if (document.visibilityState === "visible" && me) refresh().catch(() => {}); });
  }

  async function start() {
    try {
      const s = await call("status");
      if (s.user) { me = s.user; await signedIn(); }
      else showAuth(s.needsSetup ? "setup" : "login");
    } catch (err) {
      if (!auth) buildAuth();
      showAuth("login");
      auth.querySelector("#authErr").textContent = err.message || "The server can't be reached.";
    }
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", start);
  else start();
})();
