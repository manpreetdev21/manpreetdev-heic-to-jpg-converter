=== HEIC to JPG Converter ===
Tags: heic, heif, iphone, jpg, media
Requires at least: 5.8
Requires PHP: 7.0
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically converts iPhone HEIC/HEIF photos to JPG or PNG inside the browser. No ImageMagick or server-side HEIC support needed.

== Description ==

Most browsers (Chrome, Firefox, Edge) cannot display .heic images, so photos uploaded from an iPhone show up broken on the website.

This plugin converts them to JPG (or PNG) in the browser of the person uploading. The server only receives a normal JPG/PNG and creates thumbnails the usual way.

* New uploads: HEIC files are converted before they are uploaded. Works in the Media Library, the media modal, Elementor, the block editor and Media → Add New.
* Existing images: HEIC images already in the Media Library are converted automatically in the background while an editor uses the dashboard, or on demand from Media → HEIC Converter.
* The attachment ID stays the same, so Elementor widgets, featured images and galleries keep working.
* Old .heic links in posts, pages and Elementor layouts are updated to the new file, and Elementor's CSS cache is cleared.
* Optionally keeps the original .heic file on the server (removed when the attachment is deleted).

== Installation ==

1. In WordPress go to Plugins → Add New → Upload Plugin and upload the zip file.
2. Activate the plugin.
3. Go to Media → HEIC Converter to choose JPG or PNG and other options, and to convert existing HEIC images.

== Frequently Asked Questions ==

= Does the server need ImageMagick or libheif? =

No. Conversion happens in the browser. The server only needs to handle normal JPG/PNG files.

= Do I need to keep the page open while existing images are converted? =

Yes, conversion runs in the browser. On Media → HEIC Converter keep the page open until it says "Finished". Background conversion processes up to 25 images per dashboard page load.

= Which iPhone photos are supported? =

Photos from all iPhones, including iOS 18 HDR photos (iPhone 15/16 and newer). The plugin tries the browser's own HEIC support first (Safari), then heic-to (libheif 1.23), then heic2any as a last resort.

= An image failed to convert =

The reason is shown in Media → HEIC Converter, where you can try again. If your media files are served from a different domain (a CDN or offload plugin), that domain must allow cross-origin requests from your admin area.

== Credits ==

* heic-to by hoppergee (LGPL-3.0), using libheif 1.23 — bundled unmodified in assets/vendor/heic-to.
* heic2any by Alex Corvi (MIT License), bundled in assets/vendor/heic2any (fallback).

== Changelog ==

= 1.1.0 =
* Fixed "ERR_LIBHEIF format not supported" for newer iPhone photos (iOS 18 HDR) by switching to a current libheif decoder, with the browser's own decoder and the old one as fallbacks.
* New "Maximum size" setting (default 4096 px) so 48 MP photos stay under upload limits and browser canvas limits.
* Images that failed with the previous version are retried automatically.

= 1.0.0 =
* First release.
