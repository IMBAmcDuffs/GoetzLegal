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

import { CtaEdit, save } from '../../src/blocks/cta/edit';
import { useBlockProps } from '@wordpress/block-editor';
import { findAll, findByLabel } from './helpers';

describe('CTA editor', () => {
  afterEach(() => {
    document.body.innerHTML = '';
    document.documentElement.classList.remove('goetz-cta-ready');
    delete globalThis.goetzSiteEditorSettings;
    jest.resetModules();
  });

  test('renders current copy and preserves the button label when its link changes', () => {
    const setAttributes = jest.fn();
    const tree = CtaEdit({
      attributes: {
        eyebrow: 'Current eyebrow',
        heading: 'Current <em>heading</em>',
        buttonText: 'Keep this label',
        buttonUrl: '/contact/',
        buttonNewTab: false,
      },
      setAttributes,
    });

    expect(findByLabel(tree, 'CTA eyebrow').props.value).toBe('Current eyebrow');
    expect(findByLabel(tree, 'CTA heading').props.value).toBe(
      'Current <em>heading</em>'
    );
    expect(findByLabel(tree, 'CTA button text').props.value).toBe('Keep this label');

    findByLabel(tree, 'CTA heading').props.onChange('Changed <strong>heading</strong>');
    expect(setAttributes).toHaveBeenLastCalledWith({
      heading: 'Changed <strong>heading</strong>',
    });

    findByLabel(tree, 'CTA button link').props.onChange({
      url: '/consultation/',
      newTab: true,
    });
    expect(setAttributes).toHaveBeenLastCalledWith({
      buttonUrl: '/consultation/',
      buttonNewTab: true,
    });
    expect(setAttributes).not.toHaveBeenCalledWith(
      expect.objectContaining({ buttonText: expect.anything() })
    );
  });

  test('maps decorative media selection and removal to CTA attributes', () => {
    const setAttributes = jest.fn();
    const tree = CtaEdit({ attributes: {}, setAttributes });

    findByLabel(tree, 'CTA background image').props.onChange({
      imageId: 31,
      imageUrl: 'https://example.test/background.jpg',
      imageAlt: '',
    });
    expect(setAttributes).toHaveBeenLastCalledWith({
      backgroundImageId: 31,
      backgroundImageUrl: 'https://example.test/background.jpg',
    });

    findByLabel(tree, 'CTA background image').props.onChange({
      imageId: 0,
      imageUrl: '',
      imageAlt: '',
    });
    expect(setAttributes).toHaveBeenLastCalledWith({
      backgroundImageId: 0,
      backgroundImageUrl: '',
    });
  });

  test('composes the selected background image under the editor overlay', () => {
    const tree = CtaEdit({
      attributes: {
        backgroundImageId: 31,
        backgroundImageUrl: 'https://example.test/gavel.jpg',
      },
      setAttributes: jest.fn(),
    });
    const [preview] = findAll(
      tree,
      (node) => node.type === 'section' && node.props?.className?.includes('goetz-editor-preview--cta')
    );

    expect(preview.props.style).toEqual({
      '--goetz-cta-background-image': 'url("https://example.test/gavel.jpg")',
    });
    expect(preview.props.children.map((child) => child.type)).toEqual([
      'div',
      'RichText',
    ]);
    expect(preview.props.children[0].props.children.map(
      (child) => child.props['aria-label']
    )).toEqual(['CTA eyebrow', 'CTA heading']);
  });

  test('previews localized blank defaults without persisting block overrides', () => {
    globalThis.goetzSiteEditorSettings = {
      ctaLabel: 'Schedule from Site Settings',
      ctaUrl: '/site-settings-contact/',
      ctaBackgroundUrl: 'https://example.test/default-gavel.jpg',
    };
    const attributes = {
      buttonText: ' ',
      buttonUrl: '',
      backgroundImageUrl: '',
    };
    const setAttributes = jest.fn();
    const tree = CtaEdit({ attributes, setAttributes });

    expect(useBlockProps).toHaveBeenLastCalledWith({
      className: 'goetz-cta goetz-editor-preview goetz-editor-preview--cta',
      style: {
        '--goetz-cta-background-image':
          'url("https://example.test/default-gavel.jpg")',
      },
    });
    expect(findByLabel(tree, 'CTA button text').props.value).toBe(
      'Schedule from Site Settings'
    );
    expect(findByLabel(tree, 'CTA button link').props.url).toBe(
      '/site-settings-contact/'
    );
    expect(findByLabel(tree, 'CTA background image').props.imageUrl).toBe(
      'https://example.test/default-gavel.jpg'
    );
    expect(attributes).toEqual({
      buttonText: ' ',
      buttonUrl: '',
      backgroundImageUrl: '',
    });
    expect(setAttributes).not.toHaveBeenCalled();
  });

  test('hydrates a filtered data-backed background into the presentation property', () => {
    document.body.innerHTML = `
      <section class="goetz-cta" data-goetz-cta-background="https://example.test/gavel.jpg"></section>
    `;

    jest.isolateModules(() => require('../../blocks/cta/view'));

    expect(
      document.querySelector('.goetz-cta').style.getPropertyValue(
        '--goetz-cta-background-image'
      )
    ).toBe('url("https://example.test/gavel.jpg")');
    expect(document.documentElement.classList.contains('goetz-cta-ready')).toBe(true);
  });

  test('is dynamic', () => {
    expect(save()).toBeNull();
  });
});
