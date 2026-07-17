<?php

$items = isset($attributes['items']) && is_array($attributes['items'])
    ? array_values($attributes['items'])
    : [];
?>
<div <?php echo get_block_wrapper_attributes(['class' => 'goetz-faq-list']); ?>>
    <?php foreach ($items as $item): ?>
        <?php
        if (! is_array($item)) {
            continue;
        }
        $question = isset($item['question']) && is_scalar($item['question'])
            ? (string) $item['question']
            : '';
        $answer = isset($item['answer']) && is_scalar($item['answer'])
            ? \Goetz\Site\rich_text_markup((string) $item['answer'])
            : '';
        ?>
        <section class="goetz-faq-list__item">
            <h2><?php echo esc_html($question); ?></h2>
            <?php echo wpautop($answer); ?>
        </section>
    <?php endforeach; ?>
</div>
