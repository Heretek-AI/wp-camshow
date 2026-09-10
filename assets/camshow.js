(function () {
	var widget = document.getElementById('cbcs-widget');
	if (!widget) {
		return;
	}
	var STORAGE_KEY = 'cbcsHide';
	var expandBtn = widget.querySelector('.cbcs-expand');
	var closeBtn = widget.querySelector('.cbcs-close');

	function dismissHours() {
		var hours = parseInt(widget.getAttribute('data-dismiss-hours'), 10);
		return isFinite(hours) && hours > 0 ? hours : 6;
	}

	function setExpanded(expanded) {
		widget.classList.toggle('cbcs-is-expanded', expanded);
		expandBtn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
		expandBtn.textContent = expanded ? '⤡' : '⤢';
	}

	expandBtn.addEventListener('click', function () {
		setExpanded(!widget.classList.contains('cbcs-is-expanded'));
	});

	closeBtn.addEventListener('click', function () {
		setExpanded(false);
		widget.style.display = 'none';
		try {
			localStorage.setItem(
				STORAGE_KEY,
				JSON.stringify({
					until: Date.now() + dismissHours() * 3600000,
					room: widget.getAttribute('data-room') || ''
				})
			);
		} catch (e) {}
	});
})();
