import { MediaUpload, MediaUploadCheck } from '@wordpress/block-editor';
import { BaseControl, Button, TextControl } from '@wordpress/components';
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

function selectedMediaUrl(media) {
  return (
    media?.url ||
    media?.source_url ||
    media?.sizes?.full?.url ||
    ''
  );
}

export function MediaControl({
  label,
  imageId = 0,
  imageUrl = '',
  imageAlt = '',
  onChange,
  showAlt = true,
}) {
  const hasImage = Boolean(imageId || imageUrl);
  const action = hasImage ? __('Replace', 'goetz-site') : __('Select', 'goetz-site');
  const actionLabel = `${action} ${label}`;
  const removeLabel = `${__('Remove', 'goetz-site')} ${label}`;

  const selectMedia = (media) => {
    onChange({
      imageId: Number.isFinite(Number(media?.id)) ? Number(media.id) : 0,
      imageUrl: selectedMediaUrl(media),
      imageAlt: typeof media?.alt === 'string' ? media.alt : '',
    });
  };

  return (
    <BaseControl
      label={label}
      className="goetz-editor-media-control"
      __nextHasNoMarginBottom
    >
      {imageUrl ? (
        <img
          className="goetz-editor-media-control__preview"
          src={imageUrl}
          alt=""
        />
      ) : null}
      <div className="goetz-editor-media-control__actions">
        <MediaUploadCheck>
          <MediaUpload
            allowedTypes={['image']}
            value={imageId || 0}
            onSelect={selectMedia}
            aria-label={actionLabel}
            render={({ open }) => (
              <Button
                variant="secondary"
                onClick={open}
                aria-label={actionLabel}
              >
                {hasImage
                  ? __('Replace image', 'goetz-site')
                  : __('Select image', 'goetz-site')}
              </Button>
            )}
          />
        </MediaUploadCheck>
        {hasImage ? (
          <Button
            variant="tertiary"
            isDestructive
            onClick={() =>
              onChange({ imageId: 0, imageUrl: '', imageAlt: '' })
            }
            aria-label={removeLabel}
          >
            {__('Remove image', 'goetz-site')}
          </Button>
        ) : null}
      </div>
      {showAlt ? (
        <TextControl
          label={`${label} ${__('alt text', 'goetz-site')}`}
          value={imageAlt || ''}
          onChange={(nextAlt) =>
            onChange({
              imageId: imageId || 0,
              imageUrl: imageUrl || '',
              imageAlt: nextAlt,
            })
          }
          __nextHasNoMarginBottom
          __next40pxDefaultSize
        />
      ) : null}
    </BaseControl>
  );
}
