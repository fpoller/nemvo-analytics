=== Nemvo Analytics ===
Contributors: fpoller
Tags: analytics, statistics, privacy, bot detection, b2b
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Self-hosted, cookieless web analytics with company identification and bot detection. Your data stays on your server.

== Description ==

Nemvo Analytics shows where your visitors come from, what they read, how long they stay, which companies visit and which visits are bots. Everything is stored in your own database and nothing is sent to third parties. It sets no cookies, so in most EU interpretations it needs no consent banner.

It is made for sites that want Google Analytics-style reports without Google, with extra insight for B2B sites: which organisations visit, and whether a visit came from a prospect's office, a home connection or a datacenter.

= Reports =

* Visitors, visits, pageviews, views per visit, visit duration, bounce rate, time on page, scroll depth and conversions, compared with the previous period or the same period last year.
* A time-series chart for every metric, by hour, day, week or month. Click a day to zoom in.
* Traffic sources: channels (organic search, social, AI assistants, email, paid, referral…), sources, referrers and UTM campaigns.
* Pages: top pages, entry and exit pages, time on page, and each page's journey (where visitors came from and went next).
* A world map with Europe, Nordics, Americas and Asia-Pacific views, plus countries, regions and cities.
* Companies & networks: organisations visiting from their own network or office line, network types (business, home, mobile, datacenter, education, government) and providers.
* Devices, operating systems, browsers, screen sizes and languages.
* When people visit: a weekday × hour heatmap.
* Goals and events: form submissions, file downloads, outbound links, email and phone clicks, site search terms, 404 pages, and your own events.
* A visit log: every visit with its pages, time on each page, events, organisation and bot classification.
* Realtime: people on the site right now, pageviews per minute and a live activity feed.
* Every chart, map cell and table row is clickable to filter the whole dashboard; filters can be combined and shared as a link.
* CSV export, a dashboard widget, a views column in the posts list and a weekly email report.

= Companies and networks =

Each visit's network is looked up in a free ASN database (DB-IP, downloaded when you click). Organisations with their own network are named directly. For connections from an internet provider, a reverse DNS lookup tells an office line (for example fw.acme.se, which names the company) from a home line. The IP address and hostname are never stored; only the network owner, its type and the company name or domain are.

= Automated traffic =

Nothing is blocked and nothing is lost: every visit is recorded with a bot score and the signals behind it. The dashboard shows people by default and can switch to automated traffic or everything. Signals include crawler user agents, automated browsers, hits sent without the tracker, referrer spam, datacenter networks, impossible browsing speed and missing browser headers on one side, and real mouse, touch or keyboard use and a solved proof of work on the other.

