import { InspectorControls, RichText, useBlockProps } from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import { createElement, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { LinkControl } from '../../components/link-control';
import { MediaControl } from '../../components/media-control';

export function HeroEdit({ attributes = {}, setAttributes }) {
  const blockProps = useBlockProps({
    className: 'goetz-editor-preview goetz-editor-preview--hero',
  });

  return (
    <Fragment>
      <InspectorControls>
        <PanelBody title={__('Hero media and link', 'goetz-site')} initialOpen>
          <MediaControl
            label={__('Hero image', 'goetz-site')}
            imageId={attributes.imageId || 0}
            imageUrl={attributes.imageUrl || ''}
            imageAlt={attributes.imageAlt || ''}
            onChange={(media) => setAttributes(media)}
          />
          <LinkControl
            label={__('Hero button link', 'goetz-site')}
            url={attributes.buttonUrl || ''}
            newTab={Boolean(attributes.buttonNewTab)}
            onChange={({ url, newTab }) =>
              setAttributes({ buttonUrl: url, buttonNewTab: newTab })
            }
          />
        </PanelBody>
      </InspectorControls>
      <section {...blockProps}>
        <RichText
          tagName="p"
          className="goetz-editor-preview__eyebrow"
          aria-label={__('Hero eyebrow', 'goetz-site')}
          value={attributes.eyebrow || ''}
          allowedFormats={[]}
          onChange={(eyebrow) => setAttributes({ eyebrow })}
        />
        <RichText
          tagName="h2"
          className="goetz-editor-preview__heading"
          aria-label={__('Hero heading', 'goetz-site')}
          value={attributes.heading || ''}
          allowedFormats={['core/bold', 'core/italic']}
          onChange={(heading) => setAttributes({ heading })}
        />
        <RichText
          tagName="p"
          className="goetz-editor-preview__content"
          aria-label={__('Hero content', 'goetz-site')}
          value={attributes.content || ''}
          allowedFormats={['core/bold', 'core/italic', 'core/link']}
          onChange={(content) => setAttributes({ content })}
        />
        <RichText
          tagName="span"
          className="goetz-editor-preview__button"
          aria-label={__('Hero button text', 'goetz-site')}
          value={attributes.buttonText || ''}
          allowedFormats={[]}
          onChange={(buttonText) => setAttributes({ buttonText })}
        />
      </section>
    </Fragment>
  );
}

export function save() {
  return null;
}
