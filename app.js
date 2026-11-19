"use strict";

const API = {
  reports: "api/reports.php",
  auth: "api/auth.php",
  admin: "api/admin.php",
  analytics: "api/analytics.php",
  matches: "api/matches.php",
  claims: "api/claims.php",
  notifications: "api/notifications.php",
};
const PAGE_SIZE = 12;
// Keep in sync with STATUS_LABELS in api/bootstrap.php (used in emails).
const STATUS_LABELS = { pending: "Awaiting review", active: "On the board", resolved: "Found" };
const CLAIM_LABELS = { pending: "Pending", approved: "Approved", rejected: "Rejected" };
const MATCH_LABELS = { open: "Open", confirmed: "Confirmed", dismissed: "Dismissed" };
const ASSIGNMENT_LABELS = { unassigned: "Unassigned", assigned: "Assigned", in_progress: "In progress", completed: "Completed" };
const MAX_UPLOAD_SIDE = 1600;
const SAVED_REFS_KEY = "lf-saved-references";
const PIN_ICON = '<svg viewBox="0 0 18 18" fill="none" aria-hidden="true"><path d="M14.2 7.6c0 3.5-5.2 8-5.2 8s-5.2-4.5-5.2-8a5.2 5.2 0 1 1 10.4 0Z" stroke="currentColor" stroke-width="1.3"/><circle cx="9" cy="7.5" r="1.7" stroke="currentColor" stroke-width="1.3"/></svg>';

const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

const grid = $("#item-grid");
const reportDialog = $("#report-dialog");
const reportForm = $("#report-form");
const confirmDialogEl = $("#confirm-dialog");
const claimDialog = $("#claim-dialog");
const claimForm = $("#claim-form");
const staffDialog = $("#staff-dialog");
const lightbox = $("#lightbox");
const toast = $("#toast");

const auth = { authenticated: false, username: "", name: "", role: "", id: 0, csrf: "" };
const board = { status: "loading", reports: [], stats: null, error: "", shown: PAGE_SIZE };
const admin = { status: "idle", reports: [], stats: {}, error: "", tab: "lost" };
const analyticsState = { status: "idle", data: null, error: "", period: "monthly", request: 0 };
const matchesState = { status: "idle", matches: [], suggestions: [], staff: [], error: "" };
const claimsState = { status: "idle", claims: [], counts: {}, error: "", filter: "pending" };
const emailsState = { status: "idle", notifications: [], counts: {}, templates: {}, mail: null, error: "" };
let authReady = Promise.resolve();
let shownView = null;
let toastTimer;
let authError = "";
let lastReference = null;

/* ---------- API ---------- */

class ApiError extends Error {
  constructor(message, status = 0, data = null) {
    super(message);
    this.status = status;
    this.data = data;
  }
}

async function api(endpoint, { method = "GET", body, query } = {}) {
  const url = new URL(endpoint, window.location.href);
  for (const [key, value] of Object.entries(query || {})) url.searchParams.set(key, value);
  const headers = { Accept: "application/json" };
  const isForm = body instanceof FormData;
  if (body !== undefined && !isForm) headers["Content-Type"] = "application/json";
  if (method !== "GET" && auth.csrf) headers["X-CSRF-Token"] = auth.csrf;

  let response;
  try {
    response = await fetch(url, {
      method,
      headers,
      body: body === undefined ? undefined : isForm ? body : JSON.stringify(body),
      credentials: "same-origin",
      cache: "no-store",
    });
  } catch {
    throw new ApiError("Could not reach the server. Check your connection and that Apache is running.");
  }

  const text = await response.text();
  let data = null;
  try { data = text ? JSON.parse(text) : null; } catch { /* not JSON */ }
  if (!response.ok) {
    throw new ApiError(data?.error || `The server returned an error (${response.status}).`, response.status, data);
  }
  if (data === null) {
    throw new ApiError("The server sent an unexpected response. Check that PHP is running and api/config.php is correct.", response.status);
  }
  return data;
}

/* ---------- Helpers ---------- */

function el(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined && text !== null) node.textContent = text;
  return node;
}

function button(label, className, onClick) {
  const b = el("button", className, label);
  b.type = "button";
  if (onClick) b.addEventListener("click", onClick);
  return b;
}

function formatDate(value) {
  if (!value) return "";
  const date = new Date(`${value}T12:00:00`);
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat(undefined, { month: "short", day: "numeric", year: "numeric" }).format(date);
}

function formatDateTime(iso) {
  if (!iso) return "";
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return iso;
  return new Intl.DateTimeFormat(undefined, { month: "short", day: "numeric", year: "numeric", hour: "numeric", minute: "2-digit" }).format(date);
}

function timeAgo(iso, prefix = "Reported") {
  const then = new Date(iso).getTime();
  if (Number.isNaN(then)) return "";
  const seconds = Math.max(0, (Date.now() - then) / 1000);
  const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: "auto" });
  if (seconds < 60) return `${prefix} just now`;
  const steps = [["minute", 60], ["hour", 3600], ["day", 86400], ["week", 604800], ["month", 2592000], ["year", 31536000]];
  let unit = "minute", size = 60;
  for (const [name, length] of steps) if (seconds >= length) { unit = name; size = length; }
  return `${prefix} ${rtf.format(-Math.floor(seconds / size), unit)}`;
}

function localDateString(date = new Date()) {
  return [date.getFullYear(), String(date.getMonth() + 1).padStart(2, "0"), String(date.getDate()).padStart(2, "0")].join("-");
}

function showToast(message, { long = false } = {}) {
  clearTimeout(toastTimer);
  toast.textContent = message;
  toast.classList.add("visible");
  toastTimer = setTimeout(() => toast.classList.remove("visible"), long ? 9000 : 4500);
}

/** Explains what happened to an automatic email in one short sentence. */
function emailSummary(email) {
  if (!email) return "";
  if (email.status === "sent") return ` ${email.message}`;
  if (email.status === "failed") return ` ⚠ ${email.message}`;
  if (email.status === "skipped") return " No email on file, so no email was sent.";
  return "";
}

function confirmAction({ title, message, okLabel, danger = true }) {
  $("#confirm-title").textContent = title;
  $("#confirm-message").textContent = message;
  const ok = $("#confirm-ok");
  ok.textContent = okLabel;
  ok.className = `button ${danger ? "button-danger" : "button-dark"}`;
  confirmDialogEl.returnValue = "";
  confirmDialogEl.showModal();
  return new Promise((resolve) => {
    confirmDialogEl.addEventListener("close", () => resolve(confirmDialogEl.returnValue === "confirm"), { once: true });
  });
}

function statusBadge(status, labels = STATUS_LABELS, prefix = "") {
  return el("span", `status-badge ${prefix}${status}`, labels[status] || status);
}

/* ---------- Images: upload resizing, thumbnails and the viewer ---------- */

/** Shrinks big phone photos before upload and drops hidden metadata (canvas output has none). */
async function resizeImage(file) {
  if (!file.type.startsWith("image/")) throw new Error("Choose a JPG, PNG or WebP photo.");
  let source;
  try {
    source = await createImageBitmap(file, { imageOrientation: "from-image" });
  } catch {
    source = await new Promise((resolve, reject) => {
      const img = new Image();
      img.onload = () => resolve(img);
      img.onerror = () => reject(new Error("This photo could not be read. Choose a JPG, PNG or WebP photo."));
      img.src = URL.createObjectURL(file);
    });
  }
  const width = source.width, height = source.height;
  const scale = Math.min(1, MAX_UPLOAD_SIDE / Math.max(width, height));
  const canvas = document.createElement("canvas");
  canvas.width = Math.max(1, Math.round(width * scale));
  canvas.height = Math.max(1, Math.round(height * scale));
  const ctx = canvas.getContext("2d");
  ctx.fillStyle = "#ffffff";
  ctx.fillRect(0, 0, canvas.width, canvas.height);
  ctx.drawImage(source, 0, 0, canvas.width, canvas.height);
  const blob = await new Promise((resolve) => canvas.toBlob(resolve, "image/jpeg", 0.86));
  if (!blob) throw new Error("This photo could not be processed. Try another one.");
  return blob;
}

function setupPhotoPicker({ input, preview, remove, label, onError }) {
  const state = { blob: null, url: null };
  const reset = () => {
    if (state.url) URL.revokeObjectURL(state.url);
    state.blob = null;
    state.url = null;
    input.value = "";
    preview.hidden = true;
    preview.removeAttribute("src");
    remove.hidden = true;
    if (label) label.textContent = "Add a photo";
  };
  input.addEventListener("change", async () => {
    const file = input.files?.[0];
    if (!file) return;
    onError("");
    try {
      const blob = await resizeImage(file);
      if (state.url) URL.revokeObjectURL(state.url);
      state.blob = blob;
      state.url = URL.createObjectURL(blob);
      preview.src = state.url;
      preview.hidden = false;
      remove.hidden = false;
      if (label) label.textContent = "Change photo";
    } catch (error) {
      reset();
      onError(error.message);
    }
  });
  remove.addEventListener("click", reset);
  return { get blob() { return state.blob; }, reset };
}

/** A clickable thumbnail that opens the full image in the viewer. */
function thumb(url, alt, caption, className = "thumb") {
  const b = el("button", `${className}-button`);
  b.type = "button";
  b.setAttribute("aria-label", `View larger photo: ${alt}`);
  const img = el("img", className);
  img.src = url;
  img.alt = alt;
  img.loading = "lazy";
  img.decoding = "async";
  img.addEventListener("error", () => { b.classList.add("broken"); img.alt = "Photo unavailable"; });
  b.append(img);
  b.addEventListener("click", (event) => { event.stopPropagation(); openLightbox(url, caption || alt); });
  return b;
}

const viewer = { scale: 1, x: 0, y: 0, pointers: new Map(), startDist: 0, startScale: 1, last: null };
const lbImg = $("#lightbox-img");
const lbStage = $("#lightbox-stage");

function applyViewer() {
  lbImg.style.transform = `translate(${viewer.x}px, ${viewer.y}px) scale(${viewer.scale})`;
  $("#lb-zoom").textContent = `${Math.round(viewer.scale * 100)}%`;
  lbStage.classList.toggle("zoomed", viewer.scale > 1);
}

function setZoom(scale) {
  viewer.scale = Math.min(6, Math.max(1, scale));
  if (viewer.scale === 1) { viewer.x = 0; viewer.y = 0; }
  applyViewer();
}

function openLightbox(url, caption) {
  viewer.scale = 1; viewer.x = 0; viewer.y = 0;
  lbImg.src = url;
  lbImg.alt = caption || "Item photo";
  $("#lightbox-caption").textContent = caption || "";
  $("#lb-open").href = url;
  applyViewer();
  if (!lightbox.open) lightbox.showModal();
  $("#lb-close").focus();
}

