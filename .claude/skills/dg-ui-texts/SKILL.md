---
name: dg-ui-texts
description: Rules for every text a visitor or editor reads on the Divadielko Galéria site — where texts live (includes/lang.php and its key prefixes), how default_ texts and editor-overridable texts behave, placeholders, texts used by JavaScript, and the Slovak tone and typography the project keeps („…“ quotes, — and – dashes, …, formal vykanie, friendly theatre voice). Use whenever you add or change a label, hint, button, error message, confirmation, e-mail text, placeholder-page copy or README wording; when a new lang key is needed for a field, entity, section or admin tab; when asked to "oprav text", "preformuluj", "oprav preklep / gramatiku", "zmeň hlášku", "fix the wording / typos"; and before writing ANY new Slovak string into PHP or JS. Also explains why a raw key such as f_director shows up on the page.
---

# Interface texts

The site is Slovak-only. Content (productions, dates, people…) lives in the database; **everything
else a person reads lives in `includes/lang.php`** — one array under `'sk'`, read with `t('key', …args)`.

`t()` returns the key itself when it is missing. Nothing crashes — the page just shows `f_director`
where a label should be. `php .claude/skills/dg-dev/scripts/check.php` finds missing keys (and with
`--unused`, keys nothing refers to any more — read that list critically, it is a heuristic).

## Where a key belongs — prefixes

Keep related keys together in the file (it is grouped by area with `//` comments) and follow the prefix:

| Prefix | Used for | Notes |
| --- | --- | --- |
| `f_<field>` | label of a form field from `entities.php` | reuse existing ones (`f_title`, `f_image`, `f_year`…); a field can point elsewhere with `'label' =>` |
| `hint_…` | help text under a field | referenced by `'hint' =>` in `entities.php` |
| `ent_<entity>` | title of the edit window, singular („Inscenácia“) | also used in admin → Súbory („použité v …“) |
| `sg_<group>` | title of a settings edit window | |
| `sec_<section>`, `default_nav_<section>` | section name in admin / default menu label | |
| `default_<key>` | text shown until an editor saves their own | see below |
| `cms_…`, `cms_add_…`, `cms_err_…` | pencils, add buttons, validation errors | |
| `js_…` | every string `static/js/cms.js` shows | exported wholesale by `t_prefix('js_')`; in JS: `s('key')` without the prefix |
| `cf_…`, `cf_err_…` | contact form | error keys travel in the URL (`?cf=cf_err_name`) — lowercase letters only |
| `up_err_…` | upload errors | |
| `adm_…`, `adm_arch_<entity>`, `adm_status_…`, `adm_role_…` | administration | |
| `bk_…`, `bk_t_<table>`, `bk_compat_…` | backups; human names of tables | keep `bk_t_` labels even for dropped tables — old backups still contain them |
| `ab_…`, `mode_…`, `mode_…_desc` | admin bar, site modes | |
| `wip_…`, `mnt_…` | the two placeholder pages | |
| `login_…`, `pw_…` | login, password rules | |
| `flag_…`, `mark_…` | editor-only badges / suffixes in selects | |

## Texts editors can override — `default_`

`setting_tr('tagline')` shows the editor's saved text; while nobody has saved one it shows
`default_tagline`. An editor who saves an **empty** text means "show nothing" — the default does not
come back. Headings and menu labels use `setting_label()`, which never goes empty. So:

- Changing a `default_…` string changes the site only where nobody has saved their own text. If the
  user asks to change wording that is visible on the live page and your edit seems to do nothing, the
  text is probably in the `settings` table — it is changed with the pencil on the page, not in code.
- `%d` in a default text becomes the founding year („Na scéne od roku %d“).

## Placeholders and special formats

- `t('key', $a, $b)` is `vsprintf` — `%s`, `%d`, in order. Keep the number of placeholders when rewording.
- In JavaScript `s('key', arg)` replaces **one** `%s`. No `%d`, no second argument.
- `months`, `weekdays`, `months_short` are comma-separated lists; `js_months` / `js_weekdays` are copies
  for the date preview in the edit dialog — change both. Months are in the **genitive** („14. marca“).
- Texts are printed through `e()`, so HTML inside a text shows up as text. The one exception is built
  in code: `footer_credit` gets its link inserted *after* escaping (`sprintf(e(t(…)), '<a …>')`).
- Editors' hints may mention UI labels — quote them exactly as the label reads („Zobraziť verejnosti“),
  so people can find them.

A few Slovak strings deliberately live outside `lang.php`: `setup.php` (it must work before the
database does) and the body of the notification e-mail in `includes/mail.php`. Same rules apply there.

## Voice

Written for two audiences: families looking for a puppet show, and a couple of non-technical editors
from the theatre. Warm, plain, a little theatrical — never bureaucratic, never technical.

- **Vykanie**, lower-case: „Napíšte nám“, „ozveme sa vám“. Polite requests put *prosím* between
  commas: „Skúste to, prosím, neskôr.“
- Placeholder pages and empty states speak theatre: „Opona sa čoskoro dvíha“, „Za oponou práve niečo
  opravujeme“, „Našu históriu práve spisujeme.“ An empty state says what is coming, not what is missing.
- Errors say what to do next, in the user's words: „Formulár bol otvorený príliš dlho. Obnovte, prosím,
  stránku a skúste to znova.“ — not „Token expired“. No codes, no English, no blame.
- Hints for editors explain the *consequence*: „Kým nie je zaškrtnuté, inscenáciu v repertoári vidíte
  len vy (prihlásení) — môžete ju v pokoji pripraviť.“ If a field can be left empty, say what happens then („Prázdne = …“).
- Destructive confirmations state what disappears and whether it can come back („Zo stránky zmizne;
  obnoviť sa dá v administrácii → Archív.“ / „Toto sa nedá vrátiť.“).
- Buttons are verbs in the infinitive or imperative plural already used nearby: „Uložiť“, „Zrušiť“,
  „Pridať termín“, „Obnoviť“. Check neighbours before inventing a new verb for the same action.
- The site no longer has an English version — do not mention English variants in hints or texts.

## Typography of `lang.php` values (checked: 100 % consistent — keep it so)

| Use | Character | Example |
| --- | --- | --- |
| quotes | „ “ (U+201E … U+201C) | „Práve hráme“ — never `"…"` |
| aside, explanation | — em dash with spaces | „Divadielko Galéria — krátka prestávka“ |
| ranges | – en dash with spaces | „1900 – 2100“ |
| ellipsis | … (one character) | „Ukladám…“ |
| menu path | → | „administrácia → Archív“ |
| multiplication / dimensions | × | „1200 × 800 px“ |
| currency, units | number, space, unit | „3 €“, „50 minút“, „1,5 kB“ (decimal comma) |

Dates read „14. marca 2026“, times „16:00“ (24 h). Sentences end with a full stop, labels and buttons
do not. In PHP source, strings are single-quoted, so an apostrophe inside needs `\'`.

**Outside `lang.php` the convention differs:** code comments, docblocks, migrations, CSS comments and
`README.md` are Slovak too, but there the closing quote is almost always a straight one — „Práve hráme"
— because it is what the keyboard gives. Match the file you are editing and do not mass-convert; the
strict table above is for strings people read on the site. `check.php` flags straight quotes in
`lang.php` values only. Commit messages are English.

## After editing

Run `check.php` (missing keys, stray straight quotes). For anything beyond a typo, look at the text in
place (`testsite.php serve`) — edit-dialog labels wrap on narrow screens, and menu labels are limited
to 40 characters.
