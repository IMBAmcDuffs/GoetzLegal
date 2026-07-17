jest.mock('@wordpress/block-editor', () => ({
  RichText: 'RichText',
  useBlockProps: jest.fn((props) => props),
}));
jest.mock('@wordpress/components', () => ({ Button: 'Button' }));
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

import { FaqListEdit, save } from '../../src/blocks/faq-list/edit';
import { findByLabel } from './helpers';

describe('FAQ List editor', () => {
  const items = [
    { question: 'First question?', answer: 'First answer.' },
    { question: 'Second question?', answer: 'Second <em>answer</em>.' },
  ];

  test('renders current questions and answers and updates immutably', () => {
    const setAttributes = jest.fn();
    const tree = FaqListEdit({ attributes: { items }, setAttributes });

    expect(findByLabel(tree, 'FAQ 1 question').props.value).toBe('First question?');
    expect(findByLabel(tree, 'FAQ 2 answer').props.value).toBe(
      'Second <em>answer</em>.'
    );

    findByLabel(tree, 'FAQ 1 answer').props.onChange('Changed <strong>answer</strong>.');
    expect(setAttributes).toHaveBeenLastCalledWith({
      items: [
        { question: 'First question?', answer: 'Changed <strong>answer</strong>.' },
        items[1],
      ],
    });
    expect(items[0]).toEqual({ question: 'First question?', answer: 'First answer.' });
  });

  test('adds, removes, and reorders without mutating the original array', () => {
    const setAttributes = jest.fn();
    const tree = FaqListEdit({ attributes: { items }, setAttributes });

    findByLabel(tree, 'Add FAQ').props.onClick();
    expect(setAttributes).toHaveBeenLastCalledWith({
      items: [...items, { question: '', answer: '' }],
    });

    findByLabel(tree, 'Remove FAQ 1').props.onClick();
    expect(setAttributes).toHaveBeenLastCalledWith({ items: [items[1]] });

    findByLabel(tree, 'Move FAQ 2 up').props.onClick();
    expect(setAttributes).toHaveBeenLastCalledWith({ items: [items[1], items[0]] });
    expect(findByLabel(tree, 'Move FAQ 1 up').props.disabled).toBe(true);
    expect(findByLabel(tree, 'Move FAQ 2 down').props.disabled).toBe(true);
    expect(items).toEqual([
      { question: 'First question?', answer: 'First answer.' },
      { question: 'Second question?', answer: 'Second <em>answer</em>.' },
    ]);
  });

  test('is dynamic', () => {
    expect(save()).toBeNull();
  });
});
