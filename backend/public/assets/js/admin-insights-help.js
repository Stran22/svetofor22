'use strict';

document.addEventListener('click', function (event) {
    const button = event.target.closest('.insight-help');

    if (!button) {
        return;
    }

    const dialog = document.getElementById('insight-help-dialog');
    const title = document.getElementById('insight-help-title');
    const description = document.getElementById('insight-help-description');

    if (!dialog || !title || !description) {
        return;
    }

    title.textContent = button.dataset.insightTitle || '';
    description.textContent = button.dataset.insightDescription || '';

    if (!dialog.open) {
        dialog.showModal();
    }
});
