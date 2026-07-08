(function (blocks, element) {
  blocks.registerBlockType('goetz/hero', {
    edit: function () {
      return element.createElement('div', { className: 'goetz-editor-card' }, 'Goetz Hero');
    },
    save: function () {
      return null;
    }
  });
})(window.wp.blocks, window.wp.element);