$("#lb-zoom-in").addEventListener("click", () => setZoom(viewer.scale * 1.5));
$("#lb-zoom-out").addEventListener("click", () => setZoom(viewer.scale / 1.5));
$("#lb-reset").addEventListener("click", () => setZoom(1));
$("#lb-close").addEventListener("click", () => lightbox.close());
lightbox.addEventListener("close", () => { lbImg.removeAttribute("src"); });
lightbox.addEventListener("click", (event) => { if (event.target === lightbox || event.target === lbStage) lightbox.close(); });
lbStage.addEventListener("wheel", (event) => { event.preventDefault(); setZoom(viewer.scale * (event.deltaY < 0 ? 1.15 : 1 / 1.15)); }, { passive: false });
lbImg.addEventListener("dblclick", () => setZoom(viewer.scale > 1 ? 1 : 2.5));
lbImg.addEventListener("pointerdown", (event) => {
  event.preventDefault();
  lbImg.setPointerCapture(event.pointerId);
  viewer.pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
  if (viewer.pointers.size === 2) {
    const [a, b] = [...viewer.pointers.values()];
    viewer.startDist = Math.hypot(a.x - b.x, a.y - b.y);
    viewer.startScale = viewer.scale;
  }
  // Double-tap on touch screens.
  const now = Date.now();
  if (event.pointerType === "touch" && viewer.last && now - viewer.last < 300 && viewer.pointers.size === 1) setZoom(viewer.scale > 1 ? 1 : 2.5);
  viewer.last = now;
});
lbImg.addEventListener("pointermove", (event) => {
  const prev = viewer.pointers.get(event.pointerId);
  if (!prev) return;
  const point = { x: event.clientX, y: event.clientY };
  viewer.pointers.set(event.pointerId, point);
  if (viewer.pointers.size === 2) {
    const [a, b] = [...viewer.pointers.values()];
    setZoom(viewer.startScale * (Math.hypot(a.x - b.x, a.y - b.y) / (viewer.startDist || 1)));
  } else if (viewer.scale > 1) {
    viewer.x += point.x - prev.x;
    viewer.y += point.y - prev.y;
    applyViewer();
  }
});
const endPointer = (event) => viewer.pointers.delete(event.pointerId);
lbImg.addEventListener("pointerup", endPointer);
lbImg.addEventListener("pointercancel", endPointer);

/* ---------- Public board ---------- */

function filtersActive() {
  return Boolean($("#search-input").value.trim()) || $("#type-filter").value !== "all" || $("#category-filter").value !== "all";
}

function renderBoard() {
  grid.replaceChildren();
  grid.setAttribute("aria-busy", String(board.status === "loading"));
  const count = $("#results-count");
  const clear = $("#clear-filters");
  const more = $("#load-more");
  more.hidden = true;
  clear.hidden = true;

  if (board.stats) {
    $("#stat-open").textContent = board.stats.active;
    $("#stat-returned").textContent = board.stats.resolved;
  }

  if (board.status === "loading") {
    count.textContent = "Loading reports…";
    for (let i = 0; i < 3; i += 1) {
      const card = el("div", "item-card skeleton");
      card.append(el("span", "sk sk-short"), el("span", "sk sk-title"), el("span", "sk sk-line"), el("span", "sk sk-line sk-half"));
      grid.append(card);
    }
    return;
  }

  if (board.status === "error") {
    count.textContent = "Reports unavailable";
    const box = el("div", "empty-state");
    box.append(el("span", "empty-icon", "!"), el("strong", "", "We couldn’t load the community board"), el("p", "", board.error));
    box.append(button("Try again", "button button-dark", () => loadBoard()));
    grid.append(box);
    return;
  }

  const query = $("#search-input").value.trim().toLocaleLowerCase();
  const type = $("#type-filter").value;
  const category = $("#category-filter").value;
  const matches = board.reports
    .filter((report) => type === "all" || report.type === type)
    .filter((report) => category === "all" || report.category === category)
    .filter((report) => !query || [report.title, report.location, report.category, report.description]
      .some((value) => (value || "").toLocaleLowerCase().includes(query)));
  const visible = matches.slice(0, board.shown);
  const filtered = filtersActive();

  count.textContent = `${matches.length} ${matches.length === 1 ? "report" : "reports"}${filtered ? " found" : ""}`;
  clear.hidden = !filtered;
  more.hidden = matches.length <= visible.length;
  more.textContent = `Show more (${matches.length - visible.length} left)`;

  if (visible.length === 0) {
    const empty = el("div", "empty-state");
    empty.append(el("span", "empty-icon", filtered ? "⌕" : "✳"));
    empty.append(el("strong", "", filtered ? "No matching reports" : "A fresh start for the community board"));
    empty.append(el("p", "", filtered
      ? "Try another search or filter, or check back soon."
      : "No active reports yet. Be the first to help someone find their way back to an item."));
    empty.append(button(filtered ? "Clear filters" : "Report an item ↗", "button button-dark", filtered ? clearFilters : openReportDialog));
    grid.append(empty);
    return;
  }

  for (const report of visible) {
    const card = el("article", "item-card");
    if (report.photoUrl) card.append(thumb(report.photoUrl, report.title, `${report.title} · ${report.location}`, "card-photo"));
    const topline = el("div", "card-topline");
    topline.append(el("span", `type-badge ${report.type}`, report.type === "found" ? "✳ Found item" : "⌕ Lost item"));
    topline.append(el("span", "card-date", formatDate(report.date)));
    card.append(topline, el("h3", "", report.title), el("div", "item-category", report.category));
    const location = el("div", "card-location");
    location.innerHTML = PIN_ICON;
    location.append(el("span", "", report.location));
    card.append(location);
    if (report.description) card.append(el("p", "card-description", report.description));
    const footer = el("div", "card-footer");
    footer.append(el("span", "", timeAgo(report.createdAt)));
    if (report.type === "found") footer.append(button("Claim this item", "button button-outline button-small", () => openClaimDialog(report)));
    card.append(footer);
    grid.append(card);
  }
}

async function loadBoard({ quiet = false } = {}) {
  if (!quiet) {
    board.status = "loading";
    renderBoard();
  }
  try {
    const data = await api(API.reports);
    board.reports = data.reports;
    board.stats = data.stats;
    board.status = "ready";
  } catch (error) {
    if (quiet && board.status === "ready") return;
    board.status = "error";
    board.error = error.message;
  }
  renderBoard();
}

function clearFilters() {
  $("#search-input").value = "";
  $("#type-filter").value = "all";
  $("#category-filter").value = "all";
  board.shown = PAGE_SIZE;
  renderBoard();
}

/* ---------- Form helpers ---------- */

function clearFieldErrors(form, errorBox) {
  $$(".field-error", form).forEach((node) => { node.hidden = true; node.textContent = ""; });
  $$("[aria-invalid]", form).forEach((node) => { node.removeAttribute("aria-invalid"); node.removeAttribute("aria-describedby"); });
  if (errorBox) errorBox.hidden = true;
}

function showFieldError(form, name, message) {
  const note = $(`[data-error-for="${name}"]`, form);
  if (!note) return false;
  note.id = `${form.id}-error-${name}`;
  note.textContent = message;
  note.hidden = !message;
  const control = form.elements.namedItem(name);
  if (control && control.setAttribute) {
    control.setAttribute("aria-invalid", "true");
    control.setAttribute("aria-describedby", note.id);
  }
  return true;
}

function showFieldErrors(form, errors) {
  let first = null;
  for (const [name, message] of Object.entries(errors)) {
    if (showFieldError(form, name, message) && !first) first = name;
  }
  if (first) form.elements.namedItem(first)?.focus?.();
  return Boolean(first);
}

/* ---------- Report form ---------- */

const reportPhoto = setupPhotoPicker({
  input: $("#photo-input"),
  preview: $("#photo-preview"),
  remove: $("#photo-remove"),
  label: $("#photo-choose-label"),
  onError: (message) => showFieldError(reportForm, "photo", message),
});

function validateClient(values) {
  const errors = {};
  if (!values.title) errors.title = "Tell us what the item is.";
  if (!values.location) errors.location = "Tell us where it was last seen or found.";
  if (!values.date) errors.date = "Enter a valid date.";
  else if (values.date > localDateString()) errors.date = "The date cannot be in the future.";
  if (values.contact && reportForm.elements.namedItem("contact").validity.typeMismatch) {
    errors.contact = "Enter a valid email address, or leave this blank.";
  }
  return errors;
}

function openReportDialog() {
  reportForm.reset();
  reportPhoto.reset();
  clearFieldErrors(reportForm, $("#form-error"));
  const dateInput = reportForm.elements.namedItem("date");
  dateInput.max = localDateString();
  dateInput.value = localDateString();
  $("#description-count").textContent = "0 / 500";
  $("#report-submit").disabled = false;
  reportForm.hidden = false;
  $("#report-success").hidden = true;
  if (!reportDialog.open) reportDialog.showModal();
  reportForm.elements.namedItem("type").focus();
  $("#main-nav").classList.remove("menu-open");
  $("#menu-toggle").setAttribute("aria-expanded", "false");
}

async function submitReport(event) {
  event.preventDefault();
  clearFieldErrors(reportForm, $("#form-error"));
  const values = {};
  for (const name of ["type", "category", "title", "location", "date", "description", "contact", "website"]) {
    values[name] = String(reportForm.elements.namedItem(name).value).trim();
  }
  const errors = validateClient(values);
  if (Object.keys(errors).length) {
    showFieldErrors(reportForm, errors);
    return;
  }

  const body = new FormData();
  for (const [key, value] of Object.entries(values)) body.append(key, value);
  if (reportPhoto.blob) body.append("photo", reportPhoto.blob, "photo.jpg");

  const submit = $("#report-submit");
  submit.disabled = true;
  submit.textContent = "Submitting…";
  try {
    const data = await api(API.reports, { method: "POST", body });
    if (data.report) {
      saveReference(data.report);
      showReferenceSuccess(data.report, data.emailSent, values.contact);
    }
    reportForm.hidden = true;
    $("#report-success").hidden = false;
    $("#success-title").focus();
  } catch (error) {
    const fields = error.data?.fields;
    if (!(fields && showFieldErrors(reportForm, fields))) {
      const box = $("#form-error");
      box.textContent = error.message;
      box.hidden = false;
    }
  } finally {
    submit.disabled = false;
    submit.innerHTML = "Submit report <span aria-hidden=\"true\">↗</span>";
  }
}

/* ---------- Reference: saved codes and the downloadable image ---------- */

function savedReferences() {
  try { return JSON.parse(localStorage.getItem(SAVED_REFS_KEY) || "[]").filter((r) => r && r.reference); } catch { return []; }
}

function saveReference(report) {
  try {
    const list = savedReferences().filter((r) => r.reference !== report.reference);
    list.unshift({ reference: report.reference, title: report.title, type: report.type, createdAt: report.createdAt });
    localStorage.setItem(SAVED_REFS_KEY, JSON.stringify(list.slice(0, 20)));
  } catch { /* storage unavailable (private mode): the image and email still work */ }
}

function loadImage(url) {
  return new Promise((resolve) => {
    if (!url) return resolve(null);
    const img = new Image();
    img.onload = () => resolve(img);
    img.onerror = () => resolve(null);
    img.src = url;
  });
}

function wrapText(ctx, text, maxWidth, maxLines) {
  const words = String(text).split(/\s+/);
  const lines = [];
  let line = "";
  for (const word of words) {
    const test = line ? `${line} ${word}` : word;
    if (ctx.measureText(test).width > maxWidth && line) {
      lines.push(line);
      line = word;
      if (lines.length === maxLines) break;
    } else {
      line = test;
    }
  }
  if (lines.length < maxLines && line) lines.push(line);
  if (lines.length === maxLines && words.join(" ").length > lines.join(" ").length) {
    let last = lines[maxLines - 1];
    while (ctx.measureText(`${last}…`).width > maxWidth && last.length) last = last.slice(0, -1);
    lines[maxLines - 1] = `${last}…`;
  }
  return lines;
}

