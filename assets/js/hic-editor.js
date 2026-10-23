/**
 * HEIC to JPG Converter – block editor support.
 * Converts HEIC files uploaded through the REST API (/wp/v2/media) before they are sent.
 */
(function (window) {
	'use strict';

	var wp = window.wp;
	var HIC = window.HIC;
	if (!wp || !wp.apiFetch || !HIC || typeof window.FormData === 'undefined') {
		return;
	}

	var MEDIA_ROUTE = /\/wp\/v2\/media\/?(?:\?|$)/;

	wp.apiFetch.use(function (options, next) {
		var method = String(options.method || 'GET').toUpperCase();
		var target = options.path || options.url || '';
		var body = options.body;

		if (method !== 'POST' || !MEDIA_ROUTE.test(target) || !(body instanceof window.FormData)) {
			return next(options);
		}

		var file = body.get('file');
		if (!file || typeof file === 'string' || !HIC.isHeic(file)) {
			return next(options);
		}

		var name = file.name || 'image.heic';
		var t = HIC.toast(HIC.sprintf(HIC.i18n.converting, name));

		return HIC.convert(file, name).then(
			function (converted) {
				body.set('file', converted, converted.name);
				t.update(HIC.sprintf(HIC.i18n.converted, converted.name), 'success');
				HIC.logUpload(file, name, converted);
				return next(options);
			},
			function (err) {
				if (window.console) {
					window.console.error('HEIC conversion failed:', err, err && err.details);
				}
				t.update(HIC.sprintf(HIC.i18n.failed, name), 'error');
				HIC.logUpload(file, name, null, err);
				return next(options);
			}
		);
	});
})(window);
