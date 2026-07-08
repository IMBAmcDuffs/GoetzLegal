(function (blocks, element) {
  blocks.registerBlockType('goetz/attorney-card', {
    edit: function () {
      return element.createElement('div', { className: 'goetz-editor-card' }, 'Goetz Attorney Card');
    },
    save: function () {
      return null;
    }
  });
})(window.wp.blocks, window.wp.element);