/** Draws a phone-friendly reference card (1080×1350) for the report and returns it as a PNG blob. */
async function buildReferenceImage(report) {
  const W = 1080, H = 1350, pad = 72;
  const canvas = document.createElement("canvas");
  canvas.width = W; canvas.height = H;
  const ctx = canvas.getContext("2d");
  const font = (weight, size) => `${weight} ${size}px "Segoe UI", system-ui, -apple-system, Roboto, Arial, sans-serif`;

  ctx.fillStyle = "#f5f4ef"; ctx.fillRect(0, 0, W, H);
  ctx.fillStyle = "#174a38"; ctx.fillRect(0, 0, W, 170);
  ctx.fillStyle = "#f4f0dc"; ctx.font = font(800, 44); ctx.fillText("Campus Lost & Found", pad, 98);
  ctx.font = font(600, 24); ctx.fillStyle = "#cfe0d4"; ctx.fillText("REPORT REFERENCE", pad, 138);

  let y = 240;
  ctx.fillStyle = "#5b6962"; ctx.font = font(700, 26); ctx.fillText(report.type === "found" ? "FOUND ITEM REPORT" : "LOST ITEM REPORT", pad, y);
  y += 84;
  ctx.fillStyle = "#174a38"; ctx.font = font(800, 84); ctx.fillText(report.reference, pad, y);
  y += 30;
  ctx.strokeStyle = "#e59162"; ctx.lineWidth = 6; ctx.beginPath(); ctx.moveTo(pad, y); ctx.lineTo(pad + 140, y); ctx.stroke();

  const photo = await loadImage(report.photoUrl);
  const textRight = photo ? W - pad - 300 : W - pad;
  y += 74;
  ctx.fillStyle = "#18352e"; ctx.font = font(800, 46);
  for (const line of wrapText(ctx, report.title, textRight - pad, 2)) { ctx.fillText(line, pad, y); y += 58; }

  if (photo) {
    const box = { x: W - pad - 270, y: y - 120, w: 270, h: 270 };
    ctx.fillStyle = "#ffffff"; ctx.fillRect(box.x, box.y, box.w, box.h);
    const s = Math.min(box.w / photo.width, box.h / photo.height);
    const dw = photo.width * s, dh = photo.height * s;
    ctx.drawImage(photo, box.x + (box.w - dw) / 2, box.y + (box.h - dh) / 2, dw, dh);
    ctx.strokeStyle = "#cfd4cb"; ctx.lineWidth = 2; ctx.strokeRect(box.x, box.y, box.w, box.h);
  }

  y += 20;
  const rows = [
    ["Category", report.category],
    [report.type === "found" ? "Found at" : "Last seen at", report.location],
    [report.type === "found" ? "Date found" : "Date lost", formatDate(report.date)],
    ["Submitted", formatDateTime(report.createdAt)],
    ["Status", report.statusLabel || STATUS_LABELS[report.status] || report.status],
  ];
  if (photo) y = Math.max(y, 640);
  for (const [label, value] of rows) {
    ctx.fillStyle = "#5b6962"; ctx.font = font(700, 26); ctx.fillText(label.toUpperCase(), pad, y);
    ctx.fillStyle = "#18352e"; ctx.font = font(500, 34);
    const lines = wrapText(ctx, value || "—", W - pad * 2, 2);
    lines.forEach((line, i) => ctx.fillText(line, pad, y + 44 + i * 42));
    y += 44 + lines.length * 42 + 26;
  }
  if (report.description) {
    ctx.fillStyle = "#5b6962"; ctx.font = font(700, 26); ctx.fillText("DETAILS", pad, y);
    ctx.fillStyle = "#46574f"; ctx.font = font(400, 30);
    const lines = wrapText(ctx, report.description, W - pad * 2, 3);
    lines.forEach((line, i) => ctx.fillText(line, pad, y + 42 + i * 40));
  }

  ctx.fillStyle = "#e6ede4"; ctx.fillRect(0, H - 190, W, 190);
  ctx.fillStyle = "#18352e"; ctx.font = font(700, 28);
  ctx.fillText("Keep this image. Use the code to check your status:", pad, H - 128);
  ctx.font = font(500, 26); ctx.fillStyle = "#46574f";
  const site = `${location.origin}${location.pathname}#/track`;
  ctx.fillText(wrapText(ctx, site, W - pad * 2, 1)[0], pad, H - 86);
  ctx.fillText("Campus Security Office · Admin Building, Room 104", pad, H - 46);

  return new Promise((resolve) => canvas.toBlob(resolve, "image/png"));
}

function downloadBlob(blob, filename) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  document.body.append(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 60_000);
}

async function saveReferenceImage(report, { share = false } = {}) {
  const blob = await buildReferenceImage(report);
  if (!blob) throw new Error("Your browser could not create the image.");
  const filename = `lost-and-found-${report.reference}.png`;
  if (share) {
    const file = new File([blob], filename, { type: "image/png" });
    if (navigator.canShare?.({ files: [file] })) {
      try { await navigator.share({ files: [file], title: `Lost & Found reference ${report.reference}` }); return "shared"; } catch (error) {
        if (error.name === "AbortError") return "cancelled";
      }
    }
  }
  downloadBlob(blob, filename);
  return "downloaded";
}

function canShareFiles() {
  try { return Boolean(navigator.canShare?.({ files: [new File([""], "x.png", { type: "image/png" })] })); } catch { return false; }
}

async function showReferenceSuccess(report, emailSent, contact) {
  lastReference = report;
  $("#success-ref").textContent = report.reference;
  $("#ref-share").hidden = !canShareFiles();
  $("#success-email-note").textContent = contact
    ? (emailSent ? `We also emailed a copy to ${contact}.` : "We couldn’t email a copy right now, so please save the image or the code.")
    : "Tip: add an email address next time to get status updates automatically.";
  const preview = $("#reference-preview");
  preview.hidden = true;
  const blob = await buildReferenceImage(report);
  if (blob && lastReference === report) {
    if (preview.src) URL.revokeObjectURL(preview.src);
    preview.src = URL.createObjectURL(blob);
    preview.hidden = false;
  }
}

$("#ref-download").addEventListener("click", async (event) => {
  if (!lastReference) return;
  const b = event.currentTarget;
  b.disabled = true;
  try { await saveReferenceImage(lastReference); showToast("Reference image saved. Check your downloads or Photos."); } catch (error) { showToast(error.message); } finally { b.disabled = false; }
});
$("#ref-share").addEventListener("click", async () => {
  if (!lastReference) return;
  try { await saveReferenceImage(lastReference, { share: true }); } catch (error) { showToast(error.message); }
});
$("#ref-copy").addEventListener("click", async () => {
  if (!lastReference) return;
  try { await navigator.clipboard.writeText(lastReference.reference); showToast("Reference code copied."); } catch { showToast(`Your reference code is ${lastReference.reference}`); }
});

/* ---------- Track my report ---------- */

function renderSavedRefs() {
  const list = savedReferences();
  $("#saved-refs").hidden = list.length === 0;
  const ul = $("#saved-refs-list");
  ul.replaceChildren();
  for (const item of list) {
    const li = el("li");
    const a = el("a", "saved-ref-link");
    a.href = `#/track/${encodeURIComponent(item.reference)}`;
    a.append(el("strong", "", item.reference), el("span", "", ` · ${item.type === "found" ? "Found" : "Lost"}: ${item.title}`));
    li.append(a);
    ul.append(li);
  }
}

async function trackReference(ref) {
  const result = $("#track-result");
  const errorBox = $("#track-error");
  errorBox.hidden = true;
  result.hidden = true;
  const code = ref.trim().toUpperCase();
  if (!/^LF-[A-Z0-9]{8}$/.test(code)) {
    errorBox.textContent = "Enter a reference code like LF-7KQ2M9XA.";
    errorBox.hidden = false;
    return;
  }
  const submit = $("#track-submit");
  submit.disabled = true;
  try {
    const { report } = await api(API.reports, { query: { ref: code } });
    saveReference(report);
    renderSavedRefs();
    result.replaceChildren(trackCard(report));
    result.hidden = false;
  } catch (error) {
    errorBox.textContent = error.message;
    errorBox.hidden = false;
  } finally {
    submit.disabled = false;
  }
}

function trackCard(report) {
  const card = el("article", "track-card");
  const head = el("div", "track-card-head");
  head.append(el("span", `type-badge ${report.type}`, report.type === "found" ? "✳ Found item" : "⌕ Lost item"), statusBadge(report.status));
  card.append(head);
  const body = el("div", "track-card-body");
  if (report.photoUrl) body.append(thumb(report.photoUrl, report.title, report.title, "track-photo"));
  const info = el("div");
  info.append(el("h2", "", report.title), el("p", "muted", `${report.category} · ${report.location} · ${formatDate(report.date)}`));
  if (report.description) info.append(el("p", "card-description", report.description));
  info.append(el("p", "track-ref", `Reference ${report.reference} · submitted ${formatDateTime(report.createdAt)}`));
  if (report.statusChangedAt) info.append(el("p", "muted small", `Status last updated ${formatDateTime(report.statusChangedAt)}`));
  body.append(info);
  card.append(body);
  const explain = {
    pending: "Campus Security is reviewing your report. It will appear on the community board once approved.",
    active: report.type === "lost"
      ? "Your report is on the community board. Staff will email you if a matching item is turned in."
      : "This item is on the community board so its owner can claim it.",
    resolved: report.type === "lost"
      ? "Your item has been marked as Found. If you haven’t collected it yet, visit Campus Security (Admin Building, Room 104) with a valid ID and this reference."
      : "The item you turned in has been marked as Found and returned to its owner. Thank you!",
  }[report.status];
  card.append(el("p", "track-explain", explain));
  const actions = el("div", "reference-actions");
  actions.append(button("Save reference image", "button button-dark", async (event) => {
    const b = event.currentTarget;
    b.disabled = true;
    try { await saveReferenceImage(report); showToast("Reference image saved."); } catch (error) { showToast(error.message); } finally { b.disabled = false; }
  }));
  if (canShareFiles()) actions.append(button("Share / Save to Photos", "button button-outline", () => saveReferenceImage(report, { share: true }).catch((e) => showToast(e.message))));
  card.append(actions);
  return card;
}

$("#track-form").addEventListener("submit", (event) => {
  event.preventDefault();
  const ref = event.currentTarget.elements.namedItem("ref").value;
  const target = `#/track/${encodeURIComponent(ref.trim().toUpperCase())}`;
  if (location.hash === target) trackReference(ref);
  else location.hash = target;
});

/* ---------- Claims (public) ---------- */

const claimPhoto = setupPhotoPicker({
  input: $("#claim-photo-input"),
  preview: $("#claim-photo-preview"),
  remove: $("#claim-photo-remove"),
  onError: (message) => showFieldError(claimForm, "photo", message),
});

function openClaimDialog(report) {
  claimForm.reset();
  claimPhoto.reset();
  clearFieldErrors(claimForm, $("#claim-error"));
  claimForm.elements.namedItem("reportId").value = report.id;
  const box = $("#claim-item");
  box.replaceChildren();
  if (report.photoUrl) box.append(thumb(report.photoUrl, report.title, report.title, "mini-photo"));
  const info = el("div");
  info.append(el("strong", "", report.title), el("span", "muted", `${report.category} · found at ${report.location} · ${formatDate(report.date)}`));
  box.append(info);
  $("#proof-count").textContent = "0 / 1000";
  claimForm.hidden = false;
  $("#claim-success").hidden = true;
  claimDialog.showModal();
  claimForm.elements.namedItem("name").focus();
}

claimForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  clearFieldErrors(claimForm, $("#claim-error"));
  const f = claimForm.elements;
  const values = {
    reportId: f.namedItem("reportId").value,
    name: f.namedItem("name").value.trim(),
    email: f.namedItem("email").value.trim(),
    phone: f.namedItem("phone").value.trim(),
    proof: f.namedItem("proof").value.trim(),
    website: f.namedItem("website").value,
  };
  const errors = {};
  if (!values.name) errors.name = "Enter your full name.";
  if (!values.email || f.namedItem("email").validity.typeMismatch) errors.email = "Enter a valid email address so we can tell you the result.";
  if (values.proof.length < 15) errors.proof = "Describe something only the owner would know (at least 15 characters).";
  if (Object.keys(errors).length) return void showFieldErrors(claimForm, errors);

  const body = new FormData();
  for (const [key, value] of Object.entries(values)) body.append(key, value);
  if (claimPhoto.blob) body.append("photo", claimPhoto.blob, "proof.jpg");
  const submit = $("#claim-submit");
  submit.disabled = true;
  try {
    const data = await api(API.claims, { method: "POST", body });
    $("#claim-ref").textContent = data.reference || "";
    claimForm.hidden = true;
    $("#claim-success").hidden = false;
    $("#claim-success-title").focus();
  } catch (error) {
    if (!(error.data?.fields && showFieldErrors(claimForm, error.data.fields))) {
      $("#claim-error").textContent = error.message;
      $("#claim-error").hidden = false;
    }
  } finally {
    submit.disabled = false;
  }
});
claimForm.elements.namedItem("proof").addEventListener("input", (event) => {
  $("#proof-count").textContent = `${event.target.value.length} / 1000`;
});

