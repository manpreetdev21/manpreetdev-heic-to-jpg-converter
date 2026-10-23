/**
 * HEIC to JPG Converter – converts HEIC images already in the Media Library.
 * Each file is downloaded, converted in the browser, and sent back to replace
 * the attachment's file (the attachment ID stays the same).
 */
(function (window, document) {
	'use strict';

	var cfg = window.hicBulk;
	var HIC = window.HIC;
	if (!cfg || !HIC || !cfg.items || !cfg.items.length) {
		return;
	}

	var i18n = cfg.i18n || {};
	var running = false;

	function fmt(str, a, b) {
		return String(str || '').replace('%1$d', a).replace('%2$d', b);
	}

	function setStatus(id, text, kind) {
		var el = document.querySelector('[data-hic-status="' + id + '"]');
		if (el) {
			el.textContent = text;
			el.className = 'hic-status' + (kind ? ' is-' + kind : '');
		}
	}

	function setProgress(text) {
		var el = document.getElementById('hic-progress');
		if (el) {
			el.textContent = text;
		}
	}

	function post(path, body) {
		return window.fetch(cfg.restUrl + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce },
			body: body
		}).then(function (res) {
			return res.json().catch(function () { return {}; }).then(function (json) {
				if (!res.ok) {
					throw new Error((json && json.message) || 'HTTP ' + res.status);
				}
				return json;
			});
		});
	}

	function convertOne(item) {
		setStatus(item.id, i18n.downloading, 'busy');

		return window.fetch(item.url, { credentials: 'same-origin', cache: 'no-store' })
			.then(function (res) {
				if (!res.ok) {
					throw new Error('Download failed (HTTP ' + res.status + ')');
				}
				return res.blob();
			})
			.then(function (blob) {
				setStatus(item.id, i18n.converting, 'busy');
				return HIC.convert(blob, item.name);
			})
			.then(function (file) {
				setStatus(item.id, i18n.uploading, 'busy');
				var fd = new window.FormData();
				fd.append('file', file, file.name);
				return post('convert/' + item.id, fd);
			})
			.then(function () {
				setStatus(item.id, i18n.done, 'done');
				return true;
			})
			.catch(function (err) {
				var msg = HIC.errorText(err);
				if (window.console) {
					window.console.error('HEIC conversion failed for ' + item.name, err, err && err.details);
				}
				setStatus(item.id, i18n.failed + ': ' + msg, 'error');
				var fd = new window.FormData();
				fd.append('message', msg + (err && err.details ? ' [' + err.details + ']' : ''));
				post('fail/' + item.id, fd).catch(function () {});
				return false;
			});
	}

	function onBeforeUnload(e) {
		if (running && cfg.mode === 'page') {
			e.preventDefault();
			e.returnValue = i18n.leaveWarn;
			return i18n.leaveWarn;
		}
	}

	function run() {
		if (running) {
			return;
		}
		running = true;

		var button = document.getElementById('hic-start');
		if (button) {
			button.disabled = true;
		}

		var items = cfg.items.slice();
		var total = items.length;
		var done = 0;
		var failed = 0;

		setProgress(fmt(i18n.progress, 0, total));

		(function next() {
			if (!items.length) {
				running = false;
				setProgress(fmt(i18n.finished, done, failed));
				return;
			}
			convertOne(items.shift()).then(function (ok) {
				if (ok) {
					done++;
				} else {
					failed++;
				}
				setProgress(fmt(i18n.progress, done + failed, total));
				next();
			});
		})();
	}

	window.addEventListener('beforeunload', onBeforeUnload);

	var startButton = document.getElementById('hic-start');
	if (startButton) {
		startButton.addEventListener('click', run);
	}

	if (cfg.autoStart) {
		// Let the page finish loading first.
		if (document.readyState === 'complete') {
			run();
		} else {
			window.addEventListener('load', run);
		}
	}
})(window, document);
