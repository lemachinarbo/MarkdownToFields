(function () {
  console.log("markdown-admin: parsed");
  var runOnReady = function () {
    console.log("markdown-admin: runOnReady");
    var cb =
      document.getElementById("md_markdown_lock_transient_cb") ||
      document.querySelector('[name="md_markdown_lock_transient"]');
    var hidden = document.querySelector(
      '[name="md_markdown_lock_transient_value"]'
    );

    var ensureHidden = function (cb) {
      try {
        var h = document.querySelector(
          '[name="md_markdown_lock_transient_value"]'
        );
        if (!h && cb && cb.parentNode) {
          h = document.createElement("input");
          h.type = "hidden";
          h.name = "md_markdown_lock_transient_value";
          cb.parentNode.insertBefore(h, cb.nextSibling);
          console.log("markdown-admin: hidden input created");
        }
        return h;
      } catch (e) {
        console.error("markdown-admin: ensureHidden error", e);
        return null;
      }
    };

    // Ensure a hidden input exists next to the checkbox so server can reliably read the transient flag
    hidden = hidden || ensureHidden(cb);
    if (cb) console.log("markdown-admin: checkbox found");
    else console.warn("markdown-admin: checkbox not found");

    // Inject overlay CSS once
    var _injectOverlayCSS = function () {
      console.log("markdown-admin: injectOverlayCSS called");
      if (document.getElementById("md-disabled-overlay-style")) {
        console.log("markdown-admin: overlay CSS already present");
        return;
      }
      var s = document.createElement("style");
      s.id = "md-disabled-overlay-style";
      s.innerHTML =
        "\n.md-overlay-wrapper{position:relative;display:block}\n.md-disabled-overlay{position:absolute;inset:0;background:rgba(255,255,255,0.8);border-radius:4px;border:1px solid rgba(0,0,0,0.06);pointer-events:auto;display:flex;flex-direction:column;gap:8px;align-items:stretch;justify-content:flex-start;color:#556;font-size:12px;font-weight:600;padding:0}\n.md-overlay-badge{background:#fff3bf;color:#6b4a00;padding:16px 20px;border-top-left-radius:4px;border-top-right-radius:4px;font-size:16px;line-height:1.2;font-weight:700;box-shadow:0 1px 0 rgba(0,0,0,0.04);border:1px solid rgba(0,0,0,0.06);width:100%;box-sizing:border-box;text-align:left}\n.md-overlay-label{font-size:11px;color:#556;opacity:0.9;padding:10px 16px;text-align:left}\n.md-disabled-overlay.hidden{display:none}\n";
      document.head.appendChild(s);
      console.log("markdown-admin: overlay CSS injected");
    };

    var ensureOverlayFor = function (el) {
      try {
        var wrapper = el.closest(".md-overlay-wrapper");
        if (!wrapper) {
          wrapper = document.createElement("div");
          wrapper.className = "md-overlay-wrapper";
          el.parentNode.insertBefore(wrapper, el);
          wrapper.appendChild(el);
        }
        var overlay = wrapper.querySelector(".md-disabled-overlay");
        if (!overlay) {
          overlay = document.createElement("div");
          overlay.className = "md-disabled-overlay";

          // Add a yellow info badge with a short instruction
          var badge = document.createElement("div");
          badge.className = "md-overlay-badge";
          badge.textContent =
            "Editor disabled: Double-click the field to edit the Markdown content.";
          overlay.appendChild(badge);

          // make it obviously interactive for double-click
          overlay.style.cursor = "pointer";
          // ensure overlay receives pointer events so dblclick works
          overlay.style.pointerEvents = "auto";

          // Double-clicking the overlay should enable the editor via the same checkbox change path.
          // Reuse existing checkbox state handler by checking the checkbox and dispatching a change event.
          overlay.addEventListener("dblclick", function (e) {
            e.preventDefault();
            if (!cb) return;
            if (!cb.checked) {
              cb.checked = true;
              cb.dispatchEvent(new Event("change", { bubbles: true }));
            }
          });

          wrapper.appendChild(overlay);
        }
        return overlay;
      } catch (e) {
        console.error("markdown-admin: ensureOverlayFor error", e);
        return null;
      }
    };

    var setDisabled = function (state) {
      _injectOverlayCSS();
      document.querySelectorAll('[name^="md_markdown"]').forEach(function (el) {
        try {
          var n = el.name || "";
          if (!/^md_markdown($|\[)/.test(n)) return;
          var overlay = ensureOverlayFor(el);
          if (state) {
            el.removeAttribute("disabled");
            el.classList.remove("disabled");
            if (overlay) overlay.classList.add("hidden");
          } else {
            el.setAttribute("disabled", "disabled");
            el.classList.add("disabled");
            if (overlay) overlay.classList.remove("hidden");
          }
        } catch (e) {
          console.error("markdown-admin: setDisabled element error", e);
        }
      });
    };

    if (!cb) {
      console.warn("markdown-admin: checkbox not found, aborting");
      return;
    }
    try {
      console.log("markdown-admin: preparing checkbox for interaction");
      cb.removeAttribute("disabled");
      cb.removeAttribute("readonly");
      cb.tabIndex = 0;
    } catch (e) {
      console.error("markdown-admin: error prepping checkbox", e);
    }

    var setVal = function () {
      var val = cb.checked ? "1" : "0";
      hidden = hidden || ensureHidden(cb);
      if (hidden) hidden.value = val;
      console.log("markdown-admin: setVal hidden set to", val);
    };
    cb.addEventListener("change", function () {
      console.log("markdown-admin: checkbox changed to", cb.checked);
      setDisabled(cb.checked);
      setVal();
    });
    console.log("markdown-admin: initial setDisabled call with", cb.checked);
    setDisabled(cb.checked);
    setVal();

    var f = cb.closest("form");
    if (f) {
      f.addEventListener("submit", function () {
        console.log("markdown-admin: form submit - setting hidden value");
        setVal();
      });
    }
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", runOnReady);
  } else {
    runOnReady();
  }
})();
