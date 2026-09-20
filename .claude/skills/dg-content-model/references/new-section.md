# Adding or removing a page section

A section is an anchor (`/#kluc`), a block in `pages/site.php`, an item in the top menu and the
footer, and a row in admin → Sekcie a menu. Keys are Slovak without diacritics (`domov`, `onas`,
`galeria`, `subor`, `repertoar`, `historia`, `kontakt`) because they are visible in URLs.

## Add

1. **`includes/content.php`** — add the key to `SECTIONS`. Its position is the default order.
   Installs that already saved an order get the new section right after its default neighbour
   (`section_order()` handles it) — no data migration needed.

2. **`includes/lang.php`**
   - `sec_<key>` — name in admin → Sekcie a menu
   - `default_nav_<key>` — menu label until someone renames it
   - `default_<group>_title` — default heading (and `default_<group>_intro` if there is an intro)

3. **`includes/entities.php` → `'settings'`**
   - in group `nav`: `'nav_<key>' => ['type' => 'text', 'i18n' => true, 'max' => 40, 'label' => 'sec_<key>'],`
   - a group for the heading and intro, following the existing ones:

     ```php
     'awards' => [
         'awards_title' => ['type' => 'text', 'i18n' => true, 'max' => 120, 'label' => 'f_section_title', 'hint' => 'hint_section_title'],
         'awards_intro' => ['type' => 'textarea', 'i18n' => true, 'max' => 1000, 'label' => 'f_section_intro'],
     ],
     ```
     plus `sg_awards` in `lang.php` (title of that edit window).

4. **`pages/site.php`**
   - `$visible['<key>'] = …` — `true`, or a condition like `$awards !== [] || $editor` when an empty
     section should not exist for visitors (then it also drops out of the menu).
   - A buffered block. Order of blocks in the file does not matter; every visible key must end up in `$html`:

     ```php
     <?php $html['repertoar'] = ob_get_clean(); ob_start(); ?>
     <section class="section" id="ocenenia" aria-labelledby="ocenenia-title">
       <div class="wrap">
         <header class="section__head cms-zone">
           <h2 class="section__title" id="ocenenia-title"><?= e(setting_label('awards_title')) ?></h2>
           <div class="section__intro"><?= paragraphs(setting_tr('awards_intro')) ?></div>
           <?= cms_settings('awards') ?>
         </header>
         …
       </div>
     </section>
     <?php $html['ocenenia'] = ob_get_clean(); ob_start(); ?>
     ```
     Watch the chain: each `ob_get_clean()` closes the block **above** it and `ob_start()` opens the
     next one. The last block ends with a plain `ob_get_clean()`.
   - Update the docblock at the top of the file (it lists the sections).

5. Styles in `static/css/site.css` under a `/* ---------- name ---------- */` divider (`dg-frontend`).
   The scroll-spy in `site.js` and the footer pick the section up automatically from `$nav`.

6. `README.md` („Stránka“), and a smoke check that the anchor exists for visitors.

## Remove

Code first, data second — see migration `014_slovak_only_about.sql`, which removed „V médiách“:

- take the key out of `SECTIONS`, `$visible`, the `$html` block, the `nav` settings, `lang.php`;
- migration: delete its settings (`DELETE FROM settings WHERE key LIKE 'media\_%' OR key LIKE 'nav\_media%'`),
  fix the saved order (`array_replace` / `array_remove` on `section_order`), drop its tables;
- `section_order()` ignores unknown keys, so a stale saved order never breaks the page — the migration is tidiness.