/* ---------- Staff: session ---------- */

function isAdmin() { return auth.role === "admin"; }

function updateStaffLink() {
  const link = $("#staff-link");
  link.hidden = !auth.authenticated;
}

function showAdminPanel(which) {
  $("#admin-login").hidden = which !== "login";
  $("#admin-dashboard").hidden = which !== "dashboard";
  updateStaffLink();
}

function sessionEnded(message) {
  Object.assign(auth, { authenticated: false, csrf: "", role: "", id: 0 });
  admin.reports = [];
  admin.status = "idle";
  matchesState.status = claimsState.status = emailsState.status = "idle";
  renderAdmin();
  showAdminPanel("login");
  if (message) showLoginError(message);
}

/** Shared handling for staff API errors. Returns true when the session ended. */
function staffError(error, prefix) {
  if (error.status === 401) { sessionEnded("Your session has ended. Please sign in again."); return true; }
  showToast(`${prefix}: ${error.message}`, { long: true });
  return false;
}

function showLoginError(message) {
  const box = $("#login-error");
  box.textContent = message;
  box.hidden = !message;
}

function setAuth(data) {
  auth.authenticated = Boolean(data.authenticated);
  auth.username = data.username || "";
  auth.name = data.name || data.username || "";
  auth.role = data.role || "";
  auth.id = data.id || 0;
  auth.csrf = data.csrf || "";
}

async function refreshAuth() {
  try {
    setAuth(await api(API.auth));
    authError = "";
  } catch (error) {
    auth.authenticated = false;
    authError = error.message;
  }
  updateStaffLink();
}

async function enterAdmin(tab) {
  await authReady;
  if (!auth.authenticated) {
    showAdminPanel("login");
    showLoginError(authError);
    $("#login-form").elements.namedItem("username").focus();
    return;
  }
  showAdminPanel("dashboard");
  $("#admin-username").textContent = auth.name;
  $("#admin-role").textContent = isAdmin() ? "Administrator" : "Staff";
  $("#admin-role").className = `role-badge ${auth.role}`;
  document.body.classList.toggle("is-admin", isAdmin());
  showTab(tab);
}

function showTab(tab) {
  $("#analytics-tab").hidden = !isAdmin();
  const valid = ["analytics", "lost", "found", "matches", "claims", "emails"];
  admin.tab = valid.includes(tab) && (tab !== "analytics" || isAdmin()) ? tab : (isAdmin() ? "analytics" : "lost");
  $$(".tab").forEach((link) => {
    const active = link.dataset.tab === admin.tab;
    link.setAttribute("aria-selected", String(active));
    if (active) {
      link.setAttribute("aria-current", "page");
      link.scrollIntoView({ block: "nearest", inline: "nearest" });
    } else link.removeAttribute("aria-current");
  });
  const panel = { analytics: "analytics", lost: "reports", found: "reports", matches: "matches", claims: "claims", emails: "emails" }[admin.tab];
  $$(".tab-panel").forEach((p) => { p.hidden = p.id !== `panel-${panel}`; });

  if (panel === "reports") {
    $("#reports-heading").textContent = admin.tab === "lost" ? "Lost items" : "Found items";
    $("#reports-sub").textContent = admin.tab === "lost"
      ? "Use Send email to tell an owner that a matching item was turned in."
      : "Items turned in to Campus Security. Contact emails are visible only here.";
    if (admin.status === "idle" || admin.status === "error") loadAdmin(); else renderAdmin();
  } else if (panel === "analytics") {
    loadAnalytics();
  } else if (admin.status === "idle") {
    loadAdmin({ quiet: true });
  }
  if (panel === "matches") loadMatches();
  if (panel === "claims") loadClaims();
  if (panel === "emails") loadEmails();
}

async function loadAnalytics() {
  if (!isAdmin()) return;
  const request = ++analyticsState.request;
  analyticsState.period = $("#analytics-period").value;
  analyticsState.status = "loading";
  analyticsState.error = "";
  renderAnalytics();
  try {
    const data = await api(API.analytics, { query: { period: analyticsState.period } });
    if (request !== analyticsState.request) return;
    analyticsState.data = data.analytics;
    analyticsState.status = "ready";
  } catch (error) {
    if (request !== analyticsState.request) return;
    if (error.status === 401) return sessionEnded("Your session has ended. Please sign in again.");
    analyticsState.status = "error";
    analyticsState.error = error.message;
  }
  renderAnalytics();
}

function renderAnalytics() {
  const status = $("#analytics-status");
  if (analyticsState.status === "loading" && !analyticsState.data) {
    status.textContent = "Loading analytics…";
    return;
  }
  if (analyticsState.status === "error") {
    status.textContent = `Couldn’t load analytics: ${analyticsState.error}`;
    return;
  }
  status.textContent = analyticsState.status === "loading" ? "Refreshing analytics…" : "";
  const data = analyticsState.data;
  if (!data) return;

  const number = (value) => new Intl.NumberFormat().format(value);
  $("#analytics-range").textContent = `${data.range.label} · ${data.range.start} to ${data.range.end}`;
  $("#analytics-rate").textContent = data.summary.recoveryRate === null ? "–" : `${data.summary.recoveryRate}%`;
  $("#analytics-rate-note").textContent = `${number(data.summary.recovered)} verified of ${number(data.summary.lost)} eligible`;
  $("#analytics-lost").textContent = number(data.summary.lost);
  $("#analytics-recovered").textContent = number(data.summary.recovered);
  $("#analytics-unrecovered").textContent = number(data.summary.unrecovered);

  const comparison = $("#analytics-comparison");
  comparison.replaceChildren();
  const comparisonHeading = el("h4", "", "Monthly recovery rate comparison");
  comparison.append(comparisonHeading);
  const monthGrid = el("div", "comparison-grid");
  for (const month of [data.monthlyComparison.current, data.monthlyComparison.previous]) {
    const item = el("div", "comparison-item");
    item.append(el("span", "", month.label), el("strong", "", month.rate === null ? "–" : `${month.rate}%`),
      el("small", "", `${number(month.recovered)} of ${number(month.lost)} lost reports recovered`));
    monthGrid.append(item);
  }
  const monthDelta = data.monthlyComparison.current.rate - data.monthlyComparison.previous.rate;
  const change = data.monthlyComparison.current.rate !== null && data.monthlyComparison.previous.rate !== null
    ? `${monthDelta > 0 ? "+" : ""}${monthDelta.toFixed(1)} percentage points`
    : "Not enough data to compare";
  monthGrid.append(el("p", "comparison-change", change));
  comparison.append(monthGrid);

  const trend = $("#recovery-trend");
  trend.replaceChildren();
  if (!data.monthlyTrend.some((month) => month.lost)) {
    trend.append(el("p", "analytics-empty", "No lost-item report cohorts are available for this trend yet."));
  } else {
    const chart = el("div", "trend-chart");
    for (const month of data.monthlyTrend) {
      const bar = el("div", "trend-column");
      const rateLabel = month.rate === null ? "not available" : `${month.rate}%`;
      bar.setAttribute("aria-label", `${month.month}: ${rateLabel} recovered, ${month.recovered} of ${month.lost}`);
      const value = el("strong", "trend-value", month.lost ? `${month.rate}%` : "–");
      const track = el("div", "trend-track");
      const fill = el("span", "trend-fill");
      fill.style.height = `${Math.max(0, Math.min(100, month.rate ?? 0))}%`;
      track.append(fill);
      bar.append(value, track, el("span", "trend-month", month.month), el("small", "", `${number(month.recovered)}/${number(month.lost)}`));
      chart.append(bar);
    }
    trend.append(chart);
  }

  const categoryChart = $("#category-chart");
  categoryChart.replaceChildren();
  const categoryMax = Math.max(1, ...data.categories.flatMap((category) => [category.lost, category.found]));
  if (!data.categories.length) categoryChart.append(el("p", "analytics-empty", "No lost-item categories were reported in this range."));
  for (const category of data.categories) {
    const row = el("div", "analytics-chart-row");
    row.append(el("div", "analytics-row-title", category.category),
      el("p", "analytics-row-detail", `Lost ${number(category.lost)} · Found ${number(category.found)} · ${category.share}% of lost reports · ${category.recoveryRate}% recovered`));
    appendAnalyticsBar(row, "Lost", category.lost, categoryMax, "lost");
    appendAnalyticsBar(row, "Found", category.found, categoryMax, "found");
    categoryChart.append(row);
  }

  const categoryTrend = $("#category-trend");
  categoryTrend.replaceChildren();
  if (!data.categoryTrend.length) categoryTrend.append(el("p", "analytics-empty", "No monthly category trend data yet."));
  const trendMonths = el("div", "category-trend-months");
  const monthHeader = el("div", "category-trend-month-grid");
  trendMonths.append(el("span", ""), monthHeader);
  for (const month of data.monthlyTrend) monthHeader.append(el("small", "", month.month.split(" ")[0]));
  if (data.categoryTrend.length) categoryTrend.append(trendMonths);
  for (const rowData of data.categoryTrend) {
    const row = el("div", "category-trend-row");
    row.append(el("strong", "", rowData.category));
    const values = el("div", "category-trend-values");
    const max = Math.max(1, ...rowData.values);
    rowData.values.forEach((value, index) => {
      const cell = el("span", "category-trend-cell");
      const bar = el("i", "category-trend-bar");
      bar.style.height = `${value ? Math.max(5, value / max * 100) : 0}%`;
      bar.title = `${data.monthlyTrend[index].month}: ${number(value)} lost reports`;
      cell.append(bar, el("small", "", number(value)));
      values.append(cell);
    });
    row.append(values);
    categoryTrend.append(row);
  }

  const locationChart = $("#location-chart");
  locationChart.replaceChildren();
  const locationMax = Math.max(1, ...data.locations.flatMap((location) => [location.lost, location.found]));
  if (!data.locations.length) locationChart.append(el("p", "analytics-empty", "No item reports were recorded in this range."));
  for (const location of data.locations) {
    const row = el("div", "analytics-chart-row");
    row.append(el("div", "analytics-row-title", location.location),
      el("p", "analytics-row-detail", `Unclaimed found items ${number(location.unclaimed)} · Recovery rate ${location.recoveryRate === null ? "–" : `${location.recoveryRate}%`} (${number(location.recovered)}/${number(location.lost)} lost)`));
    appendAnalyticsBar(row, "Lost", location.lost, locationMax, "lost");
    appendAnalyticsBar(row, "Found", location.found, locationMax, "found");
    locationChart.append(row);
  }

  renderLocationRank("#location-recovery-rank", data.locationsByRecovery, (location) =>
    `${location.recoveryRate}% · ${number(location.recovered)} of ${number(location.lost)} lost reports`);
  renderLocationRank("#unclaimed-location-rank", data.locationsByUnclaimed, (location) =>
    `${number(location.unclaimed)} unclaimed found items`);

  const insights = $("#analytics-insights");
  insights.replaceChildren();
  if (!data.insights.length) insights.append(el("li", "analytics-empty", "There is not enough report data to generate an insight for this range."));
  for (const insight of data.insights) insights.append(el("li", "", insight));
}

function renderLocationRank(selector, locations, describe) {
  const list = $(selector);
  list.replaceChildren();
  if (!locations.length) {
    list.append(el("li", "analytics-empty", "No qualifying locations in this range."));
    return;
  }
  for (const location of locations) {
    const item = el("li", "analytics-rank-item");
    item.append(el("strong", "", location.location), el("span", "", describe(location)));
    list.append(item);
  }
}

