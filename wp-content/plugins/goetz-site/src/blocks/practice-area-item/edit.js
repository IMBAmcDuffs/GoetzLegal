import { RichText, useBlockProps } from '@wordpress/block-editor';
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export function PracticeAreaItemEdit({
  attributes = {},
  context = {},
  setAttributes,
}) {
  const blockProps = useBlockProps({
    className: 'goetz-practice-area-item is-revealed',
  });
  const scaleUrl = typeof context['goetz/scaleImageUrl'] === 'string'
    ? context['goetz/scaleImageUrl']
    : '';
  const scaleAlt = typeof context['goetz/scaleImageAlt'] === 'string'
    ? context['goetz/scaleImageAlt']
    : '';
  const useLegacyScaleGlyph =
    !scaleUrl || /\/law-scale-icon-purple\.png(?:[?#].*)?$/i.test(scaleUrl);

  return (
    <li {...blockProps}>
      <span
        className="goetz-practice-area-item__scale"
        aria-hidden={useLegacyScaleGlyph || !scaleAlt ? true : undefined}
      >
        {useLegacyScaleGlyph ? (
          <span
            className="goetz-practice-area-item__scale-glyph"
            aria-hidden="true"
          >
            {'\uf24e'}
          </span>
        ) : (
          <img
            className="goetz-practice-area-item__scale-image"
            src={scaleUrl}
            alt={scaleAlt}
          />
        )}
      </span>
      <RichText
        tagName="b"
        className="goetz-practice-area-item__label"
        aria-label={__('Practice area label', 'goetz-site')}
        value={attributes.label || ''}
        allowedFormats={[]}
        onChange={(label) => setAttributes({ label })}
      />
    </li>
  );
}

export function save() {
  return null;
}
