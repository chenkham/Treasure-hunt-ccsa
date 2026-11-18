"use strict";
const API_ROOT = new URL("../", location.href).pathname.replace(/\/$/, "");
const $ = (s) => document.querySelector(s);
const S = {
  token: sessionStorage.getItem("hunt-admin") || "",
  tab: "live",
  data: null,
  connected: false,
  anchor: performance.now(),
  serverTime: 0,
  modalSave: null,
  map: null,
};
const esc = (v) =>
  String(v ?? "").replace(
    /[&<>"']/g,
    (c) =>
      ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[
        c
      ],
  );
const titles = {
  live: "Live event",
  teams: "Teams & devices",
  content: "Riddles & QR spots",
  map: "Map & walking routes",
  setup: "Event readiness",
  audit: "Evidence & audit",
};
const age = (t) =>
  !t
    ? "Never"
    : `${Math.max(0, Math.floor((serverNow() - t) / 1000)) < 60 ? Math.max(0, Math.floor((serverNow() - t) / 1000)) + "s" : Math.floor((serverNow() - t) / 60000) + "m"} ago`;
const serverNow = () => S.serverTime + performance.now() - S.anchor;
const statusBadge = (s) =>
  `<span class="badge ${["FINISHED", "QUALIFIED", "RUNNING", "WINNER", "SECOND_PLACE", "THIRD_PLACE"].includes(s) ? "good" : ["ELIMINATED", "DISQUALIFIED", "TIME_OVER"].includes(s) ? "bad" : "warn"}">${esc(s?.replaceAll("_", " ") || "WAITING")}</span>`;
function notify(message, error = false) {
  const el = $("#notification");
  el.textContent = message;
  el.classList.toggle("error", error);
  el.hidden = false;
}
async function api(path, options = {}) {
  const res = await fetch(API_ROOT + "/api" + path, {
    ...options,
    headers: {
      "Content-Type": "application/json",
      ...(S.token ? { Authorization: "Bearer " + S.token } : {}),
      ...(options.headers || {}),
    },
  });
  if (!res.ok) {
    const data = await res.json().catch(() => ({ detail: "Request failed" }));
    if (res.status === 401 || (res.status===403&&data.detail==='This account has been disabled')) {
      S.token = "";
      sessionStorage.removeItem("hunt-admin");
      showAuth();
    }
    throw new Error(
      typeof data.detail === "string"
        ? data.detail
        : JSON.stringify(data.detail),
    );
  }
  return res.status === 204 ? null : res.json();
}
function showAuth() {
  $("#login").hidden = !!S.token;
  $("#application").hidden = !S.token;
}
async function refresh(renderView = false) {
  if (!S.token) return;
  if(S.refreshing){if(renderView)S.refreshQueued=true;return;}
  S.refreshing=true;
  try {
    const requestTime=performance.now();
    S.data = await api("/admin/overview");
    S.access=S.data.access;
    applyStaffUI();
    S.connected = true;
    S.serverTime = S.data.server_time+(performance.now()-requestTime)/2;
    S.anchor = performance.now();
    $("#connection").textContent = "Server connected";
    $("#published").textContent = S.data.event.published
      ? "Routes ready"
      : "Ready to prepare";
    if (renderView || ["live", "audit", "map"].includes(S.tab)) render();
  } catch (e) {
    S.connected = false;
    $("#connection").textContent = "Connection lost · retrying";
    if (!S.data) notify(e.message, true);
  } finally {
    S.refreshing=false;
    if(S.refreshQueued){S.refreshQueued=false;await refresh(true);}
  }
}
function empty(title, description) {
  return `<div class="empty"><h3>${esc(title)}</h3><p>${esc(description)}</p></div>`;
}
function table(headers, rows) {
  return `<div class="table-wrap"><table><thead><tr>${headers.map((h) => `<th>${esc(h)}</th>`).join("")}</tr></thead><tbody>${rows.join("")}</tbody></table></div>`;
}
function teamName(id) {
  return S.data.teams.find((t) => t.id === id)?.name || id;
}
function teams() {
  const rosterOpen=Object.values(S.data.rounds).every(r=>r.status==='DRAFT');
  return `<div class="actions-row"><button class="primary" data-action="add-team" ${rosterOpen?'':'disabled'}>+ Add team</button><button class="secondary" data-action="bulk-team-names" ${rosterOpen?'':'disabled'}>Add team names</button><button class="secondary" data-action="import-roster" ${rosterOpen?'':'disabled'}>Import team roster</button><button class="secondary" data-action="import-evidence">Import offline evidence</button></div><section class="card"><div class="card-head"><div><h2>Teams</h2><p>${rosterOpen?'Roster open':'Roster locked'}</p></div></div>${
    S.data.teams.length
      ? table(
          [
            "Team",
            "Device",
            "GPS last received",
            "Progress",
            "Contact",
            "Controls",
          ],
          S.data.teams.map(
            (t) =>
              `<tr><td><strong>${esc(t.name)}</strong><small>Team ID: ${esc(t.code)} · ${t.lives_remaining ?? 3}/3 lives</small><small>${esc(t.elimination_reason || "")}</small></td><td>${t.enrolled ? '<span class="badge good">Enrolled</span>' : '<span class="badge warn">Not enrolled</span>'}<small>${t.kiosk ? "Kiosk active" : "Kiosk inactive"} · ${t.assignment_received ? "Assignment ready" : "Assignment pending"}</small></td><td class="device-location">${t.location ? `${Number(t.location.lat).toFixed(5)}, ${Number(t.location.lon).toFixed(5)}<br>±${Math.round(t.location.accuracy)}m · ${esc(age(t.location.received_at))}${t.location.mock ? " · MOCK" : ""}` : "No location received"}</td>${enabledRounds().map(n=>`<td>${statusBadge(t.rounds[n]?.status)}<small>${t.rounds[n]?.completed||0}/${roundSize(n)}</small></td>`).join('')}<td>${esc(age(t.last_seen))}<small>${t.ready?'Phone ready':'Check phone'}</small><small>${t.pending_actions} reported pending</small></td><td><button class="secondary" data-edit-team="${esc(t.id)}">Edit</button><button class="secondary" data-team-credentials="${esc(t.id)}">Show login</button><button class="secondary" data-check-login="${esc(t.id)}">Check login</button><button class="secondary" data-remove-team="${esc(t.id)}" ${rosterOpen?'':'disabled'}>Remove</button><button class="secondary" data-control="${esc(t.id)}">Manage</button></td></tr>`,
          ),
        )
      : empty(
          "Add your first team",
          "Add team names to generate their logins.",
        )
  }</section>`;
}
function audit() {
  const d = S.data;
  return `<div class="actions-row"><button class="secondary" data-action="export-evidence">Download evidence & audit</button></div><section class="card"><div class="card-head"><div><h2>Actions needing attention</h2><p>Review the cause before making an audited team decision. Duplicate accepted actions never score twice.</p></div></div>${
    d.rejected.length
      ? table(
          ["Team", "Action", "Result", "Received"],
          d.rejected.map(
            (a) =>
              `<tr><td>${esc(teamName(a.team_id))}</td><td>#${a.seq}</td><td>${statusBadge(a.result)}</td><td>${esc(age(a.received))}</td></tr>`,
          ),
        )
      : empty(
          "No rejected actions",
          "GPS failures, wrong checkpoints and late offline evidence will appear here.",
        )
  }</section><section class="card"><h2>Command delivery</h2>${
    d.commands.length
      ? table(
          ["Team", "Command", "Delivery"],
          d.commands.map((c) => {
            const data = JSON.parse(c.data);
            return `<tr><td>${esc(teamName(c.team_id))}</td><td>${esc(data.kind)}</td><td>${c.acknowledged ? '<span class="badge good">Acknowledged</span>' : data.expires_at < serverNow() ? '<span class="badge bad">Expired, not delivered</span>' : '<span class="badge warn">Pending delivery</span>'}</td></tr>`;
          }),
        )
      : empty(
          "No pending commands",
          "Remote exit and notices are delivered on the next successful phone synchronization.",
        )
  }</section><section class="card"><h2>Organizer audit trail</h2>${table(
    ["When", "Actor", "Change", "Details"],
    d.audit.map(
      (a) =>
        `<tr><td>${esc(age(a.at))}</td><td>${esc(a.actor)}</td><td>${esc(a.kind)}</td><td>${esc(a.data)}</td></tr>`,
    ),
  )}</section>`;
}
function render() {
  if (!S.data) return;
  if(S.map?.instance){S.map.instance.destroy();S.map=null;}
  $("#page-title").textContent = titles[S.tab];
  document
    .querySelectorAll("[data-tab]")
    .forEach((b) => b.classList.toggle("active", b.dataset.tab === S.tab));
  $("#view").innerHTML = { live, teams, content, map: mapSetup, setup, audit, staff }[
    S.tab
  ]();
  bindForms();
  if(S.tab==='staff')loadStaff();
  initLiveMap();
  updateClocks();
}
function modal(title, html, save, label = "Save") {
  S.modalRevision=S.data?.event.revision;
  S.modalEditRevision=S.data?.event.edit_revision??S.data?.event.revision;
  S.modalSave = save;
  $("#modal-title").textContent = title;
  $("#modal-body").innerHTML = html;
  $("#modal-error").textContent = "";
  $("#modal-submit").textContent = label;
  $("#modal-submit").hidden = !save;
  $("#cancel-modal").textContent = save ? "Cancel" : "Close";
  $("#modal").showModal();
}
function teamCredentials(account) {
  modal('Team login',`${account.name?`<label>Team name<input readonly value="${esc(account.name)}"></label>`:''}<label>Team ID<input readonly value="${esc(account.code)}"></label>${account.enrollment_pin!==null?`<label>Login code<input readonly autocomplete="off" value="${esc(account.enrollment_pin)}"></label>`:'<p>Set a new login code in Edit, or use Check login once with the current code to save it for viewing.</p>'}`,null);
}
function teamCredentialsCsv(accounts) {
  const cell=v=>{let text=String(v??'');if(/^[=+@\-\t\r]/.test(text))text="'"+text;return '"'+text.replaceAll('"','""')+'"';};
  return 'Team name,Team ID,Login code\r\n'+accounts.map(a=>[a.name,a.code,a.enrollment_pin].map(cell).join(',')).join('\r\n');
}
function download(name, data, type = "application/json") {
  const url = URL.createObjectURL(
    data instanceof Blob
      ? data
      : new Blob([JSON.stringify(data, null, 2)], { type }),
  );
  const a = document.createElement("a");
  a.href = url;
  a.download = name;
  a.click();
  setTimeout(() => URL.revokeObjectURL(url), 5000);
}
async function saveContent(patch,revision=($('#modal').open?S.modalRevision:null)??S.data.event.revision,editRevision=($('#modal').open?S.modalEditRevision:null)??S.data.event.edit_revision??revision) {
  await api("/admin/content", {
    method: "PUT",
    body: JSON.stringify({ revision, edit_revision:editRevision, ...patch }),
  });
  await refresh(true);
  notify("Saved");
}
function manageTeam(id) {
  const t = S.data.teams.find((t) => t.id === id);
  modal(
    `Manage ${t.name}`,
    `<label>Action<select name="operation"><option value="notice">Send a notice</option><option value="exit">Authorize kiosk exit</option><option value="resume_kiosk">Resume managed kiosk</option><option value="reset_device">Replace phone</option><option value="eliminate">Eliminate from the hunt</option><option value="restore_lives">Restore hearts</option><option value="disqualify">Disqualify from the hunt</option><option value="reinstate">Reinstate team</option><option value="review">Approve retained late evidence</option><option value="manual_finish">Confirm final finish</option></select></label><label data-team-field="lives">Hearts to restore<input name="lives" type="number" min="1" max="3" value="3"></label><label data-team-field="message">Message<textarea name="message"></textarea></label><label>Audit reason<textarea name="reason" required minlength="8"></textarea></label>`,
    async (form) => {
      const body = Object.fromEntries(new FormData(form));
      body.round = 1;
      body.lives = Number(body.lives);
      const res = await api(
        `/admin/teams/${id}/${body.operation === "review" ? "review" : "control"}`,
        { method: "POST", body: JSON.stringify(body) },
      );
      await refresh(true);
      if (res.new_enrollment_pin)
        setTimeout(
          () =>
            modal(
              "New team enrollment PIN",
              `<p>The previous enrollment PIN and session were revoked. Give this new PIN only to the supervised replacement phone.</p><div class="secret">${esc(res.new_enrollment_pin)}</div>`,
              null,
            ),
          0,
        );
      else
        notify(
          res.delivery === "PENDING"
            ? "Command queued. Check Evidence & audit for delivery acknowledgement."
            : "Organizer decision saved with an audit record.",
        );
    },
    "Apply decision",
  );
  const form=$('#modal-form');
  const showFields=()=>{const op=form.elements.operation.value;
    for(const [field,show] of [['lives',op==='restore_lives'],['message',op==='notice']])form.querySelector('[data-team-field="'+field+'"]').hidden=!show;
  };
  form.elements.operation.onchange=showFields;showFields();
}
function fileModal(title, save) {
  modal(
    title,
    '<label>JSON file<input name="file" type="file" accept="application/json,.json" required></label><p class="hint">Files are sent only to this event server.</p>',
    async (form) => {
      const file = form.elements.file.files[0];
      if (file.size > 1_000_000)
        throw Error("File exceeds the 1 MB import limit");
      await save(JSON.parse(await file.text()));
    },
  );
}
function updateClocks() {
  if (!S.data) return;
  document.querySelectorAll("[data-countdown]").forEach((el) => {
    const r = S.data.rounds[el.dataset.countdown];
    el.textContent=huntRoundClock(r,serverNow());
  });
}
document.addEventListener("click", async (e) => {
  const b = e.target.closest("button");
  if (!b) return;
  try {
    if (b.dataset.tab || b.dataset.nav) {
      S.tab = b.dataset.tab || b.dataset.nav;
      render();
      return;
    }
    if (b.dataset.operation) {
      const n = b.dataset.round,
        op = b.dataset.operation,
        countdown = S.data.event.settings.start_countdown_seconds ?? 30;
      modal(
        `${op[0].toUpperCase() + op.slice(1)} hunt`,
        `<p>${op === "start" ? (countdown === 0 ? "The hunt will start immediately." : `A shared ${countdown}-second countdown will begin. Keep phones connected until the start is confirmed.`) : op === "end" ? "This ends the hunt. Unfinished teams become eliminated/time over." : op === "prepare" ? "Generate fresh assignments for qualified teams. Synchronize phones before starting." : "The server will update the hunt state. Disconnected phones receive this change after reconnecting."}</p>`,
        async () => {
          await api(`/admin/rounds/${n}/${op}`, { method: "POST", body: "{}" });
          await refresh(true);
          notify(`Hunt: ${op} accepted.`);
        },
        op === "start" ? (countdown === 0 ? "Start now" : `Start ${countdown}-second countdown`) : "Confirm",
      );
      return;
    }
    if (b.dataset.editRiddle) {
      editRiddle(b.dataset.editRiddle);
      return;
    }
    if (b.dataset.control) {
      manageTeam(b.dataset.control);
      return;
    }
    switch (b.dataset.action) {
      case "bulk-team-names":
        modal('Add team names','<label>One team per line<textarea name="names" rows="9" required maxlength="12000" placeholder="Blitz&#10;Moon runners&#10;Trail seekers"></textarea></label>',async form=>{
          const result=await api('/admin/teams/import',{method:'POST',body:JSON.stringify({names:form.elements.names.value})});
          download('private-team-logins.csv',new Blob(["\ufeff",teamCredentialsCsv(result.accounts)],{type:'text/csv;charset=utf-8'}));
          await refresh(true);setTimeout(()=>modal('Team logins',table(['Team','Team ID','Login code'],result.accounts.map(a=>`<tr><td>${esc(a.name)}</td><td>${esc(a.code)}</td><td>${esc(a.enrollment_pin)}</td></tr>`)),null),0);
        },'Create teams');
        break;
      case "import-roster":
        modal(
          "Import the team roster",
          '<label>CSV or JSON file<input name="file" type="file" accept=".csv,.json" required></label><p class="hint">CSV header: name. Optional columns: code,pin. JSON supports the same fields. Keep the downloaded logins private.</p>',
          async (form) => {
            const file = form.elements.file.files[0];
            if (file.size > 100000) throw Error("Roster exceeds 100 KB");
            const text = await file.text();
            const body = file.name.toLowerCase().endsWith(".csv")
              ? { csv: text }
              : { rows: JSON.parse(text) };
            const result = await api("/admin/teams/import", {
              method: "POST",
              body: JSON.stringify(body),
            });
            download("private-team-credentials.json", result.accounts);
            await refresh(true);
            notify(
              "Roster imported. Team login codes are available under Show login.",
            );
          },
        );
        break;
      case "add-team":
        modal(
          "Add a team",
          '<label>Team name<input name="name" required maxlength="100"></label><p>Team ID and login code are generated automatically.</p>',
          async (form) => {
            const res = await api("/admin/teams", {
              method: "POST",
              body: JSON.stringify(Object.fromEntries(new FormData(form))),
            });
            await refresh(true);
            setTimeout(() => teamCredentials(res), 0);
          },
        );
        break;
      case "add-riddle":
        editRiddle();
        break;
      case "import-content":
        fileModal("Import event content", async (data) => {
          const allowed = {};
          for (const k of ["riddles", "checkpoints", "anchors", "edges"])
            if (data[k]) allowed[k] = data[k];
          await saveContent(allowed);
        });
        break;
      case "export-content":
        download("event-content.json", S.data.event);
        break;
      case "import-evidence":
        fileModal("Import signed phone evidence", async (data) => {
          const res = await api("/admin/import-evidence", {
            method: "POST",
            body: JSON.stringify(data),
          });
          await refresh(true);
          notify(
            `${res.ack.length} evidence records acknowledged. Late records require explicit review.`,
          );
        });
        break;
      case "export-evidence":
        download("hunt-evidence.json", await api("/admin/export"));
        break;
      case "publish":
        modal(
          "Shuffle and publish assignments",
          "<p>Balance the starting stations and assign three riddles to each team. Teams receive fresh riddles in later rounds.</p>",
          async () => {
            await api("/admin/publish", { method: "POST", body: "{}" });
            await refresh(true);
            notify(
              "Event published. Enroll the team phones and verify readiness before starting.",
            );
          },
          "Publish event",
        );
        break;
    }
  } catch (err) {
    notify(err.message, true);
  }
});
$("#login-form").onsubmit = async (e) => {
  e.preventDefault();
  $("#login-error").textContent = "Signing in…";
  const submit=e.target.querySelector('[type=submit]');submit.disabled=true;
  try {
    const res = await api("/admin/login", {
      method: "POST",
      body: JSON.stringify(Object.fromEntries(new FormData(e.target))),
    });
    S.token = res.token;
    S.access=res.access;
    sessionStorage.setItem("hunt-admin", S.token);
    e.target.elements.password.value = "";
    showAuth();
    await refresh(true);
    if(res.content_import?.status==='blocked')notify(res.content_import.message,true);
  } catch (err) {
    $("#login-error").textContent = err.message;
  } finally {
    submit.disabled=false;
  }
};
$("#modal-form").onsubmit = async (e) => {
  e.preventDefault();
  if (!S.modalSave) return;
  $("#modal-submit").disabled = true;
  try {
    await S.modalSave(e.target);
    $("#modal").close();
  } catch (err) {
    $("#modal-error").textContent = err.message;
  } finally {
    $("#modal-submit").disabled = false;
  }
};
$("#close-modal").onclick = $("#cancel-modal").onclick = () =>
  $("#modal").close();
$("#refresh").onclick = () => refresh(true);
$("#logout").onclick = async () => {
  await api("/logout", { method: "POST", body: "{}" }).catch(() => {});
  S.token = "";
  S.data = null;
  sessionStorage.removeItem("hunt-admin");
  showAuth();
};
document.addEventListener('DOMContentLoaded',()=>{
 setInterval(() => refresh(), 3000);
 setInterval(updateClocks, 1000);
 showAuth();
 if (S.token) refresh(true);
 else fetch(new URL('../preflight.php',location.href),{cache:'no-store'}).then(r=>r.json()).then(s=>{
  if(!S.token&&!$('#login-error').textContent&&s.message)$('#login-error').textContent=s.message;
 }).catch(()=>{});
});

Object.assign(titles,{content:'Riddles',teams:'Teams',map:'Team map',setup:'Event settings'});
const roundSize=n=>S.data.event.settings.target_riddles||S.data.event.settings.round_sizes?.[n]||({1:3,2:7,3:2}[n]);
function board(rows){return table(['Rank','Team','Completed','Solved riddles','Hearts','Status','Finished (IST)','Time taken','Contact'],rows.map(r=>`<tr><td class="rank">${r.rank}</td><td><strong>${esc(r.team)}</strong></td><td>${r.completed}/${r.total}</td><td class="solved-list">${(r.solved||[]).map(v=>`<span>${esc(v.title)}</span>`).join('')||'—'}</td><td>${r.lives_remaining}/3</td><td>${statusBadge(r.outcome||r.status)}</td><td>${huntFinishTime(r.finished_at)}</td><td>${huntElapsedTime(r.finish_elapsed_ms)}</td><td>${esc(age(r.last_seen))}</td></tr>`));}
function live(){
 const d=S.data,e=d.event,continuous=e.settings.hunt_mode==='continuous-v1',r=d.rounds[1];
 if(!continuous){const running=Object.entries(d.rounds).find(([,v])=>['RUNNING','PAUSED'].includes(v.status));return `${eventControls()}<section class="card"><h2>Previous test</h2><p>Stop and reset the previous test to enable continuous play. Accounts and printed QR codes can be kept.</p>${running&&can('rounds')?`<button class="primary" data-round="${running[0]}" data-operation="end">Stop previous test</button>`:''}</section>`;}
 const buttons=r.status==='DRAFT'?'<button class="primary" data-round="1" data-operation="start">Start hunt</button>':r.status==='RUNNING'?'<button class="primary" data-round="1" data-operation="pause">Pause</button><button class="secondary" data-round="1" data-operation="end">Stop hunt</button>':r.status==='PAUSED'?'<button class="primary" data-round="1" data-operation="resume">Resume</button><button class="secondary" data-round="1" data-operation="end">Stop hunt</button>':'';
 return `${eventControls()}<section class="card"><div class="card-head"><h2>The hunt</h2>${statusBadge(r.status)}</div><p>${roundSize(1)} riddles per team · stops after the third winner</p><div class="round-clock" data-countdown="1"></div>${can('rounds')?`<div class="round-buttons">${buttons}${roundControlTools(1,r)}</div>${r.status==='DRAFT'?startReadiness(1):''}`:''}</section><section class="card"><h2>Leaderboard</h2>${board(d.leaderboard.rows)}</section>`;
}
function content(){
 const e=S.data.event;
 return `<div class="actions-row"><button class="primary" data-action="add-riddle" ${e.published?'disabled':''}>+ Add riddle</button><button class="secondary" data-print="organizer">Private organizer sheets / PDF</button><button class="secondary" data-print="stations">Station QR sheets / PDF</button></div><section class="card"><h2>${e.riddles.length} riddles</h2>${table(['Riddle','Placement (private)','Review','Actions'],[...e.riddles].sort((a,b)=>a.title.localeCompare(b.title)).map(r=>`<tr><td><strong>${esc(r.title)}</strong></td><td>${esc(r.placement||'')}</td><td>${statusBadge(r.reviewed?'REVIEWED':'DRAFT')}</td><td><button class="secondary" data-edit-riddle="${r.id}">Edit</button><button class="secondary" data-delete-riddle="${r.id}" ${e.published?'disabled':''}>Remove</button></td></tr>`))}</section>`;
}
function setup(){return `${eventControls()}<section class="card"><h2>Hunt controls</h2><div class="actions-row"><button class="primary" data-nav="live">Open controls</button>${roundControlTools(1,S.data.rounds[1])}<button class="secondary" data-print="stations">Print checkpoint QR sheets</button><button class="secondary" data-print="organizer">Organizer placement sheets</button></div>${S.data.issues.length?`<ul class="issues">${S.data.issues.map(i=>`<li>${esc(i)}</li>`).join('')}</ul>`:''}</section>`;}
function mapSetup(){return '';}
function bindForms(){}
function editRiddle(id){
 const r=S.data.event.riddles.find(r=>r.id===id)||{id:crypto.randomUUID(),title:'',question:'',round:0,difficulty:'neutral',hint:'',hint_enabled:false,placement:'',reviewed:false};
 modal(id?'Edit riddle':'Add riddle',`<label>Title (shown to teams)<input name="title" value="${esc(r.title)}" required maxlength="150"></label><label>Riddle<textarea name="question" required maxlength="5000">${esc(r.question)}</textarea></label><label>Answer location (organizers only)<input name="placement" value="${esc(r.placement)}" maxlength="1000" required></label><label class="check"><input name="reviewed" type="checkbox" ${r.reviewed?'checked':''}>Reviewed and ready to print</label>`,async form=>{
  const f=new FormData(form);await saveContent({riddles:[...S.data.event.riddles.filter(x=>x.id!==r.id),{id:r.id,title:f.get('title'),question:f.get('question'),placement:f.get('placement'),round:r.round,difficulty:r.difficulty,reviewed:f.has('reviewed'),points:100}]});
 });
}

document.addEventListener('click',async ev=>{const b=ev.target.closest('button');if(!b)return;try{
 if(b.dataset.deleteRiddle)modal('Remove riddle','<p>Remove this riddle?</p>',()=>saveContent({riddles:S.data.event.riddles.filter(r=>r.id!==b.dataset.deleteRiddle)}),'Remove');
 if(b.dataset.removeTeam){const t=S.data.teams.find(t=>t.id===b.dataset.removeTeam);modal('Remove '+t.name,`<p>Remove team <strong>${esc(t.code)}</strong> before play starts? Its phone access will be revoked. Reconnect the remaining phones to receive updated assignments.</p>`,async()=>{await api('/admin/teams/'+t.id,{method:'DELETE'});await refresh(true);notify('Team removed. Reconnect enrolled phones before starting.');},'Remove team');}
 if(b.dataset.editTeam){const t=S.data.teams.find(t=>t.id===b.dataset.editTeam);modal('Team account',`<label>Team name<input name="name" required maxlength="100" value="${esc(t.name)}"></label><label>Login<input value="${esc(t.code)}" readonly></label><label>New login code (leave blank to keep)<input name="password" type="password" minlength="6" autocomplete="new-password"></label>`,async form=>{const body=Object.fromEntries(new FormData(form));await api('/admin/teams/'+t.id,{method:'PUT',body:JSON.stringify(body)});form.elements.password.value='';await refresh(true);if(body.password)setTimeout(()=>teamCredentials({code:t.code,enrollment_pin:body.password}),0);else notify('Account updated');});}
 if(b.dataset.teamCredentials){try{teamCredentials(await api('/admin/teams/'+b.dataset.teamCredentials+'/credentials'));}catch(error){notify(error.message);}}
 if(b.dataset.checkLogin){const t=S.data.teams.find(t=>t.id===b.dataset.checkLogin);modal('Check team login',`<label>Team ID<input readonly value="${esc(t.code)}"></label><label>Current login code<input name="pin" type="password" required autocomplete="off"></label>`,async form=>{const result=await api('/admin/teams/'+t.id+'/login-check',{method:'POST',body:JSON.stringify({pin:form.elements.pin.value})});if(!result.valid)throw Error('This is not the saved login code. Use Edit to set a new one.');form.elements.pin.value='';notify('Login verified for Team ID '+result.code+(result.phone_enrolled?'. A phone is already enrolled.':'. No phone is enrolled.'));},'Check');}
 if(b.dataset.print)location.href='print.html?mode='+encodeURIComponent(b.dataset.print);
 }catch(e){notify(e.message,true);}});