function appendAnalyticsBar(row, label, value, max, kind) {
  const line = el("div", "analytics-barline");
  const track = el("span", "analytics-bar-track");
  const fill = el("i", `analytics-bar-fill ${kind}`);
  fill.style.width = `${Math.max(0, Math.min(100, value / max * 100))}%`;
  track.append(fill);
  line.append(el("span", "analytics-bar-label", label), track, el("strong", "", new Intl.NumberFormat().format(value)));
  row.append(line);
}

function renderStats(stats) {
  if (!stats) return;
  admin.stats = { ...admin.stats, ...stats };
  const s = admin.stats;
  const set = (id, value) => { $(id).textContent = value ?? "–"; };
  set("#admin-pending", s.pending);
  set("#admin-active", s.active);
  set("#admin-resolved", s.resolved);
  set("#admin-claims", s.pendingClaims);
  set("#admin-matches", s.openMatches);
  const badge = (id, n) => { const b = $(id); b.hidden = !n; b.textContent = n || ""; };
  badge("#tab-count-claims", s.pendingClaims);
  badge("#tab-count-matches", s.openMatches);
  badge("#tab-count-emails", s.failedEmails);
  $("#tab-count-emails").title = s.failedEmails ? `${s.failedEmails} failed in the last 7 days` : "";
}

/* ---------- Staff: reports ---------- */

async function loadAdmin({ quiet = false } = {}) {
  if (!quiet) {
    admin.status = "loading";
    admin.error = "";
    renderAdmin();
  }
  try {
    const data = await api(API.admin);
    admin.reports = data.reports;
    renderStats(data.stats);
    if (data.me) setAuth({ ...auth, ...data.me, authenticated: true, csrf: auth.csrf });
    admin.status = "ready";
  } catch (error) {
    if (error.status === 401) return sessionEnded("Your session has ended. Please sign in again.");
    admin.status = "error";
    admin.error = error.message;
  }
  renderAdmin();
}

function renderAdmin() {
  const body = $("#admin-table-body");
  const empty = $("#admin-empty");
  body.replaceChildren();

  if (admin.status === "loading" || admin.status === "idle") {
    empty.hidden = admin.status === "idle";
    empty.textContent = "Loading reports…";
    return;
  }
  if (admin.status === "error") {
    empty.hidden = false;
    empty.textContent = `Couldn’t load reports: ${admin.error}`;
    return;
  }

  const type = admin.tab === "found" ? "found" : "lost";
  const filter = $("#admin-filter").value;
  const query = $("#admin-search").value.trim().toLocaleLowerCase();
  const ofType = admin.reports.filter((report) => report.type === type);
  const visible = ofType
    .filter((report) => filter === "all" || report.status === filter)
    .filter((report) => !query || [report.title, report.location, report.category, report.description, report.contact, report.reference]
      .some((value) => (value || "").toLocaleLowerCase().includes(query)));

  empty.hidden = visible.length > 0;
  empty.textContent = ofType.length === 0
    ? `No ${type} item reports yet. New submissions will appear here for review.`
    : "No reports match this search or filter.";

  for (const report of visible) {
    const row = document.createElement("tr");
    const photo = el("td", "photo-cell");
    if (report.photoUrl) photo.append(thumb(report.photoUrl, report.title, `${report.title} · ${report.reference}`, "table-photo"));
    else photo.append(el("span", "no-photo", "No photo"));

    const item = document.createElement("td");
    const name = el("button", "admin-item-name link-like", report.title);
    name.type = "button";
    name.addEventListener("click", () => openReportDetails(report.id));
    item.append(name, el("span", "admin-item-sub", `${report.category} · ${report.reference}`));
    if (report.description) item.append(el("span", "admin-item-desc", report.description));
    if (report.contact) {
      const mail = el("a", "admin-contact", report.contact);
      mail.href = `mailto:${report.contact}`;
      item.append(mail);
    } else {
      item.append(el("span", "admin-item-sub", "No email on file"));
    }
    const flags = el("div", "row-flags");
    if (report.pendingClaims) flags.append(el("span", "flag warn", `${report.pendingClaims} pending claim${report.pendingClaims > 1 ? "s" : ""}`));
    if (report.matchCount) flags.append(el("span", "flag", `${report.matchCount} match${report.matchCount > 1 ? "es" : ""}`));
    if (flags.childElementCount) item.append(flags);

    const status = document.createElement("td");
    status.append(statusBadge(report.status));
    if (report.statusChangedAt) status.append(el("span", "admin-item-sub", formatDateTime(report.statusChangedAt)));

    const actions = document.createElement("td");
    const group = el("div", "admin-actions");
    if (report.status === "pending") group.append(adminButton("Publish", () => updateStatus(report.id, "active")));
    else if (report.status === "active") group.append(adminButton("Mark as Found", () => updateStatus(report.id, "resolved")));
    else group.append(adminButton("Reopen", () => updateStatus(report.id, "active")));
    if (report.status === "active") group.append(adminButton("Unpublish", () => updateStatus(report.id, "pending")));
    if (report.type === "lost") {
      const send = adminButton("Send email", () => openSendEmail(report), "primary");
      if (!report.contact) { send.disabled = true; send.title = "This report has no email address."; }
      group.append(send);
    }
    group.append(adminButton("Details", () => openReportDetails(report.id)));
    if (isAdmin()) group.append(adminButton("Delete", () => deleteReport(report), "delete"));
    actions.append(group);

    row.append(photo, item, el("td", "", report.location), el("td", "", formatDate(report.date)), status, actions);
    body.append(row);
  }
}

function adminButton(label, action, variant = "") {
  const b = el("button", `admin-action${variant ? ` ${variant}` : ""}`, label);
  b.type = "button";
  b.addEventListener("click", async () => {
    if (b.disabled) return;
    b.disabled = true;
    try { await action(); } finally { if (b.isConnected) b.disabled = false; }
  });
  return b;
}

async function updateStatus(id, status) {
  try {
    const data = await api(API.admin, { method: "PATCH", query: { id }, body: { status } });
    admin.reports = admin.reports.map((report) => (report.id === id ? { ...report, ...data.report } : report));
    renderStats(data.stats);
    renderAdmin();
    loadBoard({ quiet: true });
    if (emailsState.status !== "idle") loadEmails({ quiet: true });
    const done = { active: "Report published on the community board.", resolved: "Marked as Found.", pending: "Report moved back to review." }[status];
    showToast(`${data.changed ? done : "No change."}${emailSummary(data.email)}`, { long: data.email?.status === "failed" });
  } catch (error) {
    staffError(error, "Could not save this change");
  }
}

async function deleteReport(report) {
  const ok = await confirmAction({
    title: "Delete this report?",
    message: `“${report.title}” (${report.reference}) will be removed permanently, with its photo, claims and matches. Email history is kept. This cannot be undone.`,
    okLabel: "Delete report",
  });
  if (!ok) return;
  try {
    const data = await api(API.admin, { method: "DELETE", query: { id: report.id } });
    admin.reports = admin.reports.filter((item) => item.id !== report.id);
    renderStats(data.stats);
    renderAdmin();
    loadBoard({ quiet: true });
    showToast("Report deleted.");
  } catch (error) {
    staffError(error, "Could not delete this report");
  }
}

/* ---------- Staff: shared dialog ---------- */

function openStaffDialog(eyebrow, title, content) {
  $("#staff-dialog-eyebrow").replaceChildren(el("span"), document.createTextNode(` ${eyebrow}`));
  $("#staff-dialog-title").textContent = title;
  $("#staff-dialog-content").replaceChildren(content);
  if (!staffDialog.open) staffDialog.showModal();
}

function detailList(rows) {
  const dl = el("dl", "detail-list");
  for (const [label, value] of rows) {
    if (value === null || value === undefined || value === "") continue;
    dl.append(el("dt", "", label));
    const dd = el("dd");
    if (value instanceof Node) dd.append(value); else dd.textContent = value;
    dl.append(dd);
  }
  return dl;
}

async function openReportDetails(id) {
  openStaffDialog("REPORT DETAILS", "Loading…", el("p", "muted", "Loading report…"));
  try {
    const data = await api(API.admin, { query: { id } });
    const r = data.report;
    const wrap = el("div", "details");
    if (r.photoUrl) wrap.append(thumb(r.photoUrl, r.title, `${r.title} · ${r.reference}`, "detail-photo"));
    const contact = r.contact ? Object.assign(el("a", "admin-contact", r.contact), { href: `mailto:${r.contact}` }) : "No email on file";
    wrap.append(detailList([
      ["Reference", r.reference],
      ["Type", r.type === "lost" ? "Lost item" : "Found item"],
      ["Status", statusBadge(r.status)],
      ["Category", r.category],
      [r.type === "lost" ? "Last seen at" : "Found at", r.location],
      ["Date", formatDate(r.date)],
      ["Description", r.description || "—"],
      ["Contact", contact],
      ["Submitted", formatDateTime(r.createdAt)],
    ]));

    wrap.append(el("h3", "", "Status history"));
    if (data.history.length) {
      const ul = el("ul", "timeline");
      for (const h of data.history) ul.append(el("li", "", `${formatDateTime(h.changedAt)} — ${STATUS_LABELS[h.fromStatus] || "New"} → ${STATUS_LABELS[h.toStatus] || h.toStatus}${h.changedBy ? ` by ${h.changedBy}` : ""}`));
      wrap.append(ul);
    } else wrap.append(el("p", "muted", "No status changes yet."));

    wrap.append(el("h3", "", "Emails"));
    if (data.emails.length) {
      const ul = el("ul", "timeline");
      for (const e of data.emails) {
        const li = el("li");
        li.append(el("span", `status-badge mail-${e.status}`, e.status === "sent" ? "Sent" : "Failed"), document.createTextNode(` ${formatDateTime(e.createdAt)} — ${e.subject} → ${e.recipient}`));
        if (e.error) li.append(el("span", "admin-item-sub error-text", e.error));
        ul.append(li);
      }
      wrap.append(ul);
    } else wrap.append(el("p", "muted", "No emails sent for this report yet."));

    if (data.claims.length) {
      wrap.append(el("h3", "", "Claims"));
      const ul = el("ul", "timeline");
      for (const c of data.claims) {
        const li = el("li");
        li.append(statusBadge(c.status, CLAIM_LABELS, "claim-"), document.createTextNode(` ${c.reference} — ${c.claimantName}, ${formatDateTime(c.createdAt)}`));
        ul.append(li);
      }
      wrap.append(ul);
    }
    const actions = el("div", "dialog-actions");
    if (r.type === "lost" && r.contact) actions.append(button("Send email", "button button-outline", () => openSendEmail(r)));
    actions.append(button("Close", "button button-dark", () => staffDialog.close()));
    wrap.append(actions);
    openStaffDialog("REPORT DETAILS", r.title, wrap);
  } catch (error) {
    staffDialog.close();
    staffError(error, "Could not load the report");
  }
}

/* ---------- Staff: Send email (lost item → matching found item) ---------- */

