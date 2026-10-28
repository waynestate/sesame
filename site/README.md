# SESAME front page

A static page: `index.html`, `assets/sesame.css`, `assets/sesame.js`. No build step. Serve the `site/` folder from
anywhere (Citadel, any web server, or `python -m http.server` inside `site/` for a look; fonts are blocked over
`file://` in some browsers).

- type: **Bungee** 400 (SIL OFL 1.1, self-hosted in `assets/fonts/` from `@fontsource`, licence in `OFL-bungee.txt`)
  for the shop signs; Lato from WayneUI for body text; the system monospace for commands, key tags and config lines.
- world: a key-cutting shop. A cream shop floor, a perforated pegboard, brass and nickel keys, a green service
  counter with a bell, a job ticket for every doorway, shop rules on an enamel sign, and the opening hours (the
  session clocks) hung on a string. Green `#0C5449`, gold `#FFCC33`, cream `#F4F1EA` and Slate Ink `#314842` come
  from the board in `brand/`.
- base: WayneUI 2.0.8, copied from its static path into `assets/wayne-ui/` (tokens, Lato, buttons, table, skip link,
  sr-only), then re-dressed in `assets/sesame.css`.
- the keys: every key on the page is drawn in inline SVG. They all carry one bitting, the lock's profile (your app's
  `findOrImportUser(Identity)`); only the heads differ, one per driver. `saml` and `oauth2` hang as uncut blanks.
- magic: **the duplicator.** Scroll, and a gateway blank is clamped beside the cas key, the tracer follows the cas
  cut while the cutter copies it, and the cut types `SESAME_DRIVER=gateway` into the slot as it goes. The new key
  travels to the same door, turns, and the door opens: ab1234 is in. Open sesame.
- the lock: pick a key on the pegboard and it walks to the door. Cut keys turn and the door opens; uncut blanks
  jiggle and stay out, and show no config line, because those drivers are not built yet.
- marks: `brand/` is SESAME's (generated with ChatGPT, never redrawn). `assets/marks/` holds the other stack parts'
  marks, copied from the sibling sites; WayneUI uses its own reversed mark on green.
- reduced motion: the page is the finished shop, every word present at first paint: every key cut, the duplicator
  done, the door open. The pegboard still works, instantly, and the note says what happened.
- shh: ring the bell, or type the magic words.

Screenshots are in `screenshots/` (desktop 1440, mobile 390, reduced motion, a motion GIF, and per-section mobile
crops in `screenshots/sections/`).

Keys cut while you wait. Sessions kept by the app.
