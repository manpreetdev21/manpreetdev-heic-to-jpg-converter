/**
 * HEIC to JPG Converter – shared in-browser conversion helpers.
 * Exposes window.HIC.
 *
 * Decoding is attempted in this order, stopping at the first that works:
 *   1. The browser's own HEIC support (Safari 17+).
 *   2. heic-to (libheif 1.23 – reads newer iPhone photos, incl. iOS 18 HDR).
 *   3. heic2any (older libheif – still reads a few unusual files).
 * The decoded picture is then resized if needed and encoded to JPG or PNG.
 */
(function (window, document) {
	'use strict';

	var cfg = window.hicSettings || {};
	var libs = cfg.libs || {};
	var i18n = cfg.i18n || {};
	var HEIC_TYPES = ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'];

	// Browsers (Safari especially) refuse canvases above ~16.7 megapixels.
	var MAX_CANVAS_AREA = 16000000;

	/* ---- Script loading ---------------------------------------------- */

	var loading = {};

	/** Loads a bundled library once, only when it is actually needed. */
	function loadScript(url, globalName) {
		if (window[globalName]) {
			return Promise.resolve(window[globalName]);
		}
		if (!url) {
			return Promise.reject(new Error(globalName + ' is not configured.'));
		}
		if (!loading[url]) {
			loading[url] = new Promise(function (resolve, reject) {
				// If an AMD loader is on the page, a library could register itself
				// with it instead of window. Hide it while the library loads.
				var savedDefine = window.define;
				var hasAmd = typeof savedDefine === 'function' && savedDefine.amd;
				if (hasAmd) {
					window.define = undefined;
				}
				function done(ok) {
					if (hasAmd) {
						window.define = savedDefine;
					}
					if (ok && window[globalName]) {
						resolve(window[globalName]);
					} else {
						delete loading[url];
						reject(new Error('Could not load ' + globalName + '.'));
					}
				}
				var s = document.createElement('script');
				s.src = url;
				s.async = true;
				s.onload = function () { done(true); };
				s.onerror = function () { done(false); };
				(document.head || document.documentElement).appendChild(s);
			});
		}
		return loading[url];
	}

	/* ---- Helpers ----------------------------------------------------- */

	function isHeicName(name) {
		return /\.(heic|heif)$/i.test(name || '');
	}

	function isHeic(file) {
		if (!file) {
			return false;
		}
		var type = String(file.type || '').toLowerCase();
		return HEIC_TYPES.indexOf(type) !== -1 || isHeicName(file.name);
	}

	function targetType() {
		return cfg.format === 'png' ? 'image/png' : 'image/jpeg';
	}

	function outputName(name) {
		var ext = cfg.format === 'png' ? '.png' : '.jpg';
		var base = String(name || 'image').replace(/\.(heic|heif)$/i, '');
		return (base || 'image') + ext;
	}

	function errorText(err) {
		if (!err) {
			return 'unknown error';
		}
		if (typeof err === 'string') {
			return err;
		}
		return err.message || err.code || String(err);
	}

	function withTimeout(promise, ms, label) {
		return new Promise(function (resolve, reject) {
			var timer = setTimeout(function () {
				reject(new Error(label + ' timed out'));
			}, ms);
			promise.then(
				function (v) { clearTimeout(timer); resolve(v); },
				function (e) { clearTimeout(timer); reject(e); }
			);
		});
	}

	/* ---- Decoders: each returns a Promise<ImageBitmap> --------------- */

	function decodeNative(blob) {
		if (typeof window.createImageBitmap !== 'function') {
			return Promise.reject(new Error('createImageBitmap not available'));
		}
		// Chrome/Firefox/Edge reject HEIC here; Safari 17+ decodes it natively.
		var typed = blob.type ? blob : new Blob([blob], { type: 'image/heic' });
		return withTimeout(window.createImageBitmap(typed), 15000, 'Browser decoder');
	}

	function decodeHeicTo(blob) {
		return loadScript(libs.heicTo, 'HeicTo').then(function (HeicTo) {
			return withTimeout(HeicTo({ blob: blob, type: 'bitmap' }), 120000, 'heic-to');
		});
	}

	function decodeHeic2any(blob) {
		return loadScript(libs.heic2any, 'heic2any').then(function (heic2any) {
			return withTimeout(heic2any({ blob: blob, toType: 'image/png' }), 120000, 'heic2any');
		}).then(function (out) {
			return window.createImageBitmap(Array.isArray(out) ? out[0] : out);
		});
	}

	function decode(blob) {
		var decoders = [
			['browser', decodeNative],
			['heic-to', decodeHeicTo],
			['heic2any', decodeHeic2any]
		];
		var errors = [];

		return (function attempt(i) {
			if (i >= decoders.length) {
				var err = new Error(i18n.unsupported || 'This HEIC photo could not be read by any decoder.');
				err.details = errors.join(' | ');
				return Promise.reject(err);
			}
			return decoders[i][1](blob).then(
				function (bitmap) {
					if (!bitmap || !bitmap.width || !bitmap.height) {
						throw new Error('empty image');
					}
					return bitmap;
				}
			).catch(function (e) {
				errors.push(decoders[i][0] + ': ' + errorText(e));
				return attempt(i + 1);
			});
		})(0);
	}

	/* ---- Encode ------------------------------------------------------ */

	function targetSize(w, h) {
		var scale = 1;
		var maxSide = parseInt(cfg.maxSize, 10) || 0;
		if (maxSide > 0 && Math.max(w, h) > maxSide) {
			scale = maxSide / Math.max(w, h);
		}
		if (w * h * scale * scale > MAX_CANVAS_AREA) {
			scale = Math.sqrt(MAX_CANVAS_AREA / (w * h));
		}
		return { w: Math.max(1, Math.round(w * scale)), h: Math.max(1, Math.round(h * scale)) };
	}

	function encode(bitmap, type, quality) {
		return new Promise(function (resolve, reject) {
			var size = targetSize(bitmap.width, bitmap.height);
			var canvas = document.createElement('canvas');
			canvas.width = size.w;
			canvas.height = size.h;
			var ctx = canvas.getContext('2d');
			if (!ctx) {
				reject(new Error('Canvas not available'));
				return;
			}
			if (type === 'image/jpeg') {
				// JPG has no transparency: put transparent areas on white.
				ctx.fillStyle = '#ffffff';
				ctx.fillRect(0, 0, size.w, size.h);
			}
			ctx.imageSmoothingEnabled = true;
			ctx.imageSmoothingQuality = 'high';
			ctx.drawImage(bitmap, 0, 0, size.w, size.h);

			canvas.toBlob(function (out) {
				canvas.width = 1;
				canvas.height = 1;
				if (out) {
					resolve(out);
				} else {
					reject(new Error('The browser could not encode the image (it may be too large).'));
				}
			}, type, type === 'image/jpeg' ? (Number(cfg.quality) || 0.85) : undefined);
		});
	}

	/**
	 * Converts a HEIC Blob/File to a JPG or PNG File.
	 * @returns {Promise<File>}
	 */
	function convert(blob, name) {
		var type = targetType();
		return decode(blob)
			.then(function (bitmap) {
				return encode(bitmap, type).then(function (out) {
					if (bitmap.close) {
						bitmap.close();
					}
					return out;
				});
			})
			.then(function (out) {
				var fileName = outputName(name);
				try {
					return new File([out], fileName, { type: type, lastModified: Date.now() });
				} catch (e) {
					out.name = fileName;
					out.lastModified = Date.now();
					return out;
				}
			});
	}

	function sprintf(str, value) {
		return String(str || '').replace('%s', value);
	}

	/* ---- Small toast messages ---------------------------------------- */

	var wrap = null;

	function toast(message) {
		if (!wrap) {
			wrap = document.createElement('div');
			wrap.className = 'hic-toasts';
			wrap.setAttribute('aria-live', 'polite');
			document.body.appendChild(wrap);
		}
		var el = document.createElement('div');
		el.className = 'hic-toast';
		el.textContent = message;
		wrap.appendChild(el);

		var api = {
			update: function (msg, kind) {
				el.textContent = msg;
				el.className = 'hic-toast' + (kind ? ' is-' + kind : '');
				if (kind) {
					setTimeout(api.close, kind === 'error' ? 9000 : 3500);
				}
				return api;
			},
			close: function () {
				if (el.parentNode) {
					el.parentNode.removeChild(el);
				}
			}
		};
		return api;
	}

	window.HIC = {
		isHeic: isHeic,
		convert: convert,
		decode: decode,
		outputName: outputName,
		errorText: errorText,
		toast: toast,
		sprintf: sprintf,
		i18n: i18n
	};
})(window, document);
