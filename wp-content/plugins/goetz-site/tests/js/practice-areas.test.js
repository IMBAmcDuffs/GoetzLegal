jest.mock('@wordpress/block-editor', () => {
  const InnerBlocks = function InnerBlocks() {};
  InnerBlocks.ButtonBlockAppender = 'InnerBlocks.ButtonBlockAppender';
  InnerBlocks.Content = 'InnerBlocks.Content';

  return {
    InnerBlocks,
    InspectorControls: 'InspectorControls',
    MediaUpload: 'MediaUpload',
    MediaUploadCheck: 'MediaUploadCheck',
    RichText: 'RichText',
    URLInput: 'URLInput',
    URLInputButton: 'URLInputButton',
    useBlockProps: jest.fn((props) => props || {}),
  };
});
jest.mock('@wordpress/components', () => ({
  BaseControl: 'BaseControl',
  Button: 'Button',
  PanelBody: 'PanelBody',
  TextControl: 'TextControl',
  ToggleControl: 'ToggleControl',
}));
jest.mock('@wordpress/element', () => ({
  Fragment: 'Fragment',
  createElement: (...args) => require('./helpers').createElement(...args),
  useRef: (value) => ({ current: value }),
}));
jest.mock('@wordpress/i18n', () => ({
  __: (value) => value,
  sprintf: (template, ...values) =>
    values.reduce((result, value) => result.replace('%d', value), template),
}));

import { InnerBlocks } from '@wordpress/block-editor';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import childMetadata from '../../blocks/practice-area-item/block.json';
import parentMetadata from '../../blocks/practice-areas/block.json';
import {
  PracticeAreaItemEdit,
  save as savePracticeAreaItem,
} from '../../src/blocks/practice-area-item/edit';
import {
  DEFAULT_ITEMS,
  PracticeAreasEdit,
  save as savePracticeAreas,
} from '../../src/blocks/practice-areas/edit';
import { registerStableBlocks, stableBlocks } from '../../src/stable-blocks';
import { findAll, findByLabel } from './helpers';

const expectedItems = [
  'Corporate',
  'Construction',
  'Real Estate',
  'Probate',
  'Criminal',
  'Bankruptcy',
  'Appeals',
];

