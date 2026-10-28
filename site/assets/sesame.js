/* ============================================================================
   SESAME front page: the lock, the duplicator, and the bell on the counter.

   Vanilla, no framework, no network. Motion is gated twice: the <head> script
   adds .motion only when the system has no reduced-motion request, and
   motionOn() checks again. Without motion the page is already finished: keys
   cut, door open, every word in the DOM from the first paint. The buttons
   still work then; they just skip the walk to the door.

   Sections: 1. setup  2. toast  3. copy  4. the lock  5. the duplicator
             6. reveals  7. the bell and the magic words
   ========================================================================== */
(function () {
  "use strict";

  /* --------------------------------------------------------------- 1. setup */
  var root = document.documentElement;
  var reduce = window.matchMedia ? window.matchMedia("(prefers-reduced-motion: reduce)") : null;

  function motionOn() {
    return root.classList.contains("motion") && !(reduce && reduce.matches);
  }
  function clamp(v) { return v < 0 ? 0 : v > 1 ? 1 : v; }
  function seg(p, a, b) { return clamp((p - a) / (b - a)); }
  function ease(t) { return t < .5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2; }
  function lerp(a, b, t) { return a + (b - a) * t; }
  function $(sel, el) { return (el || document).querySelector(sel); }
  function $$(sel, el) { return Array.prototype.slice.call((el || document).querySelectorAll(sel)); }

  /* --------------------------------------------------------------- 2. toast */
  var toastEl = $(".toast");
  var toastTimer = null;
  function toast(msg) {
    if (!toastEl) return;
    clearTimeout(toastTimer);
    toastEl.textContent = msg;
    toastEl.classList.add("is-up");
    toastTimer = setTimeout(function () {
      toastEl.classList.remove("is-up");
      toastTimer = setTimeout(function () { toastEl.textContent = ""; }, 300);
    }, 2800);
  }

  /* ---------------------------------------------------------------- 3. copy */
  $$("[data-copy]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var text = btn.getAttribute("data-copy");
      function done(ok) {
        btn.textContent = ok ? "Cut!" : "Copy";
        toast(ok ? "Copied. Freshly cut." : "Copy didn't take. Select the line and copy it by hand.");
        setTimeout(function () { btn.textContent = "Copy"; }, 1600);
      }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); });
      } else {
        done(false);
      }
    });
  });

  /* ------------------------------------------------------------ 4. the lock */
  // Driver notes follow README.md ("Drivers", "The cas driver", "The gateway
  // driver"). Drivers that are not built yet show no config line.
  var DRIVERS = {
    fake: { line: "SESAME_DRIVER=fake", opens: true,
      note: "<strong>fake:</strong> local development and tests only. It refuses to boot in production, so this door only opens on your own machine." },
    cas: { line: "SESAME_DRIVER=cas", opens: true,
      note: "<strong>cas:</strong> CAS 2.0 by default. The CAS server sends the user back with a ticket; SESAME validates it once, then drops it from the address bar. The door opens." },
    gateway: { line: "SESAME_DRIVER=gateway", opens: true,
      note: "<strong>gateway:</strong> a signed JWT assertion from DRAWBRIDGE, in one header, from a trusted proxy. Never a bare header, and each assertion works once. The door opens." },
    saml: { line: null, opens: false,
      note: "<strong>saml:</strong> later, as a direct SAML service provider. The blank hangs on the board, but it isn't cut yet, so it won't turn." },
    oauth2: { line: null, opens: false,
      note: "<strong>oauth2:</strong> later, for JWT bearer tokens. Not cut yet, so it won't turn." }
  };

  var lock = $("[data-lock]");
  var lockTimers = [];
  function later(fn, ms) { lockTimers.push(setTimeout(fn, ms)); }

  function tryKey(name, quiet) {
    var d = DRIVERS[name];
    if (!lock || !d) return;
    lockTimers.forEach(clearTimeout);
    lockTimers = [];

    $$(".peg").forEach(function (b) {
      b.setAttribute("aria-pressed", b.getAttribute("data-driver") === name ? "true" : "false");
    });
    var line = $("[data-readout-line]", lock);
    var note = $("[data-readout-note]", lock);
    line.textContent = d.line || "not cut yet: coming later";
    line.classList.toggle("is-uncut", !d.line);
    if (!quiet) note.innerHTML = d.note;

    lock.setAttribute("data-driver", name);
    $("[data-lock-key]", lock).setAttribute("href", "#key-" + name);

    if (!motionOn()) {
      lock.classList.toggle("is-in", true);
      lock.classList.toggle("is-turned", d.opens);
      lock.classList.toggle("is-open", d.opens);
      lock.classList.remove("is-stuck");
      return;
    }
    lock.classList.remove("is-in", "is-turned", "is-open", "is-stuck");
    void lock.offsetWidth; // start from the closed door
    later(function () { lock.classList.add("is-in"); }, 380);
    if (d.opens) {
      later(function () { lock.classList.add("is-turned"); }, 900);
      later(function () { lock.classList.add("is-open"); }, 1300);
    } else {
      later(function () { lock.classList.add("is-stuck"); }, 900);
      later(function () { lock.classList.remove("is-stuck"); }, 1450);
    }
  }

  $$(".peg").forEach(function (b) {
    b.addEventListener("click", function () { tryKey(b.getAttribute("data-driver")); });
  });

  // With motion on, the shop shows you once: the door starts shut and the
  // cas key opens it as the board scrolls into view.
  if (lock && motionOn() && "IntersectionObserver" in window) {
    lock.classList.remove("is-in", "is-turned", "is-open");
    var lockSeen = new IntersectionObserver(function (entries) {
      if (entries[0].isIntersecting) {
        lockSeen.disconnect();
        setTimeout(function () { tryKey(lock.getAttribute("data-driver") || "cas", true); }, 250);
      }
    }, { threshold: 0.5 });
    lockSeen.observe(lock);
  }

  /* ------------------------------------------------------ 5. the duplicator */
  // The same numbers gen.py used to draw the final frame. Every cut key in
  // the shop carries one bitting: the lock's profile.
  var BITS = [3, 1, 4, 1, 5], PIN0 = 110, PITCH = 22, DSTEP = 3.2;
  var RX = 530, KY = 170, KS = 1.4;
  var FX = 430 - 252 * 0.55, FY = 558 - 49 * 0.55, FS = 0.55;

  function depthAt(x) {
    for (var i = 0; i < BITS.length; i++) {
      var cx = PIN0 + i * PITCH, d = BITS[i] * DSTEP;
      if (x >= cx - 8 && x <= cx - 3) return d * (x - (cx - 8)) / 5;
      if (x >= cx - 3 && x <= cx + 3) return d;
      if (x >= cx + 3 && x <= cx + 8) return d * ((cx + 8) - x) / 5;
    }
    return 0;
  }

  var dup = $("[data-dup]");
  if (dup && motionOn()) {
    var q = function (s) { return dup.querySelector(s); };
    var el = {
      cut: q("[data-cut]"), uncut: q("[data-uncut]"), type: q("[data-type]"),
      carriage: q("[data-carriage]"), tracer: q("[data-tracer]"), cutter: q("[data-cutter]"),
      wheel: q(".m-wheel"), chips: q(".m-chips"), key: q("[data-newkey]"), leaf: q("[data-leaf]"),
      spill: q("[data-spill]"), open1: q("[data-open]"), open2: q("[data-open2]"),
      steps: $$(".step", dup)
    };
    var lastP = -1, spinTimer = null, ticking = false;

    var render = function (p) {
      var c = seg(p, 0, .08), k = seg(p, .1, .55), l = seg(p, .55, .62);
      var t = ease(seg(p, .62, .82)), i = seg(p, .82, .87), u = seg(p, .87, .92), o = seg(p, .92, 1);

      var local = 98 + k * 154;
      el.cut.setAttribute("width", local.toFixed(1));
      el.uncut.setAttribute("x", local.toFixed(1));
      var dy = (KS * depthAt(local)).toFixed(1);
      el.tracer.setAttribute("transform", "translate(0," + dy + ")");
      el.cutter.setAttribute("transform", "translate(0," + dy + ")");
      el.carriage.setAttribute("transform", "translate(" + (k * 154 * KS).toFixed(1) + "," + (-l * 22).toFixed(1) + ")");
      el.type.setAttribute("width", (k * 380).toFixed(1));

      var x = lerp(RX, FX - 30, t) + 30 * i;
      var y = lerp(KY + (1 - c) * 60, FY, t);
      var s = lerp(KS, FS, t);
      el.key.setAttribute("transform",
        "translate(" + x.toFixed(1) + "," + y.toFixed(1) + ") scale(" + s.toFixed(3) + ")" +
        " translate(0,45) scale(1," + (1 - .7 * u).toFixed(3) + ") translate(0,-45)");
      el.key.setAttribute("opacity", (c * (1 - o)).toFixed(3));

      el.leaf.setAttribute("transform", "translate(600,0) scale(" + (1 - .88 * o).toFixed(3) + ",1) translate(-600,0)");
      el.spill.setAttribute("opacity", o.toFixed(3));
      el.open1.setAttribute("opacity", seg(o, .2, .8).toFixed(3));
      el.open2.setAttribute("opacity", seg(o, .5, 1).toFixed(3));

      var now = p < .1 ? 0 : p < .55 ? 1 : p < .87 ? 2 : 3;
      el.steps.forEach(function (st, n) { st.classList.toggle("is-now", n === now); });

      var cutting = k > 0 && k < 1;
      if (cutting && Math.abs(p - lastP) > .0005) {
        el.wheel.classList.add("is-spinning");
        el.chips.classList.add("is-cutting");
        clearTimeout(spinTimer);
        spinTimer = setTimeout(function () {
          el.wheel.classList.remove("is-spinning");
          el.chips.classList.remove("is-cutting");
        }, 220);
      } else if (!cutting) {
        el.wheel.classList.remove("is-spinning");
        el.chips.classList.remove("is-cutting");
      }
      lastP = p;
    };

    var progress = function () {
      var r = dup.getBoundingClientRect();
      var total = dup.offsetHeight - window.innerHeight;
      return total > 0 ? clamp(-r.top / total) : 1;
    };
    var onScroll = function () {
      if (ticking) return;
      ticking = true;
      requestAnimationFrame(function () { ticking = false; if (motionOn()) render(progress()); });
    };
    render(progress());
    window.addEventListener("scroll", onScroll, { passive: true });
    window.addEventListener("resize", onScroll);

    // Reduced motion switched on mid-visit: finish the job at once.
    if (reduce && reduce.addEventListener) {
      reduce.addEventListener("change", function (e) {
        if (e.matches) { root.classList.remove("motion"); render(1); el.steps.forEach(function (st) { st.classList.remove("is-now"); }); }
      });
    }
  }

  /* ------------------------------------------------------------- 6. reveals */
  if (motionOn() && "IntersectionObserver" in window) {
    var groups = [".ticket", ".rules li", ".ring__tag", ".hand__stop", ".testbench", ".xref", ".hours", ".install"];
    var targets = [];
    groups.forEach(function (sel) {
      $$(sel).forEach(function (node, n) {
        node.classList.add("reveal");
        node.style.setProperty("--i", String(n % 8));
        targets.push(node);
      });
    });
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) { e.target.classList.add("is-seen"); io.unobserve(e.target); }
      });
    }, { threshold: 0.12, rootMargin: "0px 0px -6% 0px" });
    targets.forEach(function (t) { io.observe(t); });
    if (reduce && reduce.addEventListener) {
      reduce.addEventListener("change", function (e) {
        if (e.matches) targets.forEach(function (t) { t.classList.remove("reveal"); });
      });
    }
  }

  /* --------------------------------------- 7. the bell and the magic words */
  var bell = $("[data-bell]");
  var DINGS = [
    "Ding. Be right with you.",
    "Ding. Keys cut while you wait. Sessions kept by the app.",
    "Ding. No appointment needed. No ticket kept, either."
  ];
  var dingN = 0;
  if (bell) {
    bell.addEventListener("click", function () {
      bell.classList.remove("is-ringing");
      void bell.offsetWidth;
      bell.classList.add("is-ringing");
      toast(DINGS[dingN++ % DINGS.length]);
    });
  }

  // Type the magic words anywhere on the page. Shh.
  var heard = "";
  document.addEventListener("keydown", function (e) {
    if (e.ctrlKey || e.metaKey || e.altKey || !e.key || e.key.length !== 1) return;
    heard = (heard + e.key.toLowerCase()).replace(/[^a-z]/g, "").slice(-10);
    if (heard === "opensesame") {
      heard = "";
      toast("Nobody knocks: the door is already open.");
      var door = $(".hero__door");
      if (door && motionOn()) {
        door.classList.remove("is-magic");
        void door.offsetWidth;
        door.classList.add("is-magic");
      }
    }
  });
})();