For stronger proof, connect a self-hosted [Cap](https://capjs.js.org) server. The Cap widget comes with the plugin, and WordPress relays the challenge so visitors only ever talk to your site.

= Privacy =

* No cookies, no local storage, no fingerprinting.
* Visitors are counted with a salted hash of IP address and browser; the salt is replaced daily (or weekly or monthly), so visitors cannot be followed over time.
* IP addresses are never stored. Screen sizes are bucketed and browser versions dropped.
* Do Not Track and Global Privacy Control are respected. Logged-in roles, IP ranges and paths can be excluded.
* Suggested privacy policy text and an opt-out shortcode: `[nemvo_analytics_optout]`.
* Data older than a set number of days (395 by default) is deleted automatically.

= Built for speed =

* The tracker is about 3 KB gzipped, printed inline, and loads nothing else unless a visit needs a proof of work.
* A hit costs a handful of small queries in one transaction. The response is sent before engagement and event pings are written where the server allows it.
* Optional queue: with Valkey (or Redis) in front, hits are answered at once and a worker writes them in batches of hundreds.
* Statistics live in the WordPress database by default, or in a separate MySQL or MariaDB database. Move them there and back with one click; tracking continues during the move.
* The dashboard asks for all its reports in a single request, and caches them in your persistent object cache when there is one.

= For developers =

* `nvaTrack( name, label )` and `data-nva-event` attributes for your own events.
* Filters: `nemvo_analytics_should_track`, `nemvo_analytics_allowed_hosts`, `nemvo_analytics_spam_referrers`, `nemvo_analytics_dns_servers`.
* WP-CLI: `wp nemvo-analytics status | install | migrate | queue work | lookup | geoip-update | purge | report | seed`.
* Configuration through wp-config.php constants or environment variables (`NEMVO_ANALYTICS_DB_*`, `NEMVO_ANALYTICS_QUEUE_URL`, `NEMVO_ANALYTICS_CAP_*`, `_FILE` variants for Docker secrets). The settings page shows ready-made Docker Compose examples.

Source code and issue tracker: [github.com/fpoller/nemvo-analytics](https://github.com/fpoller/nemvo-analytics).

= Made for sites without page caching =

Nemvo Analytics is built for sites that are not behind a page cache or a CDN. It still works with them, but JavaScript optimizers can delay the tracker and a proxy hides visitor addresses. The setup checklist and Site Health warn you when that is the case and say what to change.

== Installation ==

1. Install and activate the plugin. Statistics are collected right away, in the WordPress database.
2. Open **Analytics › Settings**. The setup checklist at the top shows what is worth doing next:
   * Download the free IP databases: countries (or countries and cities) and networks, needed to identify companies.
   * Add goals, such as a thank-you page or a form submission.
3. Logged-in administrators and editors are not counted. Use a private window to see your own visit under **Analytics › Realtime**.

Optional, for busy sites: a separate statistics database, the Valkey queue and Cap. Each section of the settings page explains how to set it up with Docker Compose or wp-config.php.

== Frequently Asked Questions ==

= Do I need a cookie banner? =

Nemvo Analytics sets no cookies and stores nothing on the visitor's device, and it does not store IP addresses. Most EU data protection authorities consider such analytics exempt from consent, but check what applies to you. Add the suggested text to your privacy policy (Settings › Privacy › Policy guide).

= How does it identify companies? Is that allowed? =

It looks up which network a visit came from, the same public information any web server log contains. It names organisations by their network or by the hostname of their office line, and never stores the IP address. Companies are organisations, not people, so this is generally not personal data, but mention it in your privacy policy (the suggested text does).

= Does it work with page caching or a CDN? =

Pageviews are sent by JavaScript, so cached pages are counted. But "delay JavaScript" and similar features can hold back the tracker, and a CDN or proxy hides visitor addresses unless you turn on "Trust proxy headers". The plugin was built for sites without caching; the setup checklist warns you when it finds a cache or a proxy.

= Where is the data stored? =

In your WordPress database (tables starting with your table prefix and `nemvo_`), or in a separate MySQL/MariaDB database if you set one up. Nothing leaves your server.

= Does it slow my site down? =

The tracker is about 3 KB gzipped and runs after the page has loaded. Collecting a hit takes a few milliseconds. With the optional queue, the database is only touched in batches.

= What happens with bots? =

They are recorded and flagged, not blocked, so your numbers show people only while you can still see the crawlers, scrapers and spam separately.

= Can I import from Google Analytics? =

Not in this version.

== External services ==

The plugin works without any external service. These are only contacted when you set them up:

* **DB-IP** (db-ip.com): when an administrator clicks a download button on the settings page or runs `wp nemvo-analytics geoip-update`, the free "IP to Country Lite", "IP to City Lite" or "IP to ASN Lite" database is downloaded from download.db-ip.com, once, and then used locally. With "Update the downloaded IP databases monthly" turned on, it is downloaded again once a month. No visitor data is sent. The databases are licensed under [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/); see the [DB-IP terms](https://db-ip.com/db/lite.php).
* **Your DNS resolver**: with "Use reverse DNS to tell offices from homes" turned on, the reverse DNS name of a visiting IP address is looked up through the server's own resolver (from /etc/resolv.conf), once per visit, to tell office lines from home lines. Like any DNS query, the resolver sees the address being looked up.
* **Your Cap server** (optional, self-hosted, [capjs.js.org](https://capjs.js.org)): with Cap turned on, WordPress forwards proof-of-work challenges between the visitor's browser and the Cap server you configured, including the visitor's IP address in an X-Forwarded-For header for Cap's rate limiting, and verifies the result with it.
* **Your Valkey or Redis server** (optional): with the queue turned on, collected hits (without IP addresses) wait there until they are written to the database.

== Third-party code ==

All bundled in `assets/vendor/` (see `assets/vendor/README.txt`), so browsers load nothing from a CDN:

* Chart.js 4.5.1, MIT, https://github.com/chartjs/Chart.js
* chartjs-chart-geo 4.3.6, MIT, https://github.com/sgratzl/chartjs-chart-geo (includes parts of d3 and topojson-client, ISC)
* chartjs-chart-matrix 3.1.0, MIT, https://github.com/kurkle/chartjs-chart-matrix
* World map data from world-atlas 2.0.2 (ISC) and Natural Earth (public domain), simplified.
* Cap widget 0.1.58 and Cap WASM 0.0.8, Apache-2.0, https://github.com/tiagozip/cap, and pako 2.1.0, MIT and Zlib, https://github.com/nodeca/pako

The minified files are unmodified copies of the published packages. `assets/js/tracker.min.js` is generated from `assets/js/tracker.js` with terser.

== Screenshots ==

1. The dashboard: key metrics, the trend chart, sources and pages.
2. Locations, companies & networks, goals, devices and the weekday × hour heatmap.
3. One visit: pages, time on each page, events and how the visit was classified.
4. Automated traffic: what was filtered out and why.
5. Realtime.
6. Dark mode.
7. Settings with the setup checklist and Docker Compose instructions.

== Changelog ==

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.0.0 =
First release.
