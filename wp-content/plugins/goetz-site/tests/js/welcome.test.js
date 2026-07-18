jest.mock('@wordpress/block-editor', () => ({
  InspectorControls: 'InspectorControls',
  MediaUpload: 'MediaUpload',
  MediaUploadCheck: 'MediaUploadCheck',
  RichText: 'RichText',
  URLInput: 'URLInput',
  URLInputButton: 'URLInputButton',
  useBlockProps: jest.fn((props) => props),
}));
jest.mock('@wordpress/components', () => ({
  BaseControl: 'BaseControl',
  Button: 'Button',
  PanelBody: 'PanelBody',
  TextControl: 'TextControl',
}));
jest.mock('@wordpress/element', () => ({
  Fragment: 'Fragment',
  createElement: (...args) => require('./helpers').createElement(...args),
}));
jest.mock('@wordpress/i18n', () => ({ __: (value) => value }));

import { useBlockProps } from '@wordpress/block-editor';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { WelcomeEdit, save } from '../../src/blocks/welcome/edit';
import { findAll, findByLabel } from './helpers';

describe('Welcome editor', () => {
  const attributes = {
    leftImageId: 11,
    leftImageUrl: 'https://example.test/left.jpg',
    leftImageAlt: 'Left portrait',
    rightImageId: 22,
    rightImageUrl: 'https://example.test/right.jpg',
    rightImageAlt: 'Right portrait',
    heading: '<strong>Current</strong> heading',
    contentPrefix: 'Current prefix',
    phoneLabel: '(239) 555-0100',
    phoneUrl: 'tel:+12395550100',
    contentJoin: 'or reach the firm',
    onlineLabel: 'through the contact page',
    onlineUrl: '/contact-us/',
  };

  afterEach(() => {
    delete globalThis.goetzSiteEditorSettings;
  });

  test('uses the exact section wrapper and renders both selected media previews', () => {
    const tree = WelcomeEdit({ attributes, setAttributes: jest.fn() });

    expect(useBlockProps).toHaveBeenCalledWith({
      className: 'goetz-welcome goetz-intro-section',
    });

    const images = findAll(tree, (node) => node.type === 'img');
    expect(images).toHaveLength(2);
    expect(images.map(({ props }) => [props.src, props.alt])).toEqual([
      ['https://example.test/left.jpg', 'Left portrait'],
      ['https://example.test/right.jpg', 'Right portrait'],
    ]);
  });

  test('keeps the exact legacy image crop treatment in frontend and editor CSS', () => {
    const css = readFileSync(
      resolve(__dirname, '../../blocks/welcome/style.css'),
      'utf8'
    );

    expect(css).toMatch(/\.goetz-intro__image\s*\{[^}]*object-fit:\s*cover;/s);
    expect(css).toMatch(
      /\.goetz-intro__media--left \.goetz-intro__image\s*\{[^}]*object-position:\s*50% 0%;/s
    );
    expect(css).toMatch(
      /\.goetz-intro__media--right \.goetz-intro__image\s*\{[^}]*object-position:\s*0% 0%;/s
    );
  });

  test('renders every text and URL field with the allowed formatting contract', () => {
    const tree = WelcomeEdit({ attributes, setAttributes: jest.fn() });

    const expectedValues = {
      'Welcome heading': attributes.heading,
      'Welcome content prefix': attributes.contentPrefix,
      'Welcome phone label': attributes.phoneLabel,
      'Welcome phone URL': attributes.phoneUrl,
      'Welcome content join': attributes.contentJoin,
      'Welcome online label': attributes.onlineLabel,
      'Welcome online URL': attributes.onlineUrl,
    };

    Object.entries(expectedValues).forEach(([label, value]) => {
      expect(findByLabel(tree, label).props.value).toBe(value);
    });

    expect(findByLabel(tree, 'Welcome heading').props.allowedFormats).toEqual([
      'core/bold',
      'core/italic',
    ]);
    [
      'Welcome content prefix',
      'Welcome phone label',
      'Welcome content join',
      'Welcome online label',
    ].forEach((label) => {
      expect(findByLabel(tree, label).props.allowedFormats).toEqual([]);
    });
  });

  test('previews the two current-context links without owning their labels', () => {
    const tree = WelcomeEdit({ attributes, setAttributes: jest.fn() });
    const links = findAll(tree, (node) => node.type === 'a');

    expect(links).toHaveLength(2);
    expect(links.map(({ props }) => props.href)).toEqual([
      attributes.phoneUrl,
      attributes.onlineUrl,
    ]);
    links.forEach(({ props }) => {
      expect(props.target).toBeUndefined();
      expect(props.rel).toBeUndefined();
    });
  });

  test('uses native URL inputs for both stored link overrides', () => {
    const tree = WelcomeEdit({ attributes, setAttributes: jest.fn() });
    const urlInputs = findAll(tree, (node) => node.type === 'URLInput');

    expect(urlInputs.map(({ props }) => [props.label, props.value, props.required])).toEqual([
      ['Welcome phone URL', attributes.phoneUrl, false],
      ['Welcome online URL', attributes.onlineUrl, false],
    ]);
    expect(
      findAll(
        tree,
        (node) =>
          node.type === 'TextControl' &&
          ['Welcome phone URL', 'Welcome online URL'].includes(node.props.label)
      )
    ).toHaveLength(0);
  });

  test.each([
    ['Welcome heading', 'heading', '<em>Changed heading</em>'],
    ['Welcome content prefix', 'contentPrefix', 'Changed prefix'],
    ['Welcome phone label', 'phoneLabel', '(239) 555-0199'],
    ['Welcome phone URL', 'phoneUrl', 'tel:+12395550199'],
    ['Welcome content join', 'contentJoin', 'and contact the firm'],
    ['Welcome online label', 'onlineLabel', 'using our form'],
    ['Welcome online URL', 'onlineUrl', '/changed-contact/'],
  ])('changes only %s', (label, attribute, value) => {
    const setAttributes = jest.fn();
    const tree = WelcomeEdit({ attributes, setAttributes });

    findByLabel(tree, label).props.onChange(value);

    expect(setAttributes).toHaveBeenCalledTimes(1);
    expect(setAttributes).toHaveBeenCalledWith({ [attribute]: value });
  });

  test('maps left and right media changes independently', () => {
    const setAttributes = jest.fn();
    const tree = WelcomeEdit({ attributes, setAttributes });

    findByLabel(tree, 'Welcome left image').props.onChange({
      imageId: 31,
      imageUrl: 'https://example.test/new-left.jpg',
      imageAlt: 'New left alt',
    });
    expect(setAttributes).toHaveBeenLastCalledWith({
      leftImageId: 31,
      leftImageUrl: 'https://example.test/new-left.jpg',
      leftImageAlt: 'New left alt',
    });

    findByLabel(tree, 'Welcome right image').props.onChange({
      imageId: 42,
      imageUrl: 'https://example.test/new-right.jpg',
      imageAlt: 'New right alt',
    });
    expect(setAttributes).toHaveBeenLastCalledWith({
      rightImageId: 42,
      rightImageUrl: 'https://example.test/new-right.jpg',
      rightImageAlt: 'New right alt',
    });
  });

  test('previews public render-time fallbacks without storing them as overrides', () => {
    globalThis.goetzSiteEditorSettings = {
      phoneLabel: '(239) 555-0188',
      phoneUrl: 'tel:+12395550188',
      onlineUrl: '/contact/',
    };
    const setAttributes = jest.fn();
    const blankFallbackAttributes = {
      phoneLabel: '',
      phoneUrl: '',
      onlineLabel: 'online',
      onlineUrl: '',
    };
    const tree = WelcomeEdit({
      attributes: blankFallbackAttributes,
      setAttributes,
    });

    expect(findByLabel(tree, 'Welcome phone label').props.value).toBe('(239) 555-0188');
    expect(findByLabel(tree, 'Welcome phone URL').props.value).toBe('tel:+12395550188');
    expect(findByLabel(tree, 'Welcome online label').props.value).toBe('online');
    expect(findByLabel(tree, 'Welcome online URL').props.value).toBe('/contact/');
    expect(
      findAll(tree, (node) => node.type === 'a').map(({ props }) => props.href)
    ).toEqual(['tel:+12395550188', '/contact/']);
    expect(blankFallbackAttributes).toEqual({
      phoneLabel: '',
      phoneUrl: '',
      onlineLabel: 'online',
      onlineUrl: '',
    });
    expect(setAttributes).not.toHaveBeenCalled();
  });

  test('is dynamic', () => {
    expect(save()).toBeNull();
  });
});
