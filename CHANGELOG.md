# Changelog

## 1.2.0 (2026-11-22)

- New `[bmlt_minutes_latest]` shortcode: the newest minutes of a committee (and its sub-committees) shown whole in the page, for policy guides and last month's minutes. PDFs are drawn page by page with PDF.js 6.5.438 (included, Apache-2.0), with selectable, searchable text and working links, on desktops and phones; pages draw as they come into view. Word (.docx), OpenDocument (.odt, .ods), Excel (.xlsx), CSV and text files are converted to HTML on the server (headings, emphasis, links, lists numbered as in the document, tables; a Word table of contents links to its headings) and cached as transients until the file changes. Password-protected minutes show WordPress's password form.
- `uninstall.php` removes those cached conversions.
- If BMLT Minutes has its own `[bmlt_minutes_latest]` (proposed as bmlt-enabled/bmlt-minutes#12), this plugin leaves it to BMLT Minutes. Its script and stylesheet handles (`bmlt-minutes-by-month-latest`) never clash with BMLT Minutes'.

## 1.1.0 (2026-11-22)

- Documents show as File blocks with PDF previews only with `show_excerpt="true"`, as `[bmlt_minutes]` does; without it each document is a link in BMLT Minutes' list style. Add `show_excerpt="true"` to keep the 1.0 look (`display="file"` also still works).
- With `show_excerpt="true"`, the minutes' excerpt shows under each document when they have one.

## 1.0.0 (2026-11-21)

- First release: the `[bmlt_minutes_by_month]` shortcode lists BMLT Minutes by committee, year and month (collapsible; the newest month open), by posting date or meeting date. Documents show as WordPress File blocks, PDFs with a 500 px preview that loads when its month is opened.
