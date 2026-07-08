(function (blocks, element) {
  blocks.registerBlockType('goetz/cta', {
    edit: function () {
      return element.createElement('div', { className: 'goetz-editor-card' }, 'Goetz CTA');
    },
    save: function () {
      return null;
    }
  });
})(window.wp.blocks, window.wp.element);

