<?php
/*
Plugin Name: USM Notes
Description: Плагин для создания заметок с приоритетами и датой напоминания.
Version: 1.0
Author: Irina Cristeva
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* =========================
   CPT: Notes
========================= */

function usm_register_notes_cpt() {
    $labels = array(
        'name'          => 'Заметки',
        'singular_name' => 'Заметка',
        'menu_name'     => 'Заметки',
        'add_new'       => 'Добавить заметку',
        'add_new_item'  => 'Новая заметка',
        'edit_item'     => 'Редактировать заметку',
        'all_items'     => 'Все заметки',
        'not_found'     => 'Заметки не найдены',
    );
    $args = array(
        'labels'       => $labels,
        'public'       => true,
        'has_archive'  => true,
        'rewrite'      => array('slug' => 'notes'),
        'supports'     => array('title', 'editor', 'author', 'thumbnail'),
        'menu_icon'    => 'dashicons-clipboard',
        'show_in_rest' => true,
    );
    register_post_type('usm_note', $args);
}
add_action('init', 'usm_register_notes_cpt');


/* =========================
   TAXONOMY: Priority
========================= */

function usm_register_priority_taxonomy() {
    $labels = array(
        'name'          => 'Приоритеты',
        'singular_name' => 'Приоритет',
        'menu_name'     => 'Приоритеты',
        'all_items'     => 'Все приоритеты',
        'add_new_item'  => 'Добавить приоритет',
    );
    $args = array(
        'labels'            => $labels,
        'hierarchical'      => true,
        'public'            => true,
        'show_admin_column' => true,
        'rewrite'           => array('slug' => 'priority'),
        'show_in_rest'      => true,
    );
    register_taxonomy('usm_priority', 'usm_note', $args);
}
add_action('init', 'usm_register_priority_taxonomy');


/* =========================
   META BOX (Due Date)
========================= */

function usm_add_due_date_metabox() {
    add_meta_box(
        'usm_due_date',
        'Дата напоминания',
        'usm_due_date_render',
        'usm_note',
        'side',
        'high'
    );
}
add_action('add_meta_boxes', 'usm_add_due_date_metabox');

function usm_due_date_render($post) {
    wp_nonce_field('usm_save_due_date', 'usm_due_date_nonce');
    $value = get_post_meta($post->ID, '_usm_due_date', true);
    echo '<label>Выберите дату:</label><br>';
    echo '<input type="date" name="usm_due_date" value="' . esc_attr($value) . '" required>';
}


/* =========================
   SAVE META
========================= */

