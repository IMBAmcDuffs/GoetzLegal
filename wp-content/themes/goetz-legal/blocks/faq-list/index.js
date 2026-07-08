(function (blocks, element) {
  blocks.registerBlockType('goetz/faq-list', {
    edit: function () {
      return element.createElement('div', { className: 'goetz-editor-card' }, 'Goetz FAQ List');
    },
    save: function () {
      return null;
    }
  });
})(window.wp.blocks, window.wp.element);

