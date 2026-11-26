=== ADX Gated Resources ===
Contributors: adxdepot
Tags: lead generation, gated content, zerobounce, downloads
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.19.7
License: GPLv2 or later

Soft-gates selected downloads (e.g. the Revit library), file bundles, or
whole pages behind a fully configurable email capture form, verifies
the email in real time with ZeroBounce, logs every download for Sales,
tracks subscribers, and remembers verified visitors for 30 days.

== Description ==

1. Go to **ADX Gated Resources > All Resources > Add New**. Give the
   resource a title, choose a Gate Type (Single file or Bundle), and
   paste the exact file URL(s) (or pick them from the Media Library).
   "Auto-gate" (single file only) is on by default.
2. Any link on the site whose href matches a single file's URL is
   automatically intercepted by the email-gate popup -- no need to edit
   the resource page itself. Alternatively use the shortcode shown on
   the edit screen, e.g. `[adx_gated_resource id="123"]Download[/adx_gated_resource]`,
   which works for both single files and bundles.
3. To gate a whole Page (e.g. a "BIM & Revit library" landing page with
   its own listing of downloads), open it for editing and tick "Require
   email verification to view this page" in the Gate Access box, with
   an optional teaser shown on the lock screen. Once a visitor passes
   the gate once, the same cookie unlocks the page itself and every
   gated download on it -- no repeat forms.
4. Go to **ADX Gated Resources > Form Fields** to add, edit, remove, or
   reorder the fields collected on the gate form (email is always
   collected and can't be removed). Each field can be a text box,
   textarea, dropdown (with your own options), or checkbox, and marked
   required or optional.
5. Go to **ADX Gated Resources > Settings** and enter your ZeroBounce
   API key, choose which ZeroBounce statuses are acceptable, list one
   or more email addresses to notify on every gate pass, and customise
   the form copy and cookie duration.
6. **ADX Gated Resources > Subscribers** lists everyone who has ever
   passed the gate, with their captured field values and total
   downloads -- click "View downloads" for their full history.
   **ADX Gated Resources > Downloads** is the underlying log: every
   single gate pass, which resource, and when. Both have a CSV export
   button.
7. To show the BIM library (resources grouped by product series, with
   filters and a search box), place `[adx_gated_library]` on a page.
   Each Category under **ADX Gated Resources > Categories** is a series
   (e.g. "1000 Series"); give it a Badge code (e.g. 1000), a Headline,
   a Description and a Logo for its card. Optionally put series under a
   parent category (e.g. "Aluminium Partitioning") to add a top-level
   filter. Assign each resource to its series; a resource whose file is
   a .zip (or a Bundle) becomes that series' "Download all" card.
   Combine this with #3 above -- gate the page itself -- so every
   download is instant, with no repeat form, for visitors who already
   passed the page gate.

8. To send product-page visitors to the library, pick the library page
   under **Settings > Library page** and place
   `[adx_library_access text="BIM & Revit Library"]` on each product
   page. Clicking it opens the gate form; on submit, the "Notify by
   email" addresses get an email naming the product (the page title,
   or `product="..."` if set) and the visitor is taken to the library.
   The library page is always gated: a visitor who reaches it without
   a valid cookie fills in the form there, and the email names the
   page they came from instead. Downloads themselves are logged but no
   longer send an email.

9. Every day at 5pm (site timezone; change it under **Settings > Daily
   download report**) the "Notify by email" addresses get a report of
   every download since the previous report, grouped by person, with
   a link to a report page that opens without logging in. Receivers
   can filter it by date (quick ranges such as "Last 7 days", or any
   from/to dates) and download the filtered view as CSV. Every link
   carries an unguessable access key; "Reset report links" voids every
   link sent so far.
   To send it by hand instead, untick the automatic email and click
   "Send today's report" at the end of each day.
   WordPress only runs scheduled tasks when the site gets a visit, so
   for an on-time send give your hosting provider the **Scheduler URL**
   shown under Settings > Daily download report (with a ready-to-send
   message). They call it every 5 minutes; the plugin sends the report
   once a day, within 3 hours after the send time, on the send days,
   and ignores every other call (a report not triggered in that window
   is skipped and its downloads go into the next one). "Test the connection" checks it without sending an
   email. With Saturday off, Sunday's "Weekend downloads report" covers
   everything since Friday's.

