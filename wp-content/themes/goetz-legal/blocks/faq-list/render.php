<?php
$items = isset($attributes['items']) && is_array($attributes['items']) ? $attributes['items'] : array();
?>
<div <?php echo get_block_wrapper_attributes(array('class' => 'goetz-faq-list')); ?>>
    <?php foreach ($items as $item): ?>
        <?php
        $question = isset($item['question']) ? (string) $item['question'] : '';
        $answer   = isset($item['answer']) ? (string) $item['answer'] : '';
        ?>
        <section class="goetz-faq-list__item">
            <h2><?php echo esc_html($question); ?></h2>
            <?php echo wp_kses_post(wpautop($answer)); ?>
        </section>
    <?php endforeach; ?>
</div>

