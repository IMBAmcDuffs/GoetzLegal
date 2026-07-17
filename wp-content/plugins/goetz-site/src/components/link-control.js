import { URLInputButton } from '@wordpress/block-editor';
import { BaseControl, ToggleControl } from '@wordpress/components';
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export function LinkControl({ label, url = '', newTab = false, onChange }) {
  return (
    <BaseControl
      label={label}
      className="goetz-editor-link-control"
      __nextHasNoMarginBottom
    >
      <div data-goetz-link-control={label}>
        <URLInputButton
          url={url || ''}
          onChange={(nextUrl) => onChange({ url: nextUrl, newTab: Boolean(newTab) })}
        />
        <ToggleControl
          label={`${__('Open', 'goetz-site')} ${label} ${__(
            'in new tab',
            'goetz-site'
          )}`}
          checked={Boolean(newTab)}
          onChange={(nextValue) =>
            onChange({ url: url || '', newTab: Boolean(nextValue) })
          }
          __nextHasNoMarginBottom
        />
      </div>
    </BaseControl>
  );
}
