import { InspectorControls, RichText, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import { createElement, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { LinkControl } from '../../components/link-control';
import { MediaControl } from '../../components/media-control';

export function AttorneyCardEdit({ attributes = {}, setAttributes }) {
  const blockProps = useBlockProps({
    className: 'goetz-editor-preview goetz-editor-preview--attorney-card',
  });

  return (
    <Fragment>
      <InspectorControls>
        <PanelBody title={__('Attorney media and link', 'goetz-site')} initialOpen>
          <MediaControl
            label={__('Attorney image', 'goetz-site')}
            imageId={attributes.imageId || 0}
            imageUrl={attributes.imageUrl || ''}
            imageAlt={attributes.imageAlt || ''}
            onChange={(media) => setAttributes(media)}
          />
          <TextControl
            label={__('Attorney email', 'goetz-site')}
            value={attributes.email || ''}
            onChange={(email) => setAttributes({ email })}
            __nextHasNoMarginBottom
            __next40pxDefaultSize
          />
          <LinkControl
            label={__('Attorney profile link', 'goetz-site')}
            url={attributes.profileUrl || ''}
            newTab={Boolean(attributes.profileNewTab)}
            onChange={({ url, newTab }) =>
              setAttributes({ profileUrl: url, profileNewTab: newTab })
            }
          />
        </PanelBody>
      </InspectorControls>
      <article {...blockProps}>
        <RichText
          tagName="h3"
          className="goetz-editor-preview__heading"
          aria-label={__('Attorney name', 'goetz-site')}
          value={attributes.name || ''}
          allowedFormats={[]}
          onChange={(name) => setAttributes({ name })}
        />
        <RichText
          tagName="p"
          className="goetz-editor-preview__eyebrow"
          aria-label={__('Attorney role', 'goetz-site')}
          value={attributes.role || ''}
          allowedFormats={[]}
          onChange={(role) => setAttributes({ role })}
        />
        <RichText
          tagName="p"
          className="goetz-editor-preview__content"
          aria-label={__('Attorney biography', 'goetz-site')}
          value={attributes.bio || ''}
          allowedFormats={[]}
          onChange={(bio) => setAttributes({ bio })}
        />
      </article>
    </Fragment>
  );
}

export function save() {
  return null;
}
