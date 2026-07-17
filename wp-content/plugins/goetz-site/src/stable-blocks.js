import { useBlockProps } from '@wordpress/block-editor';
import { createElement } from '@wordpress/element';

import attorneyCard from '../blocks/attorney-card/block.json';
import cta from '../blocks/cta/block.json';
import faqList from '../blocks/faq-list/block.json';
import hero from '../blocks/hero/block.json';
import resourceLinks from '../blocks/resource-links/block.json';

export const stableBlocks = [attorneyCard, cta, faqList, hero, resourceLinks];

export function StableBlockEdit({ name }) {
  const metadata = stableBlocks.find((block) => block.name === name);
  const blockProps = useBlockProps({ className: 'goetz-editor-card' });

  return createElement('div', blockProps, metadata ? metadata.title : 'Goetz block');
}

export function registerStableBlocks(registerBlockType) {
  stableBlocks.forEach(({ name }) => {
    registerBlockType(name, {
      edit: StableBlockEdit,
      save: () => null,
    });
  });
}
