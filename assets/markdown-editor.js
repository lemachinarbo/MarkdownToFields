(function () {
  "use strict";

  const HIDDEN_NAME = "md_markdown_lock_transient_value";
  const STYLE_ID = "md-disabled-overlay-style";

  function ensureHiddenInForm(form) {
    if (!form) return null;
    const existing = form.querySelector('[name="' + HIDDEN_NAME + '"]');
    if (existing) return existing;
    const h = document.createElement("input");
    h.type = "hidden";
    h.name = HIDDEN_NAME;
    h.value = "0";
    form.appendChild(h);
    return h;
  }

  function injectOverlayCSS() {
    if (document.getElementById(STYLE_ID)) return;
    const s = document.createElement("style");
    s.id = STYLE_ID;
    s.textContent = `
.md-overlay-wrapper{position:relative;display:block}
.md-disabled-overlay{position:absolute;inset:0;background:rgba(255,255,255,0.92);border-radius:4px;border:1px solid rgba(0,0,0,0.06);pointer-events:auto;display:flex;flex-direction:column;gap:8px;align-items:stretch;justify-content:flex-start;color:#556;font-size:12px;font-weight:600;padding:0}
.md-overlay-badge{background:#fff3bf;color:#6b4a00;padding:16px 20px;border-top-left-radius:4px;border-top-right-radius:4px;font-size:16px;line-height:1.2;font-weight:700;box-shadow:0 1px 0 rgba(0,0,0,0.04);border-bottom:1px solid rgba(0,0,0,0.06);width:100%;box-sizing:border-box;text-align:left}
.md-overlay-label{font-size:11px;color:#556;opacity:0.9;padding:10px 16px;text-align:left}
.md-disabled-overlay.hidden{display:none}
`;
    document.head.appendChild(s);
  }

  function createOverlay(el) {
    const overlay = document.createElement("div");
    overlay.className = "md-disabled-overlay";

    const badge = document.createElement("div");
    badge.className = "md-overlay-badge";
    badge.textContent =
      "Editor disabled: Double-click the field to edit the Markdown content.";
    overlay.appendChild(badge);

    overlay.style.cursor = "pointer";

    overlay.addEventListener("dblclick", function (e) {
      e.preventDefault();
      const form = el.closest("form");
      const h = ensureHiddenInForm(form);
      if (h) h.value = "1";
      setEnabled(true);
      if (typeof el.focus === "function") el.focus();
    });

    return overlay;
  }

  function ensureOverlayFor(el) {
    let wrapper = el.closest(".md-overlay-wrapper");
    if (!wrapper) {
      wrapper = document.createElement("div");
      wrapper.className = "md-overlay-wrapper";
      el.parentNode.insertBefore(wrapper, el);
      wrapper.appendChild(el);
    }
    let overlay = wrapper.querySelector(".md-disabled-overlay");
    if (!overlay) {
      overlay = createOverlay(el);
      wrapper.appendChild(overlay);
    }
    return overlay;
  }

  function setEnabled(enabled) {
    document.querySelectorAll('[name^="md_markdown"]').forEach(function (el) {
      const n = el.name || "";
      if (!/^md_markdown($|\[|__)/.test(n)) return;
      const overlay = ensureOverlayFor(el);
      if (enabled) {
        el.removeAttribute("disabled");
        el.classList.remove("disabled");
        overlay && overlay.classList.add("hidden");
      } else {
        el.setAttribute("disabled", "disabled");
        el.classList.add("disabled");
        overlay && overlay.classList.remove("hidden");
      }
    });
  }

  function init() {
    injectOverlayCSS();

    // create hidden fields for forms that contain markdown textareas (exact md_markdown only)
    document.querySelectorAll('[name^="md_markdown"]').forEach(function (el) {
      const n = el.name || "";
      if (!/^md_markdown($|\[|__)/.test(n)) return;
      const form = el.closest("form");
      if (form) ensureHiddenInForm(form);
    });

    // editors start disabled; overlay enables them on dblclick
    setEnabled(false);

    // ensure transient hidden field is present and set to '0' on submit unless enabled by overlay
    document.querySelectorAll("form").forEach(function (form) {
      // only attach to forms that contain the exact md_markdown field
      let hasMd = false;
      form.querySelectorAll('[name^="md_markdown"]').forEach(function (el) {
        const n = el.name || "";
        if (/^md_markdown($|\[|__)/.test(n)) hasMd = true;
      });
      if (!hasMd) return;
      form.addEventListener("submit", function () {
        const h = ensureHiddenInForm(form);
        if (h && h.value !== "1") h.value = "0";
      });
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
