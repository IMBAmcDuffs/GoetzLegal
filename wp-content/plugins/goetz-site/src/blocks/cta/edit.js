import { InspectorControls, RichText, useBlockProps } from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import { createElement, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { LinkControl } from '../../components/link-control';
import { MediaControl } from '../../components/media-control';

export function CtaEdit({ attributes = {}, setAttributes }) {
  const settings = globalThis.goetzSiteEditorSettings || {};
  const backgroundImageUrl =
    typeof attributes.backgroundImageUrl === 'string' &&
    attributes.backgroundImageUrl.trim() !== ''
      ? attributes.backgroundImageUrl
      : settings.ctaBackgroundUrl || '';
  const buttonText =
    typeof attributes.buttonText === 'string' && attributes.buttonText.trim() !== ''
      ? attributes.buttonText
      : settings.ctaLabel || 'Get Consultation';
  const buttonUrl =
    typeof attributes.buttonUrl === 'string' && attributes.buttonUrl.trim() !== ''
      ? attributes.buttonUrl
      : settings.ctaUrl || '/contact/';
  const blockProps = useBlockProps({
    className: 'goetz-cta goetz-editor-preview goetz-editor-preview--cta',
    style: backgroundImageUrl
      ? {
          '--goetz-cta-background-image': `url("${backgroundImageUrl}")`,
        }
      : undefined,
  });

  return (
    <Fragment>
      <InspectorControls>
        <PanelBody title={__('CTA media and link', 'goetz-site')} initialOpen>
          <MediaControl
            label={__('CTA background image', 'goetz-site')}
            imageId={attributes.backgroundImageId || 0}
            imageUrl={backgroundImageUrl}
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
            url={buttonUrl}
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
          className="goetz-button goetz-editor-preview__button"
          aria-label={__('CTA button text', 'goetz-site')}
          value={buttonText}
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
