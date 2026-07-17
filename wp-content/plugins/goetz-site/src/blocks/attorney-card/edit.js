import { InspectorControls, RichText, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import { createElement, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { LinkControl } from '../../components/link-control';
import { MediaControl } from '../../components/media-control';

export function AttorneyCardEdit({ attributes = {}, setAttributes }) {
  const isProfile = /(?:^|\s)is-style-profile(?:\s|$)/.test(
    typeof attributes.className === 'string' ? attributes.className : ''
  );
  const blockProps = useBlockProps({
    className: [
      'goetz-editor-preview goetz-editor-preview--attorney-card',
      isProfile
        ? 'goetz-editor-preview--attorney-profile goetz-attorney-card--profile'
        : '',
    ].filter(Boolean).join(' '),
  });
  const nameField = (
    <RichText
      tagName={isProfile ? 'h2' : 'h3'}
      className={
        isProfile
          ? 'goetz-attorney-card__heading'
          : 'goetz-editor-preview__heading'
      }
      aria-label={__('Attorney name', 'goetz-site')}
      value={attributes.name || ''}
      allowedFormats={[]}
      onChange={(name) => setAttributes({ name })}
    />
  );
  const roleField = (
    <RichText
      tagName="p"
      className={
        isProfile
          ? 'goetz-attorney-card__role'
          : 'goetz-editor-preview__eyebrow'
      }
      aria-label={__('Attorney role', 'goetz-site')}
      value={attributes.role || ''}
      allowedFormats={[]}
      onChange={(role) => setAttributes({ role })}
    />
  );
  const biographyField = (
    <RichText
      tagName="p"
      className={
        isProfile
          ? 'goetz-attorney-card__bio'
          : 'goetz-editor-preview__content'
      }
      aria-label={__('Attorney biography', 'goetz-site')}
      value={attributes.bio || ''}
      allowedFormats={[]}
      onChange={(bio) => setAttributes({ bio })}
    />
  );

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
      {isProfile ? (
        <article {...blockProps}>
          {attributes.imageUrl ? (
            <img
              className="goetz-attorney-card__image"
              src={attributes.imageUrl}
              alt={attributes.imageAlt || attributes.name || ''}
            />
          ) : (
            <div
              className="goetz-attorney-card__image-placeholder"
              aria-hidden="true"
            >
              {__('Select an attorney image', 'goetz-site')}
            </div>
          )}
          <div className="goetz-attorney-card__body">
            <span className="goetz-attorney-card__mark" aria-hidden="true" />
            {nameField}
            {roleField}
            {biographyField}
            <div className="goetz-attorney-card__links" aria-hidden="true">
              {attributes.profileUrl ? (
                <span>{__('Read Full Bio', 'goetz-site')}</span>
              ) : null}
              {attributes.email ? (
                <span>
                  {__('Email', 'goetz-site')} {attributes.name || ''}
                </span>
              ) : null}
            </div>
          </div>
        </article>
      ) : (
        <article {...blockProps}>
          {nameField}
          {roleField}
          {biographyField}
        </article>
      )}
    </Fragment>
  );
}

export function save() {
  return null;
}
