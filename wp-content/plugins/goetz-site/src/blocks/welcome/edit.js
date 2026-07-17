import {
  InspectorControls,
  RichText,
  URLInput,
  useBlockProps,
} from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import { createElement, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { MediaControl } from '../../components/media-control';

function editorFallbacks() {
  const settings = globalThis.goetzSiteEditorSettings;

  return {
    phoneLabel:
      typeof settings?.phoneLabel === 'string' ? settings.phoneLabel : '',
    phoneUrl: typeof settings?.phoneUrl === 'string' ? settings.phoneUrl : '',
    onlineUrl:
      typeof settings?.onlineUrl === 'string' ? settings.onlineUrl : '/contact/',
  };
}

function valueOrFallback(value, fallback) {
  return typeof value === 'string' && value.trim() !== '' ? value : fallback;
}

function mediaPreview(url, alt, side) {
  return (
    <div className={`goetz-intro__media goetz-intro__media--${side}`}>
      {url ? <img className="goetz-intro__image" src={url} alt={alt || ''} /> : null}
    </div>
  );
}

export function WelcomeEdit({ attributes = {}, setAttributes }) {
  const blockProps = useBlockProps({
    className: 'goetz-welcome goetz-intro-section',
  });
  const fallbacks = editorFallbacks();
  const phoneLabel = valueOrFallback(attributes.phoneLabel, fallbacks.phoneLabel);
  const phoneUrl = valueOrFallback(attributes.phoneUrl, fallbacks.phoneUrl);
  const onlineLabel = valueOrFallback(attributes.onlineLabel, 'online');
  const onlineUrl = valueOrFallback(attributes.onlineUrl, fallbacks.onlineUrl);

  return (
    <Fragment>
      <InspectorControls>
        <PanelBody title={__('Welcome media and links', 'goetz-site')} initialOpen>
          <MediaControl
            label={__('Welcome left image', 'goetz-site')}
            imageId={attributes.leftImageId || 0}
            imageUrl={attributes.leftImageUrl || ''}
            imageAlt={attributes.leftImageAlt || ''}
            onChange={({ imageId, imageUrl, imageAlt }) =>
              setAttributes({
                leftImageId: imageId,
                leftImageUrl: imageUrl,
                leftImageAlt: imageAlt,
              })
            }
          />
          <MediaControl
            label={__('Welcome right image', 'goetz-site')}
            imageId={attributes.rightImageId || 0}
            imageUrl={attributes.rightImageUrl || ''}
            imageAlt={attributes.rightImageAlt || ''}
            onChange={({ imageId, imageUrl, imageAlt }) =>
              setAttributes({
                rightImageId: imageId,
                rightImageUrl: imageUrl,
                rightImageAlt: imageAlt,
              })
            }
          />
          <URLInput
            label={__('Welcome phone URL', 'goetz-site')}
            value={phoneUrl}
            onChange={(phoneUrl) => setAttributes({ phoneUrl })}
            help={__('Leave blank to use the Site Settings phone link.', 'goetz-site')}
            required={false}
          />
          <URLInput
            label={__('Welcome online URL', 'goetz-site')}
            value={onlineUrl}
            onChange={(onlineUrl) => setAttributes({ onlineUrl })}
            help={__('Leave blank to use /contact/.', 'goetz-site')}
            required={false}
          />
        </PanelBody>
      </InspectorControls>
      <section {...blockProps}>
        <div className="goetz-intro">
          {mediaPreview(
            attributes.leftImageUrl || '',
            attributes.leftImageAlt || '',
            'left'
          )}
          <div className="goetz-intro__content">
            <RichText
              tagName="h2"
              className="goetz-intro__heading"
              aria-label={__('Welcome heading', 'goetz-site')}
              value={attributes.heading || ''}
              allowedFormats={['core/bold', 'core/italic']}
              onChange={(heading) => setAttributes({ heading })}
            />
            <span className="goetz-intro__icon" aria-hidden="true" />
            <p className="goetz-intro__copy">
              <RichText
                tagName="span"
                className="goetz-intro__prefix"
                aria-label={__('Welcome content prefix', 'goetz-site')}
                value={attributes.contentPrefix || ''}
                allowedFormats={[]}
                onChange={(contentPrefix) => setAttributes({ contentPrefix })}
              />{' '}
              <a
                className="goetz-intro__phone"
                href={phoneUrl || undefined}
                onClick={(event) => event.preventDefault()}
              >
                <RichText
                  tagName="span"
                  aria-label={__('Welcome phone label', 'goetz-site')}
                  value={phoneLabel}
                  allowedFormats={[]}
                  onChange={(phoneLabel) => setAttributes({ phoneLabel })}
                />
              </a>{' '}
              <RichText
                tagName="span"
                className="goetz-intro__join"
                aria-label={__('Welcome content join', 'goetz-site')}
                value={attributes.contentJoin || ''}
                allowedFormats={[]}
                onChange={(contentJoin) => setAttributes({ contentJoin })}
              />{' '}
              <a
                className="goetz-intro__online"
                href={onlineUrl || undefined}
                onClick={(event) => event.preventDefault()}
              >
                <RichText
                  tagName="span"
                  aria-label={__('Welcome online label', 'goetz-site')}
                  value={onlineLabel}
                  allowedFormats={[]}
                  onChange={(onlineLabel) => setAttributes({ onlineLabel })}
                />
              </a>
              <span aria-hidden="true">.</span>
            </p>
          </div>
          {mediaPreview(
            attributes.rightImageUrl || '',
            attributes.rightImageAlt || '',
            'right'
          )}
        </div>
      </section>
    </Fragment>
  );
}

export function save() {
  return null;
}
