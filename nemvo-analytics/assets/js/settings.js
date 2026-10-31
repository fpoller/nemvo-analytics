/* Nemvo Analytics settings screen: connection tests, migrations, lookups and goal rows. */
(function () {
	'use strict';

	var cfg = window.nvaSettings;
	var i18n = window.wp.i18n;
	var __ = i18n.__;
	var sprintf = i18n.sprintf;

	// Same theme as the dashboard (chosen there with the theme button).
	try {
		var theme = JSON.parse(localStorage.getItem('nva-theme') || '"auto"');
		if (theme === 'light' || theme === 'dark') {
			document.body.setAttribute('data-nva-theme', theme);
		}
	} catch (e) {}

	function url(path) {
		// rest_url() may be pretty (/wp-json/nemvo/v1) or plain (?rest_route=/nemvo/v1).
		return cfg.root.replace(/\/$/, '') + path;
	}

	function post(path, body) {
		return fetch(url(path), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify(body || {})
		}).then(function (res) {
			return res.json().then(function (data) {
				if (!res.ok) {
					throw new Error(data && data.message ? data.message : res.statusText);
				}
				return data;
			});
		});
	}

	/** Current values of the fields in a group (data-group="db|queue|cap"). */
	function group(name) {
		var out = {};
		document.querySelectorAll('[data-group="' + name + '"] [data-field]').forEach(function (el) {
			out[el.getAttribute('data-field')] = el.value;
		});
		return out;
	}

	function show(button, ok, message) {
		var out = button.parentNode.querySelector('.nva-result');
		if (!out) {
			return;
		}
		out.textContent = message;
		out.className = 'nva-result ' + (ok ? 'is-ok' : 'is-error');
	}

	function lookupResult(data) {
		var box = document.getElementById('nva-lookup-result');
		var rows = [
			[__('Address', 'nemvo-analytics'), data.ip],
			[__('Location', 'nemvo-analytics'), [data.city, data.region, data.country].filter(Boolean).join(', ') || '–'],
			[__('Network', 'nemvo-analytics'), data.asn ? 'AS' + data.asn + ' ' + data.provider : '–'],
			[__('Hostname', 'nemvo-analytics'), data.hostname || '–'],
			[__('Type', 'nemvo-analytics'), data.network || '–'],
			[__('Company', 'nemvo-analytics'), data.company || '–'],
			[__('Lookup time', 'nemvo-analytics'), sprintf(/* translators: %d: milliseconds */ __('%d ms', 'nemvo-analytics'), data.ms)]
		];
		box.replaceChildren.apply(box, rows.map(function (r) {
			var div = document.createElement('div');
			var dt = document.createElement('dt');
			var dd = document.createElement('dd');
			dt.textContent = r[0];
			dd.textContent = r[1];
			div.append(dt, dd);
			return div;
		}));
		box.hidden = false;
	}

	/* Migration: start, then step until done, with a progress bar. */
	function migrate(button, replace) {
		var box = document.getElementById('nva-migrate');
		var progress = box.querySelector('.nva-progress');
		var bar = progress.querySelector('span');
		var text = progress.querySelector('p');
		var to = button.getAttribute('data-to');
		button.disabled = true;
		progress.hidden = false;

		function render(data) {
			bar.style.width = data.percent + '%';
			text.textContent = data.message;
		}
		function step() {
			return post('/admin/migrate', { action: 'step' }).then(function (data) {
				if (!data.ok) {
					throw new Error(data.message);
				}
				render(data);
				return data.done ? data : step();
			});
		}
		var start = cfg.migration ? Promise.resolve({ ok: true }) : post('/admin/migrate', { action: 'start', to: to, replace: replace ? 1 : 0 });
		start.then(function (data) {
			if (!data.ok && data.code === 'nemvo_migrate_not_empty') {
				progress.hidden = true;
				button.disabled = false;
				if (window.confirm(data.message + '\n\n' + __('Replace the statistics in the target database with the current ones?', 'nemvo-analytics'))) {
					migrate(button, true);
				}
				return null;
			}
			if (!data.ok) {
				throw new Error(data.message);
			}
			cfg.migration = null;
			return step();
		}).then(function (data) {
			if (data) {
				show(button, true, data.message);
				setTimeout(function () {
					window.location.reload();
				}, 1500);
			}
		}).catch(function (err) {
			show(button, false, err.message);
			button.disabled = false;
		});
	}

	var actions = {
		'db-test': function () {
			return post('/admin/db-test', group('db'));
		},
		install: function () {
			return post('/admin/install');
		},
		geo: function (button) {
			return post('/admin/geo', { edition: button.getAttribute('data-edition') });
		},
		lookup: function () {
			return post('/admin/lookup', { ip: document.getElementById('nva-lookup-ip').value }).then(function (data) {
				if (data.ok) {
					lookupResult(data);
					data.message = '';
				}
				return data;
			});
		},
		'queue-test': function () {
			var g = group('queue');
			return post('/admin/queue-test', { host: g.queue_host, port: g.queue_port, password: g.queue_password, db: g.queue_db });
		},
		'queue-work': function () {
			return post('/admin/queue-work');
		},
		'cap-test': function () {
			var g = group('cap');
			return post('/admin/cap-test', { url: g.cap_url, site_key: g.cap_site_key, secret: g.cap_secret });
		},
		purge: function () {
			var before = document.getElementById('nva-purge-before').value;
			if (!before) {
				return Promise.resolve({ ok: false, message: __('Pick a date first.', 'nemvo-analytics') });
			}
			// translators: %s: a date.
			if (!window.confirm(sprintf(__('Delete all statistics recorded before %s? This cannot be undone.', 'nemvo-analytics'), before))) {
				return null;
			}
			return post('/admin/purge', { before: before });
		},
		'purge-all': function () {
			if (!window.confirm(__('Delete ALL statistics? This cannot be undone.', 'nemvo-analytics'))) {
				return null;
			}
			return post('/admin/purge', { all: 1 });
		},
		'purge-inactive': function () {
			if (!window.confirm(__('Delete the old copy of the statistics? The copy in use is not touched.', 'nemvo-analytics'))) {
				return null;
			}
			return post('/admin/purge', { inactive: 1 });
		}
	};

	document.addEventListener('click', function (e) {
		var button = e.target.closest('[data-nva-action]');
		if (!button) {
			return;
		}
		var name = button.getAttribute('data-nva-action');
		if (name === 'migrate') {
			migrate(button, false);
			return;
		}
		var run = actions[name];
		var request = run && run(button);
		if (!request) {
			return;
		}
		button.disabled = true;
		show(button, true, __('Working…', 'nemvo-analytics'));
		request.then(function (data) {
			show(button, !!data.ok, data.message || '');
		}).catch(function (err) {
			show(button, false, err.message);
		}).then(function () {
			button.disabled = false;
		});
	});

	// A migration that was interrupted (e.g. the page was closed) continues on request.
	if (cfg.migration) {
		var resume = document.querySelector('[data-nva-action="migrate"]');
		if (resume) {
			// translators: %s: progress message.
			show(resume, false, sprintf(__('A move was interrupted (%s). Click to continue.', 'nemvo-analytics'), cfg.migration.message));
		}
	}

	/* Copy buttons on code samples. */
	document.addEventListener('click', function (e) {
		var button = e.target.closest('[data-nva-copy]');
		if (!button) {
			return;
		}
		var text = document.getElementById(button.getAttribute('data-nva-copy')).textContent;
		navigator.clipboard.writeText(text).then(function () {
			var label = button.textContent;
			button.textContent = __('Copied', 'nemvo-analytics');
			setTimeout(function () {
				button.textContent = label;
			}, 1500);
		});
	});

	/* Goal rows. */
	var rows = document.getElementById('nva-goal-rows');
	var template = document.getElementById('nva-goal-template');
	var next = rows ? rows.children.length : 0;
	var add = document.getElementById('nva-goal-add');
	if (add && rows && template) {
		add.addEventListener('click', function () {
			var html = template.innerHTML.replace(/__i__/g, String(next++));
			var body = document.createElement('tbody');
			body.innerHTML = html;
			var row = body.firstElementChild;
			rows.appendChild(row);
			row.querySelector('input').focus();
		});
		rows.addEventListener('click', function (e) {
			var remove = e.target.closest('.nva-goal-remove');
			if (remove) {
				remove.closest('tr').remove();
			}
		});
	}

	/* Highlight the section in view. */
	var links = document.querySelectorAll('.nva-settings__nav a');
	if ('IntersectionObserver' in window && links.length) {
		var observer = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (!entry.isIntersecting) {
					return;
				}
				links.forEach(function (a) {
					a.classList.toggle('is-active', a.getAttribute('href') === '#' + entry.target.id);
				});
			});
		}, { rootMargin: '-30% 0px -60% 0px' });
		document.querySelectorAll('.nva-section').forEach(function (s) {
			observer.observe(s);
		});
	}
})();