async function openSendEmail(lost, preselectFoundId = null) {
  if (!lost.contact) return showToast("This report has no email address, so no email can be sent.");
  if (admin.status !== "ready") await loadAdmin({ quiet: true });
  if (matchesState.status === "idle") await loadMatches({ quiet: true });

  const matched = new Set(matchesState.matches.filter((m) => m.lost.id === lost.id && m.status !== "dismissed").map((m) => m.found.id));
  const candidates = admin.reports
    .filter((r) => r.type === "found" && (r.status !== "resolved" || matched.has(r.id)))
    .sort((a, b) => (matched.has(b.id) - matched.has(a.id)) || ((b.category === lost.category) - (a.category === lost.category)) || b.date.localeCompare(a.date));

  const wrap = el("div", "send-email");
  const summary = el("div", "claim-item");
  if (lost.photoUrl) summary.append(thumb(lost.photoUrl, lost.title, lost.title, "mini-photo"));
  const info = el("div");
  info.append(el("strong", "", `Lost: ${lost.title}`), el("span", "muted", `${lost.reference} · ${lost.category} · to ${lost.contact}`));
  summary.append(info);
  wrap.append(summary);

  const step1 = el("div");
  step1.append(el("p", "dialog-intro", "Choose the found item that matches. The owner will get an email with its details, photo and what to do next."));
  const search = el("input", "plain-input");
  search.type = "search";
  search.placeholder = "Filter found items";
  search.setAttribute("aria-label", "Filter found items");
  const list = el("div", "choice-list");
  list.setAttribute("role", "radiogroup");
  list.setAttribute("aria-label", "Found items");
  step1.append(search, list);
  const next = button("Review email", "button button-dark");
  next.disabled = true;
  const actions1 = el("div", "dialog-actions");
  actions1.append(button("Cancel", "button button-quiet", () => staffDialog.close()), next);
  step1.append(actions1);
  let chosen = null;

  const renderChoices = () => {
    list.replaceChildren();
    const q = search.value.trim().toLocaleLowerCase();
    const shown = candidates.filter((r) => !q || [r.title, r.description, r.location, r.category, r.reference].some((v) => (v || "").toLocaleLowerCase().includes(q)));
    if (!shown.length) list.append(el("p", "muted", candidates.length ? "No found items match this filter." : "There are no found item reports yet."));
    for (const r of shown) {
      const label = el("label", "choice");
      const radio = el("input");
      radio.type = "radio";
      radio.name = "found-choice";
      radio.value = r.id;
      radio.checked = chosen?.id === r.id;
      radio.addEventListener("change", () => { chosen = r; next.disabled = false; });
      label.append(radio);
      if (r.photoUrl) { const img = el("img", "choice-photo"); img.src = r.photoUrl; img.alt = ""; label.append(img); } else label.append(el("span", "choice-photo none", "No photo"));
      const text = el("span", "choice-text");
      text.append(el("strong", "", r.title), el("span", "muted", `${r.category} · ${r.location} · ${formatDate(r.date)} · ${STATUS_LABELS[r.status]}`));
      if (matched.has(r.id)) text.append(el("span", "flag", "Matched"));
      else if (r.category === lost.category) text.append(el("span", "flag soft", "Same category"));
      label.append(text);
      list.append(label);
    }
  };
  search.addEventListener("input", renderChoices);
  if (preselectFoundId) { chosen = candidates.find((r) => r.id === preselectFoundId) || null; next.disabled = !chosen; }
  renderChoices();

  const step2 = el("div");
  step2.hidden = true;
  next.addEventListener("click", () => {
    if (!chosen) return;
    step2.replaceChildren();
    step2.append(el("p", "dialog-intro", "Check the email before sending. Nothing is sent until you press Send email."));
    const preview = el("div", "email-preview");
    preview.append(detailList([
      ["To", lost.contact],
      ["Subject", `A possible match for your lost ${lost.title} has been turned in [${lost.reference}]`],
    ]));
    const body = el("div", "email-preview-body");
    if (chosen.photoUrl) { const img = el("img", "email-preview-photo"); img.src = chosen.photoUrl; img.alt = `Photo of ${chosen.title}`; body.append(img); }
    body.append(detailList([
      ["Found item", chosen.title],
      ["Description", chosen.description || "No description provided"],
      ["Category", chosen.category],
      ["Found at", chosen.location],
      ["Date found", formatDate(chosen.date)],
      ["Status", STATUS_LABELS[chosen.status]],
    ]));
    body.append(el("p", "muted small", "Includes next steps: visit Campus Security with a valid ID, the reference code and proof of ownership, or submit a claim online."));
    preview.append(body);
    step2.append(preview);
    const result = el("div", "form-error");
    result.hidden = true;
    result.setAttribute("role", "alert");
    step2.append(result);
    const sendBtn = button(`Send email to ${lost.contact}`, "button button-dark");
    const actions2 = el("div", "dialog-actions");
    actions2.append(button("Back", "button button-quiet", () => { step2.hidden = true; step1.hidden = false; }), sendBtn);
    step2.append(actions2);
    sendBtn.addEventListener("click", async () => {
      sendBtn.disabled = true;
      sendBtn.textContent = "Sending…";
      result.hidden = true;
      try {
        const data = await api(API.notifications, { method: "POST", body: { action: "match", lostId: lost.id, foundId: chosen.id } });
        staffDialog.close();
        showToast(`${data.email.message}${data.matchCreated ? " A match record was created." : ""}`);
        loadMatches({ quiet: true });
        loadEmails({ quiet: true });
        loadAdmin({ quiet: true });
      } catch (error) {
        if (error.status === 401) { staffDialog.close(); return sessionEnded("Your session has ended. Please sign in again."); }
        result.textContent = error.message;
        result.hidden = false;
        sendBtn.disabled = false;
        sendBtn.textContent = "Try again";
        loadEmails({ quiet: true });
      }
    });
    step1.hidden = true;
    step2.hidden = false;
    sendBtn.focus();
  });

  wrap.append(step1, step2);
  openStaffDialog("SEND EMAIL", "Tell the owner a matching item was found", wrap);
  search.focus();
}

/* ---------- Staff: matches ---------- */

async function loadMatches({ quiet = false } = {}) {
  if (!quiet) { matchesState.status = "loading"; renderMatches(); }
  try {
    const data = await api(API.matches);
    Object.assign(matchesState, { status: "ready", matches: data.matches, suggestions: data.suggestions, staff: data.staff, error: "" });
    renderStats(data.stats);
  } catch (error) {
    if (error.status === 401) return sessionEnded("Your session has ended. Please sign in again.");
    if (!quiet) Object.assign(matchesState, { status: "error", error: error.message });
  }
  renderMatches();
}

function reportSide(label, r, extra) {
  const side = el("div", "match-side");
  side.append(el("span", `type-badge ${r === null ? "" : label.toLowerCase()}`, label === "Lost" ? "⌕ Lost" : "✳ Found"));
  const body = el("div", "match-side-body");
  if (r.photoUrl) body.append(thumb(r.photoUrl, r.title, `${r.title} · ${r.reference}`, "mini-photo"));
  else body.append(el("span", "mini-photo none", "No photo"));
  const text = el("div");
  const name = el("button", "admin-item-name link-like", r.title);
  name.type = "button";
  name.addEventListener("click", () => openReportDetails(r.id));
  text.append(name, el("span", "admin-item-sub", `${r.reference} · ${r.category}`), el("span", "admin-item-sub", `${r.location} · ${formatDate(r.date)}`));
  const st = statusBadge(r.status);
  text.append(st);
  if (extra) text.append(extra);
  body.append(text);
  side.append(body);
  return side;
}

function canManageMatch(m) {
  return isAdmin() || m.assignedStaffId === null || m.assignedStaffId === auth.id;
}

async function patchMatch(id, body, message) {
  try {
    const data = await api(API.matches, { method: "PATCH", query: { id }, body });
    matchesState.matches = matchesState.matches.map((m) => (m.id === id ? data.match : m));
    renderStats(data.stats);
    renderMatches();
    showToast(`${message}${data.email ? emailSummary(data.email) : ""}`, { long: data.email?.status === "failed" });
    if (data.email) loadEmails({ quiet: true });
  } catch (error) {
    staffError(error, "Could not update the match");
    renderMatches();
  }
}

function renderMatches() {
  const list = $("#match-list");
  list.replaceChildren();
  if (matchesState.status === "loading" || matchesState.status === "idle") { list.append(el("p", "admin-empty", "Loading matches…")); return; }
  if (matchesState.status === "error") { list.append(el("p", "admin-empty", `Couldn’t load matches: ${matchesState.error}`)); return; }

  const f = $("#match-filter").value;
  const shown = matchesState.matches.filter((m) => ({
    all: true,
    mine: m.assignedStaffId === auth.id,
    unassigned: m.assignedStaffId === null && m.status !== "dismissed",
    active: m.assignmentStatus !== "completed" && m.status !== "dismissed",
    completed: m.assignmentStatus === "completed",
    dismissed: m.status === "dismissed",
  })[f]);
  if (!shown.length) {
    list.append(el("p", "admin-empty", matchesState.matches.length
      ? "No matches for this filter."
      : "No matches yet. Create one from the suggestions below, with “+ New match”, or with Send email on a lost item."));
  }

  for (const m of shown) {
    const card = el("article", `match-card match-${m.status}`);
    const head = el("div", "match-head");
    head.append(statusBadge(m.status, MATCH_LABELS, "match-"), el("span", `status-badge assign-${m.assignmentStatus}`, ASSIGNMENT_LABELS[m.assignmentStatus]));
    head.append(el("span", "muted small", `Match #${m.id} · created ${formatDateTime(m.createdAt)}${m.createdByName ? ` by ${m.createdByName}` : ""}`));
    card.append(head);

    const pair = el("div", "match-pair");
    const contact = m.lost.contact ? el("span", "admin-item-sub", m.lost.contact) : el("span", "admin-item-sub", "No email on file");
    pair.append(reportSide("Lost", m.lost, contact), el("span", "match-arrow", "⇄"), reportSide("Found", m.found));
    card.append(pair);

    const assign = el("div", "match-assign");
    const who = el("div", "assigned-to");
    who.append(el("span", "muted small", "Assigned staff"));
    who.append(el("strong", "", m.assignedStaffName || "Unassigned"));
    if (m.assignedAt) who.append(el("span", "muted small", `by ${m.assignedByName || "—"} on ${formatDateTime(m.assignedAt)}`));
    assign.append(who);

    if (isAdmin()) {
      const label = el("label", "inline-field");
      label.append(el("span", "", "Assign to"));
      const select = el("select");
      select.append(new Option("Unassigned", ""));
      for (const s of matchesState.staff) select.append(new Option(`${s.name} (${s.role === "admin" ? "Admin" : "Staff"})${s.hasEmail ? "" : " · no email"}`, String(s.id)));
      select.value = m.assignedStaffId ? String(m.assignedStaffId) : "";
      select.addEventListener("change", () => {
        select.disabled = true;
        const value = select.value ? Number(select.value) : null;
        const name = matchesState.staff.find((s) => s.id === value)?.name;
        patchMatch(m.id, { assignedStaffId: value }, value ? `Assigned to ${name}.` : "Assignment removed.");
      });
      label.append(select);
      assign.append(label);
    }

    const manage = canManageMatch(m);
    if (manage && m.assignedStaffId !== null) {
      const label = el("label", "inline-field");
      label.append(el("span", "", "Progress"));
      const select = el("select");
      for (const v of ["assigned", "in_progress", "completed"]) select.append(new Option(ASSIGNMENT_LABELS[v], v));
      select.value = m.assignmentStatus;
      select.addEventListener("change", () => { select.disabled = true; patchMatch(m.id, { assignmentStatus: select.value }, `Progress set to ${ASSIGNMENT_LABELS[select.value]}.`); });
      label.append(select);
      assign.append(label);
    }
    card.append(assign);

    const notes = el("div", "match-notes");
    if (manage) {
      const area = el("textarea", "plain-input");
      area.rows = 2;
      area.maxLength = 500;
      area.value = m.notes || "";
      area.placeholder = "Notes (e.g. called owner, pickup arranged for Friday)";
      area.setAttribute("aria-label", `Notes for match ${m.id}`);
      const save = adminButton("Save notes", () => patchMatch(m.id, { notes: area.value }, "Notes saved."));
      notes.append(area, save);
    } else if (m.notes) {
      notes.append(el("p", "muted", m.notes));
    }
    if (!manage) notes.append(el("p", "muted small", `Only ${m.assignedStaffName} or an administrator can update this match.`));
    card.append(notes);

    const actions = el("div", "admin-actions");
    if (m.lost.contact) actions.append(adminButton(m.emailsSent ? `Email owner again (${m.emailsSent} sent)` : "Email owner", () => openSendEmail(m.lost, m.found.id), "primary"));
    if (manage) {
      if (m.status !== "confirmed") actions.append(adminButton("Confirm match", () => patchMatch(m.id, { status: "confirmed" }, "Match confirmed.")));
      if (m.status !== "dismissed") actions.append(adminButton("Dismiss", () => patchMatch(m.id, { status: "dismissed" }, "Match dismissed.")));
      if (m.status !== "open") actions.append(adminButton("Reopen", () => patchMatch(m.id, { status: "open" }, "Match reopened.")));
    }
    if (isAdmin()) actions.append(adminButton("Delete", async () => {
      if (!(await confirmAction({ title: "Delete this match?", message: `Match #${m.id} between “${m.lost.title}” and “${m.found.title}” will be removed. The reports are kept.`, okLabel: "Delete match" }))) return;
      try {
        const data = await api(API.matches, { method: "DELETE", query: { id: m.id } });
        renderStats(data.stats);
        loadMatches({ quiet: true });
        showToast("Match deleted.");
      } catch (error) { staffError(error, "Could not delete the match"); }
    }, "delete"));
    card.append(actions);
    list.append(card);
  }

  const sug = $("#suggestion-list");
  sug.replaceChildren();
  if (!matchesState.suggestions.length) sug.append(el("p", "muted", "No new suggestions right now."));
  for (const s of matchesState.suggestions) {
    const card = el("article", "match-card suggestion");
    const pair = el("div", "match-pair");
    pair.append(reportSide("Lost", s.lost), el("span", "match-arrow", "⇄"), reportSide("Found", s.found));
    card.append(pair);
    card.append(el("p", "muted small", s.reasons.join(" · ")));
    const actions = el("div", "admin-actions");
    actions.append(adminButton("Create match", () => createMatch(s.lost.id, s.found.id), "primary"));
    card.append(actions);
    sug.append(card);
  }
}

