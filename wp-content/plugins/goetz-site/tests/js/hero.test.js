jest.mock('@wordpress/block-editor', () => ({
  InspectorControls: 'InspectorControls',
  RichText: 'RichText',
  URLInputButton: 'URLInputButton',
  MediaUpload: 'MediaUpload',
  MediaUploadCheck: 'MediaUploadCheck',
  useBlockProps: jest.fn((props) => props),
}));
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
}));
jest.mock('@wordpress/i18n', () => ({ __: (value) => value }));

import { useBlockProps } from '@wordpress/block-editor';

import { MediaControl } from '../../src/components/media-control';
import { HeroEdit, save } from '../../src/blocks/hero/edit';
import { findAll, findByLabel } from './helpers';

describe('Hero editor', () => {
  const attributes = {
    eyebrow: 'Current eyebrow',
    heading: 'Current <strong>heading</strong>',
    content: 'Current content',
    imageId: 4,
    imageUrl: 'https://example.test/hero.jpg',
    imageAlt: 'Current alt',
    buttonText: 'Current button',
    buttonUrl: '/current/',
    buttonNewTab: false,
  };

  test('renders current content and sends exact RichText and link changes', () => {
    const setAttributes = jest.fn();
    const tree = HeroEdit({ attributes, setAttributes });

    expect(findByLabel(tree, 'Hero eyebrow').props.value).toBe('Current eyebrow');
    expect(findByLabel(tree, 'Hero heading').props.value).toBe(
      'Current <strong>heading</strong>'
    );
    expect(findByLabel(tree, 'Hero content').props.value).toBe('Current content');

    findByLabel(tree, 'Hero heading').props.onChange('Changed <em>heading</em>');
    expect(setAttributes).toHaveBeenLastCalledWith({
      heading: 'Changed <em>heading</em>',
    });

    findByLabel(tree, 'Hero button link').props.onChange({
      url: '/changed/',
      newTab: true,
    });
    expect(setAttributes).toHaveBeenLastCalledWith({
      buttonUrl: '/changed/',
      buttonNewTab: true,
    });
    expect(setAttributes).not.toHaveBeenCalledWith(
      expect.objectContaining({ buttonText: expect.anything() })
    );
  });

  test('selects and removes ID, fallback URL, and alt together', () => {
    const onChange = jest.fn();
    const selected = MediaControl({
      label: 'Hero image',
      imageId: attributes.imageId,
      imageUrl: attributes.imageUrl,
      imageAlt: attributes.imageAlt,
      onChange,
    });

    findByLabel(selected, 'Replace Hero image').props.onSelect({
      id: 19,
      url: 'https://example.test/selected.jpg',
      alt: 'Selected alt',
    });
    expect(onChange).toHaveBeenLastCalledWith({
      imageId: 19,
      imageUrl: 'https://example.test/selected.jpg',
      imageAlt: 'Selected alt',
    });

    findByLabel(selected, 'Remove Hero image').props.onClick();
    expect(onChange).toHaveBeenLastCalledWith({
      imageId: 0,
      imageUrl: '',
      imageAlt: '',
    });
  });

  test('previews the selected circular image after the text in source order', () => {
    const tree = HeroEdit({ attributes, setAttributes: jest.fn() });
    const [section] = findAll(
      tree,
      (node) => node.type === 'section' && node.props?.className?.includes('goetz-editor-preview--hero')
    );
    const images = findAll(
      tree,
      (node) => node.type === 'img' && node.props?.className === 'goetz-hero__image'
    );

    expect(section.props.children.map((child) => child.type)).toEqual(['div', 'figure']);
    expect(useBlockProps).toHaveBeenLastCalledWith({
      className: 'goetz-hero goetz-editor-preview goetz-editor-preview--hero',
    });
    expect(images).toEqual([
      expect.objectContaining({
        props: expect.objectContaining({
          src: attributes.imageUrl,
          alt: attributes.imageAlt,
        }),
      }),
    ]);
    expect(findByLabel(tree, 'Hero heading').props.allowedFormats).toEqual([
      'core/bold',
      'core/italic',
    ]);
  });

  test('is dynamic', () => {
    expect(save()).toBeNull();
  });
});
