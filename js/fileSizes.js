/**
 * @file js/fileSizes.js
 *
 * Distributed under the GNU GPL v3.
 *
 * Docx Font Stripper: file size under the file type in the back end file
 * lists of OJS 3.3 (workflow, author dashboard, reviewer). Moved here from
 * the Manuscript-Daffa theme, so the sizes are shown with any theme.
 *
 * Sizes come from the server in one request per submission and per page
 * load (window.docxFontStripperFileSizes.url). Sizes of 5 MB and more are
 * shown in bold red: often a Word file with embedded fonts.
 */
(function () {
	'use strict';

	var config = window.docxFontStripperFileSizes || {};
	var LARGE = config.largeBytes || 5 * 1024 * 1024;
	var DECIMAL = config.decimalSeparator || '.';
	var CLASS_NAME = 'dfs-file-size';
	var requests = {};

	function oneDecimal(value) {
		return value.toFixed(1).replace('.', DECIMAL);
	}

	function formatSize(bytes) {
		if (bytes < 1024) {
			return bytes + ' B';
		}
		if (Math.round(bytes / 1024) < 1024) {
			return Math.max(1, Math.round(bytes / 1024)) + ' KB';
		}
		if (bytes < 1024 * 1024 * 1024) {
			return oneDecimal(bytes / 1024 / 1024) + ' MB';
		}
		return oneDecimal(bytes / 1024 / 1024 / 1024) + ' GB';
	}

	function param(url, name) {
		var match = new RegExp('[?&]' + name + '=(\\d+)').exec(String(url || '').replace(/&amp;/g, '&'));
		return match ? parseInt(match[1], 10) : null;
	}

	function pageSubmissionId() {
		var match = /\/reviewer\/submission\/(\d+)/.exec(window.location.pathname)
			|| /\/(?:workflow|authorDashboard)\/[a-zA-Z]+\/(\d+)/.exec(window.location.pathname);
		if (match) {
			return parseInt(match[1], 10);
		}
		var href = window.location.href;
		return param(href, 'workflowSubmissionId') || param(href, 'submissionId');
	}

	function loadSizes(submissionId) {
		if (!config.url || !submissionId) {
			return Promise.resolve({});
		}
		if (!requests[submissionId]) {
			var url = config.url + (config.url.indexOf('?') === -1 ? '?' : '&') + 'submissionId=' + submissionId;
			requests[submissionId] = fetch(url, {credentials: 'same-origin', headers: {Accept: 'application/json'}})
				.then(function (response) {
					return response.ok ? response.json() : {sizes: {}};
				})
				.then(function (data) {
					return (data && data.sizes) || {};
				})
				.catch(function () {
					return {};
				});
		}
		return requests[submissionId];
	}

	/**
	 * The cell that gets the size: the file type, otherwise the last text
	 * cell (the date in the reviewer list).
	 */
	function targetCell(row) {
		var cells = Array.prototype.slice.call(row.children);
		var best = null;
		cells.forEach(function (cell) {
			var text = (cell.textContent || '').replace(/\s+/g, '');
			if (!text || cell.querySelector('button, input, a[href*="submissionFileId"]')) {
				return;
			}
			if (cell.querySelector('a') && !cell.querySelector('span')) {
				return;
			}
			best = cell;
		});
		return best || cells[cells.length > 1 ? 1 : 0] || null;
	}

	/**
	 * Center the size under the first visible element of the cell (the file
	 * type badge, or the date in the reviewer list).
	 */
	function centerUnder(size, cell) {
		for (var el = cell.firstElementChild; el; el = el.nextElementSibling) {
			if (el === size || !el.getClientRects().length) {
				continue;
			}
			var width = el.getBoundingClientRect().width;
			if (width > 0) {
				size.style.display = 'table';
				size.style.minWidth = Math.round(width) + 'px';
				size.style.textAlign = 'center';
			}
			return;
		}
	}

	function showSize(row, bytes) {
		// Also skip rows the old Manuscript-Daffa theme script already sized
		if (row.querySelector('.' + CLASS_NAME + ', .msd-file-size')) {
			return;
		}
		var cell = targetCell(row);
		if (!cell) {
			return;
		}
		var size = document.createElement('div');
		size.className = CLASS_NAME;
		size.textContent = formatSize(bytes);
		size.style.fontSize = '0.8em';
		size.style.lineHeight = '1.3';
		size.style.marginTop = '2px';
		size.style.opacity = '0.8';
		if (bytes >= LARGE) {
			size.className += ' ' + CLASS_NAME + '-large';
			size.style.color = '#d00';
			size.style.fontWeight = '700';
			size.style.opacity = '1';
			if (config.largeLabel) {
				size.title = config.largeLabel;
			}
		}
		cell.appendChild(size);
		centerUnder(size, cell);
	}

	function fileRows() {
		var rows = [];
		// File lists with download links (workflow, author dashboard)
		Array.prototype.forEach.call(document.querySelectorAll('tr a[href*="submissionFileId="]'), function (link) {
			var row = link.closest('tr');
			var fileId = param(link.getAttribute('href'), 'submissionFileId');
			if (row && fileId) {
				rows.push({row: row, fileId: fileId, submissionId: param(link.getAttribute('href'), 'submissionId')});
			}
		});
		// Legacy file grids (reviewer): row ids end with -row-<submissionFileId>
		Array.prototype.forEach.call(document.querySelectorAll('[id^="component-grid-files-"] tr[id*="-row-"]'), function (row) {
			var match = /-row-(\d+)$/.exec(row.id);
			if (match) {
				rows.push({row: row, fileId: parseInt(match[1], 10), submissionId: null});
			}
		});
		return rows;
	}

	function addFileSizes() {
		var pageId = pageSubmissionId();
		fileRows().forEach(function (item) {
			if (item.row.getAttribute('data-dfs-size') === String(item.fileId)) {
				return;
			}
			var submissionId = item.submissionId || pageId;
			if (!submissionId) {
				return;
			}
			item.row.setAttribute('data-dfs-size', String(item.fileId));
			loadSizes(submissionId).then(function (sizes) {
				if (Object.prototype.hasOwnProperty.call(sizes, item.fileId) && item.row.isConnected) {
					showSize(item.row, sizes[item.fileId]);
				}
			});
		});
	}

	// Run now and when the page changes (grids are loaded with AJAX)
	var timer = null;
	function run() {
		timer = null;
		try {
			addFileSizes();
		} catch (e) {
			if (window.console) {
				window.console.warn('docxFontStripper file sizes:', e);
			}
		}
	}

	function schedule() {
		if (timer === null) {
			timer = window.setTimeout(run, 150);
		}
	}

	function start() {
		run();
		if (!window.MutationObserver) {
			return;
		}
		new MutationObserver(function (mutations) {
			for (var i = 0; i < mutations.length; i++) {
				var nodes = mutations[i].addedNodes;
				var own = nodes.length > 0;
				for (var j = 0; j < nodes.length; j++) {
					var cls = nodes[j].classList;
					if (!cls || !cls.contains(CLASS_NAME)) {
						own = false;
					}
				}
				if (!own) {
					schedule();
					return;
				}
			}
		}).observe(document.body, {childList: true, subtree: true});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
