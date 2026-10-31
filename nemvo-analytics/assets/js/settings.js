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

	/* Check tracking: server-side checks, then a live log of incoming hits. */
	var diag = document.getElementById('nva-diag');
	var diagTimer = 0;
	var HITS = {
		req: __('Page request (server)', 'nemvo-analytics'),
		pv: __('Tracker pageview', 'nemvo-analytics'),
		e: __('Engagement', 'nemvo-analytics'),
		ev: __('Event', 'nemvo-analytics'),
		v: __('Verification', 'nemvo-analytics'),
		'': __('Request', 'nemvo-analytics')
	};

	function cell(text, cls) {
		var td = document.createElement('td');
		td.textContent = text;
		if (cls) {
			td.className = cls;
		}
		return td;
	}

	function renderDiag(data) {
		var checks = diag.querySelector('.nva-diag__checks');
		var live = diag.querySelector('.nva-diag__live');
		var tbody = diag.querySelector('.nva-diag__log tbody');
		if (data.checks) {
			checks.replaceChildren.apply(checks, data.checks.map(function (c) {
				var li = document.createElement('li');
				li.className = 'is-' + c[0];
				var badge = document.createElement('span');
				badge.className = 'nva-badge is-' + c[0];
				badge.textContent = c[0] === 'good' ? '✓' : '!';
				var body = document.createElement('div');
				var strong = document.createElement('strong');
				strong.textContent = c[1];
				body.append(strong);
				if (c[2]) {
					var p = document.createElement('p');
					p.textContent = c[2];
					body.append(p);
				}
				li.append(badge, body, document.createElement('span'));
				return li;
			}));
			checks.hidden = false;
		}
		live.hidden = !data.active && !data.log.length;
		diag.querySelector('[data-nva-action="diag-stop"]').hidden = !data.active;
		diag.querySelector('.nva-diag__status').textContent = data.active
			? sprintf(/* translators: %d: minutes left */ __('Listening for %d more minutes. Open your site in another browser or a private window and click around; each hit appears here within seconds.', 'nemvo-analytics'), data.minutes)
			: __('Stopped.', 'nemvo-analytics');
		if (!data.log.length) {
			var empty = document.createElement('tr');
			var td = cell(data.active ? __('Waiting for the first hit…', 'nemvo-analytics') : __('No hits were received.', 'nemvo-analytics'), 'nva-muted');
			td.colSpan = 4;
			empty.append(td);
			tbody.replaceChildren(empty);
			return;
		}
		tbody.replaceChildren.apply(tbody, data.log.map(function (e) {
			var tr = document.createElement('tr');
			var result;
			var cls;
			if (e.outcome === 'ignored') {
				result = __('Ignored', 'nemvo-analytics');
				cls = 'is-bad';
			} else if ((e.type === 'pv' || e.type === 'req') && Number(e.score) >= data.threshold) {
				// translators: %s: bot score.
				result = sprintf(__('Stored as automated (score %s)', 'nemvo-analytics'), e.score);
				cls = 'is-warn';
			} else if (e.type === 'pv' || e.type === 'req') {
				// translators: %s: bot score.
				result = sprintf(__('Stored as a person (score %s)', 'nemvo-analytics'), e.score || 0);
				cls = 'is-good';
			} else {
				result = e.outcome === 'queued' ? __('Queued', 'nemvo-analytics') : __('Received', 'nemvo-analytics');
				cls = 'is-good';
			}
			var details = [];
			if (e.why) {
				details.push(e.why + (e.host ? ' (' + e.host + ')' : ''));
			}
			if (e.path) {
				details.push(e.path);
			}
			if ((e.type === 'pv' || e.type === 'req') && e.outcome !== 'ignored') {
				details.push([e.company, e.network, e.provider, e.country, e.browser].filter(Boolean).join(' · '));
				if (e.signals) {
					// translators: %s: list of bot signals.
					details.push(sprintf(__('Signals: %s', 'nemvo-analytics'), e.signals));
				}
			}
			if (e.type === 'e') {
				details.push(e.interaction === '1' ? __('with mouse, touch or keyboard use', 'nemvo-analytics') : __('no mouse, touch or keyboard use yet', 'nemvo-analytics'));
			}
			tr.append(cell(sprintf(/* translators: %s: time difference */ __('%s ago', 'nemvo-analytics'), e.when)), cell(HITS[e.type] || e.type), cell(result, 'nva-diag__result ' + cls), cell(details.filter(Boolean).join(' — ')));
			return tr;
		}));
	}

	function pollDiag() {
		clearTimeout(diagTimer);
		post('/admin/diagnostics', { action: 'status' }).then(function (data) {
			renderDiag(data);
			if (data.active) {
				diagTimer = setTimeout(pollDiag, 4000);
			}
		}).catch(function () {
			diagTimer = setTimeout(pollDiag, 10000);
		});
	}

	if (diag) {
		diag.addEventListener('click', function (e) {
			var button = e.target.closest('[data-nva-action^="diag-"]');
			if (!button) {
				return;
			}
			e.stopPropagation();
			var start = button.getAttribute('data-nva-action') === 'diag-start';
			button.disabled = true;
			show(button, true, start ? __('Checking the home page and sending a test hit…', 'nemvo-analytics') : '');
			post('/admin/diagnostics', { action: start ? 'start' : 'stop' }).then(function (data) {
				show(button, true, '');
				renderDiag(data);
				if (data.active) {
					diagTimer = setTimeout(pollDiag, 4000);
				}
			}).catch(function (err) {
				show(button, false, err.message);
			}).then(function () {
				button.disabled = false;
			});
		}, true);
		if (cfg.diag) {
			pollDiag();
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
