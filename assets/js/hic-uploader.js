/**
 * HEIC to JPG Converter – converts HEIC files in the WordPress media uploader
 * (Media Library, media modal, Elementor, "Add New Media File") before upload.
 */
(function (window, $) {
	'use strict';

	var HIC = window.HIC;
	var plupload = window.plupload;
	if (!HIC || !plupload) {
		return;
	}

	function convertAndAdd(uploader, nativeFile, name) {
		var t = HIC.toast(HIC.sprintf(HIC.i18n.converting, name));

		HIC.convert(nativeFile, name)
			.then(function (converted) {
				t.update(HIC.sprintf(HIC.i18n.converted, converted.name), 'success');
				HIC.logUpload(nativeFile, name, converted);
				uploader.addFile(converted, converted.name);
			})
			.catch(function (err) {
				if (window.console) {
					window.console.error('HEIC conversion failed:', err, err && err.details);
				}
				t.update(HIC.sprintf(HIC.i18n.failed, name), 'error');
				HIC.logUpload(nativeFile, name, null, err);
				// Upload the original so nothing is lost; it can be converted later.
				nativeFile.__hicSkip = true;
				uploader.addFile(nativeFile, name);
			});
	}

	/*
	 * A plupload file filter runs before a file enters the upload queue. Rejecting the
	 * HEIC here (instead of removing it in FilesAdded) means a queue that is already
	 * uploading can never pick up the original by mistake.
	 */
	plupload.addFileFilter('hic_heic', function (enabled, file, cb) {
		var nativeFile = file && typeof file.getNative === 'function' ? file.getNative() : null;
		if (!enabled || !nativeFile || nativeFile.__hicSkip || !HIC.isHeic(file)) {
			cb(true);
			return;
		}
		cb(false);
		convertAndAdd(this, nativeFile, file.name);
	});

	function bindUploader(uploader) {
		if (!uploader || !uploader.settings || uploader.__hicBound) {
			return;
		}
		uploader.__hicBound = true;
		uploader.settings.filters = uploader.settings.filters || {};
		uploader.settings.filters.hic_heic = true;
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
		bindUploader(window.uploader);
	}
	if ($) {
		$(bindClassic);
	}
	window.addEventListener('load', bindClassic);
})(window, window.jQuery);