10. To move the library to another site (e.g. staging to live), go to
   **ADX Gated Resources > Import / Export**. On the site that has the
   records, tick the categories to export and click Download CSV (the
   file holds those categories and the resources in them). On the other
   site, install the plugin, upload that file, and set "Replace ...
   with ..." to change the address in the file links (e.g. the staging
   address to the live one). Then tick which of the file's categories to
   import. You are shown a summary and which file links do not answer
   yet before anything is saved; then click Import now. All the records
   are created first and the images are copied afterwards with a
   progress bar, so a slow image or a host's time limit can't leave
   resources out; the finished page confirms every resource in the file
   is on the site. Records are matched by slug, so importing again
   updates them instead of duplicating, and nothing is ever deleted.
   The .rfa and .zip files themselves are not copied, so upload them to
   the new site. Settings, the Library Hero, subscribers and the
   download log are not part of the file.

Only resources and pages you explicitly gate are affected -- everything
else on the site is unaffected. Logged-in users who can edit a gated
page always see its real content.

== Changelog ==

= 1.19.6 =
* New option on a resource, "Keep this resource out of the library page":
  it is not listed as a card in the [adx_gated_library] grid. Choosing a
  resource for the hero's download button (Library Hero) ticks it for you,
  and the file stays out of the grid whether or not the hero is shown. The
  setting is part of the Import / Export file.
* Fixed the size shown on download buttons: a size ending in a zero was
  shortened, so a 280 MB file showed "28 MB" (and 100, 200, 250 MB showed
  "10", "20", "25"). It now shows the real figure on every button.

= 1.19.5 =
* The [adx_library_access] button now has a download icon before its text,
  and its text, icon and outline are white. Add icon="no" to leave the icon
  out, or class="..." to restyle it.

