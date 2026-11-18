"use strict";
function initLiveMap() {
  document.querySelector("#live-map-card")?.remove();
  const card = document.createElement("section");
  card.className = "card";
  card.id = "live-map-card";
  card.innerHTML =
    '<div class="card-head"><div><h2>Teams on campus</h2><p>Latest received GPS only · green is recent, gray is stale · click a team for details</p></div><span class="badge">Private organizer view</span></div><div class="map-wrap live-map-wrap"><canvas id="live-map" aria-label="Last synchronized team GPS positions"></canvas><div class="map-help" id="live-map-note"></div></div>';
  const race = [...document.querySelectorAll("#view section.card")].find(
    (c) => c.querySelector("h2")?.textContent === "Race standings",
  );
  if (!race) return;
  race.before(card);
  const canvas = card.querySelector("canvas"),
    ctx = canvas.getContext("2d"),
    image = new Image(),
    markers = [];
  const width = canvas.clientWidth,
    height = canvas.clientHeight,
    scale = Math.min(width / 6000, height / 3375),
    ox = (width - 6000 * scale) / 2,
    oy = (height - 3375 * scale) / 2;
  canvas.width = width * devicePixelRatio;
  canvas.height = height * devicePixelRatio;
  image.onload = () => {
    ctx.setTransform(devicePixelRatio, 0, 0, devicePixelRatio, 0, 0);
    ctx.drawImage(image, ox, oy, 6000 * scale, 3375 * scale);
    const calibration = S.data.map_calibration;
    card.querySelector("#live-map-note").textContent = calibration
      ? "Locations can be delayed by poor connectivity. GPS is not conclusive proof of attendance."
      : "GPS dots appear after measured map calibration. No coordinates have been fabricated.";
    if (!calibration) return;
    const a = calibration.lat,
      b = calibration.lon,
      det = a[0] * b[1] - a[1] * b[0];
    if (Math.abs(det) < 1e-16) return;
    for (const team of S.data.teams) {
      const p = team.location;
      if (!p) continue;
      const x = ((p.lat - a[2]) * b[1] - (p.lon - b[2]) * a[1]) / det,
        y = (a[0] * (p.lon - b[2]) - b[0] * (p.lat - a[2])) / det;
      if (x < 0 || x > 6000 || y < 0 || y > 3375) continue;
      const sx = ox + x * scale,
        sy = oy + y * scale,
        recent = serverNow() - p.received_at < 45000;
      ctx.beginPath();
      ctx.arc(sx, sy, 8, 0, Math.PI * 2);
      ctx.fillStyle = p.mock ? "#ba7425" : recent ? "#44762f" : "#8c978a";
      ctx.fill();
      ctx.strokeStyle = "white";
      ctx.lineWidth = 2;
      ctx.stroke();
      ctx.font = "600 11px Segoe UI, sans-serif";
      ctx.fillStyle = "#173c2b";
      ctx.fillText(team.code, sx + 12, sy + 4);
      markers.push({ x: sx, y: sy, team });
    }
  };
  canvas.onclick = (e) => {
    const box = canvas.getBoundingClientRect(),
      x = e.clientX - box.left,
      y = e.clientY - box.top;
    const marker = markers.find((p) => Math.hypot(p.x - x, p.y - y) < 22);
    if (!marker) return;
    const t = marker.team,
      p = t.location;
    modal(
      t.name,
      `<p>Last received GPS: ${esc(p.lat.toFixed(6))}, ${esc(p.lon.toFixed(6))}</p><p>Accuracy ±${Math.round(p.accuracy)}m · ${esc(age(p.received_at))}</p><p>${t.kiosk ? "Managed kiosk active" : "Kiosk not reported active"} · ${t.pending_actions} reported pending actions</p><p class="hint">This is the last synchronized location, not a prediction of where the team is now.</p>`,
      null,
    );
  };
  image.src = "campus_map.webp";
}
