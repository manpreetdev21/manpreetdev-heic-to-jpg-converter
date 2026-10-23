=== ManpreetDev HEIC to JPG Converter ===
Tags: heic, heif, iphone, jpg, media
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.0
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically converts iPhone HEIC/HEIF photos to JPG or PNG inside the browser. No ImageMagick or server-side HEIC support needed.

== Description ==

Most browsers (Chrome, Firefox, Edge) cannot display .heic images, so photos uploaded from an iPhone show up broken on the website.

This plugin converts them to JPG (or PNG) in the browser of the person uploading. The server only receives a normal JPG/PNG and creates thumbnails the usual way. If your server can read HEIC, you can let it do the conversion instead.

= Features =

* **New uploads** are converted before they are uploaded: Media Library, media modal, Elementor, block editor and Media → Add New.
* **Existing images** already in the Media Library are converted in the background while an editor uses the dashboard, or on demand from HEIC Converter → Tools. Failed images can be retried with one click.
* **Same attachment ID**, so Elementor widgets, featured images and galleries keep working.
* **Old links updated**: .heic links in posts, pages and Elementor layouts, including resized copies (e.g. image-300x200.heic), point to the new file, and Elementor's CSS cache is cleared.
* **Server conversion (optional)**: on WordPress 6.7+ with Imagick HEIC support, the server converts instead of the browser, with the browser as a fallback.
* **Safe with big photos**: choose how many photos convert at the same time, and a maximum size so 48 MP photos stay within upload and memory limits.
* **Logs** of every conversion with file sizes, time taken, converter used and errors, downloadable as CSV.
* **Help page** with a system check of your server's upload size, memory and HEIC support.
* Optionally keeps the original .heic file on the server (removed when the attachment is deleted).
* **Translation ready**: every text in the plugin can be translated. A translation template is included in the languages folder.

= Admin pages =

Everything lives in its own **HEIC Converter** menu:

* **Tools** – stats and conversion of existing HEIC images.
* **Settings** – output format, quality, maximum size, parallel conversions, automation and link updates.
* **Logs** – conversion history with filters and CSV export.
* **Help** – how it works, FAQ and system check.

== Installation ==

1. In WordPress go to Plugins → Add New → Upload Plugin and upload the zip file.
2. Activate the plugin.
3. Open **HEIC Converter** in the admin menu. Check **Settings**, then use **Tools** to convert HEIC images already in your Media Library.

== Frequently Asked Questions ==

= Does the server need ImageMagick or libheif? =

No. Conversion happens in the browser by default. The server only needs to handle normal JPG/PNG files. If it can read HEIC (WordPress 6.7+ with Imagick), you can turn on "Let the server convert when it can".

= Do I need to keep the page open while existing images are converted? =

Yes. On HEIC Converter → Tools keep the page open until it says "Finished". Background conversion processes up to 25 images per dashboard page load.

= Which iPhone photos are supported? =

Photos from all iPhones, including iOS 18 HDR photos (iPhone 15/16 and newer). The plugin tries the browser's own HEIC support first (Safari), then heic-to (libheif 1.23), then heic2any as a last resort.

= Is the camera data (EXIF) kept? =

No. Browser conversion removes camera details such as the camera model, date taken and GPS location. This also stops visitors from seeing where a photo was taken. Keep the original .heic file if you need that information.

= My browser freezes or a large photo fails =

Set "Photos converted at the same time" to 1 and keep "Maximum size" at 4096 px or lower. Each 48 MP photo needs several hundred MB of memory while it is being converted.

= How do I translate the plugin? =

Use the template languages/manpreetdev-heic-to-jpg-converter.pot with a tool such as Poedit, and save the translation as languages/manpreetdev-heic-to-jpg-converter-{locale}.mo (for example manpreetdev-heic-to-jpg-converter-hi_IN.mo). Translations from translate.wordpress.org are used automatically and take priority.

= An image failed to convert =

See the reason in HEIC Converter → Logs and use "Retry failed" on the Tools page. If your media files are served from a different domain (a CDN or offload plugin), that domain must allow cross-origin requests from your admin area.

== Credits ==

* heic-to by hoppergee (LGPL-3.0), using libheif 1.23 — bundled unmodified in assets/vendor/heic-to.
* heic2any by Alex Corvi (MIT License), bundled in assets/vendor/heic2any (fallback).

== Changelog ==

= 1.2.0 =
* New: the plugin has its own admin menu, HEIC Converter, with Tools, Settings, Logs and Help pages.
* New: redesigned admin screens with conversion stats, a progress bar and thumbnails of converted images.
* New: Logs page listing every conversion with sizes, duration, converter and errors (latest 500 kept), with CSV download.
* New: "Photos converted at the same time" setting; uploads of many large photos no longer run out of browser memory.
* New: optional server conversion when the server supports HEIC, with browser fallback.
* New: "Retry failed" button on the Tools page.
* New: Help page with FAQ and a system check.
* New: translation ready, with a translation template (.pot) in the languages folder.
* New: the counters on the Tools page update live while images are converted.
* Fixed: HEIC files uploaded in the Media Library grid, the media modal and Elementor were not converted (the upload script was never loaded there).
* Fixed: when several HEIC photos were uploaded at once, the original HEIC files could be uploaded as well as the converted JPGs.
* Fixed: opening the Tools page retried failed images on every visit; they are now retried only with "Retry failed".
* Fixed: links to resized copies (e.g. image-300x200.heic) are now updated too.
* Fixed: images WordPress 6.7+ had already converted on the server were listed and converted a second time.
* Fixed: an image being converted in another browser tab was wrongly marked as failed.
* Fixed: two tabs could convert the same image at the same time.
* Plugin renamed to ManpreetDev HEIC to JPG Converter; text domain now matches the plugin slug.
* Settings and logs are removed when the plugin is deleted.

= 1.1.0 =
* Fixed "ERR_LIBHEIF format not supported" for newer iPhone photos (iOS 18 HDR) by switching to a current libheif decoder, with the browser's own decoder and the old one as fallbacks.
* New "Maximum size" setting (default 4096 px) so 48 MP photos stay under upload limits and browser canvas limits.
* Images that failed with the previous version are retried automatically.

= 1.0.0 =
* First release.
