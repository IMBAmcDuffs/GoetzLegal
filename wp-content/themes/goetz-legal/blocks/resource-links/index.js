(function (blocks, element) {
  blocks.registerBlockType('goetz/resource-links', {
    edit: function () {
      return element.createElement('div', { className: 'goetz-editor-card' }, 'Goetz Resource Links');
    },
    save: function () {
      return null;
    }
  });
})(window.wp.blocks, window.wp.element);

