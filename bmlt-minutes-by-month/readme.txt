=== BMLT Minutes by Month ===
Contributors: gutterwall
Tags: meeting minutes, bmlt, archive, shortcode
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Requires Plugins: bmlt-minutes
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lists BMLT Minutes by committee, then by year and month.

== Description ==

A companion to [BMLT Minutes](https://wordpress.org/plugins/bmlt-minutes/). Its `[bmlt_minutes_by_month]` shortcode shows every committee's minutes as an archive:

* a heading for each committee (alphabetical; minutes without a committee come last, under "Uncategorized")
* a collapsible section for each year, showing how many documents it holds (the newest year is open)
* the months of that year, each with its documents

Minutes are filed under the month they were posted, or under their meeting date with `date="meeting"`. Minutes filed under two committees appear under both. Password-protected minutes link to their own page, where WordPress asks for the password, and never show the document's address. Items look the same as in BMLT Minutes' own `[bmlt_minutes]` list.

= Shortcode attributes =

* `committee`: a committee slug, or several separated by commas. Sub-committees are included. Default: all committees.
* `date`: `posted` (default, the month the minutes were posted) or `meeting` (the meeting date; minutes without one use the month they were posted).
* `year`: only this year, for example `2026`.
* `order`: `desc` (newest first) or `asc`. Default: BMLT Minutes' "Default Sort Order" setting.
* `open`: which years start expanded: `latest` (default, the newest year of each committee), `all` or `none`.
* `heading_level`: the committee heading level, `2` to `4` (default `3`). Years and months use the next two levels.
* `show_date`: `true` (default) shows each document's meeting date; `false` hides it.

= Examples =

`[bmlt_minutes_by_month]`

`[bmlt_minutes_by_month committee="richmond-area" date="meeting"]`

`[bmlt_minutes_by_month year="2026" open="all" heading_level="2"]`

== Installation ==

1. Install and activate BMLT Minutes.
2. Upload this plugin (Plugins → Add New → Upload Plugin) and activate it.
3. Add `[bmlt_minutes_by_month]` to a page.

== Frequently Asked Questions ==

= Why are some committee headings long, like "Area Service Committee / Richmond Area / Public Relations"? =

When two listed committees have the same name, each heading shows the committees above it so they can be told apart.

= Does it store anything? =

No. It reads BMLT Minutes' posts and adds no settings, tables or files.

== Changelog ==

= 1.0.0 =
* First release: the `[bmlt_minutes_by_month]` shortcode.
