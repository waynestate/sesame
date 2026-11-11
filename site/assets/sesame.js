/* ============================================================================
   SESAME front page: the lock, the duplicator, and the bell on the counter.

   Vanilla, no framework, no network. Motion is gated twice: the <head> script
   adds .motion only when the system has no reduced-motion request, and
   motionOn() checks again. Without motion the page is already finished: keys
   cut, door open, every word in the DOM from the first paint. The buttons
   still work then; they just skip the walk to the door.

   Sections: 1. setup  2. toast  3. copy  4. the lock  5. the duplicator
             6. reveals  7. the door tally  8. the bell and the magic words
             9. the lock inspection (php artisan sesame:check)
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
  // driver", "oauth2 with Entra", "The oidc driver (Entra sign-in)", "The saml
  // driver"). A driver that is not built yet gets line: null and opens: false.
  var DRIVERS = {
    fake: { line: "SESAME_DRIVER=fake", opens: true,
      note: "<strong>fake:</strong> local development and tests only. It refuses to boot in production, so this door only opens on your own machine." },
    cas: { line: "SESAME_DRIVER=cas", opens: true,
      note: "<strong>cas:</strong> CAS 2.0 by default. The CAS server sends the user back with a ticket; SESAME validates it once, then drops it from the address bar. The door opens." },
    gateway: { line: "SESAME_DRIVER=gateway", opens: true,
      note: "<strong>gateway:</strong> a signed JWT assertion from DRAWBRIDGE, in one header, from a trusted proxy. Never a bare header, and each assertion works once. The door opens." },
    saml: { line: "SESAME_DRIVER=saml", opens: true,
      note: "<strong>saml:</strong> opt-in browser sign-in as a SAML 2.0 service provider, for a Shibboleth-style IdP you name. The assertion must be signed, each one works once, and the IdP's cross-site POST waits on a one-time handle until your session cookie comes back. The door opens." },
    oauth2: { line: "SESAME_DRIVER=oauth2", opens: true,
      note: "<strong>oauth2:</strong> a JWT bearer token, checked against the issuer's key set (Entra first). For APIs. The door opens." },
    oidc: { line: "SESAME_DRIVER=oidc", opens: true,
      note: "<strong>oidc:</strong> browser sign-in with OpenID Connect, Microsoft Entra ID first. Code flow with PKCE; the ID token is checked against the issuer's key set, and state and nonce work once. The only key that brings its own ID. The door opens." }
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
    later(function () { lock.classList.add("is-in"); }, 420);
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
    var groups = [".ticket", ".rules li", ".ring__tag", ".hand__stop", ".testbench", ".xref", ".hours", ".tally", ".install"];
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

  /* ------------------------------------------------------- 7. the door tally */
  // Shops count who comes through with a hand counter. SESAME does too, when
  // asked: by driver and outcome, never by name. Four wheels, so it rolls over
  // at 10,000 like the real thing (rate() would forgive it; Grafana would not).
  var tally = $("[data-tally]");
  var plunger = tally ? $("[data-tally-click]", tally) : null;
  if (tally && plunger) {
    var strips = $$(".tally__strip", tally);
    var said = $("[data-tally-said]", tally);
    var count = strips.reduce(function (n, s) {
      return n * 10 + (parseInt(s.style.getPropertyValue("--d"), 10) || 0);
    }, 0);
    var CLICKS = [
      "Click. {n} through the door. No names on the tally.",
      "Click. Counted by driver and outcome. Never by face.",
      "Click. {n}. HELMET's /metrics will hear about this one.",
      "Click. A turn of the key, not whose hand turned it."
    ];
    var clickN = 0;
    plunger.addEventListener("click", function () {
      count = (count + 1) % 10000;
      var digits = ("000" + count).slice(-4);
      strips.forEach(function (s, n) { s.style.setProperty("--d", digits.charAt(n)); });
      if (said) said.textContent = "The counter reads " + count + ".";
      tally.classList.add("is-counting");
      if (motionOn()) {
        [[plunger, "is-pressed"], [tally, "is-jolted"]].forEach(function (p) {
          p[0].classList.remove(p[1]);
          void p[0].offsetWidth;
          p[0].classList.add(p[1]);
        });
      }
      toast(count === 0
        ? "Click. Rolled over to 0000. Four wheels was always optimistic."
        : CLICKS[clickN++ % CLICKS.length].replace("{n}", count.toLocaleString("en-US")));
    });
  }

  /* --------------------------------------- 8. the bell and the magic words */
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

  /* ------------------------------------------------- 9. the lock inspection */
  // The same rules and the same words as php artisan sesame:check
  // (src/Laravel/Console/CheckCommand.php), for a production app on the cas
  // driver. Only the four switches on the plate change; the other lines are
  // what a sound app gets. FrontPageCheckTest keeps these texts honest.
  var bench = $("[data-inspect]");
  var plate = bench ? $("[data-inspect-plate]", bench) : null;
  var slip = bench ? $(".slip", bench) : null;
  if (bench && plate && slip) {
    var linesEl = $("[data-inspect-lines]", bench);
    var sumEl = $("[data-inspect-sum]", bench);
    var exitEl = $("[data-inspect-exit]", bench);
    var stampEl = $("[data-inspect-stamp]", bench);
    var saidEl = $("[data-inspect-said]", bench);
    var STAMPS = { ok: "Cleared to open", warn: "Open, with notes", fail: "Do not open" };

    var inspect = function (s) {
      var driver = s.driver === "array"
        ? ["fail", "Session driver: array ends every session with the request, so a sign-in never sticks."]
        : s.driver === "cookie"
          ? ["warn", "Session driver: cookie keeps the whole session in the browser; prefer file, database or redis."]
          : ["ok", "Session driver: " + s.driver + "."];
      var sameSite = s.same_site === "strict"
        ? ["fail", "Session cookie: SameSite=Strict drops the cookie on the identity provider's return, so every sign-in fails; use lax."]
        : s.same_site === "none"
          ? ["warn", "Session cookie: SameSite=None sends the cookie on cross-site requests; use lax."]
          : ["ok", "Session cookie: SameSite=Lax."];
      var secure = s.secure === "null"
        ? ["warn", "Session cookie: Secure follows each request's scheme; set SESSION_SECURE_COOKIE=true on an https app."]
        : s.secure === "false"
          ? ["fail", "Session cookie: session.secure is false in production; set SESSION_SECURE_COOKIE=true."]
          : ["ok", "Session cookie: Secure."];
      var lifetime = s.lifetime === "30"
        ? ["warn", "Session lifetime: Laravel drops a quiet session after 30 minutes, before SESAME's 120 minute idle timeout."]
        : ["ok", "Session lifetime: 120 minutes, SESAME's idle timeout is 120."];
      return [
        ["ok", "Driver: cas is configured."],
        ["ok", "User resolver: set."],
        driver,
        ["ok", "Session cookie: HttpOnly."],
        sameSite,
        secure,
        lifetime
      ];
    };

    var read = function () {
      var s = {};
      ["same_site", "secure", "driver", "lifetime"].forEach(function (name) {
        var on = plate.querySelector("input[name='" + name + "']:checked");
        s[name] = on ? on.value : "";
      });
      return s;
    };

    var last = inspect(read());
    var print = function (said) {
      var lines = inspect(read());
      var n = { ok: 0, warn: 0, fail: 0 };
      linesEl.textContent = "";
      lines.forEach(function (line, i) {
        n[line[0]] += 1;
        var li = document.createElement("li");
        li.setAttribute("data-v", line[0]);
        li.style.setProperty("--n", String(i));
        if (last[i][1] !== line[1]) li.className = "is-changed";
        var v = document.createElement("span");
        v.className = "slip__v";
        v.textContent = "[" + line[0].toUpperCase() + "]";
        var t = document.createElement("span");
        t.textContent = line[1];
        li.append(v, " ", t);
        linesEl.append(li);
      });
      last = lines;
      var verdict = n.fail ? "fail" : n.warn ? "warn" : "ok";
      var sum = n.ok + " ok, " + n.warn + (n.warn === 1 ? " warning, " : " warnings, ") +
        n.fail + (n.fail === 1 ? " failure." : " failures.");
      sumEl.textContent = sum;
      exitEl.textContent = "exit " + (n.fail ? 1 : 0);
      stampEl.setAttribute("data-v", verdict);
      stampEl.textContent = STAMPS[verdict];
      if (said && saidEl) {
        saidEl.textContent = "Reprinted: " + sum + " Exit code " + (n.fail ? 1 : 0) + ". " + STAMPS[verdict] + ".";
      }
      if (motionOn()) {
        slip.classList.remove("is-printing", "is-jolted", "is-waiting");
        void slip.offsetWidth;
        slip.classList.add("is-printing");
        if (verdict === "fail") slip.classList.add("is-jolted");
      }
    };

    plate.hidden = false;
    plate.addEventListener("change", function () { print(true); });

    // With motion on, the slip feeds out once when the bench comes into view.
    if (motionOn() && "IntersectionObserver" in window) {
      slip.classList.add("is-waiting");
      var slipSeen = new IntersectionObserver(function (entries) {
        if (entries.some(function (e) { return e.isIntersecting; })) {
          slipSeen.disconnect();
          print(false);
        }
      }, { threshold: 0.35 });
      slipSeen.observe(slip);
    }
  }
})();
