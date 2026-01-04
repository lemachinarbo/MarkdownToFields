(function () {
  console.log("markdown-admin: parsed");
  var runOnReady = function () {
    console.log("markdown-admin: runOnReady");
    // Hidden transient field name used to indicate overlay-enabled editing
    var hiddenSelectorName = "md_markdown_lock_transient_value";

    var ensureHiddenInForm = function (form) {
      try {
        if (!form || !form.querySelector) return null;
        var h = form.querySelector('[name="' + hiddenSelectorName + '"]');
        if (!h) {
          h = document.createElement("input");
          h.type = "hidden";
          h.name = hiddenSelectorName;
          h.value = "0";
          form.appendChild(h);
          console.log("markdown-admin: hidden input created in form");
        }
        return h;
      } catch (e) {
        console.error("markdown-admin: ensureHiddenInForm error", e);
        return null;
      }
    };

    // No checkbox is needed; overlay handles transient enable and hidden input is created per-form when needed.

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

          // Double-clicking the overlay should enable the editor: set transient in the form and enable editors.
          overlay.addEventListener("dblclick", function (e) {
            e.preventDefault();
            try {
              var form = el.closest("form");
              // mark the form as having transient raw-edit enabled for this save
              var h = ensureHiddenInForm(form);
              if (h) h.value = "1";
              // enable editors (globally for this page)
              setDisabled(true);
              try {
                el.focus();
              } catch (err) {}
              console.log("markdown-admin: overlay dblclick - enabled editor");
            } catch (err) {
              console.error(
                "markdown-admin: overlay dblclick handler error",
                err
              );
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

    // Initialization: ensure hidden inputs exist in each form with md_markdown fields and default to 0
    document.querySelectorAll('[name^="md_markdown"]').forEach(function (el) {
      try {
        var form = el.closest("form");
        if (form) ensureHiddenInForm(form);
      } catch (e) {}
    });

    // Start with editors disabled by default (overlay will enable when double-clicked)
    console.log(
      "markdown-admin: initial setDisabled call (disabled by default)"
    );
    setDisabled(false);

    // Ensure each form with an md_markdown field sets the hidden transient value on submit (safety)
    document.querySelectorAll("form").forEach(function (form) {
      try {
        if (!form.querySelector('[name^="md_markdown"]')) return;
        form.addEventListener("submit", function () {
          var h = ensureHiddenInForm(form);
          if (h && h.value !== "1") h.value = "0";
          console.log(
            "markdown-admin: form submit - transient value",
            h ? h.value : "(none)"
          );
        });
      } catch (e) {
        // ignore
      }
    });
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", runOnReady);
  } else {
    runOnReady();
  }
})();