function usm_save_due_date($post_id) {
    if (!isset($_POST['usm_due_date_nonce']) ||
        !wp_verify_nonce($_POST['usm_due_date_nonce'], 'usm_save_due_date')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;
    if (empty($_POST['usm_due_date'])) return;
    $date = sanitize_text_field($_POST['usm_due_date']);
    update_post_meta($post_id, '_usm_due_date', $date);
}
add_action('save_post', 'usm_save_due_date');


/* =========================
   SHORTCODE
========================= */

function usm_notes_shortcode($atts) {

    $atts = shortcode_atts(array(
        'priority'    => '',
        'before_date' => '',
    ), $atts, 'usm_notes');

    $args = array(
        'post_type'              => 'usm_note',
        'posts_per_page'         => -1,
        'post_status'            => 'publish',
        'no_found_rows'          => true,
        'update_post_term_cache' => true,
        'update_post_meta_cache' => true,
    );

    if (!empty($atts['priority'])) {
        $args['tax_query'] = array(
            array(
                'taxonomy' => 'usm_priority',
                'field'    => 'slug',
                'terms'    => sanitize_text_field($atts['priority']),
            ),
        );
    }

    if (!empty($atts['before_date'])) {
        $args['meta_query'] = array(
            array(
                'key'     => '_usm_due_date',
                'value'   => sanitize_text_field($atts['before_date']),
                'compare' => '<=',
                'type'    => 'DATE',
            ),
        );
    }

    global $post;
    $original_post = $post;

    $query = new WP_Query($args);

    if (!$query->have_posts()) {
        $post = $original_post;
        wp_reset_postdata();
        return '<p class="usm-empty">Нет заметок с заданными параметрами</p>';
    }

    $posts = $query->posts;
    wp_reset_postdata();
    $post = $original_post;

    $output = '<div class="usm-notes-grid">';

    foreach ($posts as $p) {
        $post_id  = $p->ID;
        $title    = get_the_title($post_id);
        $link     = get_permalink($post_id);
        $date     = get_post_meta($post_id, '_usm_due_date', true);
        $content  = get_post_field('post_content', $post_id);
        $excerpt  = wp_trim_words(wp_strip_all_tags($content), 15, '...');
        $terms    = get_the_terms($post_id, 'usm_priority');
        $priority = ($terms && !is_wp_error($terms)) ? esc_html($terms[0]->name) : '—';
        $slug     = ($terms && !is_wp_error($terms)) ? sanitize_html_class($terms[0]->slug) : 'none';

        $output .= '<div class="usm-note usm-note-' . $slug . '">';
        $output .= '<div class="usm-note-inner">';
        $output .= '<span class="usm-badge usm-badge-' . $slug . '">' . $priority . '</span>';
        $output .= '<h3 class="usm-note-title">' . esc_html($title) . '</h3>';
        $output .= '<p class="usm-note-excerpt">' . esc_html($excerpt) . '</p>';
        $output .= '<div class="usm-note-meta"><span>📅 ' . esc_html($date) . '</span></div>';
        $output .= '<a href="' . esc_url($link) . '" class="usm-btn">Подробнее →</a>';
        $output .= '</div>';
        $output .= '</div>';
    }

    $output .= '</div>';

    return $output;
}
add_shortcode('usm_notes', 'usm_notes_shortcode');


/* =========================
   STYLES
========================= */

function usm_enqueue_styles() {
    wp_add_inline_style('wp-block-library', '
        .usm-notes-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin: 24px 0;
        }
        .usm-note {
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            border-left: 5px solid #aaa;
            background: #fff;
            box-shadow: 0 2px 6px rgba(0,0,0,0.07);
        }
        .usm-note-high   { border-left-color: #e74c3c; }
        .usm-note-medium { border-left-color: #f39c12; }
        .usm-note-low    { border-left-color: #27ae60; }
        .usm-note-inner  { padding: 16px 18px; }
        .usm-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: #fff;
            margin-bottom: 8px;
        }
        .usm-badge-high   { background: #e74c3c; }
        .usm-badge-medium { background: #f39c12; }
        .usm-badge-low    { background: #27ae60; }
        .usm-badge-none   { background: #95a5a6; }
        .usm-note-title   { font-size: 16px; margin: 0 0 8px; line-height: 1.4; }
        .usm-note-excerpt { font-size: 13px; color: #555; margin: 0 0 10px; line-height: 1.5; }
        .usm-note-meta    { font-size: 12px; color: #777; margin-bottom: 12px; }
        .usm-btn {
            display: inline-block;
            padding: 6px 14px;
            background: #3498db;
            color: #fff !important;
            border-radius: 4px;
            font-size: 13px;
            text-decoration: none !important;
        }
        .usm-btn:hover { background: #2980b9; }
        .usm-empty { color: #888; font-style: italic; }
        @media (max-width: 600px) { .usm-notes-grid { grid-template-columns: 1fr; } }
    ');
}
add_action('wp_enqueue_scripts', 'usm_enqueue_styles');


/* =========================
   ADMIN COLUMN (Due Date)
========================= */

function usm_add_due_date_column($columns) {
    $columns['usm_due_date'] = 'Дата напоминания';
    return $columns;
}
add_filter('manage_usm_note_posts_columns', 'usm_add_due_date_column');

function usm_show_due_date_column($column, $post_id) {
    if ($column === 'usm_due_date') {
        $date = get_post_meta($post_id, '_usm_due_date', true);
        echo $date ? esc_html($date) : '—';
    }
}
add_action('manage_usm_note_posts_custom_column', 'usm_show_due_date_column', 10, 2);
