<?php
/**
 * Plugin Name: Barnaroznowce Menu Helper
 * Description: Dodaje typ wpisu "Danie" wraz z polami na alergeny i gramaturę.
 * Version: 1.0.0
 * Author: OpenAI Assistant
 */

// Zarejestruj typ wpisu "danie".
add_action('init', function () {
    register_post_type('danie', [
        'labels' => [
            'name' => __('Dania', 'barnaroznowce'),
            'singular_name' => __('Danie', 'barnaroznowce'),
            'add_new' => __('Dodaj danie', 'barnaroznowce'),
            'add_new_item' => __('Dodaj nowe danie', 'barnaroznowce'),
            'edit_item' => __('Edytuj danie', 'barnaroznowce'),
            'new_item' => __('Nowe danie', 'barnaroznowce'),
            'view_item' => __('Zobacz danie', 'barnaroznowce'),
            'search_items' => __('Szukaj dań', 'barnaroznowce'),
        ],
        'public' => true,
        'menu_position' => 20,
        'supports' => ['title', 'editor', 'thumbnail'],
        'show_in_rest' => true,
        'has_archive' => true,
    ]);
});

// Dodaj meta box z polami Alergeny i Gramatura.
add_action('add_meta_boxes', function () {
    add_meta_box(
        'barnaroznowce_danie_details',
        __('Szczegóły dania', 'barnaroznowce'),
        'barnaroznowce_render_danie_fields',
        'danie',
        'normal',
        'default'
    );
});

/**
 * Renderuje pola meta dla alergenu i gramatury.
 */
function barnaroznowce_render_danie_fields($post)
{
    $allergens = get_post_meta($post->ID, '_barnaroznowce_allergens', true);
    $grams = get_post_meta($post->ID, '_barnaroznowce_grams', true);
    wp_nonce_field('barnaroznowce_danie_fields', 'barnaroznowce_danie_nonce');
    ?>
    <p>
        <label for="barnaroznowce_allergens"><strong><?php _e('Alergeny', 'barnaroznowce'); ?></strong></label><br />
        <input type="text" name="barnaroznowce_allergens" id="barnaroznowce_allergens" class="widefat"
               value="<?php echo esc_attr($allergens); ?>" placeholder="np. gluten, orzechy" />
        <span class="description"><?php _e('Wpisz listę alergenów oddzieloną przecinkami.', 'barnaroznowce'); ?></span>
    </p>
    <p>
        <label for="barnaroznowce_grams"><strong><?php _e('Gramatura', 'barnaroznowce'); ?></strong></label><br />
        <input type="number" step="0.01" min="0" name="barnaroznowce_grams" id="barnaroznowce_grams" class="small-text"
               value="<?php echo esc_attr($grams); ?>" />
        <span class="description"><?php _e('Podaj gramaturę porcji (g).', 'barnaroznowce'); ?></span>
    </p>
    <?php
}

// Zapisz wartości pól meta przy zapisywaniu wpisu.
add_action('save_post_danie', function ($post_id, $post, $update) {
    if (!isset($_POST['barnaroznowce_danie_nonce']) || !wp_verify_nonce($_POST['barnaroznowce_danie_nonce'], 'barnaroznowce_danie_fields')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    $allergens = isset($_POST['barnaroznowce_allergens']) ? sanitize_text_field($_POST['barnaroznowce_allergens']) : '';
    $grams = isset($_POST['barnaroznowce_grams']) ? floatval($_POST['barnaroznowce_grams']) : '';

    update_post_meta($post_id, '_barnaroznowce_allergens', $allergens);
    update_post_meta($post_id, '_barnaroznowce_grams', $grams);
}, 10, 3);

// Udostępnij pola w REST API.
add_action('rest_api_init', function () {
    register_rest_field('danie', 'allergens', [
        'get_callback' => function ($object) {
            return get_post_meta($object['id'], '_barnaroznowce_allergens', true);
        },
        'update_callback' => function ($value, $object) {
            update_post_meta($object->ID, '_barnaroznowce_allergens', sanitize_text_field($value));
        },
        'schema' => [
            'description' => __('Lista alergenów', 'barnaroznowce'),
            'type' => 'string',
            'context' => ['view', 'edit'],
        ],
    ]);

    register_rest_field('danie', 'grams', [
        'get_callback' => function ($object) {
            return get_post_meta($object['id'], '_barnaroznowce_grams', true);
        },
        'update_callback' => function ($value, $object) {
            update_post_meta($object->ID, '_barnaroznowce_grams', floatval($value));
        },
        'schema' => [
            'description' => __('Gramatura porcji', 'barnaroznowce'),
            'type' => 'number',
            'context' => ['view', 'edit'],
        ],
    ]);
});

// Dodaj kolumny w tabeli listy dań.
add_filter('manage_danie_posts_columns', function ($columns) {
    $columns['barnaroznowce_allergens'] = __('Alergeny', 'barnaroznowce');
    $columns['barnaroznowce_grams'] = __('Gramatura (g)', 'barnaroznowce');
    return $columns;
});

add_action('manage_danie_posts_custom_column', function ($column, $post_id) {
    if ($column === 'barnaroznowce_allergens') {
        echo esc_html(get_post_meta($post_id, '_barnaroznowce_allergens', true));
    }

    if ($column === 'barnaroznowce_grams') {
        $grams = get_post_meta($post_id, '_barnaroznowce_grams', true);
        if ($grams !== '') {
            echo esc_html($grams . ' g');
        }
    }
}, 10, 2);
?>
