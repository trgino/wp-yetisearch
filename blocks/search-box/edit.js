/* WP YetiSearch block editor placeholder. Vanilla JS on purpose: no build step. */
(function (blocks, element) {
  if (!blocks || !element) {
    return;
  }
  var el = element.createElement;
  blocks.registerBlockType('yetisearch/search-box', {
    edit: function () {
      return el(
        'div',
        { className: 'yetisearch-block-placeholder' },
        'WP YetiSearch'
      );
    },
  });
})(window.wp && window.wp.blocks, window.wp && window.wp.element);