describe('Practice Areas InnerBlocks API', () => {
  test('keeps the child parent-restricted while enabling its nested inserter', () => {
    expect(parentMetadata).toEqual(expect.objectContaining({
      apiVersion: 3,
      name: 'goetz/practice-areas',
      attributes: {
        heading: {
          type: 'string',
          default: 'Providing <strong>Legal Advice</strong> in:',
        },
        backgroundImageId: { type: 'number', default: 0 },
        backgroundImageUrl: { type: 'string', default: '' },
        backgroundImageAlt: { type: 'string', default: '' },
        scaleImageId: { type: 'number', default: 0 },
        scaleImageUrl: { type: 'string', default: '' },
        scaleImageAlt: { type: 'string', default: '' },
      },
      providesContext: {
        'goetz/scaleImageId': 'scaleImageId',
        'goetz/scaleImageUrl': 'scaleImageUrl',
        'goetz/scaleImageAlt': 'scaleImageAlt',
      },
      supports: { html: false },
      editorScript: 'goetz-site-block-editor',
      render: 'file:./render.php',
      style: 'file:./style.css',
      viewScript: 'file:./view.js',
    }));

    expect(childMetadata).toEqual(expect.objectContaining({
      apiVersion: 3,
      name: 'goetz/practice-area-item',
      attributes: { label: { type: 'string', default: '' } },
      parent: ['goetz/practice-areas'],
      usesContext: [
        'goetz/scaleImageId',
        'goetz/scaleImageUrl',
        'goetz/scaleImageAlt',
      ],
      supports: { html: false },
      editorScript: 'goetz-site-block-editor',
      render: 'file:./render.php',
      style: 'file:./style.css',
    }));
  });

  test('seeds exactly seven editable children in one unlocked InnerBlocks region', () => {
    const tree = PracticeAreasEdit({ attributes: {}, setAttributes: jest.fn() });
    const regions = findAll(tree, (node) => node.type === InnerBlocks);

    expect(DEFAULT_ITEMS).toEqual(expectedItems);
    expect(regions).toHaveLength(1);
    expect(regions[0].props.allowedBlocks).toEqual(['goetz/practice-area-item']);
    expect(regions[0].props.template).toEqual(
      expectedItems.map((label) => ['goetz/practice-area-item', { label }])
    );
    expect(regions[0].props.templateLock).toBe(false);
    expect(regions[0].props.renderAppender).toBe(InnerBlocks.ButtonBlockAppender);
  });

  test('edits the heading and both media records independently', () => {
    const setAttributes = jest.fn();
    const attributes = {
      heading: 'Current <strong>practice heading</strong>',
      backgroundImageId: 11,
      backgroundImageUrl: 'https://example.test/background.jpg',
      backgroundImageAlt: 'Office library',
      scaleImageId: 22,
      scaleImageUrl: 'https://example.test/scale.png',
      scaleImageAlt: '',
    };
    const tree = PracticeAreasEdit({ attributes, setAttributes });

    findByLabel(tree, 'Practice Areas heading').props.onChange(
      'Changed <em>practice heading</em>'
    );
    expect(setAttributes).toHaveBeenLastCalledWith({
      heading: 'Changed <em>practice heading</em>',
    });

    findByLabel(tree, 'Practice background image').props.onChange({
      imageId: 31,
      imageUrl: 'https://example.test/new-background.jpg',
      imageAlt: 'New office library',
    });
    expect(setAttributes).toHaveBeenLastCalledWith({
      backgroundImageId: 31,
      backgroundImageUrl: 'https://example.test/new-background.jpg',
      backgroundImageAlt: 'New office library',
    });

    findByLabel(tree, 'Practice scale image').props.onChange({
      imageId: 42,
      imageUrl: 'https://example.test/new-scale.png',
      imageAlt: 'Decorative scale',
    });
    expect(setAttributes).toHaveBeenLastCalledWith({
      scaleImageId: 42,
      scaleImageUrl: 'https://example.test/new-scale.png',
      scaleImageAlt: 'Decorative scale',
    });
  });

  test('edits a child label and previews the scale inherited from parent context', () => {
    const setAttributes = jest.fn();
    const tree = PracticeAreaItemEdit({
      attributes: { label: 'Corporate' },
      context: {
        'goetz/scaleImageUrl': 'https://example.test/scale.png',
        'goetz/scaleImageAlt': 'Scale of justice',
      },
      setAttributes,
    });

    const label = findByLabel(tree, 'Practice area label');
    expect(label.props.value).toBe('Corporate');
    label.props.onChange('Commercial Litigation');
    expect(setAttributes).toHaveBeenCalledWith({
      label: 'Commercial Litigation',
    });

    expect(findAll(tree, (node) => node.type === 'img')).toEqual([
      expect.objectContaining({
        props: expect.objectContaining({
          src: 'https://example.test/scale.png',
          alt: 'Scale of justice',
        }),
      }),
    ]);
  });

  test('uses the exact self-contained legacy scale glyph when no custom icon is selected', () => {
    const tree = PracticeAreaItemEdit({
      attributes: { label: 'Corporate' },
      context: {},
      setAttributes: jest.fn(),
    });
    const glyphs = findAll(
      tree,
      (node) => node.props?.className === 'goetz-practice-area-item__scale-glyph'
    );

    expect(glyphs).toHaveLength(1);
    expect(glyphs[0].props.children).toBe('\uf24e');
    expect(findAll(tree, (node) => node.type === 'img')).toHaveLength(0);

    const css = readFileSync(
      resolve(__dirname, '../../blocks/practice-area-item/style.css'),
      'utf8'
    );
    const fontData = css.match(/data:font\/woff2;base64,([A-Za-z0-9+/=]+)/);
    expect(fontData).not.toBeNull();
    expect(
      createHash('sha256').update(Buffer.from(fontData[1], 'base64')).digest('hex')
    ).toBe('7984fae0905a4123fae840c0b624e5038be468f0523b4237fb5a48ca590f6155');
    expect(css).not.toMatch(/https?:\/\//i);
    expect(css).toMatch(
      /\.goetz-practice-area-item__scale-glyph\s*\{[^}]*display:\s*block;/s
    );

  });

  test('serializes parent children while keeping each dynamic child markup server-rendered', () => {
    expect(savePracticeAreas()).toEqual(expect.objectContaining({
      type: InnerBlocks.Content,
    }));
    expect(savePracticeAreaItem()).toBeNull();
  });

  test('registers both metadata blocks with their distinct editor and save contracts', () => {
    const registrations = [];

    registerStableBlocks((name, settings) => registrations.push({ name, settings }));

    expect(stableBlocks.map(({ name }) => name)).toEqual(expect.arrayContaining([
      'goetz/practice-area-item',
      'goetz/practice-areas',
    ]));
    const parent = registrations.find(({ name }) => name === 'goetz/practice-areas');
    const child = registrations.find(({ name }) => name === 'goetz/practice-area-item');
    expect(parent?.settings.edit).toBe(PracticeAreasEdit);
    expect(parent?.settings.save()).toEqual(expect.objectContaining({
      type: InnerBlocks.Content,
    }));
    expect(child?.settings.edit).toBe(PracticeAreaItemEdit);
    expect(child?.settings.save()).toBeNull();
  });
});
