import { RichText, useBlockProps } from '@wordpress/block-editor';
import { Button } from '@wordpress/components';
import { createElement, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
  createRepeaterKey,
  moveAt,
  removeAt,
  replaceAt,
  syncRepeaterKeys,
} from '../../components/repeater';

function normalizeItems(value) {
  if (!Array.isArray(value)) {
    return [];
  }

  return value
    .filter((item) => item && typeof item === 'object' && !Array.isArray(item))
    .map((item) => ({
      question: typeof item.question === 'string' ? item.question : '',
      answer: typeof item.answer === 'string' ? item.answer : '',
    }));
}

export function FaqListEdit({ attributes = {}, setAttributes }) {
  const items = normalizeItems(attributes.items);
  const itemKeys = useRef(null);
  itemKeys.current = syncRepeaterKeys(itemKeys.current, items.length, 'faq');
  const blockProps = useBlockProps({
    className: 'goetz-editor-preview goetz-editor-preview--faq-list',
  });

  const setItems = (nextItems) => setAttributes({ items: nextItems });
  const updateItem = (index, changes) =>
    setItems(replaceAt(items, index, { ...items[index], ...changes }));
  const addItem = () => {
    itemKeys.current = [...itemKeys.current, createRepeaterKey('faq')];
    setItems([...items, { question: '', answer: '' }]);
  };
  const deleteItem = (index) => {
    itemKeys.current = removeAt(itemKeys.current, index);
    setItems(removeAt(items, index));
  };
  const moveItem = (fromIndex, toIndex) => {
    itemKeys.current = moveAt(itemKeys.current, fromIndex, toIndex);
    setItems(moveAt(items, fromIndex, toIndex));
  };

  return (
    <section {...blockProps}>
      <div className="goetz-editor-repeater">
        {items.map((item, index) => {
          const number = index + 1;

          return (
            <article className="goetz-editor-repeater__item" key={itemKeys.current[index]}>
              <RichText
                tagName="h3"
                aria-label={sprintf(__('FAQ %d question', 'goetz-site'), number)}
                value={item.question}
                allowedFormats={[]}
                placeholder={__('Enter a question', 'goetz-site')}
                onChange={(question) => updateItem(index, { question })}
              />
              <RichText
                tagName="p"
                aria-label={sprintf(__('FAQ %d answer', 'goetz-site'), number)}
                value={item.answer}
                allowedFormats={['core/bold', 'core/italic', 'core/link']}
                placeholder={__('Enter an answer', 'goetz-site')}
                onChange={(answer) => updateItem(index, { answer })}
              />
              <div className="goetz-editor-repeater__controls">
                <Button
                  variant="secondary"
                  disabled={index === 0}
                  aria-label={sprintf(__('Move FAQ %d up', 'goetz-site'), number)}
                  onClick={() => moveItem(index, index - 1)}
                >
                  {__('Move up', 'goetz-site')}
                </Button>
                <Button
                  variant="secondary"
                  disabled={index === items.length - 1}
                  aria-label={sprintf(__('Move FAQ %d down', 'goetz-site'), number)}
                  onClick={() => moveItem(index, index + 1)}
                >
                  {__('Move down', 'goetz-site')}
                </Button>
                <Button
                  variant="tertiary"
                  isDestructive
                  aria-label={sprintf(__('Remove FAQ %d', 'goetz-site'), number)}
                  onClick={() => deleteItem(index)}
                >
                  {__('Remove FAQ', 'goetz-site')}
                </Button>
              </div>
            </article>
          );
        })}
      </div>
      <Button variant="primary" aria-label={__('Add FAQ', 'goetz-site')} onClick={addItem}>
        {__('Add FAQ', 'goetz-site')}
      </Button>
    </section>
  );
}

export function save() {
  return null;
}
