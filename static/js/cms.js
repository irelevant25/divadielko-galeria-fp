/*
 * Divadielko Galéria — úpravy obsahu pre prihlásených.
 *
 * Tlačidlá s data-cms-action (vykresľuje ich PHP, len pre prihlásených):
 *   edit / add / delete / up / down   záznam (data-entity, data-id, data-preset)
 *   settings                          skupina voľných textov (data-group)
 *   toggle-pens                       skryť / zobraziť ceruzky
 * Formulár v okne sa skladá z popisu, ktorý pošle api.php (includes/entities.php).
 * [data-cms-uploader] = samostatné nahrávanie súborov (administrácia → Súbory).
 */
(function () {
  'use strict';

  var cfgEl = document.getElementById('cms-config');
  if (!cfgEl) return;
  var cfg = JSON.parse(cfgEl.textContent);
  var S = cfg.strings || {};
  var uid = 0;

  var TYPES = {
    image: ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif'],
    video: ['mp4', 'mov', 'm4v', 'webm', 'mkv', 'avi'],
    audio: ['mp3', 'wav', 'ogg', 'oga', 'opus', 'm4a', 'aac', 'flac']
  };

  function s(key, arg) {
    var text = S[key] || key;
    return arg !== undefined ? text.replace('%s', arg) : text;
  }

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      var v = attrs[k];
      if (v === null || v === undefined || v === false) return;
      if (k === 'class') node.className = v;
      else if (k === 'text') node.textContent = v;
      else if (k.indexOf('on') === 0) node.addEventListener(k.slice(2), v);
      else node.setAttribute(k, v === true ? '' : v);
    });
    (children || []).forEach(function (c) {
      if (c) node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return node;
  }

  function ext(name) {
    var m = /\.([a-z0-9]+)$/i.exec(name || '');
    return m ? m[1].toLowerCase() : '';
  }

  function typeOf(name) {
    var e = ext(name);
    for (var t in TYPES) if (TYPES[t].indexOf(e) !== -1) return t;
    return 'other';
  }

  function bytes(n) {
    var units = ['B', 'kB', 'MB', 'GB'];
    var i = 0;
    while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
    return (i ? n.toFixed(n < 10 ? 1 : 0) : n) + ' ' + units[i];
  }

  function randomHex(len) {
    var a = new Uint8Array(len);
    crypto.getRandomValues(a);
    return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }

  // ── API ───────────────────────────────────────────────────────────────────

  function api(action, params, body) {
    var url = cfg.api + '?action=' + encodeURIComponent(action);
    Object.keys(params || {}).forEach(function (k) {
      url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
    });
    var opts = { credentials: 'same-origin', headers: { 'X-CSRF-Token': cfg.csrf, Accept: 'application/json' } };
    if (body !== undefined) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    return fetch(url, opts).then(function (res) {
      return res.json().catch(function () { return { error: s('error') }; }).then(function (data) {
        if (!res.ok || data.error) {
          var err = new Error(data.error || s('error'));
          err.field = data.field;
          throw err;
        }
        return data;
      });
    });
  }

  function toast(message, kind) {
    var node = el('div', { class: 'cms-toast cms-toast--' + (kind || 'error'), role: 'alert', text: message });
    document.body.appendChild(node);
    setTimeout(function () { node.classList.add('is-leaving'); }, 4200);
    setTimeout(function () { node.remove(); }, 4800);
  }

  // Po uložení sa stránka načíta znova (obsah vykresľuje PHP) a vráti sa na to isté miesto.
  function reload() {
    try { sessionStorage.setItem('cms-scroll', String(window.scrollY)); } catch (e) { /* súkromný režim */ }
    location.reload();
  }
  try {
    var savedY = sessionStorage.getItem('cms-scroll');
    if (savedY !== null) {
      sessionStorage.removeItem('cms-scroll');
      window.addEventListener('load', function () { window.scrollTo({ top: +savedY, behavior: 'instant' }); });
    }
  } catch (e) { /* súkromný režim */ }

  // ── Okno (dialog) ─────────────────────────────────────────────────────────

  function modal(title, content, buttons, opts) {
    opts = opts || {};
    var closeBtn = el('button', { type: 'button', class: 'cms-modal__x', 'aria-label': s('close'), text: '×' });
    var dialog = el('dialog', { class: 'cms-modal' + (opts.wide ? ' cms-modal--wide' : ''), 'aria-label': title }, [
      el('header', { class: 'cms-modal__head' }, [el('h2', { class: 'cms-modal__title', text: title }), closeBtn]),
      el('div', { class: 'cms-modal__body' }, content),
      buttons && buttons.length ? el('footer', { class: 'cms-modal__foot' }, buttons) : null
    ]);
    closeBtn.addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('close', function () { dialog.remove(); });
    document.body.appendChild(dialog);
    dialog.showModal();
    return dialog;
  }

  // ── Náhľad súboru ─────────────────────────────────────────────────────────

  function mediaPreview(file) {
    var type = file.type || typeOf(file.name);
    var url = file.url || '/assets/' + encodeURIComponent(file.name);
    if (type === 'image') return el('img', { src: url, alt: '', loading: 'lazy' });
    if (type === 'video') return el('video', { src: url + '#t=0.5', preload: 'metadata', muted: true, playsinline: true });
    return el('span', { class: 'cms-audio-icon', 'aria-hidden': 'true', text: '♪' });
  }

  // ── Formulár záznamu / nastavení ──────────────────────────────────────────

  function control(field, column, value, required) {
    var id = 'cms-f' + (++uid);
    var input;
    var placeholder = field.placeholders && field.placeholders[column];

    switch (field.type) {
      case 'bool':
        input = el('input', { type: 'checkbox', id: id });
        input.checked = !!value;
        return { node: el('label', { class: 'cms-check', for: id }, [input, el('span', { text: field.label })]), input: input, get: function () { return input.checked; }, id: id };

      case 'file':
        return fileControl(field, value || '');

      case 'files':
        return filesControl(field, Array.isArray(value) ? value : []);

      case 'people':
        return peopleControl(Array.isArray(value) ? value : []);

      case 'textarea':
        input = el('textarea', { id: id, rows: 5, maxlength: field.max, placeholder: placeholder });
        break;

      case 'richtext':
        return richtextControl(field, value || '');

      case 'select':
        input = el('select', { id: id }, [required ? null : el('option', { value: '', text: '—' })].concat((field.options || []).map(function (o) {
          return el('option', { value: String(o.value), text: o.label });
        })));
        break;

      case 'number':
        input = el('input', { type: 'number', id: id, min: field.min, max: field.max, step: 1, inputmode: 'numeric', placeholder: placeholder });
        break;

      case 'date':
        input = el('input', { type: 'date', id: id });
        break;

      case 'datetime':
        return dateTimeControl(value, required, id);

      case 'url':
        input = el('input', { type: 'url', id: id, placeholder: placeholder || 'https://', inputmode: 'url' });
        break;

      default:
        input = el('input', { type: 'text', id: id, maxlength: field.max, placeholder: placeholder });
    }

    input.value = value === null || value === undefined ? '' : String(value);
    if (required) input.required = true;
    return { node: input, input: input, get: function () { return input.value; }, id: id };
  }

  // ── Jednoduchý editor textu (pole richtext) ───────────────────────────────
  // Tučné, kurzíva, odkaz, zoznam, zrušenie formátovania a prepnutie na HTML kód.
  // Vložený text príde bez formátovania (z Wordu či webu by prišli písma a farby).
  // Čo sa uloží, vyčistí ešte server (bootstrap.php → rich_html) — tu je rovnaký
  // zoznam povolených značiek, aby sa do editora nedostalo nič spustiteľné.

  var RTE_KEEP = { P: 'p', BR: 'br', B: 'strong', STRONG: 'strong', I: 'em', EM: 'em', A: 'a', UL: 'ul', OL: 'ol', LI: 'li' };
  var RTE_BLOCK = /^(DIV|H[1-6]|BLOCKQUOTE|PRE)$/;
  var RTE_DROP = /^(SCRIPT|STYLE|IFRAME|OBJECT|EMBED|TEMPLATE|NOSCRIPT|SVG|MATH|HEAD|TITLE|META|LINK|FORM|INPUT|BUTTON|SELECT|TEXTAREA|IMG|VIDEO|AUDIO)$/;
  var RTE_HREF = /^(https?:\/\/|mailto:|tel:|#|\/(?!\/))/i;

  // DOMParser nič nespúšťa ani nenačítava (obrázky, udalosti), až výsledok ide do editora.
  function rteClean(html) {
    var doc = new DOMParser().parseFromString('<body>' + (html || '') + '</body>', 'text/html');
    var out = document.createElement('div');
    (function copy(from, to) {
      Array.prototype.forEach.call(from.childNodes, function (n) {
        if (n.nodeType === 3) { to.appendChild(document.createTextNode(n.data)); return; }
        if (n.nodeType !== 1 || RTE_DROP.test(n.tagName)) return;
        var tag = RTE_KEEP[n.tagName] || (RTE_BLOCK.test(n.tagName) ? 'p' : null);
        var href = (n.getAttribute('href') || '').trim();
        if (tag === 'a' && !RTE_HREF.test(href)) tag = null;
        if (!tag) { copy(n, to); return; }
        var node = document.createElement(tag);
        if (tag === 'a') node.setAttribute('href', href);
        to.appendChild(node);
        if (tag !== 'br') copy(n, node);
      });
    })(doc.body, out);
    return out.innerHTML;
  }

  function rteEscape(text) {
    return el('div', { text: text }).innerHTML;
  }

  // HTML kód po riadkoch, aby sa v ňom dalo vyznať.
  function rtePretty(html) {
    return html.replace(/<(ul|ol)>/g, '<$1>\n').replace(/<\/(p|ul|ol|li)>/g, '</$1>\n').replace(/\n+$/, '');
  }

  // Farebné zvýraznenie HTML kódu (značky, atribúty, hodnoty, entity, komentáre).
  // Značky a atribúty, ktoré sa pri uložení odstránia, sú podčiarknuté načerveno.
  var RTE_OK_TAGS = /^(p|br|strong|b|em|i|a|ul|ol|li)$/i;

  function rteHighlight(code) {
    var esc = function (t) { return t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); };
    var span = function (cls, t) { return '<span class="hl-' + cls + '">' + esc(t) + '</span>'; };
    var out = '';
    var last = 0;
    var re = /(<!--[\s\S]*?(?:-->|$))|(<\/?)([a-zA-Z][\w-]*)([^>]*)(>?)|(&(?:#\d+|#x[\da-fA-F]+|[a-zA-Z]\w*);)/g;
    var m;
    while ((m = re.exec(code))) {
      out += esc(code.slice(last, m.index));
      last = re.lastIndex;
      if (m[1]) { out += span('comment', m[1]); continue; }
      if (m[6]) { out += span('entity', m[6]); continue; }
      var okTag = RTE_OK_TAGS.test(m[3]);
      out += span('punct', m[2]) + span(okTag ? 'tag' : 'tag hl-bad', m[3]);
      // atribúty: meno, =, hodnota (v úvodzovkách alebo bez nich); zvyšok (napr. „/“) ako interpunkcia
      out += m[4].replace(/(\s+)([^\s=\/]+)(?:(\s*=\s*)("[^"]*"?|'[^']*'?|[^\s"']+))?|([^\s])/g, function (all, sp, name, eq, val, other) {
        if (other) return span('punct', other);
        var okAttr = okTag && /^href$/i.test(name) && /^a$/i.test(m[3]);
        return esc(sp) + span(okAttr ? 'attr' : 'attr hl-bad', name) + (eq ? span('punct', eq) + span('value', val || '') : '');
      });
      out += span('punct', m[5]);
    }
    return out + esc(code.slice(last));
  }

  var RTE_ICONS = {
    bold: '<b>B</b>',
    italic: '<i>I</i>',
    link: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1.2 1.2M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1.2-1.2"/></svg>',
    list: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1.2"/><circle cx="4.5" cy="12" r="1.2"/><circle cx="4.5" cy="18" r="1.2"/></svg>',
    clear: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 5h12M11 5l-3 14M15 13l5 5M20 13l-5 5"/></svg>',
    source: '<span>&lt;/&gt;</span> HTML'
  };

  function richtextControl(field, value) {
    var id = 'cms-f' + (++uid);
    var area = el('div', { class: 'cms-rte__area', id: id, contenteditable: 'true', role: 'textbox', 'aria-multiline': 'true', 'aria-label': field.label, spellcheck: 'true' });
    // HTML kód: priehľadné pole na písanie nad farebnou kópiou toho istého textu
    var source = el('textarea', { class: 'cms-rte__source', spellcheck: 'false', autocapitalize: 'off', autocomplete: 'off', wrap: 'soft', 'aria-label': field.label + ' (HTML)' });
    var painted = el('pre', { class: 'cms-rte__paint', 'aria-hidden': 'true' });
    var code = el('div', { class: 'cms-rte__code', hidden: true }, [painted, source]);
    function paint() {
      painted.innerHTML = rteHighlight(source.value) + '\n '; // posledný prázdny riadok má tiež výšku
      painted.scrollTop = source.scrollTop;
      painted.scrollLeft = source.scrollLeft;
    }
    source.addEventListener('input', paint);
    source.addEventListener('scroll', function () {
      painted.scrollTop = source.scrollTop;
      painted.scrollLeft = source.scrollLeft;
    });
    var sourceHint = el('p', { class: 'cms-hint', text: s('rte_source_hint'), hidden: true });
    var buttons = {};
    var sourceOn = false;
    area.innerHTML = rteClean(value);

    function selectionInside() {
      var sel = window.getSelection();
      return sel.rangeCount && area.contains(sel.getRangeAt(0).commonAncestorContainer) ? sel : null;
    }

    function currentLink() {
      var sel = selectionInside();
      var node = sel && sel.anchorNode;
      node = node && (node.nodeType === 1 ? node : node.parentNode);
      var a = node && node.closest('a');
      return a && area.contains(a) ? a : null;
    }

    function update() {
      if (sourceOn || !selectionInside()) return;
      [['bold', 'bold'], ['italic', 'italic'], ['list', 'insertUnorderedList']].forEach(function (p) {
        buttons[p[0]].setAttribute('aria-pressed', document.queryCommandState(p[1]) ? 'true' : 'false');
      });
      buttons.link.setAttribute('aria-pressed', currentLink() ? 'true' : 'false');
    }

    function exec(cmd, arg) {
      area.focus();
      document.execCommand(cmd, false, arg);
      update();
    }

    // Odkaz: bez výberu sa vloží adresa ako text; v odkaze sa adresa zmení,
    // prázdna adresa odkaz zruší (text ostane).
    function link() {
      var sel = window.getSelection();
      var range = selectionInside() ? sel.getRangeAt(0).cloneRange() : null;
      var a = currentLink();
      var url = window.prompt(s('rte_link_ask'), a ? a.getAttribute('href') : 'https://');
      area.focus();
      if (range) { sel.removeAllRanges(); sel.addRange(range); }
      if (url === null) return;
      url = url.trim();
      if (url === '' || url === 'https://') {
        if (a) {
          var r = document.createRange();
          r.selectNodeContents(a);
          sel.removeAllRanges();
          sel.addRange(r);
          exec('unlink');
        }
        return;
      }
      if (!RTE_HREF.test(url)) url = (url.indexOf('@') > 0 && url.indexOf('/') < 0 ? 'mailto:' : 'https://') + url;
      if (a) { a.setAttribute('href', url); update(); return; }
      if (!range || range.collapsed) exec('insertHTML', '<a href="' + rteEscape(url).replace(/"/g, '&quot;') + '">' + rteEscape(url) + '</a>');
      else exec('createLink', url);
    }

    function toggleSource() {
      sourceOn = !sourceOn;
      if (sourceOn) source.value = rtePretty(rteClean(area.innerHTML));
      else area.innerHTML = rteClean(source.value);
      area.hidden = sourceOn;
      code.hidden = sourceHint.hidden = !sourceOn;
      if (sourceOn) { source.scrollTop = 0; paint(); }
      Object.keys(buttons).forEach(function (k) { if (k !== 'source') buttons[k].disabled = sourceOn; });
      buttons.source.setAttribute('aria-pressed', sourceOn ? 'true' : 'false');
      (sourceOn ? source : area).focus();
    }

    function button(key, label, action) {
      var b = el('button', { type: 'button', class: 'cms-rte__btn cms-rte__btn--' + key, title: label, 'aria-label': label, 'aria-pressed': key === 'clear' ? null : 'false' });
      b.innerHTML = RTE_ICONS[key];
      b.addEventListener('mousedown', function (e) { e.preventDefault(); }); // výber v texte ostane
      b.addEventListener('click', action);
      buttons[key] = b;
      return b;
    }

    var bar = el('div', { class: 'cms-rte__bar', role: 'toolbar', 'aria-label': s('rte_toolbar') }, [
      button('bold', s('rte_bold'), function () { exec('bold'); }),
      button('italic', s('rte_italic'), function () { exec('italic'); }),
      button('link', s('rte_link'), link),
      button('list', s('rte_list'), function () { exec('insertUnorderedList'); }),
      button('clear', s('rte_clear'), function () { exec('removeFormat'); exec('unlink'); }),
      el('span', { class: 'cms-rte__gap' }),
      button('source', s('rte_source'), toggleSource)
    ]);

    area.addEventListener('focus', function () {
      document.execCommand('defaultParagraphSeparator', false, 'p'); // Enter = <p>, nie <div>
      if (!area.textContent.trim() && !area.querySelector('li')) area.innerHTML = '<p><br></p>';
    });
    area.addEventListener('keyup', update);
    area.addEventListener('mouseup', update);
    area.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && e.key.toLowerCase() === 'k') { e.preventDefault(); link(); }
    });
    // Vložený text bez formátovania: prázdny riadok = nový odsek, riadok = zlom riadku.
    area.addEventListener('paste', function (e) {
      var text = (e.clipboardData || window.clipboardData).getData('text/plain');
      e.preventDefault();
      if (!text) return;
      var paras = text.replace(/\r\n?/g, '\n').trim().split(/\n{2,}/).map(function (p) { return rteEscape(p).replace(/\n/g, '<br>'); });
      exec('insertHTML', paras.length > 1 ? '<p>' + paras.join('</p><p>') + '</p>' : paras[0]);
    });
    area.addEventListener('drop', function (e) { e.preventDefault(); });

    return {
      node: el('div', { class: 'cms-rte' }, [bar, area, code, sourceHint]),
      input: area,
      id: id,
      get: function () {
        var html = rteClean(sourceOn ? source.value : area.innerHTML);
        var probe = el('div');
        probe.innerHTML = html;
        return probe.textContent.replace(/\u00a0/g, ' ').trim() === '' ? '' : html;
      }
    };
  }

  // Dátum a čas: vždy 24-hodinový čas a minúty po štvrťhodinách (00, 15, 30, 45).
  // Natívne pole datetime-local by podľa jazyka prehliadača ukazovalo AM/PM.
  function dateTimeControl(value, required, id) {
    var m = /^(\d{4}-\d{2}-\d{2})[T ](\d{2}):(\d{2})/.exec(value || '');
    var date = el('input', { type: 'date', id: id, required: required });
    date.value = m ? m[1] : '';

    function part(label, values, current) {
      // Starší čas mimo štvrťhodín (napr. 14:40) sa pri úprave nestratí.
      if (current && values.indexOf(current) === -1) { values.push(current); values.sort(); }
      var sel = el('select', { class: 'cms-datetime__part', 'aria-label': label }, [el('option', { value: '', text: '--' })].concat(values.map(function (v) {
        return el('option', { value: v, text: v });
      })));
      sel.value = current || '';
      return sel;
    }

    var hours = [];
    for (var h = 0; h < 24; h++) hours.push(('0' + h).slice(-2));
    var hour = part(s('hour'), hours, m ? m[2] : '');
    var minute = part(s('minute'), ['00', '15', '30', '45'], m ? m[3] : '');

    // Dátum slovami („nedeľa 31. augusta 2026, 16:00") — pole s dátumom ukazuje
    // formát podľa jazyka prehliadača (napr. 08/31/2026), toto je jednoznačné.
    var preview = el('p', { class: 'cms-hint cms-datetime__preview', 'aria-live': 'polite' });
    function refresh() {
      var p = (date.value || '').split('-');
      if (p.length !== 3) { preview.textContent = ''; return; }
      var d = new Date(+p[0], +p[1] - 1, +p[2]);
      var months = (S.months || '').split(',');
      var days = (S.weekdays || '').split(',');
      var text = (days[(d.getDay() + 6) % 7] || '') + ' ' + d.getDate() + '. ' + (months[d.getMonth()] || '') + ' ' + d.getFullYear();
      preview.textContent = text + (hour.value && minute.value ? ', ' + hour.value + ':' + minute.value : '');
    }
    date.addEventListener('input', refresh);
    hour.addEventListener('change', refresh);
    minute.addEventListener('change', refresh);
    refresh();

    return {
      node: el('div', { class: 'cms-datetime-wrap' }, [
        el('div', { class: 'cms-datetime' }, [date, hour, el('span', { class: 'cms-datetime__sep', 'aria-hidden': 'true', text: ':' }), minute]),
        preview
      ]),
      input: date,
      id: id,
      // Chýbajúca hodina / minúty → server odpovie „Neplatný dátum".
      get: function () { return date.value ? date.value + 'T' + (hour.value || '--') + ':' + (minute.value || '--') : ''; }
    };
  }

  function fileControl(field, value) {
    var current = value;
    var preview = el('div', { class: 'cms-file__preview' });
    var name = el('p', { class: 'cms-file__name' });
    var choose = el('button', { type: 'button', class: 'cms-button' });
    var remove = el('button', { type: 'button', class: 'cms-button cms-button--ghost', text: s('remove') });

    function render() {
      preview.textContent = '';
      if (current) {
        preview.appendChild(mediaPreview({ name: current }));
        name.textContent = current;
        choose.textContent = s('change');
        remove.hidden = false;
      } else {
        preview.appendChild(el('span', { class: 'cms-file__empty', text: '—' }));
        name.textContent = s('no_file');
        choose.textContent = s('choose');
        remove.hidden = true;
      }
    }

    choose.addEventListener('click', function () {
      openPicker(field.accept || 'any', function (file) {
        current = file.name;
        render();
      });
    });
    remove.addEventListener('click', function () {
      current = '';
      render();
    });
    render();

    return {
      node: el('div', { class: 'cms-file' }, [preview, el('div', { class: 'cms-file__side' }, [name, el('div', { class: 'cms-file__buttons' }, [choose, remove])])]),
      input: choose,
      get: function () { return current; }
    };
  }

  // Viac obrázkov (galéria inscenácie): náhľady s posunom doľava / doprava a odobratím.
  function filesControl(field, value) {
    var list = value.slice();
    var grid = el('div', { class: 'cms-files__grid' });
    var add = el('button', { type: 'button', class: 'cms-button', text: s('add_images') });

    function move(i, d) {
      var j = i + d;
      var tmp = list[i];
      list[i] = list[j];
      list[j] = tmp;
      render();
    }

    function render() {
      grid.textContent = '';
      if (!list.length) {
        grid.appendChild(el('p', { class: 'cms-files__empty', text: s('no_file') }));
        return;
      }
      list.forEach(function (name, i) {
        grid.appendChild(el('div', { class: 'cms-files__item', title: name }, [
          el('span', { class: 'cms-files__thumb' }, [mediaPreview({ name: name })]),
          el('span', { class: 'cms-files__tools' }, [
            el('button', { type: 'button', class: 'cms-files__btn', title: s('move_earlier'), 'aria-label': s('move_earlier'), text: '‹', disabled: i === 0, onclick: function () { move(i, -1); } }),
            el('button', { type: 'button', class: 'cms-files__btn cms-files__btn--remove', title: s('remove'), 'aria-label': s('remove'), text: '×', onclick: function () { list.splice(i, 1); render(); } }),
            el('button', { type: 'button', class: 'cms-files__btn', title: s('move_later'), 'aria-label': s('move_later'), text: '›', disabled: i === list.length - 1, onclick: function () { move(i, 1); } })
          ])
        ]));
      });
    }

    add.addEventListener('click', function () {
      openPicker(field.accept || 'any', function (files) {
        files.forEach(function (f) { if (list.indexOf(f.name) === -1) list.push(f.name); });
        render();
      }, { multi: true });
    });
    render();

    return { node: el('div', { class: 'cms-files' }, [grid, add]), input: add, get: function () { return list.slice(); } };
  }

  // Ľudia v skupine súboru: meno a nepovinný rok, poradie ↑ ↓, odobratie ×,
  // „Pridať človeka" na konci. Uloží sa až tlačidlom Uložiť v okne.
  function peopleControl(value) {
    var list = value.map(function (p) {
      return { name: p && p.name ? String(p.name) : '', since: p && p.since ? String(p.since) : '' };
    });
    var rows = el('ol', { class: 'cms-people__list' });
    var add = el('button', { type: 'button', class: 'cms-button', text: '+ ' + s('add_person') });

    // Po posune ostane fokus na tom istom tlačidle presunutého človeka (dá sa klikať ďalej).
    function render(focus) {
      rows.textContent = '';
      if (!list.length) {
        rows.appendChild(el('li', { class: 'cms-people__empty', text: s('no_people') }));
      }
      list.forEach(function (person, i) {
        var nr = ' ' + (i + 1);
        var name = el('input', { type: 'text', maxlength: 120, placeholder: s('person_name'), 'aria-label': s('person_name') + nr });
        var since = el('input', { type: 'number', min: 1900, max: 2100, step: 1, inputmode: 'numeric', placeholder: s('person_since'), 'aria-label': s('person_since') + nr });
        name.value = person.name;
        since.value = person.since;
        name.addEventListener('input', function () { person.name = name.value; });
        since.addEventListener('input', function () { person.since = since.value; });

        var up = el('button', { type: 'button', class: 'cms-files__btn', title: s('move_up'), 'aria-label': s('move_up') + nr, text: '↑', disabled: i === 0, onclick: function () { move(i, -1, 'up'); } });
        var down = el('button', { type: 'button', class: 'cms-files__btn', title: s('move_down'), 'aria-label': s('move_down') + nr, text: '↓', disabled: i === list.length - 1, onclick: function () { move(i, 1, 'down'); } });
        var remove = el('button', { type: 'button', class: 'cms-files__btn cms-files__btn--remove', title: s('remove'), 'aria-label': s('remove') + nr, text: '×', onclick: function () {
          list.splice(i, 1);
          render(list.length ? { index: Math.min(i, list.length - 1), what: 'name' } : null);
          if (!list.length) add.focus();
        } });

        rows.appendChild(el('li', { class: 'cms-people__row' }, [name, since, el('span', { class: 'cms-people__tools' }, [up, down, remove])]));

        if (focus && focus.index === i) {
          var target = { name: name, up: up, down: down }[focus.what];
          (target && !target.disabled ? target : name).focus();
        }
      });
    }

    function move(i, d, what) {
      var j = i + d;
      var tmp = list[i];
      list[i] = list[j];
      list[j] = tmp;
      render({ index: j, what: what });
    }

    add.addEventListener('click', function () {
      list.push({ name: '', since: '' });
      render({ index: list.length - 1, what: 'name' });
    });
    render(null);

    return {
      node: el('div', { class: 'cms-people' }, [rows, add]),
      input: add,
      get: function () { return list.map(function (p) { return { name: p.name, since: p.since }; }); }
    };
  }

  function openForm(data, onSave) {
    var getters = {};
    var wraps = {};
    var errorBox = el('p', { class: 'cms-form__error', role: 'alert', hidden: true });
    var form = el('form', { class: 'cms-form', novalidate: true }, [errorBox]);
    // Prekladané pole je v stĺpci <pole>_sk (stránka je len po slovensky).
    data.schema.forEach(function (field) {
      var column = field.i18n ? field.name + '_' + cfg.lang : field.name;
      var ctl = control(field, column, data.item[column], field.required);
      var parts = [];
      if (field.type !== 'bool') {
        parts.push(el('label', { class: 'cms-field__label', for: ctl.id || null, text: field.label + (field.required ? ' *' : '') }));
      }
      parts.push(ctl.node);
      if (field.hint) parts.push(el('p', { class: 'cms-hint', text: field.hint }));
      var wrap = el('div', { class: 'cms-field cms-field--' + field.type }, parts);
      getters[column] = ctl.get;
      wraps[column] = { wrap: wrap, focus: ctl.input };
      form.appendChild(wrap);
    });

    var save = el('button', { type: 'submit', class: 'cms-button cms-button--primary', text: s('save') });
    save.setAttribute('form', 'cms-form-' + (++uid));
    form.id = save.getAttribute('form');
    var cancel = el('button', { type: 'button', class: 'cms-button cms-button--ghost', text: s('cancel') });

    var title = (data.item && data.item.id === null ? s('new') + ': ' : '') + data.title;
    var dialog = modal(title, [form], [cancel, save]);
    cancel.addEventListener('click', function () { dialog.close(); });

    var firstInput = form.querySelector('input:not([type=checkbox]), textarea, select');
    if (firstInput) firstInput.focus();

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      errorBox.hidden = true;
      Object.keys(wraps).forEach(function (k) {
        wraps[k].wrap.classList.remove('has-error');
        var old = wraps[k].wrap.querySelector('.cms-field__error');
        if (old) old.remove();
      });

      var values = {};
      Object.keys(getters).forEach(function (k) { values[k] = getters[k](); });

      save.disabled = true;
      save.textContent = s('saving');
      onSave(values)
        .then(function () { dialog.close(); reload(); })
        .catch(function (err) {
          save.disabled = false;
          save.textContent = s('save');
          var target = err.field && wraps[err.field];
          if (target) {
            target.wrap.classList.add('has-error');
            target.wrap.appendChild(el('p', { class: 'cms-field__error', text: err.message }));
            target.wrap.scrollIntoView({ block: 'center' });
            if (target.focus) target.focus.focus();
          } else {
            errorBox.textContent = err.message;
            errorBox.hidden = false;
            errorBox.scrollIntoView({ block: 'center' });
          }
        });
    });
  }

  // ── Výber súboru ──────────────────────────────────────────────────────────

  // opts.multi: výber viacerých súborov (klik = označiť/odznačiť, potvrdí sa tlačidlom).
  function openPicker(accept, onPick, opts) {
    var multi = !!(opts && opts.multi);
    var selected = [];
    var confirmBtn = null;
    var fixedType = accept && accept !== 'any' ? accept : '';
    var filter = fixedType;
    var files = [];
    var dialog;

    function toggle(name) {
      var at = selected.indexOf(name);
      if (at === -1) selected.push(name); else selected.splice(at, 1);
      updateConfirm();
      render();
    }

    function updateConfirm() {
      if (!confirmBtn) return;
      confirmBtn.textContent = s('add_selected', String(selected.length));
      confirmBtn.disabled = !selected.length;
    }

    var grid = el('div', { class: 'cms-picker__grid', 'aria-live': 'polite' });
    var search = el('input', { type: 'search', class: 'cms-input', placeholder: s('search'), 'aria-label': s('search') });
    var tabs = null;

    if (!fixedType) {
      tabs = el('div', { class: 'cms-tabs', role: 'group' }, [['', 'all'], ['image', 'images'], ['video', 'videos'], ['audio', 'audio']].map(function (t) {
        return el('button', {
          type: 'button', class: 'cms-tab', 'aria-pressed': t[0] === filter ? 'true' : 'false', 'data-type': t[0], text: s(t[1]),
          onclick: function (e) {
            filter = t[0];
            tabs.querySelectorAll('.cms-tab').forEach(function (b) { b.setAttribute('aria-pressed', b === e.currentTarget ? 'true' : 'false'); });
            render();
          }
        });
      }));
    }

    function pick(file) {
      onPick(file);
      dialog.close();
    }

    function render() {
      var q = search.value.trim().toLowerCase();
      var shown = files.filter(function (f) {
        return (!filter || f.type === filter) && (!q || f.name.toLowerCase().indexOf(q) !== -1);
      });
      grid.textContent = '';
      if (!shown.length) {
        grid.appendChild(el('p', { class: 'cms-empty', text: files.length ? s('no_match') : s('empty') }));
        return;
      }
      shown.forEach(function (f) {
        var isSelected = multi && selected.indexOf(f.name) !== -1;
        grid.appendChild(el('button', {
          type: 'button', class: 'cms-pick' + (isSelected ? ' is-selected' : ''), title: f.name,
          'aria-pressed': multi ? String(isSelected) : null,
          onclick: function () { if (multi) toggle(f.name); else pick(f); }
        }, [
          el('span', { class: 'cms-pick__thumb' }, [mediaPreview(f)]),
          el('span', { class: 'cms-pick__name', text: f.name }),
          el('span', { class: 'cms-pick__meta', text: bytes(f.size) + (f.width ? ' · ' + f.width + '×' + f.height : '') + (f.original ? ' · ' + s('original') + ' ✓' : '') })
        ]));
      });
    }

    function load() {
      grid.textContent = '';
      grid.appendChild(el('p', { class: 'cms-empty', text: '…' }));
      return api('files', { type: fixedType }).then(function (data) {
        files = data.files;
        render();
      }).catch(function (err) { toast(err.message); });
    }

    search.addEventListener('input', render);

    var uploader = createUploader({
      accept: fixedType,
      onDone: function (uploaded) {
        var usable = uploaded.filter(function (f) { return !fixedType || f.type === fixedType; });
        if (multi) {
          // Práve nahraté obrázky sa rovno označia — stačí potvrdiť.
          usable.forEach(function (f) { if (selected.indexOf(f.name) === -1) selected.push(f.name); });
          updateConfirm();
          load();
          return;
        }
        load().then(function () {
          // Jeden práve nahratý súbor rovno použijeme.
          if (uploaded.length === 1 && usable.length === 1) pick(usable[0]);
        });
      }
    });

    var footer = [el('button', { type: 'button', class: 'cms-button cms-button--ghost', text: s('cancel'), onclick: function () { dialog.close(); } })];
    if (multi) {
      confirmBtn = el('button', {
        type: 'button', class: 'cms-button cms-button--primary',
        onclick: function () {
          onPick(selected.map(function (name) { return { name: name }; }));
          dialog.close();
        }
      });
      footer.push(confirmBtn);
      updateConfirm();
    }

    dialog = modal(s('files'), [
      uploader.node,
      el('div', { class: 'cms-picker__bar' }, [search, tabs]),
      grid
    ], footer, { wide: true });

    load();
    search.focus();
  }

  // ── Nahrávanie po kúskoch s ukazovateľom priebehu ─────────────────────────

  function uploadFile(file, deleteOriginal, onProgress, onProcessing) {
    return new Promise(function (resolve, reject) {
      var id = randomHex(16);
      var offset = 0;
      var retries = 0;

      function next() {
        var end = Math.min(offset + cfg.chunkSize, file.size);
        var last = end >= file.size;
        var fd = new FormData();
        fd.append('upload_id', id);
        fd.append('offset', String(offset));
        fd.append('size', String(file.size));
        fd.append('name', file.name);
        if (deleteOriginal) fd.append('delete_original', '1');
        fd.append('chunk', file.slice(offset, end), 'chunk');

        var xhr = new XMLHttpRequest();
        xhr.open('POST', cfg.api + '?action=upload');
        xhr.setRequestHeader('X-CSRF-Token', cfg.csrf);
        xhr.upload.onprogress = function (e) {
          if (e.lengthComputable) onProgress(Math.min(1, (offset + (e.loaded / e.total) * (end - offset)) / file.size));
        };
        if (last) {
          // Posledný kúsok je na serveri — teraz sa konvertuje.
          xhr.upload.onload = function () { onProgress(1); onProcessing(); };
        }
        xhr.onload = function () {
          var data = null;
          try { data = JSON.parse(xhr.responseText); } catch (e) { /* nie JSON */ }
          if (xhr.status >= 200 && xhr.status < 300 && data) {
            retries = 0;
            if (data.done) return resolve(data.file);
            offset = typeof data.received === 'number' ? data.received : end;
            return next();
          }
          // Brána / server na chvíľu nedostupný — skúsime ten istý kúsok znova.
          if (!last && [502, 503, 504].indexOf(xhr.status) !== -1 && retries < 3) {
            retries++;
            return setTimeout(next, 1500 * retries);
          }
          reject(new Error((data && data.error) || s('error') + ' (' + xhr.status + ')'));
        };
        xhr.onerror = function () {
          if (retries < 3) {
            retries++;
            setTimeout(next, 1500 * retries);
          } else {
            reject(new Error(s('error')));
          }
        };
        xhr.send(fd);
      }

      next();
    });
  }

  function createUploader(opts) {
    var acceptExts = opts.accept ? TYPES[opts.accept] : cfg.accept;
    var input = el('input', { type: 'file', multiple: true, hidden: true, accept: acceptExts.map(function (e) { return '.' + e; }).join(',') });
    var remember = 'cms-delete-original';
    var del = el('input', { type: 'checkbox', id: 'cms-del-' + (++uid) });
    try { del.checked = localStorage.getItem(remember) === '1'; } catch (e) { /* bez úložiska */ }
    del.addEventListener('change', function () {
      try { localStorage.setItem(remember, del.checked ? '1' : '0'); } catch (e) { /* bez úložiska */ }
    });

    var list = el('ul', { class: 'cms-uploads' });
    var button = el('button', { type: 'button', class: 'cms-button cms-button--primary', text: s('upload'), onclick: function () { input.click(); } });
    var drop = el('div', { class: 'cms-drop' }, [button, el('span', { class: 'cms-drop__hint', text: s('drop') })]);
    var busy = false;

    function row(file) {
      var bar = el('span', { class: 'cms-progress__bar' });
      var status = el('span', { class: 'cms-upload__status', text: s('uploading', '') });
      var node = el('li', { class: 'cms-upload' }, [
        el('span', { class: 'cms-upload__name', text: file.name + ' · ' + bytes(file.size) }),
        el('span', { class: 'cms-progress', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': '0', 'aria-label': file.name }, [bar]),
        status
      ]);
      list.appendChild(node);
      return {
        progress: function (p) {
          var pct = Math.round(p * 100);
          bar.style.width = pct + '%';
          node.querySelector('.cms-progress').setAttribute('aria-valuenow', String(pct));
          status.textContent = pct + ' %';
        },
        processing: function () {
          node.classList.add('is-processing');
          status.textContent = typeOf(file.name) === 'video' ? s('processing_video') : s('processing');
        },
        done: function (info) {
          node.classList.remove('is-processing');
          node.classList.add(info.converted === false ? 'is-warn' : 'is-done');
          status.textContent = info.converted === false ? s('not_converted') : s('uploaded') + ' → ' + info.name;
        },
        fail: function (message) {
          node.classList.remove('is-processing');
          node.classList.add('is-error');
          status.textContent = message;
        }
      };
    }

    function handle(fileList) {
      var queue = Array.prototype.slice.call(fileList);
      if (!queue.length || busy) return;
      busy = true;
      button.disabled = true;
      var uploaded = [];

      (function step() {
        var file = queue.shift();
        if (!file) {
          busy = false;
          button.disabled = false;
          input.value = '';
          if (uploaded.length && opts.onDone) opts.onDone(uploaded);
          return;
        }
        var r = row(file);
        if (acceptExts.indexOf(ext(file.name)) === -1) {
          r.fail(s('bad_type', '.' + (ext(file.name) || '?')));
          return step();
        }
        if (file.size === 0 || file.size > cfg.maxSize) {
          r.fail(s('too_big', bytes(file.size)));
          return step();
        }
        uploadFile(file, del.checked, r.progress, r.processing)
          .then(function (info) { r.done(info); uploaded.push(info); })
          .catch(function (err) { r.fail(err.message); })
          .then(step);
      })();
    }

    input.addEventListener('change', function () { handle(input.files); });
    ['dragenter', 'dragover'].forEach(function (type) {
      drop.addEventListener(type, function (e) { e.preventDefault(); drop.classList.add('is-over'); });
    });
    ['dragleave', 'drop'].forEach(function (type) {
      drop.addEventListener(type, function (e) { e.preventDefault(); drop.classList.remove('is-over'); });
    });
    drop.addEventListener('drop', function (e) {
      if (e.dataTransfer && e.dataTransfer.files) handle(e.dataTransfer.files);
    });

    return {
      node: el('div', { class: 'cms-uploader' }, [
        drop,
        el('label', { class: 'cms-check', for: del.id }, [del, el('span', { text: s('delete_original') })]),
        el('p', { class: 'cms-hint', text: s('delete_original_hint') }),
        list,
        input
      ])
    };
  }

  // ── Kliknutia na ceruzky ──────────────────────────────────────────────────

  function busyButton(btn, promise) {
    btn.disabled = true;
    return promise.catch(function (err) { toast(err.message); }).then(function () { btn.disabled = false; });
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-cms-action]');
    if (!btn) return;
    var action = btn.getAttribute('data-cms-action');
    var entity = btn.getAttribute('data-entity');
    var id = btn.getAttribute('data-id');
    e.preventDefault();

    if (action === 'toggle-pens') {
      var hidden = document.body.classList.toggle('cms-hidden');
      btn.textContent = btn.getAttribute(hidden ? 'data-show' : 'data-hide');
      try { localStorage.setItem('cms-hidden', hidden ? '1' : '0'); } catch (err) { /* bez úložiska */ }
      return;
    }

    if (action === 'edit' || action === 'add') {
      var params = action === 'edit' ? { entity: entity, id: id } : { entity: entity, preset: btn.getAttribute('data-preset') || '' };
      busyButton(btn, api(action === 'edit' ? 'item' : 'blank', params).then(function (data) {
        openForm(data, function (values) {
          return api('save', null, { entity: entity, id: data.item.id, values: values });
        });
      }));
    } else if (action === 'settings') {
      var group = btn.getAttribute('data-group');
      busyButton(btn, api('settings', { group: group }).then(function (data) {
        openForm(data, function (values) {
          return api('settings_save', null, { group: group, values: values });
        });
      }));
    } else if (action === 'delete') {
      // Text potvrdenia posiela PHP: „presunúť do archívu" alebo pri termíne „zmazať".
      if (!confirm(btn.getAttribute('data-confirm') || s('confirm_delete'))) return;
      busyButton(btn, api('delete', null, { entity: entity, id: +id }).then(reload));
    } else if (action === 'up' || action === 'down') {
      busyButton(btn, api('move', null, { entity: entity, id: +id, dir: action }).then(reload));
    }
  });

  try {
    if (localStorage.getItem('cms-hidden') === '1') {
      document.body.classList.add('cms-hidden');
      var t = document.querySelector('[data-cms-action="toggle-pens"]');
      if (t) t.textContent = t.getAttribute('data-show');
    }
  } catch (e) { /* bez úložiska */ }

  // ── Administrácia ─────────────────────────────────────────────────────────

  document.querySelectorAll('[data-cms-uploader]').forEach(function (host) {
    host.appendChild(createUploader({
      accept: '',
      onDone: function () {
        if (host.hasAttribute('data-reload')) setTimeout(reload, 900);
      }
    }).node);
  });

  document.addEventListener('submit', function (e) {
    var message = (e.submitter && e.submitter.getAttribute('data-confirm')) || e.target.getAttribute('data-confirm');
    if (message && !confirm(message)) { e.preventDefault(); return; }
    // Dlhšia akcia (napr. záloha): tlačidlo ukáže, že sa pracuje, a nedá sa kliknúť dvakrát.
    var busy = e.target.querySelector('[data-busy]');
    if (busy) {
      setTimeout(function () { busy.disabled = true; busy.textContent = busy.getAttribute('data-busy'); }, 0);
    }
  });
})();
