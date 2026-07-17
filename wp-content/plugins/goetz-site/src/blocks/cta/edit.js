import { InspectorControls, RichText, useBlockProps } from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import { createElement, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { LinkControl } from '../../components/link-control';
import { MediaControl } from '../../components/media-control';

export function CtaEdit({ attributes = {}, setAttributes }) {
  const blockProps = useBlockProps({
    className: 'goetz-editor-preview goetz-editor-preview--cta',
  });

  return (
    <Fragment>
      <InspectorControls>
        <PanelBody title={__('CTA media and link', 'goetz-site')} initialOpen>
          <MediaControl
            label={__('CTA background image', 'goetz-site')}
            imageId={attributes.backgroundImageId || 0}
            imageUrl={attributes.backgroundImageUrl || ''}
            imageAlt=""
            showAlt={false}
            onChange={({ imageId, imageUrl }) =>
              setAttributes({
                backgroundImageId: imageId,
                backgroundImageUrl: imageUrl,
              })
            }
          />
          <LinkControl
            label={__('CTA button link', 'goetz-site')}
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
          aria-label={__('CTA eyebrow', 'goetz-site')}
          value={attributes.eyebrow || ''}
          allowedFormats={[]}
          onChange={(eyebrow) => setAttributes({ eyebrow })}
        />
        <RichText
          tagName="h2"
          className="goetz-editor-preview__heading"
          aria-label={__('CTA heading', 'goetz-site')}
          value={attributes.heading || ''}
          allowedFormats={['core/bold', 'core/italic']}
          onChange={(heading) => setAttributes({ heading })}
        />
        <RichText
          tagName="span"
          className="goetz-editor-preview__button"
          aria-label={__('CTA button text', 'goetz-site')}
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