= 1.19.4 =
* The plugin's shortcodes now work when their quotes have been turned into
  curly quotes by an editor or a paste (or only one of the two has):
  [adx_library_access text="Get files”] used to show the default word
  "Library" instead of the text. Applies to [adx_gated_resource],
  [adx_gated_library], [adx_library_access] and [adx_library_hero].

= 1.19.3 =
* Fixed an import bug that left resources out. The import looked up
  existing resources by slug with a WordPress function that also searches
  the Media Library, so a picture with the same slug as a resource (a
  family's image is often named like the family) was mistaken for the
  resource and "updated" instead of the resource being created. It now only
  matches real resources. Importing again creates the ones that were
  missed, and removes the resource data an earlier import had written onto
  those pictures.

= 1.19.2 =
* The check screen before an import now lists the resources that are
  already on the site but differ from the file (in another category, none,
  or another status), saying where each one is now, so you can see what
  the import will correct before you click Import now.

= 1.19.1 =
* After an import the category counts (the Count column on the
  Categories screen) are recounted, so a count left wrong by an earlier
  or interrupted import is corrected.
* The check at the end of an import is stricter: besides resources that
  are missing it now lists resources whose status differs from the file
  (a draft is not shown in the library) and resources that are not in the
  category the file gives them, saying which category they are in
  instead.

= 1.19.0 =
* Export by category: tick the categories to export and the file holds
  just those categories and their resources (plus "Resources without a
  category" if ticked). Import by category: after choosing the file,
  tick which of its categories to bring in and see the summary for
  just those.
* The import can no longer leave resources out because of a time limit:
  all the categories and resources are created first, then the images
  are copied in small batches with a progress bar (a failed or slow image
  never loses a record, and "Continue copying" resumes). When it is done
  the page checks that every resource in the file is on the site and
  lists any that are missing or have no category.
* A category that already exists under a different slug on the new site is
  found by its name instead of failing, and a category that cannot be
  found no longer removes the categories a resource already has.

= 1.18.0 =
* New **Import / Export** screen: download every Category (badge code,
  headline, card description, logo, order) and every Resource (file
  links, bundle links, categories, badges, image) as one CSV, and import
  that CSV on another site. The import shows a summary and checks the
  file links first, matches records by slug (re-importing updates,
  never duplicates, nothing is deleted), can change the address in file
  links (e.g. staging to live) and copies images into the Media Library.

= 1.17.4 =
* Updating the plugin now clears the file sizes and zip file counts it
  remembered earlier, so a count that was looked up before a zip finished
  uploading (or by an older version) no longer sticks; the library page
  fills them in again.

= 1.17.3 =
* More reliable file counts and sizes for files hosted outside this
  server: a failed lookup is retried after 10 minutes instead of being
  remembered for 6 hours; a server that won't answer a "last bytes"
  request is asked for the same bytes by position; and a page view may
  now make 20 new lookups instead of 8, so a library with many large
  zips is filled in at once.

= 1.17.2 =
* A Bundle that contains just one .zip is now treated as that zip: the
  library shows how many files are inside it ("Contains 9 files")
  instead of "Contains 1 file", and the zip downloads as it is instead of
  being zipped again.

= 1.17.1 =
* On the library page, the words "series" and "nexus" always start with a
  capital: "1000 series" shows as "1000 Series" and "NEXUS 64" as
  "Nexus 64" (filters, cards, "Download all" titles, descriptions, the
  hero). The names saved in the admin are not changed.

= 1.17.0 =
* New hero banner at the top of the library page, managed under
  **ADX Gated Resources > Library Hero**: title, intro, three headline
  numbers (families, Product Series, file format) and a "Download complete
  library" button. The numbers are counted from the library automatically
  (type a value to override one); the button downloads a normal resource
  (the zip of the whole library) with the email gate and its size shown,
  and that resource is kept out of the grid.
* `[adx_gated_library hero="no"]` hides it on one page;
  `[adx_library_hero]` places the banner on its own.
* "Product series" is now "Product Series" in the plugin's own text.

= 1.16.4 =
* The green line along the top of the series description card is shown
  by default and disappears while the card is hovered (it returns when
  the mouse leaves). Theme styles can't change it.

= 1.16.2 =
* The thick green line along the top of the series description card on
  the library page is kept (1.16.1 removed it by mistake).

= 1.16.0 =
* New "Library card" section on the Category add/edit screen, with the
  category image (logo), the green sub description and the card
  description in one place, plus a live preview of the card as you type.
  Badge code and Display order sit in a "Library settings" section below.
* The card description is now its own field (line breaks are kept)
  instead of WordPress's Description box, which is hidden on these
  screens. Existing categories keep showing their old description until
  the category is saved once.

= 1.15.2 =
* The scheduler URL's reply now shows how many downloads (and people)
  are waiting for the next report: `pending_downloads`, `pending_people`
  and `pending_since`. The same sentence is added to the message when
  nothing is sent.

= 1.15.1 =
* The automatic report is now only sent within 3 hours after the send
  time (5pm). Previously a report that was missed, e.g. because the
  daily report was switched off or the scheduler was down, was sent as
  soon as the scheduler URL was next opened, even the next morning. Now
  it's skipped ("missed") and its downloads go into the next report, so
  nothing is lost. Applies to the scheduler URL and the WP-Cron job; the
  window can be changed with the adx_gr_report_send_window filter.
* The first call to the scheduler URL on a brand-new setup now connects
  quietly on any day of the week.

= 1.15.0 =
* Scheduler URL for sites without a server cron job: a secret link
  (Settings > Daily download report) that the hosting provider's
  scheduler calls every few minutes. The plugin works out for itself
  whether the report is due (send time passed, a send day, not yet sent
  that day), so it sends exactly once a day at the send time (5pm South
  Australia time when the site timezone is Adelaide), repeat or
  overlapping calls do nothing, and it follows daylight saving without
  the scheduler needing to. Add &dry_run=1 to test the connection
  without sending. The reply is JSON (HTTP 200 fine, 500 a report could
  not be sent, 403 wrong key).
* Failed sends are retried on later calls (3 attempts) and never lose
  downloads; each report picks up from the last one that went out. A
  Saturday send time with Saturday off is skipped, so downloads after
  Friday's report are in Sunday's weekend report.
* Settings shows the URL with a Copy button, a ready-to-send message for
  the hosting provider, the last call received, and a warning when the
  site timezone isn't Adelaide. "Generate a new URL" replaces the key.
* The WP-Cron job and the URL share one routine, so they can both be on
  without sending a report twice.

= 1.14.0 =
* The report page has a search box: by name, email, company, or the
  downloaded resource (including its series). All the words typed must
  match; capitals don't matter. Words that match the person show all
  their downloads, words that match a resource show that person with
  those downloads. The counts at the top and the Download CSV button
  follow the search, it carries over when changing the date range, and
  a link opened from the email keeps its exact period while searching.
  A "Clear search" link (and an x in the box) returns to the full list.

= 1.13.2 =
* The report's CSV no longer includes the "Lifetime downloads" and
  "ZeroBounce status" columns.

= 1.13.1 =
* The daily report now shows the series of each downloaded resource: as
  a tag beside the resource on the report page, in the "Downloads
  report" email's list of downloads (e.g. "Door Frame with Window (1000
  series)"), and as a Series column in the report's CSV.

= 1.13.0 =
* The Downloads log (and its CSV export) now has a Series column, next
  to Resource, showing the series (category) of each downloaded
  resource, so identically named families in different series can be
  told apart. It reads the resource's current categories, so it also
  fills in for downloads logged earlier. A resource ticked in more than
  one series lists them all; a deleted or uncategorised resource shows
  a dash.

= 1.12.7 =
* The library grid now shows up to 5 columns by default (was 4),
  stepping down on smaller screens or when zoomed in. Each card stays
  at least 200px wide.

= 1.12.6 =
* Library search box: a clear (×) button appears while something is
  typed; clicking it (or pressing Esc) clears the search and resets the
  filters to All. The browser's own clear button is hidden to avoid two.
* The library grid now shows at most 4 columns (sharing the container
  width), stepping down to 3, 2 and 1 on smaller screens or when zoomed
  in. The maximum can be changed with the --adx-gr-lib-columns CSS
  variable.
* "Download all" collages only use images from families that belong to
  that series alone; a family ticked in more than one series (e.g. a
  copied resource that kept the original's tick) is left out.
* Series names on the description card fit on one line at narrower
  card widths.

= 1.12.5 =
* The library sits inside the theme's content container again (lined up
  with the logo and menu), and its cards now share the container width
  evenly (at least 200px each), so it fills the container edge to edge
  and shows as many columns as fit: about 6 on a typical desktop, fewer
  when zoomed in, 1 on phones. `layout="full"` gives the full-width
  version instead.
* Series names on the description card are slightly smaller so names
  like "1000 series" stay on one line, and the download buttons keep
  "Download .zip" and the size each in one piece.

= 1.12.4 =
* The library now spans the full browser width by default, breaking out
  of the theme's content column, so the grid fits as many columns as the
  screen allows and adds or removes columns when the window is resized
  or zoomed. Use `[adx_gated_library layout="contained"]` to keep it
  inside the theme's column (e.g. on a page with a sidebar).

= 1.12.3 =
* "Contains X files" on a "Download all" card is now always counted
  from the zip, including zips hosted on another server: only the zip's
  list of contents at the end of the file is fetched (a few KB), not
  the whole zip. Folders and macOS junk files (__MACOSX, .DS_Store)
  aren't counted. The card's file type also comes from the files inside
  (e.g. "RFA" rather than "ZIP"). Works with very large (ZIP64) zips.
* Remove the "Number of files" field added in 1.12.1; any number
  entered there is ignored.

= 1.12.2 =
* The library now shows file sizes for files hosted on another server
  too (another domain, a CDN, or the live site's URL used on staging).
  The size is read from the other server's response headers, without
  downloading the file, cached for a week, and refreshed when the
  resource is saved. Links that lead to a web page (e.g. share links)
  or are broken show no size.

= 1.12.1 =
* New "Number of files" field on the resource edit screen. For a .zip
  or bundle, the number entered is shown as "Contains X files" on its
  "Download all" card in the library; leave it blank to count the files
  automatically.

= 1.12.0 =
* New BIM library design for `[adx_gated_library]`, grouped by product
  series: a description card per series (logo, name, headline,
  description), a "Download all" card for the series' .zip (with an
  image collage, file count read from the zip, and size), then a card
  per family with the series badge, file type, last-updated month,
  series code before the title and file size on the button.
* Filters: optional parent-category toggle, series chips, and a search
  that matches series codes and names as well as titles.
* Categories screen: new Badge code, Headline, Logo and Display order
  fields (the logo falls back to the parent category's).
* The Series taxonomy and the per-resource Badges field are no longer
  used by the library page.

= 1.11.2 =
* Remove the Series box from the add/edit resource screen. Series
  already assigned to resources are kept, and the Series screen in
  the admin menu is unchanged.

= 1.11.1 =
* The ADX Gated Resources admin menu now stays open, with the right
  item highlighted, on the resource screens (All Resources, adding or
  editing a resource) and the Categories and Series screens, including
  editing a single category or series. Previously the menu collapsed
  on the Categories/Series screens, and adding a resource highlighted
  "Subscribers".

= 1.11.0 =
* "Notify by email" notifications now go out only for new subscribers.
  Someone already in the Subscribers list (for example back after
  their remember-me cookie expired, or clicking the library button
  again while remembered) no longer triggers a "Page unlocked" or
  "Library access" email. Their details are still updated and their
  downloads still appear in the daily report.

= 1.10.0 =
* Daily download report: choose which days it is emailed (Settings >
  Daily download report > "Send on"). Every day except Saturday by
  default: a report at close of business Monday to Friday, then one
  "Weekend downloads report" on Sunday at 5pm covering Friday 5pm to
  Sunday 5pm, ready for Monday morning. Downloads on days without an
  email always roll into the next report.
* A report ending on a Sunday that covers more than a day is titled
  "Weekend downloads report" in its subject and heading, with the
  date range in the subject.
* A run that WordPress fires late (WP-Cron waits for a site visit)
  still counts for the day it was due, so Friday's report going out
  after midnight is not skipped as a Saturday one.

= 1.9.0 =
* Add "Send today's report" (Settings > Daily download report), for
  sending the real end-of-day report by hand. It covers everything
  from midnight, or from the end of the last report if that's earlier,
  up to the moment it's clicked, so evening downloads after the
  previous report are included and nothing is repeated the next day.
  It asks for confirmation, names the period and the recipients, and
  works with "Resend last report". Turn off the automatic daily email
  when sending by hand, to avoid two reports.

= 1.8.2 =
* "Send test report" now covers the last 24 hours (or back to the last
  scheduled report, if that's longer). Previously it only covered the
  time since the last 5pm run, so a test sent just after one, even a
  skipped one, came out empty. Repeat clicks send the same logs again.
* The confirmation after a test send says how many downloads it held,
  and warns when the report was empty.
* "Resend last report" is always shown, greyed out with an explanation
  until a scheduled report has been emailed.

= 1.8.1 =
* "Send report now" is now "Send test report": it emails what tonight's
  report covers so far without moving the report's starting point, so
  the scheduled 5pm report still includes those downloads (previously a
  manual send consumed them, and sending again showed zero).
* Add "Resend last report": emails the last scheduled report's exact
  period again, also without affecting the next scheduled report.
* Test and resent emails are marked "[Test]" / "[Resent]" in the
  subject, with a short note in the email.

= 1.8.0 =
* Add a daily download report: emailed to the "Notify by email"
  addresses at a set time (default 5pm, site timezone), covering every
  download since the last report, grouped by person with their details
  and a "new subscriber" flag. The email links to a no-login report page
  with date filtering (quick ranges and a from/to picker) and a CSV
  download of the filtered view. Settings add an on/off switch, the send
  time, "skip days with no downloads", "Send report now", "Open report
  page", and "Reset report links".

= 1.7.0 =
* Add the `[adx_library_access]` product-page button and a "Library
  page" setting. The notification email is now sent when a visitor
  uses this button (naming the product it sits on), not on every
  download. Downloads are still logged.

= 1.4.2 =
* Gated pages now unlock correctly behind a full-page cache (e.g. Nginx
  FastCGI cache): verification also sets the standard
  `wordpress_no_cache` bypass cookie (filter: `adx_gr_cache_bypass_cookie`),
  and unlocked gated pages are sent with no-cache headers so the real
  content is never cached and served to unverified visitors.
* "Unlock this page" tries a remembered visitor's cookie first, so a
  returning visitor shown a cached lock screen isn't asked to fill in
  the form again.
* Single-file downloads are streamed as an attachment instead of
  redirecting to the file's URL.
* Subscribers screen: delete a subscriber via the row's "Delete" link,
  or several at once via checkboxes and the "Delete" bulk action. Their
  download log entries are kept.

= 1.4.0 =
* Add a `[adx_gated_library]` shortcode: a searchable, filterable grid
  of every published Gated Resource (single files and bundles), for a
  "resource library" landing page. Two new taxonomies, Category and
  Series, drive the pill filter rows shown above the grid; a free-text
  "Badges" field per resource (e.g. "LOD 300, RFA, 2021+") drives the
  small tag row on each card; and each resource can optionally have a
  featured image for its card, falling back to a generic icon.
* Bundle downloads ("Download all") are now zipped on the fly into a
  single file and downloaded in one click, instead of showing a list
  of individual file links to click through one by one.
* Since the library grid's download buttons reuse the existing gate
  mechanism, placing `[adx_gated_library]` on a page that itself has
  "Require email verification to view this page" enabled means every
  download on the grid is instant for visitors who already passed that
  gate -- no per-file form.

= 1.3.0 =
* Add a Form Fields builder (Gated Resources > Form Fields): every
  field on the gate form except email is now fully configurable --
  add, edit, remove, and reorder fields, each as text, textarea,
  dropdown, or checkbox, required or optional. Replaces the old fixed
  First Name/Last Name/Company/Role/State/Project fields and the
  separate Role/State option-list settings.
* Add a dedicated Subscribers screen: one row per unique email,
  aggregated across every gate pass, with total downloads, first-seen
  and last-download dates, and a link to that person's full download
  history.
* The old "Leads" screen is renamed "Downloads" and is now the
  per-event log (every single gate pass, not deduplicated), so you can
  see exactly what was downloaded and when, alongside the new
  aggregated Subscribers view.
* CSV exports for both Subscribers and Downloads now include a column
  for every currently configured form field.

= 1.2.0 =
* Add "Bundle" gate type: a gated resource can now hold multiple files;
  visitors get a list of one-time download links for each after passing
  the gate.
* Add whole-page gating: any Page can be marked "Require email
  verification to view this page" via a new Gate Access box on the Page
  edit screen. Unverified visitors see a locked-page view instead of the
  real template; the same verification cookie unlocks all gated content
  site-wide. Editors/admins always see the real page.

= 1.1.1 =
* Default Role/Discipline options: Architect, Interior designer, BIM
  manager, Builder. Default State options: NSW, QLD, SA, TAS, VIC, WA,
  Outside Australia.
* Add a "Notify by email" setting: comma/newline separated addresses
  that get emailed the lead's details every time someone completes the
  gate form or triggers a remembered (cookie) download.

= 1.1.0 =
* Expand the gate form with First Name, Last Name, Company, Role /
  Discipline, State, and an optional Project Name field, restyled to a
  two-column layout. All original labels, headings, and button text
  are unchanged. Role/Discipline and State options are configurable
  under Settings.
* Leads table, CSV export, and admin Leads screen updated to capture
  and display the new fields.

= 1.0.0 =
* Initial release.
