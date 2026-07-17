(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var idField = document.querySelector('[data-goetz-media-id]');
        var preview = document.querySelector('[data-goetz-media-preview]');
        var selectButton = document.querySelector('[data-goetz-media-select]');
        var removeButton = document.querySelector('[data-goetz-media-remove]');

        if (!idField || !preview || !selectButton || !removeButton || !window.wp || !window.wp.media) {
            return;
        }

        var frame;
        selectButton.addEventListener('click', function () {
            if (!frame) {
                frame = window.wp.media({
                    title: 'Choose social image',
                    button: { text: 'Use this image' },
                    library: { type: 'image' },
                    multiple: false
                });
                frame.on('select', function () {
                    var attachment = frame.state().get('selection').first().toJSON();
                    var imageUrl = attachment.sizes && attachment.sizes.thumbnail
                        ? attachment.sizes.thumbnail.url
                        : attachment.url;
                    idField.value = String(attachment.id);
                    preview.replaceChildren();
                    var image = document.createElement('img');
                    image.src = imageUrl;
                    image.alt = attachment.alt || '';
                    image.className = 'goetz-site-settings-image-preview';
                    preview.appendChild(image);
                });
            }
            frame.open();
        });

        removeButton.addEventListener('click', function () {
            idField.value = '0';
            preview.replaceChildren();
        });
    });
}());
