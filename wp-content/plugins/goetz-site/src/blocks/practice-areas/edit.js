import {
  InnerBlocks,
  InspectorControls,
  RichText,
  useBlockProps,
} from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import { createElement, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { MediaControl } from '../../components/media-control';

export const DEFAULT_ITEMS = [
  'Corporate',
  'Construction',
  'Real Estate',
  'Probate',
  'Criminal',
  'Bankruptcy',
  'Appeals',
];

export function PracticeAreasEdit({ attributes = {}, setAttributes }) {
  const blockProps = useBlockProps({
    className:
      'goetz-practice-areas goetz-practice-band is-animation-ready is-animation-complete',
  });

  return (
    <Fragment>
      <InspectorControls>
        <PanelBody title={__('Practice Areas media', 'goetz-site')} initialOpen>
          <MediaControl
            label={__('Practice background image', 'goetz-site')}
            imageId={attributes.backgroundImageId || 0}
            imageUrl={attributes.backgroundImageUrl || ''}
            imageAlt={attributes.backgroundImageAlt || ''}
            onChange={({ imageId, imageUrl, imageAlt }) =>
              setAttributes({
                backgroundImageId: imageId,
                backgroundImageUrl: imageUrl,
                backgroundImageAlt: imageAlt,
              })
            }
          />
          <MediaControl
            label={__('Practice scale image', 'goetz-site')}
            imageId={attributes.scaleImageId || 0}
            imageUrl={attributes.scaleImageUrl || ''}
            imageAlt={attributes.scaleImageAlt || ''}
            onChange={({ imageId, imageUrl, imageAlt }) =>
              setAttributes({
                scaleImageId: imageId,
                scaleImageUrl: imageUrl,
                scaleImageAlt: imageAlt,
              })
            }
          />
        </PanelBody>
      </InspectorControls>
      <section {...blockProps}>
        <figure className="goetz-practice-band__image">
          {attributes.backgroundImageUrl ? (
            <img
              className="goetz-practice-band__background"
              src={attributes.backgroundImageUrl}
              alt={attributes.backgroundImageAlt || ''}
            />
          ) : null}
        </figure>
        <div className="goetz-practice-band__content">
          <RichText
            tagName="h2"
            className="goetz-practice-areas__heading"
            aria-label={__('Practice Areas heading', 'goetz-site')}
            value={attributes.heading || ''}
            allowedFormats={['core/bold', 'core/italic']}
            onChange={(heading) => setAttributes({ heading })}
          />
          <div className="goetz-practice-list">
            <InnerBlocks
              allowedBlocks={['goetz/practice-area-item']}
              template={DEFAULT_ITEMS.map((label) => [
                'goetz/practice-area-item',
                { label },
              ])}
              templateLock={false}
              renderAppender={InnerBlocks.ButtonBlockAppender}
            />
          </div>
        </div>
      </section>
    </Fragment>
  );
}

export function save() {
  return <InnerBlocks.Content />;
}
