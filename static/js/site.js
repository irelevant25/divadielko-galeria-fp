/* Divadielko Galéria — správanie stránky (bez závislostí). */
(function () {
  'use strict';

  var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // ── Navigácia ─────────────────────────────────────────────────────────────

  var toggle = document.querySelector('.topnav__toggle');
  var list = document.getElementById('topnav-list');

  function setMenu(open) {
    if (!toggle || !list) return;
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    list.classList.toggle('is-open', open);
  }

  if (toggle) {
    toggle.addEventListener('click', function () {
      setMenu(toggle.getAttribute('aria-expanded') !== 'true');
    });
    list.addEventListener('click', function (e) {
      if (e.target.closest('a')) setMenu(false);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
        setMenu(false);
        toggle.focus();
      }
    });
  }

  // Zvýraznenie sekcie v menu: aktívna je posledná sekcia, ktorej začiatok už došiel
  // pod hornú lištu (tam sa zastaví aj po kliknutí v menu — funguje to rovnako pre
  // krátke aj dlhé sekcie); na úplnom konci stránky posledná sekcia.
  var navLinks = document.querySelectorAll('[data-nav]');
  var navSections = Array.prototype.map.call(navLinks, function (a) { return document.getElementById(a.getAttribute('data-nav')); })
    .filter(Boolean);
  var topbar = document.querySelector('.topbar');

  function spy() {
    var line = (topbar ? topbar.offsetHeight : 0) + 40;
    var current = navSections[0].id;
    navSections.forEach(function (section) {
      if (section.getBoundingClientRect().top <= line) current = section.id;
    });
    if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4) {
      current = navSections[navSections.length - 1].id;
    }
    navLinks.forEach(function (a) { a.classList.toggle('is-active', a.getAttribute('data-nav') === current); });
  }

  if (navSections.length) {
    var spyTicking = false;
    window.addEventListener('scroll', function () {
      if (spyTicking) return;
      spyTicking = true;
      window.requestAnimationFrame(function () { spyTicking = false; spy(); });
    }, { passive: true });
    window.addEventListener('resize', spy);
    spy();
  }

  // ── Karusely ──────────────────────────────────────────────────────────────
  // Položky sú v mriežke po stĺpcoch; koľko stĺpcov (--cols) a riadkov (--rows)
  // má jedna strana, určuje CSS podľa šírky. Tu sa dopočítajú strany, bodky
  // (pri mnohých stranách počítadlo „3 / 17") a šípky. Posúva sa aj prstom.
  // Posledná strana je zarovnaná doprava — vždy je plná (posunie sa len o toľko,
  // koľko chýba), nie pár položiek vľavo a prázdne miesto. Keď sa všetko zmestí
  // na jednu stranu, položky sú v strede.

  var MAX_DOTS = 12;

  function initCarousel(root) {
    var track = root.querySelector('.carousel__track');
    var nav = root.querySelector('.carousel__nav');
    var dotsBox = root.querySelector('.carousel__dots');
    var count = root.querySelector('.carousel__count');
    var prev = root.querySelector('[data-carousel-prev]');
    var next = root.querySelector('[data-carousel-next]');
    var pageLabel = root.getAttribute('data-page-label') || '%d';
    var items = Array.prototype.filter.call(track.children, function (el) { return el.classList.contains('carousel__item'); });
    var dots = [];
    var pages = 1;
    var page = 0;
    var perPage = 0;
    var step = 1;
    var width = 0;

    function cssInt(name) {
      var value = parseInt(getComputedStyle(root).getPropertyValue(name), 10);
      return value > 0 ? value : 1;
    }

    function maxScroll() {
      return Math.max(0, track.scrollWidth - track.clientWidth);
    }

    // Kam sa posunúť pre stranu p: celé strany po šírke karusela, posledná až po koniec.
    function target(p) {
      return p >= pages - 1 ? maxScroll() : p * step;
    }

    function firstVisible() {
      for (var i = 0; i < items.length; i++) {
        if (items[i].offsetLeft + items[i].offsetWidth > track.scrollLeft + 2) return i;
      }
      return 0;
    }

    function layout() {
      var w = track.clientWidth;
      if (!w) return; // skrytý karusel
      var cols = cssInt('--cols');
      var rows = cssInt('--rows');
      var changed = cols * rows !== perPage;
      if (changed) {
        // Pri zmene počtu stĺpcov ostane na obrazovke tá istá prvá položka.
        var first = perPage ? firstVisible() : 0;
        var columns = Math.max(1, Math.ceil(items.length / rows));
        perPage = cols * rows;
        pages = Math.max(1, Math.ceil(columns / cols));
        page = Math.min(Math.floor(Math.floor(first / rows) / cols), pages - 1);
        root.classList.toggle('is-single-page', pages === 1);
        root.style.setProperty('--tracks', String(columns));
        items.forEach(function (item, i) {
          var col = Math.floor(i / rows);
          // zastávky pri posúvaní prstom: začiatky strán a koniec (posledná strana)
          item.classList.toggle('is-page-start', i % rows === 0 && col % cols === 0 && col / cols < pages - 1);
          item.classList.toggle('is-page-end', i === items.length - 1);
        });
        buildDots();
        nav.hidden = pages < 2;
      }
      // Zmena výšky (napr. načítané obrázky) posun nemení — inak by prerušila rolovanie.
      if (!changed && w === width) return;
      width = w;
      step = w + (parseFloat(getComputedStyle(track).columnGap) || 0);
      track.scrollLeft = target(page);
      update();
    }

    function buildDots() {
      dotsBox.textContent = '';
      dots = [];
      var useDots = pages <= MAX_DOTS;
      dotsBox.hidden = !useDots;
      count.hidden = useDots;
      if (!useDots) return;
      for (var i = 0; i < pages; i++) {
        var dot = document.createElement('button');
        dot.type = 'button';
        dot.className = 'carousel__dot';
        dot.setAttribute('aria-label', pageLabel.replace('%d', String(i + 1)));
        dot.setAttribute('data-page', String(i));
        dotsBox.appendChild(dot);
        dots.push(dot);
      }
    }

    function update() {
      var x = track.scrollLeft;
      page = x >= maxScroll() - 2 ? pages - 1 : Math.max(0, Math.min(pages - 1, Math.round(x / step)));
      dots.forEach(function (dot, i) {
        if (i === page) dot.setAttribute('aria-current', 'true');
        else dot.removeAttribute('aria-current');
      });
      count.textContent = (page + 1) + ' / ' + pages;
      prev.disabled = page === 0;
      next.disabled = page >= pages - 1;
    }

    function go(p) {
      p = Math.max(0, Math.min(pages - 1, p));
      track.scrollTo({ left: target(p), behavior: reducedMotion ? 'auto' : 'smooth' });
    }

    var ticking = false;
    track.addEventListener('scroll', function () {
      if (ticking) return;
      ticking = true;
      window.requestAnimationFrame(function () { ticking = false; update(); });
    }, { passive: true });

    prev.addEventListener('click', function () { go(page - 1); });
    next.addEventListener('click', function () { go(page + 1); });
    dotsBox.addEventListener('click', function (e) {
      var dot = e.target.closest('[data-page]');
      if (dot) go(parseInt(dot.getAttribute('data-page'), 10));
    });

    root.classList.add('is-ready');
    layout();
    if ('ResizeObserver' in window) new ResizeObserver(layout).observe(track);
    else window.addEventListener('resize', layout);
  }

  document.querySelectorAll('[data-carousel]').forEach(initCarousel);

  // ── Súbor: titulky sa objavujú postupne ───────────────────────────────────
  // Skryjeme ich až odtiaľto, aby bez JavaScriptu (alebo bez animácií) bolo
  // rovno všetko vidieť. Skupiny, ktoré prídu na obrazovku naraz, idú po sebe.

  var credits = document.querySelector('[data-credits]');
  if (credits && !reducedMotion && 'IntersectionObserver' in window) {
    credits.classList.add('is-rolling');
    var roll = new IntersectionObserver(function (entries) {
      var shown = 0;
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        entry.target.style.transitionDelay = (shown++ * 70) + 'ms';
        entry.target.classList.add('is-in');
        roll.unobserve(entry.target);
      });
    }, { rootMargin: '0px 0px -10% 0px' });
    credits.querySelectorAll('.credits__group').forEach(function (group) { roll.observe(group); });
  }

  // ── Záložky (história: prehľad po rokoch / celá história) ─────────────────

  document.querySelectorAll('[data-tabs]').forEach(function (box) {
    var tablist = box.querySelector('[role="tablist"]');
    var tabs = Array.prototype.slice.call(box.querySelectorAll('[role="tab"]'));
    if (!tablist || !tabs.length) return;

    function select(tab, focus) {
      tabs.forEach(function (t) {
        var on = t === tab;
        t.setAttribute('aria-selected', on ? 'true' : 'false');
        t.tabIndex = on ? 0 : -1;
        var panel = document.getElementById(t.getAttribute('aria-controls'));
        if (panel) panel.hidden = !on;
      });
      if (focus) tab.focus();
    }

    tabs.forEach(function (tab, i) {
      tab.addEventListener('click', function () { select(tab); });
      tab.addEventListener('keydown', function (e) {
        var to = null;
        if (e.key === 'ArrowRight') to = tabs[(i + 1) % tabs.length];
        else if (e.key === 'ArrowLeft') to = tabs[(i - 1 + tabs.length) % tabs.length];
        else if (e.key === 'Home') to = tabs[0];
        else if (e.key === 'End') to = tabs[tabs.length - 1];
        if (to) { e.preventDefault(); select(to, true); }
      });
    });

    // Bez JavaScriptu sú oba panely pod sebou a záložky skryté.
    tablist.hidden = false;
    select(tabs.filter(function (t) { return t.getAttribute('aria-selected') === 'true'; })[0] || tabs[0]);
  });

  // ── Okná nad stránkou (podrobnosti, fotky, video) ─────────────────────────
  // Môžu byť otvorené nad sebou (z podrobností inscenácie sa otvorí galéria
  // alebo ukážka) — Esc a zatváranie platí pre to vrchné, fokus ostáva v ňom.

  var stack = [];

  function openOverlay(el, opener) {
    stack.push({ el: el, focus: opener || document.activeElement });
    el.hidden = false;
    document.body.classList.add('has-lightbox');
    var first = el.querySelector('[data-autofocus], .lightbox__close, [data-sheet-close]');
    if (first) first.focus();
  }

  function closeOverlay(el) {
    for (var i = stack.length - 1; i >= 0; i--) {
      if (stack[i].el !== el) continue;
      var entry = stack.splice(i, 1)[0];
      el.hidden = true;
      if (!stack.length) document.body.classList.remove('has-lightbox');
      if (entry.focus && entry.focus.focus && document.contains(entry.focus)) entry.focus.focus();
      return;
    }
  }

  function topOverlay() {
    return stack.length ? stack[stack.length - 1].el : null;
  }

  function trapFocus(el, e) {
    var focusable = Array.prototype.filter.call(
      el.querySelectorAll('a[href], button:not([disabled]), input, select, textarea, iframe, video[controls], [tabindex]:not([tabindex="-1"])'),
      function (node) { return node.getClientRects().length > 0; }
    );
    if (!focusable.length) return;
    var first = focusable[0];
    var last = focusable[focusable.length - 1];
    if (e.shiftKey && (document.activeElement === first || !el.contains(document.activeElement))) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && (document.activeElement === last || !el.contains(document.activeElement))) { e.preventDefault(); first.focus(); }
  }

  var closers = [];

  document.addEventListener('keydown', function (e) {
    var top = topOverlay();
    // Okno úprav (cms.js, <dialog>) má vlastné ovládanie.
    if (!top || document.querySelector('dialog[open]')) return;
    if (e.key === 'Escape') {
      e.preventDefault();
      closers.forEach(function (c) { if (c.el === top) c.close(); });
    } else if (e.key === 'Tab') {
      trapFocus(top, e);
    }
  });

  // ── Podrobnosti (inscenácia, článok) ──────────────────────────────────────

  var sheet = document.getElementById('sheet');
  var sheetContent = sheet && sheet.querySelector('.sheet__content');

  function closeSheet() {
    closeOverlay(sheet);
    sheetContent.textContent = ''; // zastaví aj prípadné video
  }

  if (sheet) {
    closers.push({ el: sheet, close: closeSheet });
    document.addEventListener('click', function (e) {
      var opener = e.target.closest('[data-sheet]');
      if (opener) {
        var tpl = document.getElementById(opener.getAttribute('data-sheet'));
        if (!tpl || !tpl.content) return;
        e.preventDefault();
        if (!sheet.hidden) closeSheet();
        sheetContent.appendChild(tpl.content.cloneNode(true));
        openOverlay(sheet, opener);
        sheetContent.scrollTop = 0;
        return;
      }
      if (!sheet.hidden && topOverlay() === sheet && (e.target.closest('[data-sheet-close]') || e.target === sheet)) closeSheet();
    });
  }

  // ── Prehliadač fotografií ─────────────────────────────────────────────────

  var lb = document.getElementById('lightbox');
  var lbImg = lb && lb.querySelector('.lightbox__img');
  var lbCap = lb && lb.querySelector('.lightbox__caption');
  var lbCount = lb && lb.querySelector('.lightbox__count');
  var items = [];
  var index = 0;

  function show(i) {
    index = (i + items.length) % items.length;
    var item = items[index];
    lbImg.src = item.src;
    lbImg.alt = item.caption || '';
    lbCap.textContent = item.caption || '';
    lbCap.hidden = !item.caption;
    // Počítadlo „3 / 9", aby bolo jasné, koľký obrázok to je a koľko ich je.
    lbCount.textContent = items.length > 1 ? (index + 1) + ' / ' + items.length : '';
    lbCount.hidden = items.length < 2;
  }

  function openLightbox(group, i, opener) {
    if (!lb) return;
    items = group;
    lb.classList.toggle('is-single', items.length < 2);
    show(i);
    openOverlay(lb, opener);
  }

  function closeLightbox() {
    closeOverlay(lb);
    lbImg.removeAttribute('src');
  }

  document.addEventListener('click', function (e) {
    // Galéria inscenácie — zoznam obrázkov je priamo v tlačidle.
    var set = e.target.closest('[data-lightbox-set]');
    if (set) {
      e.preventDefault();
      openLightbox(JSON.parse(set.getAttribute('data-lightbox-set')), 0, set);
      return;
    }
    var single = e.target.closest('[data-lightbox-single]');
    if (single) {
      e.preventDefault();
      openLightbox([{ src: single.getAttribute('data-lightbox-single'), caption: single.getAttribute('data-caption') }], 0, single);
      return;
    }
    var photo = e.target.closest('[data-lightbox]');
    if (photo) {
      e.preventDefault();
      var group = photo.closest('[data-lightbox-group]');
      var buttons = Array.prototype.slice.call((group || document).querySelectorAll('[data-lightbox]'));
      openLightbox(buttons.map(function (b) {
        return { src: b.getAttribute('data-lightbox'), caption: b.getAttribute('data-caption') };
      }), buttons.indexOf(photo), photo);
    }
  });

  if (lb) {
    closers.push({ el: lb, close: closeLightbox });

    lb.addEventListener('click', function (e) {
      var action = e.target.closest('[data-lb]');
      if (action) {
        var what = action.getAttribute('data-lb');
        if (what === 'close') closeLightbox();
        if (what === 'prev') show(index - 1);
        if (what === 'next') show(index + 1);
        return;
      }
      // Klik mimo fotky zatvorí.
      if (e.target === lb || e.target.classList.contains('lightbox__figure')) closeLightbox();
    });

    document.addEventListener('keydown', function (e) {
      if (topOverlay() !== lb || items.length < 2) return;
      if (e.key === 'ArrowLeft') show(index - 1);
      else if (e.key === 'ArrowRight') show(index + 1);
    });

    // Potiahnutie prstom doľava / doprava.
    var startX = null;
    lb.addEventListener('pointerdown', function (e) { startX = e.clientX; });
    lb.addEventListener('pointerup', function (e) {
      if (startX === null || items.length < 2) return;
      var dx = e.clientX - startX;
      startX = null;
      if (Math.abs(dx) > 50) show(index + (dx < 0 ? 1 : -1));
    });
  }

  // ── Video vo väčšom okne (galéria, ukážka z inscenácie, reportáž) ─────────
  // Video sa spustí samo; vo videách z galérie ([data-player-group]) sa dá listovať
  // šípkami ako vo fotkách. YouTube sa načíta až po kliknutí.

  var player = document.getElementById('player');
  var playerFrame = player && player.querySelector('.player__frame');
  var playerTitle = player && player.querySelector('.player__title');
  var playerCount = player && player.querySelector('.player__count');
  var playlist = [];
  var playIndex = 0;

  function playAt(i) {
    playIndex = (i + playlist.length) % playlist.length;
    var btn = playlist[playIndex];
    var data = JSON.parse(btn.getAttribute('data-player'));
    var media;
    if (data.kind === 'youtube') {
      media = document.createElement('iframe');
      media.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
      media.referrerPolicy = 'strict-origin-when-cross-origin';
      media.title = btn.getAttribute('data-title') || 'YouTube';
    } else {
      media = document.createElement('video');
      media.controls = true;
      media.autoplay = true;
      media.playsInline = true;
    }
    media.src = data.src;
    playerFrame.textContent = ''; // predošlé video sa odstránením zastaví
    playerFrame.className = 'player__frame player__frame--' + (data.kind === 'youtube' ? 'youtube' : 'file');
    playerFrame.appendChild(media);
    playerTitle.textContent = btn.getAttribute('data-title') || '';
    playerCount.textContent = playlist.length > 1 ? (playIndex + 1) + ' / ' + playlist.length : '';
    playerCount.hidden = playlist.length < 2;
    player.classList.toggle('is-single', playlist.length < 2);
  }

  function closePlayer() {
    closeOverlay(player);
    playerFrame.textContent = '';
  }

  if (player) {
    closers.push({ el: player, close: closePlayer });

    document.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-player]');
      if (btn) {
        var group = btn.closest('[data-player-group]');
        playlist = group ? Array.prototype.slice.call(group.querySelectorAll('[data-player]')) : [btn];
        openOverlay(player, btn);
        playAt(playlist.indexOf(btn));
        return;
      }
      if (player.hidden || topOverlay() !== player) return;
      if (e.target.closest('[data-player-prev]')) playAt(playIndex - 1);
      else if (e.target.closest('[data-player-next]')) playAt(playIndex + 1);
      else if (e.target.closest('[data-player-close]') || e.target === player) closePlayer();
    });

    document.addEventListener('keydown', function (e) {
      // šípky v ovládaní samotného videa (posun v čase) nechávame prehliadaču
      if (topOverlay() !== player || playlist.length < 2 || e.target.tagName === 'VIDEO') return;
      if (e.key === 'ArrowLeft') playAt(playIndex - 1);
      else if (e.key === 'ArrowRight') playAt(playIndex + 1);
    });
  }

  // Video bez náhľadu: záber z videa (#t=0.5) sa načíta, až keď je video na obrazovke.
  var stills = document.querySelectorAll('.video__still[data-src]');
  function loadStill(video) {
    video.preload = 'metadata';
    video.src = video.getAttribute('data-src');
    video.removeAttribute('data-src');
  }
  if ('IntersectionObserver' in window) {
    var stillObserver = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          stillObserver.unobserve(entry.target);
          loadStill(entry.target);
        }
      });
    }, { rootMargin: '200px' });
    stills.forEach(function (video) { stillObserver.observe(video); });
  } else {
    stills.forEach(loadStill);
  }

  // ── Kontaktný formulár ────────────────────────────────────────────────────

  var form = document.querySelector('[data-contact-form]');
  if (form && window.fetch && window.FormData) {
    var status = form.querySelector('.contact-form__status');
    var submit = form.querySelector('[type="submit"]');
    var label = submit.textContent;

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      form.querySelectorAll('.has-error').forEach(function (f) { f.classList.remove('has-error'); });
      status.className = 'contact-form__status';
      status.textContent = '';
      submit.disabled = true;
      submit.textContent = submit.getAttribute('data-sending');

      fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: { 'X-Requested-With': 'fetch' },
        credentials: 'same-origin'
      })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data.ok) {
            form.reset();
            status.classList.add('is-ok');
            status.textContent = data.message;
          } else {
            status.classList.add('is-error');
            status.textContent = data.error;
            if (data.field) {
              var input = form.querySelector('[name="' + data.field + '"]');
              if (input) {
                input.closest('.field').classList.add('has-error');
                input.focus();
              }
            }
          }
        })
        .catch(function () {
          // Keď fetch zlyhá, pošleme formulár klasicky — server odpovie presmerovaním.
          form.submit();
        })
        .finally(function () {
          submit.disabled = false;
          submit.textContent = label;
        });
    });
  }
})();
