import { InnerBlocks, RichText, useBlockProps } from '@wordpress/block-editor';
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export const DEFAULT_ATTORNEYS = [
  {
    name: 'James L. Goetz',
    bio: "James L. Goetz was born in Erie, Pennsylvania. He grew up in Oil City and Girard, Pennsylvania working on his father's farm and coal mines until he went to college.",
    imageAlt: 'James L. Goetz',
    profileUrl: '/james-l-goetz/',
  },
  {
    name: 'Gregory W. Goetz',
    bio: 'Mr. Gregory W. Goetz was born and raised here in Fort Myers, Florida. He attended Fort Myers High School and then was accepted to University of Florida.',
    imageAlt: 'Gregory W. Goetz',
    profileUrl: '/gregory-w-goetz/',
  },
];

export function AttorneyGridEdit({ attributes = {}, setAttributes }) {
  const blockProps = useBlockProps({
    className: 'goetz-attorney-grid goetz-section--attorneys',
  });

  return (
    <section {...blockProps}>
      <RichText
        tagName="h2"
        className="goetz-attorney-grid__heading"
        aria-label={__('Attorney Grid heading', 'goetz-site')}
        value={attributes.heading || ''}
        allowedFormats={[]}
        onChange={(heading) => setAttributes({ heading })}
      />
      <div className="goetz-attorney-grid__cards">
        <InnerBlocks
          allowedBlocks={['goetz/attorney-card']}
          template={DEFAULT_ATTORNEYS.map((attorney) => [
            'goetz/attorney-card',
            attorney,
          ])}
          templateLock={false}
          renderAppender={InnerBlocks.ButtonBlockAppender}
        />
      </div>
    </section>
  );
}

export function save() {
  return <InnerBlocks.Content />;
}