async function createMatch(lostId, foundId, notes = "") {
  try {
    const data = await api(API.matches, { method: "POST", body: { lostId, foundId, notes } });
    renderStats(data.stats);
    await loadMatches({ quiet: true });
    showToast(`Match #${data.match.id} created.${isAdmin() ? " You can now assign a staff member." : ""}`);
    return true;
  } catch (error) {
    staffError(error, "Could not create the match");
    return false;
  }
}

function openNewMatch() {
  const open = (type) => admin.reports.filter((r) => r.type === type && r.status !== "resolved");
  const form = el("form", "new-match");
  form.noValidate = true;
  const mk = (labelText, type) => {
    const label = el("label", "form-field", labelText);
    const select = el("select");
    select.required = true;
    select.append(new Option(`Choose a ${type} item`, ""));
    for (const r of open(type)) select.append(new Option(`${r.title} — ${r.category}, ${r.location} (${r.reference})`, r.id));
    label.append(select);
    form.append(label);
    return select;
  };
  const lost = mk("Lost item", "lost");
  const found = mk("Found item", "found");
  const notesLabel = el("label", "form-field", "Notes (optional)");
  const notes = el("textarea");
  notes.rows = 2;
  notes.maxLength = 500;
  notesLabel.append(notes);
  form.append(notesLabel);
  const err = el("div", "form-error");
  err.hidden = true;
  form.append(err);
  const actions = el("div", "dialog-actions");
  const submit = el("button", "button button-dark", "Create match");
  submit.type = "submit";
  actions.append(button("Cancel", "button button-quiet", () => staffDialog.close()), submit);
  form.append(actions);
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!lost.value || !found.value) { err.textContent = "Choose one lost item and one found item."; err.hidden = false; return; }
    submit.disabled = true;
    if (await createMatch(lost.value, found.value, notes.value)) staffDialog.close();
    submit.disabled = false;
  });
  openStaffDialog("MATCHES", "New match", form);
  lost.focus();
}

/* ---------- Staff: claims ---------- */

async function loadClaims({ quiet = false } = {}) {
  if (!quiet) { claimsState.status = "loading"; renderClaims(); }
  try {
    const data = await api(API.claims);
    Object.assign(claimsState, { status: "ready", claims: data.claims, counts: data.counts, error: "" });
    renderStats(data.stats);
  } catch (error) {
    if (error.status === 401) return sessionEnded("Your session has ended. Please sign in again.");
    if (!quiet) Object.assign(claimsState, { status: "error", error: error.message });
  }
  renderClaims();
}

function renderClaims() {
  for (const k of ["pending", "approved", "rejected"]) $(`#claim-count-${k}`).textContent = claimsState.counts[k] ?? 0;
  $$("[data-claim-filter]").forEach((b) => b.setAttribute("aria-pressed", String(b.dataset.claimFilter === claimsState.filter)));
  const list = $("#claim-list");
  list.replaceChildren();
  if (claimsState.status === "loading" || claimsState.status === "idle") { list.append(el("p", "admin-empty", "Loading claims…")); return; }
  if (claimsState.status === "error") { list.append(el("p", "admin-empty", `Couldn’t load claims: ${claimsState.error}`)); return; }
  const shown = claimsState.claims.filter((c) => claimsState.filter === "all" || c.status === claimsState.filter);
  if (!shown.length) list.append(el("p", "admin-empty", claimsState.filter === "pending" ? "No pending claims. New claims from the community board will appear here." : "No claims for this filter."));

  for (const c of shown) {
    const card = el("article", `claim-card claim-${c.status}`);
    const head = el("div", "match-head");
    head.append(statusBadge(c.status, CLAIM_LABELS, "claim-"), el("strong", "", c.reference), el("span", "muted small", `Submitted ${formatDateTime(c.createdAt)}`));
    card.append(head);

    const cols = el("div", "claim-cols");
    const item = el("section", "claim-section");
    item.append(el("h4", "", "Item"));
    const body = el("div", "match-side-body");
    if (c.itemPhotoUrl) body.append(thumb(c.itemPhotoUrl, c.itemTitle, `${c.itemTitle} · ${c.itemReference}`, "mini-photo"));
    const t = el("div");
    const name = el("button", "admin-item-name link-like", c.itemTitle);
    name.type = "button";
    name.addEventListener("click", () => openReportDetails(c.reportId));
    t.append(name, el("span", "admin-item-sub", `${c.itemReference} · ${c.itemCategory}`), el("span", "admin-item-sub", `Found at ${c.itemLocation} · ${formatDate(c.itemDate)}`), statusBadge(c.itemStatus));
    if (c.itemDescription) t.append(el("span", "admin-item-desc", c.itemDescription));
    body.append(t);
    item.append(body);

    const claimant = el("section", "claim-section");
    claimant.append(el("h4", "", "Claimant"));
    const mail = el("a", "admin-contact", c.claimantEmail);
    mail.href = `mailto:${c.claimantEmail}`;
    claimant.append(el("strong", "", c.claimantName), mail);
    if (c.claimantPhone) { const tel = el("a", "admin-contact", c.claimantPhone); tel.href = `tel:${c.claimantPhone.replace(/[^0-9+]/g, "")}`; claimant.append(tel); }

    const proof = el("section", "claim-section claim-proof");
    proof.append(el("h4", "", "Proof of ownership"), el("p", "proof-text", c.proof));
    if (c.proofPhotoUrl) proof.append(thumb(c.proofPhotoUrl, `Proof photo for ${c.reference}`, `Proof photo · ${c.claimantName} · ${c.reference}`, "mini-photo"));
    cols.append(item, claimant, proof);
    card.append(cols);

    if (c.reviewedAt || c.reviewNote) {
      card.append(el("p", "review-note", `${CLAIM_LABELS[c.status]}${c.reviewedBy ? ` by ${c.reviewedBy}` : ""}${c.reviewedAt ? ` on ${formatDateTime(c.reviewedAt)}` : ""}${c.reviewNote ? ` — “${c.reviewNote}”` : ""}`));
    }
    const details = el("details", "claim-history");
    details.append(el("summary", "", `Status history (${c.history.length})`));
    const ul = el("ul", "timeline");
    for (const h of c.history) ul.append(el("li", "", `${formatDateTime(h.changedAt)} — ${h.fromStatus ? `${CLAIM_LABELS[h.fromStatus]} → ` : ""}${CLAIM_LABELS[h.toStatus]}${h.changedBy ? ` by ${h.changedBy}` : " (submitted by claimant)"}${h.note && h.note !== "Claim submitted" ? `: ${h.note}` : ""}`));
    details.append(ul);
    card.append(details);

    const actions = el("div", "admin-actions");
    if (c.status === "pending") {
      actions.append(adminButton("Approve", () => openClaimReview(c, "approved"), "primary"));
      actions.append(adminButton("Reject", () => openClaimReview(c, "rejected"), "delete"));
    } else {
      actions.append(adminButton("Move back to Pending", () => reviewClaim(c, "pending", "", false)));
    }
    card.append(actions);
    list.append(card);
  }
}

function openClaimReview(c, status) {
  const approve = status === "approved";
  const form = el("form");
  form.noValidate = true;
  form.append(el("p", "dialog-intro", approve
    ? `Approve ${c.claimantName}’s claim for “${c.itemTitle}”? They will be emailed collection instructions.`
    : `Reject ${c.claimantName}’s claim for “${c.itemTitle}”? They will be emailed that the claim was not approved.`));
  const label = el("label", "form-field", approve ? "Note to the claimant (optional)" : "Reason (optional, included in the email)");
  const note = el("textarea");
  note.rows = 3;
  note.maxLength = 500;
  label.append(note);
  form.append(label);
  let mark = null;
  if (approve) {
    const check = el("label", "check-field");
    mark = el("input");
    mark.type = "checkbox";
    mark.checked = c.itemStatus !== "resolved";
    mark.disabled = c.itemStatus === "resolved";
    check.append(mark, el("span", "", c.itemStatus === "resolved" ? "The item is already marked as Found." : "Also mark the item as Found (removes it from the public board and emails the finder)"));
    form.append(check);
  }
  const actions = el("div", "dialog-actions");
  const ok = el("button", `button ${approve ? "button-dark" : "button-danger"}`, approve ? "Approve claim and email claimant" : "Reject claim and email claimant");
  ok.type = "submit";
  actions.append(button("Cancel", "button button-quiet", () => staffDialog.close()), ok);
  form.append(actions);
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    ok.disabled = true;
    if (await reviewClaim(c, status, note.value, Boolean(mark?.checked))) staffDialog.close();
    ok.disabled = false;
  });
  openStaffDialog("REVIEW CLAIM", `${approve ? "Approve" : "Reject"} claim ${c.reference}`, form);
  note.focus();
}

async function reviewClaim(c, status, note, markFound) {
  try {
    const data = await api(API.claims, { method: "PATCH", query: { id: c.id }, body: { status, note, markFound } });
    claimsState.claims = claimsState.claims.map((x) => (x.id === c.id ? data.claim : x));
    claimsState.counts = data.counts;
    renderStats(data.stats);
    renderClaims();
    const parts = [`Claim ${c.reference} is now ${CLAIM_LABELS[status]}.`];
    if (data.emails?.claimant) parts.push(emailSummary(data.emails.claimant).trim());
    if (data.emails?.reporter) parts.push(`Item marked as Found.${emailSummary(data.emails.reporter)}`);
    const failed = Object.values(data.emails || {}).some((e) => e.status === "failed");
    showToast(parts.join(" "), { long: failed });
    loadAdmin({ quiet: true });
    loadBoard({ quiet: true });
    if (emailsState.status !== "idle") loadEmails({ quiet: true });
    return true;
  } catch (error) {
    staffError(error, "Could not update the claim");
    return false;
  }
}

/* ---------- Staff: email notifications ---------- */

async function loadEmails({ quiet = false } = {}) {
  if (!quiet) { emailsState.status = "loading"; renderEmails(); }
  try {
    const data = await api(API.notifications);
    Object.assign(emailsState, { status: "ready", notifications: data.notifications, counts: data.counts, templates: data.templates, mail: data.mail, error: "" });
  } catch (error) {
    if (error.status === 401) return sessionEnded("Your session has ended. Please sign in again.");
    if (!quiet) Object.assign(emailsState, { status: "error", error: error.message });
  }
  renderEmails();
}

