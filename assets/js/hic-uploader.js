/**
 * HEIC to JPG Converter – converts HEIC files in the WordPress media uploader
 * (Media Library, media modal, Elementor, "Add New Media File") before upload.
 */
(function (window, $) {
	'use strict';

	var HIC = window.HIC;
	if (!HIC) {
		return;
	}

	// Runs before WordPress's own FilesAdded handler (default priority 0).
	var PRIORITY = 100;

	function convertAndAdd(uploader, nativeFile, name) {
		var t = HIC.toast(HIC.sprintf(HIC.i18n.converting, name));

		HIC.convert(nativeFile, name)
			.then(function (converted) {
				t.update(HIC.sprintf(HIC.i18n.converted, converted.name), 'success');
				uploader.addFile(converted, converted.name);
			})
			.catch(function (err) {
				if (window.console) {
					window.console.error('HEIC conversion failed:', err, err && err.details);
				}
				t.update(HIC.sprintf(HIC.i18n.failed, name), 'error');
				// Upload the original so nothing is lost; it can be converted later.
				nativeFile.__hicSkip = true;
				uploader.addFile(nativeFile, name);
			});
	}

	function bindUploader(uploader) {
		if (!uploader || uploader.__hicBound || typeof uploader.bind !== 'function') {
			return;
		}
		uploader.__hicBound = true;

		uploader.bind('FilesAdded', function (up, files) {
			for (var i = files.length - 1; i >= 0; i--) {
				var file = files[i];
				var nativeFile = file && typeof file.getNative === 'function' ? file.getNative() : null;

				if (!nativeFile || nativeFile.__hicSkip || !HIC.isHeic(file)) {
					continue;
				}

				// Take the HEIC file out before WordPress queues it for upload.
				files.splice(i, 1);
				up.removeFile(file);
				convertAndAdd(up, nativeFile, file.name);
			}
		}, null, PRIORITY);
	}

	// Every uploader created by wp.media (Media Library grid, media modal, Elementor).
	if (window.wp && window.wp.Uploader && window.wp.Uploader.prototype) {
		var originalInit = window.wp.Uploader.prototype.init;
		window.wp.Uploader.prototype.init = function () {
			var result = typeof originalInit === 'function' ? originalInit.apply(this, arguments) : undefined;
			bindUploader(this.uploader);
			return result;
		};
	}

	// Classic Media → Add New screen (plupload-handlers.js creates window.uploader).
	function bindClassic() {
		if (window.uploader && typeof window.uploader.bind === 'function') {
			bindUploader(window.uploader);
		}
	}
	if ($) {
		$(bindClassic);
	}
	window.addEventListener('load', bindClassic);
})(window, window.jQuery);
