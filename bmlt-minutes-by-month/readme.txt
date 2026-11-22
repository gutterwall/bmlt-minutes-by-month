=== BMLT Minutes by Month ===
Contributors: gutterwall
Tags: meeting minutes, bmlt, archive, shortcode
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Requires Plugins: bmlt-minutes
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lists BMLT Minutes by committee, year and month, and shows a committee's latest minutes whole in the page.

== Description ==

A companion to [BMLT Minutes](https://wordpress.org/plugins/bmlt-minutes/). Its `[bmlt_minutes_by_month]` shortcode shows every committee's minutes as an archive:

* a heading for each committee (alphabetical; minutes without a committee come last, under "Uncategorized")
* a heading for each year
* each month rolled up into a collapsible section showing how many documents it holds; only the committee's newest month starts open
* in an open month, each document as a link in the style of BMLT Minutes' own `[bmlt_minutes]` list
* with `show_excerpt="true"`, as in `[bmlt_minutes]`, each document is shown instead as WordPress's File block: its title linking to the file and a Download button, and for a PDF a 500 pixel high preview, with the minutes' excerpt above it when they have one

The File block previews PDFs only: Word, Excel, PowerPoint, OpenDocument, text and CSV files get the link and Download button, since browsers cannot show them in the page. Phones and other browsers without a PDF viewer get the link and button too (WordPress hides the preview there). The PDFs of a closed month are downloaded only when the month is opened, so a long archive stays quick to load.

Google Docs and other web links, and password-protected minutes, are always listed as links. Password-protected minutes link to their own page, where WordPress asks for the password, and never show the document's address.

Minutes are filed under the month they were posted, or under their meeting date with `date="meeting"`. Minutes filed under two committees appear under both.

= Shortcode attributes =

* `committee`: a committee slug, or several separated by commas. Sub-committees are included. Default: all committees.
* `date`: `posted` (default, the month the minutes were posted) or `meeting` (the meeting date; minutes without one use the month they were posted).
* `year`: only this year, for example `2026`.
* `order`: `desc` (newest first) or `asc`. Default: BMLT Minutes' "Default Sort Order" setting.
* `open`: which months start open: `latest` (default, the newest month of each committee), `all` or `none`.
* `show_excerpt`: `true` shows each document as a File block (with a PDF preview) and the minutes' excerpt; `false` (default) lists each document as a link.
* `preview_height`: the height of PDF previews in pixels with `show_excerpt="true"` (default `500`); `0` shows no previews.
* `display`: from version 1.0; `file` shows File blocks and `list` links, whatever `show_excerpt` says. Leave it out to follow `show_excerpt`.
* `heading_level`: the committee heading level, `2` to `4` (default `3`). Years and months use the next two levels.
* `show_date`: `true` (default) shows each document's meeting date; `false` hides it.

= Examples =

`[bmlt_minutes_by_month]`

`[bmlt_minutes_by_month committee="richmond-area" date="meeting"]`

`[bmlt_minutes_by_month show_excerpt="true"]`

`[bmlt_minutes_by_month year="2026" open="all" heading_level="2" show_excerpt="true" preview_height="700"]`

= The latest minutes, whole: [bmlt_minutes_latest] =

`[bmlt_minutes_latest committee="policy"]` shows the newest minutes of a committee in full on the page: a page for the area's policy guide or the last meeting's minutes always shows the newest version, readable without downloading anything, and updates itself when new minutes are posted.

* **PDF**: every page is drawn in the page by PDF.js (Mozilla's PDF reader, included), on computers and phones alike. The text can be selected, copied and found with the browser's Find, and the PDF's web links work. Pages are drawn as they scroll into view, so long documents stay light on phones.
* **Word (.docx), OpenDocument (.odt), Excel (.xlsx), OpenDocument spreadsheets (.ods), CSV and text files** become part of the page: headings, paragraphs, bold, italic and underlined text, links, numbered and bulleted lists (numbered as in the document, "a)", "iv." and so on), and tables. A Word table of contents becomes links to its headings. Pictures, headers and footers are left out.
* **Minutes written in the post itself** show their text.
* **Google Docs and other web pages, and older .doc, .xls and .ppt files**, get a link to open or download them.
* **Password-protected minutes** show WordPress's password form; once the password is entered, the page shows them.

Above the document: its title, meeting date and a Download link.

Attributes:

* `committee`: a committee slug, or several separated by commas. Sub-committees are included. Default: all committees.
* `date`: which minutes are newest: `posted` (default, the latest posted) or `meeting` (the latest meeting date).
* `heading_level`: the title's heading level, `2` (default) to `5`. The document's own headings take the levels below it.
* `show_title`, `show_date`, `download`: `true` (default) or `false`, to hide the title, the meeting date or the Download link.

Converted documents are kept (as WordPress transients) until the file changes, so each is converted once, not on every page view.

This shortcode has been proposed to BMLT Minutes. If your version of BMLT Minutes has its own `[bmlt_minutes_latest]`, that one is used.

= Third-party code =

[PDF.js](https://mozilla.github.io/pdf.js/) 6.5.438 by Mozilla, Apache License 2.0, unmodified, in the `pdfjs` folder (its licence is `pdfjs/LICENSE`). It runs in the visitor's browser and loads only from this site.

== Installation ==

1. Install and activate BMLT Minutes.
2. Upload this plugin (Plugins → Add New → Upload Plugin) and activate it.
3. Add `[bmlt_minutes_by_month]` or `[bmlt_minutes_latest committee="…"]` to a page.

== Frequently Asked Questions ==

= Why are some committee headings long, like "Area Service Committee / Richmond Area / Public Relations"? =

When two listed committees have the same name, each heading shows the committees above it so they can be told apart.

= Does it store anything? =

Only the HTML of documents `[bmlt_minutes_latest]` converted, as WordPress transients (in the database), so each document is converted once. Deleting the plugin removes them. It adds no settings, tables or files, and never changes the minutes.

= Why does a Word document look plainer on the page than in Word? =

The page keeps what reads well on any screen (headings, lists, tables, emphasis and links) and uses your theme's fonts and colours. Download it for the exact layout.

= Can it show Google Docs? =

Not yet: it links to them. Google only lets the owner's account read a document unless it is shared publicly.

== Changelog ==

= 1.2.0 =
* New `[bmlt_minutes_latest]` shortcode: a committee's newest minutes shown whole in the page. PDFs are drawn by the included PDF.js, with selectable, searchable text; Word, OpenDocument, Excel, CSV and text files become HTML.

= 1.1.0 =
* Documents show as File blocks with PDF previews only with `show_excerpt="true"`, as `[bmlt_minutes]` does; without it each document is a link. Add `show_excerpt="true"` to keep the 1.0 look (`display="file"` also still works).
* With `show_excerpt="true"`, the minutes' excerpt shows under each document when they have one.

= 1.0.0 =
* First release: the `[bmlt_minutes_by_month]` shortcode, with documents as File blocks and PDF previews.
