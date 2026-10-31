/* SPDX-License-Identifier: AGPL-3.0-or-later */
/*
 * NachweisCheck settings — one page per topic, rendered into #nw-settings.
 * Sections: access (door mode + pickers), templates (funder packs),
 * notifications (per-user deadline switch). App-admin only — the page
 * controller 403s everyone else before this script runs.
 */
(function () {
	'use strict';
	if (!window.NW) { return; }

	var t = NW.t, el = NW.el;
	var boot = NW.boot || {};
	var role = NW.role || {};

	function loading() {
		return el('div', { class: 'nw-loading', role: 'status', text: t('Loading…') });
	}

	function card(titleText, children) {
		var c = el('section', { class: 'nw-card' }, [
			el('div', { class: 'nw-card__header' }, [
				el('div', { class: 'nw-card__header-text' }, [
					el('h2', { class: 'nw-card__title', text: titleText })
				])
			]),
			el('div', { class: 'nw-card__body' }, children || [])
		]);
		return c;
	}

	function fieldEl(labelText, control, opts) {
		opts = opts || {};
		var f = el('div', { class: 'nw-field' });
		if (!control.id) { control.id = 'nw-set-' + Math.random().toString(36).slice(2, 9); }
		f.appendChild(el('label', { 'for': control.id, text: labelText }));
		f.appendChild(control);
		if (opts.hint) {
			f.appendChild(el('p', { class: 'nw-field__hint', text: opts.hint }));
		}
		return f;
	}

	function inputEl(name, opts) {
		opts = opts || {};
		// nw-input is text-field chrome (display:block; width:100%) — it must
		// never land on checkbox/radio, whose 1.25rem sizing loses the
		// specificity tie and the control renders stretched.
		var textish = opts.type !== 'checkbox' && opts.type !== 'radio';
		var i = el('input', { type: opts.type || 'text', name: name, class: textish ? 'nw-input' : '', autocomplete: 'off' });
		if (opts.value !== undefined && opts.value !== null) { i.value = opts.value; }
		if (opts.required) { i.required = true; }
		if (opts.placeholder) { i.setAttribute('placeholder', opts.placeholder); }
		if (opts.pattern) { i.setAttribute('pattern', opts.pattern); }
		if (opts.inputmode) { i.setAttribute('inputmode', opts.inputmode); }
		return i;
	}

	function tableEl(headers, rows, emptyText) {
		var wrap = el('div', { class: 'nw-table-wrap' });
		var tbl = el('table', { class: 'nw-table nw-table--cards' });
		var thead = el('thead');
		var hr = el('tr');
		headers.forEach(function (h) { hr.appendChild(el('th', { scope: 'col', text: h })); });
		thead.appendChild(hr);
		tbl.appendChild(thead);
		var tbody = el('tbody');
		if (!rows.length) {
			var tr = el('tr');
			tr.appendChild(el('td', {
				colspan: String(headers.length), class: 'nw-table-empty',
				text: emptyText || t('Nothing here yet.')
			}));
			tbody.appendChild(tr);
		}
		rows.forEach(function (row) {
			var tr = el('tr');
			row.forEach(function (cellData, i) {
				var td = el('td');
				if (headers[i] !== '') { td.setAttribute('data-cell', headers[i]); }
				if (typeof cellData === 'string' || typeof cellData === 'number') {
					td.textContent = String(cellData);
				} else if (cellData) {
					td.appendChild(cellData);
				}
				tr.appendChild(td);
			});
			tbody.appendChild(tr);
		});
		tbl.appendChild(tbody);
		wrap.appendChild(tbl);
		return wrap;
	}

	/* Directory picker against /directory/{users|groups} → { ids() }. */
	function directoryPicker(name, endpoint, opts) {
		opts = opts || {};
		var wrap = el('div', { class: 'nw-picker' });
		var input = inputEl(name + '_q', { type: 'search' });
		input.id = 'nw-set-' + name;
		input.setAttribute('role', 'combobox');
		input.setAttribute('aria-expanded', 'false');
		var results = el('ul', { class: 'nw-picker__results', role: 'listbox', hidden: 'hidden' });
		results.id = input.id + '-results';
		input.setAttribute('aria-controls', results.id);
		var chips = el('div', { class: 'nw-picker__chips', role: 'list' });
		wrap.appendChild(input);
		wrap.appendChild(results);
		wrap.appendChild(chips);
		var picker = NW.initPicker({
			input: input, results: results, chips: chips,
			selected: opts.selected || [],
			search: function (term, signal) {
				return NW.api('/directory/' + endpoint + '?q=' + encodeURIComponent(term), { signal: signal })
					.catch(function () { return []; });
			},
			onChange: function () {}
		});
		return {
			node: wrap,
			ids: function () { return picker.getSelected().map(function (x) { return x.id; }); }
		};
	}

	function saveBtn() {
		return el('button', { type: 'submit', class: 'nw-btn nw-btn--primary', text: t('Save') });
	}

	function busySubmit(form, build) {
		var btn = form.querySelector('button[type="submit"]');
		var g = NW.busyGuard(btn);
		if (!g.tryStart()) { return; }
		Promise.resolve(build())
			.then(function () { NW.toast('success', t('Saved.')); })
			.catch(NW.handleError)
			.finally(function () { g.end(); });
	}

	/* ---------- access ---------- */

	function renderAccess(host) {
		host.appendChild(loading());
		NW.api('/settings/access').then(function (cfg) {
			host.innerHTML = '';
			var groups = directoryPicker('access_groups', 'groups', {
				selected: (cfg.groups || []).map(function (id) { return { id: id, label: id }; })
			});
			var users = directoryPicker('access_users', 'users', {
				selected: (cfg.users || []).map(function (id) { return { id: id, label: id }; })
			});
			var admins = directoryPicker('access_admins', 'users', {
				selected: (cfg.admins || []).map(function (id) { return { id: id, label: id }; })
			});

			var restrictedPanel = el('div');
			var modeOpen = inputEl('mode', { type: 'radio', value: 'open' });
			modeOpen.id = 'nw-access-open';
			modeOpen.checked = cfg.mode !== 'restricted';
			var modeRestricted = inputEl('mode', { type: 'radio', value: 'restricted' });
			modeRestricted.id = 'nw-access-restricted';
			modeRestricted.checked = cfg.mode === 'restricted';
			function paintMode() {
				restrictedPanel.hidden = !modeRestricted.checked;
			}
			modeOpen.addEventListener('change', paintMode);
			modeRestricted.addEventListener('change', paintMode);

			restrictedPanel.appendChild(fieldEl(t('Allowed groups'), groups.node, {
				hint: t('Members of these groups may open the app.')
			}));
			restrictedPanel.appendChild(fieldEl(t('Allowed users'), users.node, {
				hint: t('Individual users may open the app regardless of groups.')
			}));
			paintMode();

			var form = el('form', { novalidate: 'novalidate' }, [
				el('fieldset', { class: 'nw-fieldset' }, [
					el('legend', { class: 'nw-fieldset__legend', text: t('Who may open NachweisCheck?') }),
					el('div', { class: 'nw-choice-group' }, [
						el('div', { class: 'nw-check-row' }, [
							el('label', { 'for': 'nw-access-open' }, [
								modeOpen, document.createTextNode(' ' + t('Open — every Nextcloud user'))
							])
						]),
						el('div', { class: 'nw-check-row' }, [
							el('label', { 'for': 'nw-access-restricted' }, [
								modeRestricted, document.createTextNode(' ' + t('Restricted — only the people below'))
							])
						])
					]),
					restrictedPanel,
					fieldEl(t('App admins'), admins.node, {
						hint: t('App admins manage access, templates and project settings — independent of the door.')
					})
				]),
				el('div', { class: 'nw-form-actions' }, [saveBtn()])
			]);
			form.addEventListener('submit', function (ev) {
				ev.preventDefault();
				busySubmit(form, function () {
					return NW.api('/settings/access', { method: 'PUT', json: {
						mode: modeRestricted.checked ? 'restricted' : 'open',
						groups: groups.ids(),
						users: users.ids(),
						admins: admins.ids()
					}});
				});
			});
			host.appendChild(card(t('Access'), [form]));

		}).catch(function (e) {
			host.innerHTML = '';
			NW.handleError(e);
		});
	}

	function renderSeats(host) {
		var region = el('div');
		region.appendChild(loading());
		host.appendChild(region);
		var seatPicker = directoryPicker('seat_uid', 'users', {});
		var addBtn = el('button', { type: 'button', class: 'nw-btn nw-btn--secondary', text: t('Assign seat') });
		var listRegion = el('div');

		function paint() {
			NW.api('/settings/seats').then(function (seats) {
				listRegion.innerHTML = '';
				listRegion.appendChild(tableEl([t('User'), t('Assigned'), t('Status'), ''],
					(seats || []).map(function (s) {
						var rm = el('button', { type: 'button', class: 'nw-btn nw-btn--small nw-btn--ghost', text: t('Free') });
						rm.addEventListener('click', function () {
							NW.api('/settings/seats/' + encodeURIComponent(s.uid), { method: 'DELETE' })
								.then(function () { NW.toast('success', t('Seat freed.')); paint(); })
								.catch(NW.handleError);
						});
						var badge = s.within_limit
							? el('span', { class: 'nw-badge nw-badge--success' }, [el('span', { class: 'nw-badge__dot' }), el('span', { text: t('Active') })])
							: el('span', { class: 'nw-badge nw-badge--warn', title: t('This seat exceeds the current license limit. It stays assigned but has no mobile access until you free a seat or upgrade.') }, [el('span', { class: 'nw-badge__dot' }), el('span', { text: t('Over limit') })]);
						return [s.uid, NW.fmtDateTime(s.assigned_at), badge, rm];
					}), t('No seats assigned.')));
			}).catch(function (e) {
				listRegion.innerHTML = '';
				listRegion.appendChild(el('p', { class: 'nw-muted', text: t('Seats unavailable.') }));
			});
		}

		addBtn.addEventListener('click', function () {
			var ids = seatPicker.ids();
			if (!ids.length) {
				NW.toast('warn', t('Pick a user first.'));
				return;
			}
			NW.api('/settings/seats', { method: 'POST', json: { uid: ids[0] } })
				.then(function () { NW.toast('success', t('Seat assigned.')); paint(); })
				.catch(NW.handleError);
		});

		region.innerHTML = '';
		region.appendChild(card(t('Mobile seats'), [
			el('p', { class: 'nw-muted', text: t('Seats control which users may sign in from the mobile companion.') }),
			fieldEl(t('User'), seatPicker.node),
			el('div', { class: 'nw-btn-row' }, [addBtn]),
			listRegion
		]));
		paint();
	}

	/* ---------- templates ---------- */

	var SKELETON_KINDS = [
		['personal', 'Personnel'], ['sach', 'Materials'], ['reise', 'Travel'],
		['extern', 'External services'], ['eigen', 'Own funds'], ['fremd', 'Third-party funds']
	];

	var RULE_FLAGS = [
		['einzelansatz_20pct', '20 % tolerance per budget position'],
		['festbetrag', 'Flat-rate funding'],
		['zwischen_enabled', 'Interim reports enabled']
	];

	var NUMERIC_FLAGS = [
		['final_months', 'Final report due (months after period end)'],
		['zwischen_months', 'Interim report interval (months)'],
		['bew_end_warn_days', 'Period-end warning (days before)']
	];

	function renderTemplates(host) {
		host.appendChild(loading());
		NW.api('/templates').then(function (templates) {
			host.innerHTML = '';
			host.appendChild(card(t('Funder templates'), [
				el('p', { class: 'nw-muted', text: t('Templates drive the threshold rule, deadlines and export layout — the new-project dialog offers this list.') }),
				tableEl([t('Key'), t('Name'), t('Source')],
					(templates || []).map(function (x) {
						return [
							el('code', { class: 'nw-mono', text: x.key }),
							x.name,
							NW.badge(x.source === 'builtin' ? 'neutral' : (x.source === 'pack' ? 'info' : 'success'), t(x.source))
						];
					}), t('No templates.'))
			]));

			if (!role.isPro) {
				host.appendChild(el('div', { class: 'nw-callout nw-callout--info', role: 'note' }, [
					el('p', { class: 'nw-callout__title', text: t('Template packs are a Pro feature') }),
					el('p', { class: 'nw-callout__text', text: t('Installing custom funder packs requires a Pro licence.') })
				]));
				return;
			}
			renderInstallForm(host);
		}).catch(function (e) {
			host.innerHTML = '';
			NW.handleError(e);
		});
	}

	function renderInstallForm(host) {
		var skeletonGrid = el('div', { class: 'nw-edit-grid' });
		[t('Code'), t('Title'), t('Kind'), t('Budget (EUR)'), ''].forEach(function (h) {
			skeletonGrid.appendChild(el('span', { class: 'nw-edit-grid__head', text: h }));
		});
		var rows = [];
		function addRow() {
			var code = inputEl('sk_code');
			var title = inputEl('sk_title');
			var kind = el('select', { class: 'nw-select', name: 'sk_kind' });
			SKELETON_KINDS.forEach(function (k) {
				kind.appendChild(el('option', { value: k[0], text: t(k[1]) }));
			});
			var spacer = el('span', { 'aria-hidden': 'true' });
			var rm = el('button', { type: 'button', class: 'nw-btn nw-btn--small nw-btn--ghost', 'aria-label': t('Remove row'), text: '×' });
			var r = { code: code, title: title, kind: kind };
			rm.addEventListener('click', function () { rows.splice(rows.indexOf(r), 1); row.remove(); });
			var row = el('div', { class: 'nw-edit-grid__row' }, [code, title, kind, spacer, rm]);
			skeletonGrid.appendChild(row);
			rows.push(r);
		}
		addRow();
		var addSk = el('button', { type: 'button', class: 'nw-btn nw-btn--small nw-btn--secondary', text: t('Add position') });
		addSk.addEventListener('click', addRow);

		var flagBox = el('div');
		RULE_FLAGS.forEach(function (f) {
			var id = 'nw-flag-' + f[0];
			var cb = inputEl('flag_' + f[0], { type: 'checkbox', value: '1' });
			cb.id = id;
			flagBox.appendChild(el('div', { class: 'nw-checkbox-row' }, [
				el('label', { 'for': id }, [cb, document.createTextNode(' ' + t(f[1]))])
			]));
		});
		var numBox = el('div', { class: 'nw-filter-grid' });
		NUMERIC_FLAGS.forEach(function (f) {
			numBox.appendChild(fieldEl(t(f[1]), inputEl('numflag_' + f[0], {
				type: 'number', inputmode: 'numeric', placeholder: '—'
			})));
		});

		var keyIn = inputEl('pack_key', { required: true, pattern: '[a-z0-9][a-z0-9-]{1,62}', placeholder: 'mein-geber' });
		var nameIn = inputEl('pack_name', { required: true });
		var form = el('form', { novalidate: 'novalidate' }, [
			el('div', { class: 'nw-filter-grid' }, [
				fieldEl(t('Pack key'), keyIn, { hint: t('lowercase letters, digits, dashes') }),
				fieldEl(t('Pack name'), nameIn)
			]),
			el('fieldset', { class: 'nw-fieldset' }, [
				el('legend', { class: 'nw-fieldset__legend', text: t('Rule flags') }),
				flagBox,
				numBox
			]),
			el('fieldset', { class: 'nw-fieldset' }, [
				el('legend', { class: 'nw-fieldset__legend', text: t('Position skeleton') }),
				skeletonGrid,
				addSk
			]),
			el('div', { class: 'nw-form-actions' }, [saveBtn()])
		]);
		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			var flags = {};
			RULE_FLAGS.forEach(function (f) {
				var cb = form.elements.namedItem('flag_' + f[0]);
				if (cb) { flags[f[0]] = !!cb.checked; }
			});
			NUMERIC_FLAGS.forEach(function (f) {
				var c = form.elements.namedItem('numflag_' + f[0]);
				if (c && c.value !== '') { flags[f[0]] = parseInt(c.value, 10); }
			});
			var skeleton = rows.map(function (r) {
				return { code: r.code.value.trim(), title: r.title.value.trim(), kind: r.kind.value };
			}).filter(function (p) { return p.code !== '' || p.title !== ''; });
			var incomplete = skeleton.some(function (p) { return p.code === '' || p.title === ''; });
			if (incomplete) {
				NW.toast('error', t('Every skeleton row needs a code and a title.'));
				return;
			}
			busySubmit(form, function () {
				return NW.api('/templates/install', { method: 'POST', json: {
					pack: {
						key: keyIn.value.trim(),
						name: nameIn.value.trim(),
						rule_flags: flags,
						skeleton: skeleton
					}
				}}).then(function () {
					renderTemplates(hostClear());
				});
			});
		});
		host.appendChild(card(t('Install a template pack'), [form]));
	}

	function hostClear() {
		var h = document.getElementById('nw-settings');
		if (h) { h.innerHTML = ''; }
		return h;
	}

	/* ---------- license (Pro + mobile seats) ---------- */

	function licenseBadge(st) {
		var s = st.state;
		var tier = st.tier;
		if (tier !== 'pro' && (!s || !s.valid)) {
			if (s && !s.valid) {
				return el('span', { class: 'nw-badge nw-badge--danger' }, [el('span', { class: 'nw-badge__dot' }), el('span', { text: t('Expired') })]);
			}
			return el('span', { class: 'nw-badge nw-badge--neutral' }, [el('span', { class: 'nw-badge__dot' }), el('span', { text: t('Not configured') })]);
		}
		if (s && s.expiresSoon) {
			return el('span', { class: 'nw-badge nw-badge--warn' }, [el('span', { class: 'nw-badge__dot' }), el('span', { text: t('Active — renew soon') })]);
		}
		return el('span', { class: 'nw-badge nw-badge--success' }, [el('span', { class: 'nw-badge__dot' }), el('span', { text: t('Active') })]);
	}

	function renderLicense(host) {
		host.appendChild(loading());
		NW.api('/license').then(function (st) {
			host.innerHTML = '';
			var s = st.state || null;

			var rows = [
				el('div', { class: 'nw-field' }, [
					el('span', { class: 'nw-field__label', text: t('License') }),
					licenseBadge(st)
				])
			];
			if (s) {
				rows.push(el('div', { class: 'nw-field' }, [
					el('span', { class: 'nw-field__label', text: t('Valid until') }),
					el('span', { text: s.validUntil + (s.daysRemaining !== null && s.daysRemaining >= 0 ? ' (' + s.daysRemaining + ' ' + t('day(s) remaining') + ')' : '') })
				]));
			}
			rows.push(el('div', { class: 'nw-field' }, [
				el('span', { class: 'nw-field__label', text: t('Mobile seats used') }),
				el('span', { text: (st.seats ? st.seats.assigned : 0) + ' / ' + (st.seats ? st.seats.limit : 0) })
			]));
			if (st.override) {
				rows.push(el('p', { class: 'nw-field__hint', text: t('A dev override (nw_license_mode=%s) is active and takes precedence over the stored license.').replace('%s', st.override) }));
			}

			var keyInput = el('textarea', {
				class: 'nw-input nw-license-key',
				name: 'license_key', rows: '3', spellcheck: 'false',
				'aria-label': t('License key'),
				placeholder: 'NWC2.…'
			});
			var applyBtn = el('button', { type: 'button', class: 'nw-btn nw-btn--primary', text: t('Save license') });
			applyBtn.addEventListener('click', function () {
				var key = keyInput.value.trim();
				if (!key) {
					NW.toast('warn', t('Paste an NWC2 license key.'));
					return;
				}
				applyBtn.disabled = true;
				NW.api('/license', { method: 'POST', json: { key: key } })
					.then(function () { NW.toast('success', t('License saved.')); renderLicense(hostClear()); })
					.catch(NW.handleError)
					.finally(function () { applyBtn.disabled = false; });
			});
			var actions = [applyBtn];
			if (s) {
				var rmBtn = el('button', { type: 'button', class: 'nw-btn nw-btn--danger', text: t('Remove license') });
				rmBtn.addEventListener('click', function () {
					NW.confirmDialog(
						t('Remove this license?'),
						t('Pro features and mobile seats stop working immediately. Your data stays untouched.'),
						{ confirmLabel: t('Remove license'), danger: true }
					).then(function (yes) {
						if (!yes) { return; }
						NW.api('/license', { method: 'DELETE' })
							.then(function () { NW.toast('success', t('License removed.')); renderLicense(hostClear()); })
							.catch(NW.handleError);
					});
				});
				actions.push(rmBtn);
			}
			actions.push(el('a', { class: 'nw-btn nw-btn--secondary', href: st.purchaseUrl || '#', target: '_blank', rel: 'noopener noreferrer', text: t('Purchase or renew') }));

			host.appendChild(card(t('NachweisCheck Pro'), rows.concat([
				el('p', { class: 'nw-muted', text: t('Pro unlocks multiple active projects, all funder templates, e-invoice parsing, the timesheet sign-off chain, auditor seats and mobile companion seats.') }),
				el('div', { class: 'nw-field' }, [
					el('label', { 'for': 'nw-license-key', text: t('License key') }),
					keyInput,
					el('p', { class: 'nw-field__hint', text: t('Paste the full key from your license email. Line breaks are OK.') })
				]),
				el('div', { class: 'nw-btn-row' }, actions)
			])));
			keyInput.id = 'nw-license-key';

			renderSeats(host);
		}).catch(function (e) {
			host.innerHTML = '';
			NW.handleError(e);
		});
	}

	/* ---------- notifications ---------- */

	function renderNotifications(host) {
		host.appendChild(loading());
		NW.api('/settings/notify').then(function (cfg) {
			host.innerHTML = '';
			var cb = inputEl('notify_deadlines', { type: 'checkbox', value: '1' });
			cb.className = 'nw-switch-field__input';
			cb.id = 'nw-notify-deadlines';
			cb.checked = cfg.notify_deadlines !== false;
			cb.addEventListener('change', function () {
				NW.api('/settings/notify', { method: 'PUT', json: {
					notify_deadlines: cb.checked ? '1' : '0'
				}}).then(function () {
					NW.toast('success', t('Saved.'));
				}).catch(function (e) {
					cb.checked = !cb.checked;
					NW.handleError(e);
				});
			});
			host.appendChild(card(t('Notifications'), [
				el('div', { class: 'nw-switch-field' }, [
					cb,
					el('label', { class: 'nw-switch-field__label', 'for': 'nw-notify-deadlines' }, [
						el('span', { class: 'nw-switch-field__track', 'aria-hidden': 'true' }),
						el('span', { class: 'nw-switch-field__text' }, [
							el('strong', { text: t('Deadline reminders') }),
							el('span', { class: 'nw-muted', text: t('Notify me before funding deadlines — report due dates, period-end warnings, interim reports.') })
						])
					])
				])
			]));
		}).catch(function (e) {
			host.innerHTML = '';
			NW.handleError(e);
		});
	}

	/* ---------- boot ---------- */

	var RENDERERS = {
		access: renderAccess,
		templates: renderTemplates,
		notifications: renderNotifications,
		license: renderLicense
	};

	function init() {
		var host = document.getElementById('nw-settings');
		if (!host) { return; }
		var section = host.getAttribute('data-nw-settings') || 'access';
		(RENDERERS[section] || renderAccess)(host);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
