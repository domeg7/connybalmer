(function () {
  "use strict";

  /* ---------- Year in footer ---------- */
  var yearEl = document.getElementById("year");
  if (yearEl) yearEl.textContent = new Date().getFullYear();

  /* ---------- Sticky header shadow on scroll ---------- */
  var header = document.querySelector(".site-header");
  var lastScroll = -1;
  function onScroll() {
    var y = window.scrollY;
    if (y === lastScroll) return;
    lastScroll = y;
    if (header) header.classList.toggle("is-scrolled", y > 8);
  }
  window.addEventListener("scroll", onScroll, { passive: true });
  onScroll();

  /* ---------- Mobile nav ---------- */
  var navToggle = document.getElementById("navToggle");
  var navMobile = document.getElementById("navMobile");
  function closeMobileNav() {
    if (!navToggle || !navMobile) return;
    navToggle.setAttribute("aria-expanded", "false");
    navMobile.classList.remove("is-open");
    navMobile.setAttribute("aria-hidden", "true");
  }
  if (navToggle && navMobile) {
    navToggle.addEventListener("click", function () {
      var isOpen = navToggle.getAttribute("aria-expanded") === "true";
      navToggle.setAttribute("aria-expanded", String(!isOpen));
      navMobile.classList.toggle("is-open", !isOpen);
      navMobile.setAttribute("aria-hidden", String(isOpen));
    });
    navMobile.querySelectorAll("a").forEach(function (a) {
      a.addEventListener("click", closeMobileNav);
    });
  }

  /* ---------- Smooth scroll for in-page anchors ---------- */
  document.querySelectorAll('a[data-scroll]').forEach(function (link) {
    link.addEventListener("click", function (e) {
      var href = link.getAttribute("href");
      if (!href || href.charAt(0) !== "#") return;
      var target = document.querySelector(href);
      if (!target) return;
      e.preventDefault();
      var headerOffset = (header ? header.offsetHeight : 0) + 12;
      var rect = target.getBoundingClientRect();
      var top = rect.top + window.scrollY - headerOffset;
      window.scrollTo({ top: top, behavior: "smooth" });
    });
  });

  /* ---------- Toast for not-yet-implemented pages ---------- */
  var toast = document.getElementById("toast");
  var toastTimer = null;
  function showToast(pageName) {
    if (!toast) return;
    toast.innerHTML =
      '<span class="toast__icon" aria-hidden="true">' +
      '<svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M11 7h2v6h-2zm0 8h2v2h-2zM12 2C6.48 2 2 6.48 2 12s4.48 10 10 10s10-4.48 10-10S17.52 2 12 2z"/></svg>' +
      "</span>" +
      '<span><strong>„' + pageName + '" ist noch nicht verfügbar.</strong><br>' +
      'Diese Seite wird gerade neu gestaltet.</span>';
    toast.classList.add("is-visible");
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(function () {
      toast.classList.remove("is-visible");
    }, 4200);
  }

  document.querySelectorAll('a[data-page]').forEach(function (link) {
    link.addEventListener("click", function (e) {
      e.preventDefault();
      var page = link.getAttribute("data-page") || "Diese Seite";
      showToast(page);
    });
  });

  /* ---------- Reveal on scroll ---------- */
  var revealTargets = document.querySelectorAll(
    ".hero__content, .hero__media, .about__copy, .about__card, .service-card, .services__cta, .courses__copy, .courses__media, .gallery__item, .contact__intro, .contact-card, .section__head"
  );
  revealTargets.forEach(function (el) { el.classList.add("reveal"); });

  if ("IntersectionObserver" in window) {
    var io = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add("is-visible");
            io.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.12, rootMargin: "0px 0px -40px 0px" }
    );
    revealTargets.forEach(function (el) { io.observe(el); });
  } else {
    revealTargets.forEach(function (el) { el.classList.add("is-visible"); });
  }

  /* ---------- Vacation overlay (CMS-driven) ---------- */
  var MONTHS_DE = [
    "Januar", "Februar", "März", "April", "Mai", "Juni",
    "Juli", "August", "September", "Oktober", "November", "Dezember"
  ];

  function parseIsoDate(s) {
    if (typeof s !== "string") return null;
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s);
    if (!m) return null;
    return { y: +m[1], m: +m[2], d: +m[3] };
  }

  function todayIso() {
    var n = new Date();
    var mm = String(n.getMonth() + 1).padStart(2, "0");
    var dd = String(n.getDate()).padStart(2, "0");
    return n.getFullYear() + "-" + mm + "-" + dd;
  }

  function formatRangeDe(fromIso, toIso) {
    var f = parseIsoDate(fromIso);
    var t = parseIsoDate(toIso);
    if (!f || !t) return "";
    if (f.y === t.y && f.m === t.m) {
      return f.d + ". – " + t.d + ". " + MONTHS_DE[t.m - 1] + " " + t.y;
    }
    if (f.y === t.y) {
      return f.d + ". " + MONTHS_DE[f.m - 1] + " – " + t.d + ". " + MONTHS_DE[t.m - 1] + " " + t.y;
    }
    return f.d + ". " + MONTHS_DE[f.m - 1] + " " + f.y + " – " + t.d + ". " + MONTHS_DE[t.m - 1] + " " + t.y;
  }

  function buildOverlay(entry) {
    var overlay = document.createElement("div");
    overlay.className = "vacation-overlay";
    overlay.setAttribute("role", "dialog");
    overlay.setAttribute("aria-modal", "true");
    overlay.setAttribute("aria-labelledby", "vacationOverlayTitle");

    var backdrop = document.createElement("div");
    backdrop.className = "vacation-overlay__backdrop";

    var panel = document.createElement("div");
    panel.className = "vacation-overlay__panel";

    var closeBtn = document.createElement("button");
    closeBtn.type = "button";
    closeBtn.className = "vacation-overlay__close";
    closeBtn.setAttribute("aria-label", "Schliessen");
    closeBtn.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>';

    var icon = document.createElement("div");
    icon.className = "vacation-overlay__icon";
    icon.setAttribute("aria-hidden", "true");
    icon.innerHTML = '<svg viewBox="0 0 24 24" width="32" height="32"><path fill="currentColor" d="M19 4h-1V2h-2v2H8V2H6v2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2Zm0 16H5V10h14v10ZM5 8V6h14v2H5Z"/></svg>';

    var title = document.createElement("h2");
    title.id = "vacationOverlayTitle";
    title.className = "vacation-overlay__title";
    title.textContent = "Ferienabwesenheit";

    var dates = document.createElement("p");
    dates.className = "vacation-overlay__dates";
    dates.textContent = formatRangeDe(entry.from, entry.to);

    panel.appendChild(closeBtn);
    panel.appendChild(icon);
    panel.appendChild(title);
    panel.appendChild(dates);

    if (entry.message) {
      var msg = document.createElement("p");
      msg.className = "vacation-overlay__message";
      msg.textContent = entry.message;
      panel.appendChild(msg);
    }

    var ok = document.createElement("button");
    ok.type = "button";
    ok.className = "btn btn--primary vacation-overlay__ok";
    ok.textContent = "Verstanden";
    panel.appendChild(ok);

    overlay.appendChild(backdrop);
    overlay.appendChild(panel);
    return overlay;
  }

  function showVacationOverlay(entry) {
    var overlay = buildOverlay(entry);
    document.body.appendChild(overlay);
    document.documentElement.style.overflow = "hidden";

    requestAnimationFrame(function () {
      overlay.classList.add("is-visible");
    });

    function close() {
      overlay.classList.remove("is-visible");
      document.documentElement.style.overflow = "";
      try {
        sessionStorage.setItem("vacationOverlayDismissed", entry.from + "_" + entry.to);
      } catch (e) {}
      setTimeout(function () {
        if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
      }, 280);
      document.removeEventListener("keydown", onKey);
    }
    function onKey(e) {
      if (e.key === "Escape") close();
    }
    overlay.querySelector(".vacation-overlay__close").addEventListener("click", close);
    overlay.querySelector(".vacation-overlay__ok").addEventListener("click", close);
    overlay.querySelector(".vacation-overlay__backdrop").addEventListener("click", close);
    document.addEventListener("keydown", onKey);
  }

  function initVacationOverlay() {
    if (!("fetch" in window)) return;
    var dismissed = "";
    try { dismissed = sessionStorage.getItem("vacationOverlayDismissed") || ""; } catch (e) {}

    fetch("vacations.json", { cache: "no-cache" })
      .then(function (r) { return r.ok ? r.json() : []; })
      .then(function (data) {
        if (!Array.isArray(data) || data.length === 0) return;
        var today = todayIso();
        var active = null;
        for (var i = 0; i < data.length; i++) {
          var v = data[i];
          if (!v || !v.from || !v.to) continue;
          if (v.from <= today && today <= v.to) { active = v; break; }
        }
        if (!active) return;
        if (dismissed === active.from + "_" + active.to) return;
        showVacationOverlay(active);
      })
      .catch(function () {});
  }

  initVacationOverlay();
})();
