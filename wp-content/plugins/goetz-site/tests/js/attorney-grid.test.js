jest.mock('@wordpress/block-editor', () => {
  const InnerBlocks = function InnerBlocks() {};
  InnerBlocks.ButtonBlockAppender = 'InnerBlocks.ButtonBlockAppender';
  InnerBlocks.Content = 'InnerBlocks.Content';

  return {
    InnerBlocks,
    RichText: 'RichText',
    useBlockProps: jest.fn((props) => props || {}),
  };
});
jest.mock('@wordpress/element', () => ({
  createElement: (...args) => require('./helpers').createElement(...args),
}));
jest.mock('@wordpress/i18n', () => ({ __: (value) => value }));

import { InnerBlocks, useBlockProps } from '@wordpress/block-editor';

import { findAll, findByLabel } from './helpers';

let metadata = null;
let editorModule = {};

try {
  metadata = require('../../blocks/attorney-grid/block.json');
  editorModule = require('../../src/blocks/attorney-grid/edit');
} catch (error) {
  // The first RED run intentionally exercises the missing Task 8 block.
}

const expectedAttorneys = [
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

describe('Attorney Grid InnerBlocks API', () => {
  test('registers the exact parent schema without restricting the reusable child', () => {
    expect(metadata).toEqual(expect.objectContaining({
      apiVersion: 3,
      name: 'goetz/attorney-grid',
      attributes: {
        heading: { type: 'string', default: 'Attorneys' },
      },
      providesContext: {
        'goetz/attorneyGridHeading': 'heading',
      },
      supports: { html: false },
      editorScript: 'goetz-site-block-editor',
      style: 'file:./style.css',
      render: 'file:./render.php',
    }));

    const childMetadata = require('../../blocks/attorney-card/block.json');
    expect(childMetadata).not.toHaveProperty('parent');
    expect(childMetadata.usesContext).toEqual(['goetz/attorneyGridHeading']);
    expect(childMetadata.supports).toEqual({ html: false });
    expect(childMetadata.supports).not.toHaveProperty('inserter');
  });

  test('seeds exactly James and Gregory in one unlocked native InnerBlocks region', () => {
    const { AttorneyGridEdit, DEFAULT_ATTORNEYS } = editorModule;
    expect(AttorneyGridEdit).toBeInstanceOf(Function);

    const tree = AttorneyGridEdit({ attributes: {}, setAttributes: jest.fn() });
    const regions = findAll(tree, (node) => node.type === InnerBlocks);

    expect(DEFAULT_ATTORNEYS).toEqual(expectedAttorneys);
    expect(regions).toHaveLength(1);
    expect(regions[0].props.allowedBlocks).toEqual(['goetz/attorney-card']);
    expect(regions[0].props.template).toEqual(
      expectedAttorneys.map((attributes) => ['goetz/attorney-card', attributes])
    );
    expect(regions[0].props.templateLock).toBe(false);
    expect(regions[0].props.renderAppender).toBe(InnerBlocks.ButtonBlockAppender);
  });

  test('edits the H2 heading and serializes only InnerBlocks content', () => {
    const { AttorneyGridEdit, save } = editorModule;
    expect(AttorneyGridEdit).toBeInstanceOf(Function);

    const setAttributes = jest.fn();
    const tree = AttorneyGridEdit({
      attributes: { heading: 'Our Attorneys' },
      setAttributes,
    });
    const heading = findByLabel(tree, 'Attorney Grid heading');

    expect(useBlockProps).toHaveBeenCalledWith({
      className: 'goetz-attorney-grid goetz-section--attorneys',
    });
    expect(heading.props.tagName).toBe('h2');
    expect(heading.props.allowedFormats).toEqual([]);
    heading.props.onChange('Trial Attorneys');
    expect(setAttributes).toHaveBeenCalledWith({ heading: 'Trial Attorneys' });
    expect(save()).toEqual(expect.objectContaining({ type: InnerBlocks.Content }));
  });
});