function renderMailStatus() {
  const box = $("#mail-status");
  box.replaceChildren();
  const m = emailsState.mail;
  if (!m) return;
  box.className = `mail-status ${m.problem ? "problem" : "ok"}`;
  box.append(el("strong", "", m.problem ? "Email is not ready" : "Email is configured"));
  if (m.problem) box.append(el("p", "", m.problem));
  const how = m.transport === "smtp"
    ? `SMTP ${m.host || "(no host)"}:${m.port} · ${m.encryption === "none" ? "no encryption" : m.encryption.toUpperCase()}${m.authentication ? " · signs in" : ""}`
    : "PHP mail()";
  box.append(el("p", "small", `${how} · From: ${m.fromName} <${m.fromEmail || "not set"}>`));
  box.append(el("p", "small muted", "Passwords and other credentials stay on the server and are never shown here."));
  $("#test-mail-form").hidden = !isAdmin();
}

function renderEmails() {
  renderMailStatus();
  const typeSelect = $("#email-type-filter");
  if (typeSelect.options.length <= 1 && Object.keys(emailsState.templates).length) {
    for (const [value, label] of Object.entries(emailsState.templates)) typeSelect.append(new Option(label, value));
  }
  const c = emailsState.counts;
  $("#email-counts").textContent = emailsState.status === "ready" ? `${c.sent || 0} sent · ${c.failed || 0} failed` : "Every email the system sends, with its delivery result.";
  const body = $("#email-table-body");
  const empty = $("#email-empty");
  body.replaceChildren();
  if (emailsState.status !== "ready") {
    empty.hidden = emailsState.status === "idle";
    empty.textContent = emailsState.status === "error" ? `Couldn’t load emails: ${emailsState.error}` : "Loading emails…";
    return;
  }
  const status = $("#email-status-filter").value;
  const type = typeSelect.value;
  const q = $("#email-search").value.trim().toLocaleLowerCase();
  const shown = emailsState.notifications
    .filter((n) => status === "all" || n.status === status)
    .filter((n) => type === "all" || n.template === type)
    .filter((n) => !q || [n.recipient, n.subject, n.itemTitle, n.error].some((v) => (v || "").toLocaleLowerCase().includes(q)));
  empty.hidden = shown.length > 0;
  empty.textContent = emailsState.notifications.length ? "No emails match this filter." : "No emails have been sent yet.";
  for (const n of shown) {
    const row = document.createElement("tr");
    const when = el("td", "", formatDateTime(n.createdAt));
    when.append(el("span", "admin-item-sub", n.triggeredBy));
    const subject = el("td");
    subject.append(el("span", "admin-item-name small-name", n.subject));
    if (n.itemTitle) subject.append(el("span", "admin-item-sub", `Item: ${n.itemTitle}`));
    const delivery = el("td");
    delivery.append(el("span", `status-badge mail-${n.status}`, n.status === "sent" ? "Sent" : "Failed"));
    if (n.error) delivery.append(el("span", "admin-item-sub error-text", n.error));
    const actions = el("td");
    const group = el("div", "admin-actions");
    group.append(adminButton("View", () => openEmailDetail(n.id)));
    if (n.status === "failed") group.append(adminButton("Resend", () => resendEmail(n), "primary"));
    actions.append(group);
    row.append(when, el("td", "break", n.recipient), subject, el("td", "", n.templateLabel), delivery, actions);
    body.append(row);
  }
}

async function openEmailDetail(id) {
  try {
    const { notification: n } = await api(API.notifications, { query: { id } });
    const wrap = el("div");
    wrap.append(detailList([
      ["Delivery", el("span", `status-badge mail-${n.status}`, n.status === "sent" ? "Sent" : "Failed")],
      ["Error", n.error],
      ["Date", formatDateTime(n.createdAt)],
      ["To", n.recipient],
      ["Subject", n.subject],
      ["Type", n.templateLabel],
      ["Item", n.itemTitle],
      ["Sent by", n.triggeredBy],
    ]));
    wrap.append(el("h3", "", "Message"), el("pre", "email-body", n.body));
    const actions = el("div", "dialog-actions");
    if (n.status === "failed") actions.append(button("Resend", "button button-outline", async () => { staffDialog.close(); await resendEmail(n); }));
    actions.append(button("Close", "button button-dark", () => staffDialog.close()));
    wrap.append(actions);
    openStaffDialog("EMAIL NOTIFICATION", n.subject, wrap);
  } catch (error) {
    staffError(error, "Could not load the email");
  }
}

async function resendEmail(n) {
  const ok = await confirmAction({ title: "Send this email again?", message: `“${n.subject}” will be sent to ${n.recipient} again.`, okLabel: "Resend email", danger: false });
  if (!ok) return;
  try {
    const data = await api(API.notifications, { method: "POST", body: { action: "resend", id: n.id } });
    showToast(data.email.message);
  } catch (error) {
    staffError(error, "Resend failed");
  }
  loadEmails({ quiet: true });
  loadAdmin({ quiet: true });
}

$("#test-mail-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  const input = event.currentTarget.elements.namedItem("to");
  if (!input.value.trim() || input.validity.typeMismatch) return showToast("Enter a valid email address for the test.");
  const b = $("button", event.currentTarget);
  b.disabled = true;
  b.textContent = "Sending…";
  try {
    const data = await api(API.notifications, { method: "POST", body: { action: "test", to: input.value.trim() } });
    showToast(`Test email sent to ${data.email.recipient}.`);
  } catch (error) {
    staffError(error, "Test email failed");
  } finally {
    b.disabled = false;
    b.textContent = "Send test";
    loadEmails({ quiet: true });
  }
});

/* ---------- Sign in / out ---------- */

async function submitLogin(event) {
  event.preventDefault();
  const form = event.currentTarget;
  const username = form.elements.namedItem("username").value.trim();
  const password = form.elements.namedItem("password").value;
  showLoginError("");
  if (!username || !password) return showLoginError("Enter your username and password.");

  const b = $("#login-submit");
  b.disabled = true;
  try {
    setAuth(await api(API.auth, { method: "POST", body: { username, password } }));
    authError = "";
    form.reset();
    enterAdmin(parseRoute().tab);
  } catch (error) {
    showLoginError(error.message);
    form.elements.namedItem("password").value = "";
    form.elements.namedItem("password").focus();
  } finally {
    b.disabled = false;
  }
}

async function logout() {
  try {
    await api(API.auth, { method: "DELETE" });
  } catch (error) {
    showToast(`Could not sign out: ${error.message}`);
    return;
  }
  sessionEnded("");
  location.hash = "#/";
  showToast("You have been signed out.");
}

/* ---------- Routing and wiring ---------- */

function parseRoute() {
  const hash = location.hash;
  if (hash.startsWith("#/staff")) return { view: "admin", tab: hash.split("/")[2] || "" };
  if (hash.startsWith("#/track")) return { view: "track", ref: decodeURIComponent(hash.split("/")[2] || "") };
  return { view: "public" };
}

function applyRoute() {
  const route = parseRoute();
  const changed = route.view !== shownView;
  const first = shownView === null;
  shownView = route.view;
  $("#view-public").hidden = route.view !== "public";
  $("#view-track").hidden = route.view !== "track";
  $("#view-admin").hidden = route.view !== "admin";
  $$("[data-nav]").forEach((link) => {
    if (link.dataset.nav === route.view) link.setAttribute("aria-current", "page");
    else link.removeAttribute("aria-current");
  });
  $("#main-nav").classList.remove("menu-open");
  $("#menu-toggle").setAttribute("aria-expanded", "false");
  if (changed) {
    window.scrollTo(0, 0);
    if (!first) $("#main").focus({ preventScroll: true });
  }
  if (route.view === "admin") {
    if (changed || !auth.authenticated) enterAdmin(route.tab);
    else showTab(route.tab);
  }
  if (route.view === "track") {
    renderSavedRefs();
    const input = $("#track-form").elements.namedItem("ref");
    if (route.ref) { input.value = route.ref.toUpperCase(); trackReference(route.ref); } else { $("#track-result").hidden = true; input.focus(); }
  }
}

$$("[data-open-report]").forEach((b) => b.addEventListener("click", openReportDialog));
$$("[data-close-dialog]").forEach((b) => b.addEventListener("click", () => reportDialog.close()));
$("#report-another").addEventListener("click", openReportDialog);

// Close dialogs on a backdrop click that also started on the backdrop (so text selection doesn't close them).
for (const dialog of [reportDialog, claimDialog, staffDialog]) {
  let pressedOnBackdrop = false;
  dialog.addEventListener("mousedown", (event) => { pressedOnBackdrop = event.target === dialog; });
  dialog.addEventListener("click", (event) => {
    if (event.target === dialog && pressedOnBackdrop) dialog.close();
    if (event.target.closest("[data-close]")) dialog.close();
  });
}

reportForm.addEventListener("submit", submitReport);
reportForm.elements.namedItem("description").addEventListener("input", (event) => {
  $("#description-count").textContent = `${event.target.value.length} / 500`;
});

$("#login-form").addEventListener("submit", submitLogin);
$("#logout-button").addEventListener("click", logout);
$("#admin-refresh").addEventListener("click", () => loadAdmin());
$("#admin-filter").addEventListener("change", renderAdmin);
$("#admin-search").addEventListener("input", renderAdmin);
$("#analytics-period").addEventListener("change", () => loadAnalytics());
$("#analytics-refresh").addEventListener("click", () => loadAnalytics());
$("#match-filter").addEventListener("change", renderMatches);
$("#matches-refresh").addEventListener("click", () => loadMatches());
$("#new-match-button").addEventListener("click", async () => { if (admin.status !== "ready") await loadAdmin({ quiet: true }); openNewMatch(); });
$("#claims-refresh").addEventListener("click", () => loadClaims());
$$("[data-claim-filter]").forEach((b) => b.addEventListener("click", () => { claimsState.filter = b.dataset.claimFilter; renderClaims(); }));
$("#emails-refresh").addEventListener("click", () => loadEmails());
$("#email-status-filter").addEventListener("change", renderEmails);
$("#email-type-filter").addEventListener("change", renderEmails);
$("#email-search").addEventListener("input", renderEmails);

const rerender = () => { board.shown = PAGE_SIZE; renderBoard(); };
$("#search-input").addEventListener("input", rerender);
$("#type-filter").addEventListener("change", rerender);
$("#category-filter").addEventListener("change", rerender);
$("#clear-filters").addEventListener("click", clearFilters);
$("#load-more").addEventListener("click", () => { board.shown += PAGE_SIZE; renderBoard(); });

$("#menu-toggle").addEventListener("click", (event) => {
  const isOpen = $("#main-nav").classList.toggle("menu-open");
  event.currentTarget.setAttribute("aria-expanded", String(isOpen));
});
document.addEventListener("keydown", (event) => {
  if (event.key === "Escape") {
    $("#main-nav").classList.remove("menu-open");
    $("#menu-toggle").setAttribute("aria-expanded", "false");
  }
  if (lightbox.open && (event.key === "+" || event.key === "=")) setZoom(viewer.scale * 1.5);
  if (lightbox.open && event.key === "-") setZoom(viewer.scale / 1.5);
  const typing = ["INPUT", "TEXTAREA", "SELECT"].includes(document.activeElement?.tagName);
  const anyDialog = [reportDialog, confirmDialogEl, claimDialog, staffDialog, lightbox].some((d) => d.open);
  if (event.key === "/" && !typing && shownView === "public" && !anyDialog) {
    event.preventDefault();
    $("#search-input").focus();
  }
});
window.addEventListener("hashchange", applyRoute);

if (location.protocol === "file:") $("#file-notice").hidden = false;
authReady = refreshAuth();
applyRoute();
loadBoard();
