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
  useRef: (value) => ({ current: value }),
}));
jest.mock('@wordpress/i18n', () => ({
  __: (value) => value,
  sprintf: (template, ...values) =>
    values.reduce((result, value) => result.replace('%d', value), template),
}));

import { ResourceLinksEdit, save } from '../../src/blocks/resource-links/edit';
import { findByLabel } from './helpers';

describe('Resource Links editor', () => {
  const groups = [
    {
      heading: 'Courts',
      links: [
        { label: 'Legacy court', url: 'https://legacy.example.test' },
        { label: 'Local court', url: 'https://local.example.test', newTab: false },
      ],
    },
    { heading: 'Agencies', links: [] },
  ];

  test('renders current nested content and treats legacy links as same-tab', () => {
    const setAttributes = jest.fn();
    const tree = ResourceLinksEdit({ attributes: { groups }, setAttributes });

    expect(findByLabel(tree, 'Resource group 1 heading').props.value).toBe('Courts');
    expect(findByLabel(tree, 'Resource group 1 link 1 label').props.value).toBe(
      'Legacy court'
    );
    expect(findByLabel(tree, 'Resource group 1 link 1 destination').props.newTab).toBe(
      false
    );
    expect(findByLabel(tree, 'Resource group 1 link 2 destination').props.newTab).toBe(
      false
    );

    findByLabel(tree, 'Resource group 1 link 1 destination').props.onChange({
      url: 'https://changed.example.test',
      newTab: true,
    });
    expect(setAttributes).toHaveBeenLastCalledWith({
      groups: [
        {
          heading: 'Courts',
          links: [
            {
              label: 'Legacy court',
              url: 'https://changed.example.test',
              newTab: true,
            },
            groups[0].links[1],
          ],
        },
        groups[1],
      ],
    });
    expect(groups[0].links[0]).toEqual({
      label: 'Legacy court',
      url: 'https://legacy.example.test',
    });
  });

  test('adds, removes, and reorders groups and links immutably', () => {
    const setAttributes = jest.fn();
    const tree = ResourceLinksEdit({ attributes: { groups }, setAttributes });

    findByLabel(tree, 'Add resource group').props.onClick();
    expect(setAttributes).toHaveBeenLastCalledWith({
      groups: [...groups, { heading: '', links: [] }],
    });

    findByLabel(tree, 'Add link to resource group 1').props.onClick();
    expect(setAttributes).toHaveBeenLastCalledWith({
      groups: [
        {
          heading: 'Courts',
          links: [...groups[0].links, { label: '', url: '', newTab: false }],
        },
        groups[1],
      ],
    });

    findByLabel(tree, 'Move resource group 2 up').props.onClick();
    expect(setAttributes).toHaveBeenLastCalledWith({ groups: [groups[1], groups[0]] });

    findByLabel(tree, 'Move resource group 1 link 2 up').props.onClick();
    expect(setAttributes).toHaveBeenLastCalledWith({
      groups: [
        {
          heading: 'Courts',
          links: [groups[0].links[1], groups[0].links[0]],
        },
        groups[1],
      ],
    });

    findByLabel(tree, 'Remove resource group 1 link 1').props.onClick();
    expect(setAttributes).toHaveBeenLastCalledWith({
      groups: [
        { heading: 'Courts', links: [groups[0].links[1]] },
        groups[1],
      ],
    });
    expect(groups[0].links).toHaveLength(2);
  });

  test('maps image changes and remains dynamic', () => {
    const setAttributes = jest.fn();
    const tree = ResourceLinksEdit({ attributes: {}, setAttributes });

    findByLabel(tree, 'Resource Links image').props.onChange({
      imageId: 41,
      imageUrl: 'https://example.test/resources.jpg',
      imageAlt: 'Resource books',
    });
    expect(setAttributes).toHaveBeenLastCalledWith({
      imageId: 41,
      imageUrl: 'https://example.test/resources.jpg',
      imageAlt: 'Resource books',
    });
    expect(save()).toBeNull();
  });
});
