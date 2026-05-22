(function () {
	'use strict';

	function ready(callback) {
		if (document.readyState !== 'loading') {
			callback();
			return;
		}

		document.addEventListener('DOMContentLoaded', callback);
	}

	function createElement(tag, className, text) {
		var element = document.createElement(tag);

		if (className) {
			element.className = className;
		}

		if (typeof text === 'string') {
			element.textContent = text;
		}

		return element;
	}

	function setProfileMenuState(profile, trigger, isOpen) {
		profile.classList.toggle('is-open', isOpen);
		trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
	}

	function addSidebarBrand(config) {
		var menuWrap = document.getElementById('adminmenuwrap');
		var adminMenu = document.getElementById('adminmenu');

		if (!menuWrap || !adminMenu || menuWrap.querySelector('.gaticrew-admin-brand')) {
			return;
		}

		var brand = createElement('div', 'gaticrew-admin-brand');
		var mark = createElement('span', 'gaticrew-admin-brand__mark', 'GC');
		var copy = createElement('span', 'gaticrew-admin-brand__copy');
		var title = createElement('strong', '', config.brand || 'GatiCrew');
		var subtitle = createElement('small', '', config.product || 'Operations Platform');

		copy.appendChild(title);
		copy.appendChild(subtitle);
		brand.appendChild(mark);
		brand.appendChild(copy);
		menuWrap.insertBefore(brand, adminMenu);
	}

	function addSidebarProfile(config) {
		var menuWrap = document.getElementById('adminmenuwrap');

		if (!menuWrap || menuWrap.querySelector('.gaticrew-admin-profile')) {
			return;
		}

		var profile = createElement('div', 'gaticrew-admin-profile');
		var trigger = createElement('button', 'gaticrew-admin-profile__trigger');
		var avatar = createElement('span', 'gaticrew-admin-profile__avatar', config.initials || 'GC');
		var content = createElement('span', 'gaticrew-admin-profile__content');
		var name = createElement('strong', '', config.displayName || 'Operator');
		var chevron = createElement('span', 'gaticrew-admin-profile__chevron');
		var menu = createElement('div', 'gaticrew-admin-profile__menu');
		var links = [
			{
				label: 'Profile',
				url: config.profileUrl || 'profile.php',
				icon: 'P'
			},
			{
				label: 'Account Settings',
				url: config.accountUrl || 'profile.php#account-management',
				icon: 'A'
			},
			{
				label: 'Logout',
				url: config.logoutUrl || '#',
				icon: 'L'
			}
		];

		trigger.type = 'button';
		trigger.setAttribute('aria-haspopup', 'true');
		trigger.setAttribute('aria-expanded', 'false');
		trigger.setAttribute('title', (config.displayName || 'Operator') + ' - ' + (config.roleLabel || 'Operations'));
		content.appendChild(name);
		trigger.appendChild(avatar);
		trigger.appendChild(content);
		trigger.appendChild(chevron);
		profile.appendChild(trigger);

		links.forEach(function (link) {
			var item = createElement('a', 'gaticrew-admin-profile__menu-item');
			var icon = createElement('span', 'gaticrew-admin-profile__menu-icon', link.icon);
			var label = createElement('span', '', link.label);

			item.href = link.url;
			item.appendChild(icon);
			item.appendChild(label);
			menu.appendChild(item);
		});

		profile.appendChild(menu);

		trigger.addEventListener('click', function (event) {
			event.preventDefault();
			setProfileMenuState(profile, trigger, !profile.classList.contains('is-open'));
		});

		document.addEventListener('click', function (event) {
			if (!profile.contains(event.target)) {
				setProfileMenuState(profile, trigger, false);
			}
		});

		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') {
				setProfileMenuState(profile, trigger, false);
			}
		});

		menuWrap.appendChild(profile);
	}

	function cleanupTopbar() {
		[
			'wp-admin-bar-wp-logo',
			'wp-admin-bar-site-name',
			'wp-admin-bar-comments',
			'wp-admin-bar-updates',
			'wp-admin-bar-my-account',
			'wp-admin-bar-gaticrew-platform'
		].forEach(function (id) {
			var node = document.getElementById(id);

			if (node) {
				node.remove();
			}
		});
	}

	function enhanceTables() {
		document.querySelectorAll('.wp-list-table, table.widefat').forEach(function (table) {
			table.classList.add('gaticrew-enhanced-table');
		});
	}

	ready(function () {
		var config = window.GatiCrewAdminUI || {};

		document.body.classList.add('gaticrew-admin-ready');
		cleanupTopbar();
		addSidebarBrand(config);
		addSidebarProfile(config);
		enhanceTables();
	});
}());
