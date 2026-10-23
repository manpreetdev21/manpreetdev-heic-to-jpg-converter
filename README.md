# ManpreetDev HEIC to JPG Converter

A WordPress plugin that converts iPhone **HEIC/HEIF** photos to **JPG** or **PNG**, so they display in every browser. Conversion runs in the browser of the person uploading, so the server needs no ImageMagick or libheif. Servers that can read HEIC can do the work themselves.

- **Plugin URL:** https://github.com/manpreetdev21/manpreetdev-heic-to-jpg-converter
- **Version:** 1.2.0
- **Requires:** WordPress 5.8+, PHP 7.0+
- **Tested up to:** WordPress 7.1
- **License:** [GPL-2.0-or-later](LICENSE.txt)

## Features

- **Converts on upload** in the Media Library, media modal, Elementor, block editor and Media → Add New.
- **Converts existing images** in the Media Library, in the background or on demand, with one-click retry of failures.
- **Keeps the attachment ID**, so featured images, galleries and Elementor widgets keep working.
- **Updates old links** in posts, pages and Elementor data, including resized copies, and clears Elementor's CSS cache.
- **Optional server conversion** on WordPress 6.7+ with Imagick HEIC support. If the server fails on a file, the browser converts it instead.
- **Memory-safe:** a limit on parallel conversions plus a maximum output size (48 MP photos are scaled down).
- **Logs** of every conversion (sizes, duration, converter, errors) with CSV export.
- **Help page** with FAQ and a system check.
- **Translation ready**: text domain `manpreetdev-heic-to-jpg-converter`, template in `languages/`.

## Admin pages

The plugin adds its own **HEIC Converter** menu:

| Page     | Who                  | What                                                                                |
|----------|----------------------|-------------------------------------------------------------------------------------|
| Tools    | `upload_files`       | Stats, list of HEIC images, convert / retry failed                                  |
| Settings | `manage_options`     | Format, JPG quality, max size, parallel conversions, automation, server conversion   |
| Logs     | `manage_options`     | Conversion history, filters, CSV download, clear                                    |
| Help     | `upload_files`       | How it works, FAQ, system check (admins)                                            |

## How it works

1. **Decoding:** the browser's native decoder is tried first (Safari 17+), then [heic-to](https://github.com/hoppergee/heic-to) (libheif 1.23), then [heic2any](https://github.com/alexcorvi/heic2any).
2. **Encoding:** the picture is drawn to a canvas, scaled to the maximum size and encoded as JPG/PNG. Camera EXIF data (including GPS) is removed.
3. **Uploads:** the plupload `FilesAdded` hook and the `wp.apiFetch` middleware swap the HEIC file for the converted one before it is sent.
4. **Existing images:** the converted file is posted to the plugin's REST API, which swaps it into the same attachment, rebuilds thumbnails and replaces old URLs.

### REST API (`/wp-json/hic/v1/`)

All routes require a logged-in user with `upload_files` and a `wp_rest` nonce.

| Route                 | Purpose                                                                       |
|-----------------------|-------------------------------------------------------------------------------|
| `POST convert/{id}`   | Multipart `file` (JPG/PNG) **or** `server=1` to convert on the server         |
| `POST fail/{id}`      | Records a failed conversion (`message`)                                       |
| `POST log`            | Logs a conversion done during upload                                          |

### Hooks

```php
// Fires after an attachment's HEIC file was replaced.
add_action( 'hic_attachment_converted', function ( $attachment_id, $new_file, $old_file ) {
	// ...
}, 10, 3 );
```

## Project structure

```
manpreetdev-heic-to-jpg-converter.php   Bootstrap, settings, HEIC detection, server-conversion filters
includes/admin-page.php                 Menu, Tools and Settings pages
includes/help-page.php                  Help page and system check
includes/logs.php                       Log storage, Logs page, CSV export
includes/rest.php                       REST routes, file swap, URL replacement
includes/assets.php                     Script/style loading
assets/js/hic-core.js                   Decoding, encoding, parallel-conversion limit, REST helpers
assets/js/hic-uploader.js               Media uploader (plupload) integration
assets/js/hic-editor.js                 Block editor integration
assets/js/hic-bulk.js                   Converts existing images
assets/vendor/                          heic-to, heic2any (unmodified)
languages/                              Translation template (.pot) and bundled translations
uninstall.php                           Removes settings and logs
```

## Translations

All PHP strings use the `manpreetdev-heic-to-jpg-converter` text domain. JavaScript messages are passed in from PHP, so they are translated through the same template.

1. Open `languages/manpreetdev-heic-to-jpg-converter.pot` in [Poedit](https://poedit.net/) and create a translation for your language.
2. Save it as `languages/manpreetdev-heic-to-jpg-converter-{locale}.po` / `.mo`, e.g. `manpreetdev-heic-to-jpg-converter-hi_IN.mo`.

Translations from translate.wordpress.org (`wp-content/languages/plugins/`) take priority over bundled ones.

After changing strings, regenerate the template with [WP-CLI](https://wp-cli.org/):

```bash
wp i18n make-pot . languages/manpreetdev-heic-to-jpg-converter.pot --exclude=assets/vendor
```

## Building the upload zip

The zip must contain a single `manpreetdev-heic-to-jpg-converter/` folder. On Windows (bsdtar ships with Windows 10+ and writes forward-slash paths, so the zip works on Linux hosts):

```powershell
$src = "manpreetdev-heic-to-jpg-converter"
$tmp = Join-Path $env:TEMP "hic-build"
Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
New-Item -ItemType Directory "$tmp\$src" | Out-Null
Copy-Item assets, includes, languages, LICENSE.txt, readme.txt, uninstall.php, "$src.php" "$tmp\$src" -Recurse
tar.exe -a -c -f "$src-1.2.0.zip" -C $tmp $src
```

The zip is written to the plugin root (git-ignored). Upload `manpreetdev-heic-to-jpg-converter-1.2.0.zip` in **Plugins → Add New → Upload Plugin**.

## Credits

- [heic-to](https://github.com/hoppergee/heic-to) by hoppergee (LGPL-3.0), using libheif 1.23
- [heic2any](https://github.com/alexcorvi/heic2any) by Alex Corvi (MIT)

## License

GPL-2.0-or-later. See [LICENSE.txt](LICENSE.txt).
