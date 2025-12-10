<?php
/**
 * Plugin Name: Menu Dnia Restauracji
 * Plugin URI: https://twoja-restauracja.pl
 * Description: Prosty system zarządzania daniami dnia w restauracji
 * Version: 1.1.0
 * Author: Twoja Restauracja
 * Text Domain: menu-dnia
 */

// Zabezpieczenie przed bezpośrednim dostępem
if (!defined('ABSPATH')) {
    exit;
}

// Stałe wtyczki
define('MDR_VERSION', '1.1.0');
define('MDR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MDR_PLUGIN_URL', plugin_dir_url(__FILE__));

// Aktywacja wtyczki - tworzenie tabel
register_activation_hook(__FILE__, 'mdr_create_database_tables');

function mdr_create_database_tables() {
    global $wpdb;
    
    $charset_collate = $wpdb->get_charset_collate();
    
    // Tabela z daniami
    $table_name = $wpdb->prefix . 'menu_dnia';
    
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id int(11) NOT NULL AUTO_INCREMENT,
        nazwa_dania varchar(255) NOT NULL,
        opis text,
        cena decimal(10,2) NOT NULL,
        gramy decimal(8,2) DEFAULT NULL,
        alergeny text,
        kategoria varchar(100) DEFAULT 'inne',
        dzien_tygodnia varchar(20),
        jest_daniem_dnia tinyint(1) DEFAULT 0,
        aktywne tinyint(1) DEFAULT 1,
        data_utworzenia datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Tabela z wykluczonymi dniami
    $table_excluded = $wpdb->prefix . 'menu_dnia_wykluczone';
    
    $sql_excluded = "CREATE TABLE IF NOT EXISTS $table_excluded (
        id int(11) NOT NULL AUTO_INCREMENT,
        typ varchar(20) NOT NULL,
        wartosc varchar(50) NOT NULL,
        powod text,
        data_utworzenia datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unique_exclusion (typ, wartosc)
    ) $charset_collate;";
    
    dbDelta($sql_excluded);
    
    // Tabela z kategoriami
    $table_categories = $wpdb->prefix . 'menu_dnia_kategorie';
    
    $sql_categories = "CREATE TABLE IF NOT EXISTS $table_categories (
        id int(11) NOT NULL AUTO_INCREMENT,
        nazwa varchar(100) NOT NULL,
        slug varchar(100) NOT NULL,
        kolejnosc int(11) DEFAULT 0,
        ikona varchar(50) DEFAULT '',
        data_utworzenia datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unique_slug (slug)
    ) $charset_collate;";
    
    dbDelta($sql_categories);
    
    // Dodaj domyślne kategorie jeśli tabela jest pusta
    $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_categories");
    if ($count == 0) {
        $default_categories = array(
            array('nazwa' => 'Dania Dnia', 'slug' => 'dania_dnia', 'kolejnosc' => 1, 'ikona' => '⭐'),
            array('nazwa' => 'Przystawki', 'slug' => 'przystawki', 'kolejnosc' => 2, 'ikona' => '🥗'),
            array('nazwa' => 'Zupy', 'slug' => 'zupy', 'kolejnosc' => 3, 'ikona' => '🍲'),
            array('nazwa' => 'Dania Główne', 'slug' => 'dania_glowne', 'kolejnosc' => 4, 'ikona' => '🍽️'),
            array('nazwa' => 'Desery', 'slug' => 'desery', 'kolejnosc' => 5, 'ikona' => '🍰'),
            array('nazwa' => 'Napoje', 'slug' => 'napoje', 'kolejnosc' => 6, 'ikona' => '🥤'),
            array('nazwa' => 'Inne', 'slug' => 'inne', 'kolejnosc' => 99, 'ikona' => '📋')
        );
        
        foreach ($default_categories as $cat) {
            $wpdb->insert($table_categories, $cat);
        }
    }
    
    // Zapisz wersję bazy danych
    update_option('mdr_db_version', MDR_VERSION);
}

// Sprawdź tabele przy inicjalizacji admina
add_action('admin_init', 'mdr_check_database_tables');

function mdr_check_database_tables() {
    global $wpdb;
    
    // Sprawdź czy tabele istnieją
    $table_name = $wpdb->prefix . 'menu_dnia';
    $table_excluded = $wpdb->prefix . 'menu_dnia_wykluczone';
    $table_categories = $wpdb->prefix . 'menu_dnia_kategorie';
    
    $table1_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
    $table2_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_excluded'") == $table_excluded;
    $table3_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_categories'") == $table_categories;

    // Jeśli któraś tabela nie istnieje, utwórz je
    if (!$table1_exists || !$table2_exists || !$table3_exists) {
        mdr_create_database_tables();
    }

    // Upewnij się, że kolumna "gramy" istnieje w tabeli menu_dnia
    if ($table1_exists) {
        $gramy_column = $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM {$table_name} LIKE %s", 'gramy'));

        if (!$gramy_column) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN gramy decimal(8,2) DEFAULT NULL AFTER cena");
        }
    }

    // Aktualizacja schematu, gdy zmienia się wersja bazy danych
    $db_version = get_option('mdr_db_version');
    if ($db_version !== MDR_VERSION) {
        mdr_create_database_tables();
    }
    
    // Obsługa ręcznego tworzenia tabel
    if (isset($_GET['mdr_fix_database']) && current_user_can('manage_options')) {
        check_admin_referer('mdr_fix_database');
        mdr_create_database_tables();
        
        // Przekieruj do odpowiedniej strony
        if (isset($_GET['page']) && $_GET['page'] == 'menu-dnia-tools') {
            wp_redirect(admin_url('admin.php?page=menu-dnia-tools&db_fixed=1'));
        } else {
            wp_redirect(admin_url('admin.php?page=menu-dnia&db_fixed=1'));
        }
        exit;
    }
}

// Menu administracyjne
add_action('admin_menu', 'mdr_add_admin_menu');

function mdr_add_admin_menu() {
    add_menu_page(
        'Menu Dnia',
        'Menu Dnia',
        'manage_options',
        'menu-dnia',
        'mdr_admin_page',
        'dashicons-food',
        30
    );
    
    add_submenu_page(
        'menu-dnia',
        'Zarządzaj Daniami',
        'Wszystkie Dania',
        'manage_options',
        'menu-dnia',
        'mdr_admin_page'
    );
    
    add_submenu_page(
        'menu-dnia',
        'Dodaj Nowe Danie',
        'Dodaj Nowe',
        'manage_options',
        'menu-dnia-dodaj',
        'mdr_add_dish_page'
    );
    
    add_submenu_page(
        'menu-dnia',
        'Kategorie',
        'Kategorie',
        'manage_options',
        'menu-dnia-kategorie',
        'mdr_categories_page'
    );
    
    add_submenu_page(
        'menu-dnia',
        'Wykluczone Dni',
        'Wykluczone Dni',
        'manage_options',
        'menu-dnia-wykluczone',
        'mdr_excluded_days_page'
    );
    
    add_submenu_page(
        'menu-dnia',
        'Narzędzia',
        'Narzędzia',
        'manage_options',
        'menu-dnia-tools',
        'mdr_tools_page'
    );
}

// Strona zarządzania kategoriami
function mdr_categories_page() {
    global $wpdb;
    $table_categories = $wpdb->prefix . 'menu_dnia_kategorie';
    
    // Sprawdź czy tabela istnieje
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_categories'") == $table_categories;
    
    if (!$table_exists) {
        ?>
        <div class="wrap">
            <h1>Kategorie - Problem z bazą danych</h1>
            <div class="notice notice-error">
                <p><strong>Błąd!</strong> Tabela kategorii nie istnieje w bazie danych.</p>
                <p>Przejdź do zakładki <strong>Narzędzia</strong> aby naprawić bazę danych.</p>
            </div>
            <p>
                <a href="<?php echo admin_url('admin.php?page=menu-dnia-tools'); ?>" class="button button-primary button-hero">
                    Przejdź do Narzędzi
                </a>
            </p>
        </div>
        <?php
        return;
    }
    
    // Obsługa dodawania kategorii
    if (isset($_POST['dodaj_kategorie']) && check_admin_referer('mdr_add_category', 'mdr_nonce_cat')) {
        $nazwa = sanitize_text_field($_POST['nazwa']);
        $slug = sanitize_title($_POST['slug'] ?: $nazwa);
        $ikona = sanitize_text_field($_POST['ikona']);
        $kolejnosc = intval($_POST['kolejnosc']);
        
        // Sprawdź czy slug już istnieje
        $istnieje = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_categories WHERE slug = %s",
            $slug
        ));
        
        if (!$istnieje) {
            $result = $wpdb->insert($table_categories, array(
                'nazwa' => $nazwa,
                'slug' => $slug,
                'ikona' => $ikona,
                'kolejnosc' => $kolejnosc
            ), array('%s', '%s', '%s', '%d'));
            
            if ($result) {
                echo '<div class="notice notice-success is-dismissible"><p><strong>Sukces!</strong> Kategoria została dodana!</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Błąd!</strong> Nie udało się dodać kategorii: ' . $wpdb->last_error . '</p></div>';
            }
        } else {
            echo '<div class="notice notice-warning is-dismissible"><p><strong>Uwaga!</strong> Kategoria o tym identyfikatorze już istnieje!</p></div>';
        }
    }
    
    // Obsługa edycji kategorii
    if (isset($_POST['edytuj_kategorie']) && check_admin_referer('mdr_edit_category', 'mdr_nonce_edit_cat')) {
        $id = intval($_POST['category_id']);
        $nazwa = sanitize_text_field($_POST['nazwa']);
        $ikona = sanitize_text_field($_POST['ikona']);
        $kolejnosc = intval($_POST['kolejnosc']);
        
        $result = $wpdb->update(
            $table_categories,
            array(
                'nazwa' => $nazwa,
                'ikona' => $ikona,
                'kolejnosc' => $kolejnosc
            ),
            array('id' => $id),
            array('%s', '%s', '%d'),
            array('%d')
        );
        
        if ($result !== false) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Sukces!</strong> Kategoria została zaktualizowana!</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p><strong>Błąd!</strong> Nie udało się zaktualizować kategorii.</p></div>';
        }
    }
    
    // Obsługa usuwania
    if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id']) && check_admin_referer('mdr_delete_category_' . intval($_GET['id']))) {
        $id = intval($_GET['id']);
        $slug = $wpdb->get_var($wpdb->prepare("SELECT slug FROM $table_categories WHERE id = %d", $id));
        
        // Sprawdź czy są dania w tej kategorii
        $table_dishes = $wpdb->prefix . 'menu_dnia';
        $dishes_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_dishes WHERE kategoria = %s",
            $slug
        ));
        
        if ($dishes_count > 0) {
            echo '<div class="notice notice-error is-dismissible"><p><strong>Błąd!</strong> Nie możesz usunąć kategorii która zawiera dania (' . $dishes_count . '). Najpierw usuń lub przenieś dania do innej kategorii.</p></div>';
        } else {
            $result = $wpdb->delete($table_categories, array('id' => $id), array('%d'));
            if ($result) {
                echo '<div class="notice notice-success is-dismissible"><p>Kategoria została usunięta!</p></div>';
            }
        }
    }
    
    // Pobierz wszystkie kategorie
    $kategorie = $wpdb->get_results("SELECT * FROM $table_categories ORDER BY kolejnosc, nazwa");
    
    // Tryb edycji
    $edit_mode = false;
    $edit_category = null;
    if (isset($_GET['edit']) && isset($_GET['id'])) {
        $edit_mode = true;
        $edit_category = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_categories WHERE id = %d", intval($_GET['id'])));
    }
    
    ?>
    <div class="wrap">
        <h1>Zarządzanie Kategoriami</h1>
        <p>Tutaj możesz dodawać, edytować i usuwać kategorie dla swojego menu.</p>
        
        <div style="display: flex; gap: 20px; margin: 20px 0;">
            
            <!-- Formularz dodawania/edycji kategorii -->
            <div style="flex: 1; background: white; padding: 20px; border: 1px solid #ccc; border-radius: 5px;">
                <h2><?php echo $edit_mode ? 'Edytuj Kategorię' : 'Dodaj Nową Kategorię'; ?></h2>
                <form method="post">
                    <?php 
                    if ($edit_mode) {
                        wp_nonce_field('mdr_edit_category', 'mdr_nonce_edit_cat');
                    } else {
                        wp_nonce_field('mdr_add_category', 'mdr_nonce_cat');
                    }
                    ?>
                    
                    <?php if ($edit_mode): ?>
                        <input type="hidden" name="category_id" value="<?php echo $edit_category->id; ?>">
                    <?php endif; ?>
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="nazwa">Nazwa Kategorii*</label></th>
                            <td>
                                <input type="text" name="nazwa" id="nazwa" class="regular-text" 
                                       value="<?php echo $edit_mode ? esc_attr($edit_category->nazwa) : ''; ?>" 
                                       required>
                                <p class="description">Np. "Pizza", "Makarony", "Sałatki"</p>
                            </td>
                        </tr>
                        
                        <?php if (!$edit_mode): ?>
                        <tr>
                            <th><label for="slug">Identyfikator (slug)</label></th>
                            <td>
                                <input type="text" name="slug" id="slug" class="regular-text"
                                       pattern="[a-z0-9_-]+" 
                                       placeholder="zostanie wygenerowany automatycznie">
                                <p class="description">Pozostaw puste aby wygenerować automatycznie. Tylko małe litery, cyfry, myślniki i podkreślenia.</p>
                            </td>
                        </tr>
                        <?php endif; ?>
                        
                        <tr>
                            <th><label for="ikona">Ikona (emoji)</label></th>
                            <td>
                                <input type="text" name="ikona" id="ikona" style="width: 80px; font-size: 24px;"
                                       value="<?php echo $edit_mode ? esc_attr($edit_category->ikona) : '📋'; ?>"
                                       maxlength="4">
                                <p class="description">Wklej emoji (np. 🍕 🍝 🥗 🍰 ☕)</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="kolejnosc">Kolejność</label></th>
                            <td>
                                <input type="number" name="kolejnosc" id="kolejnosc" style="width: 100px;"
                                       value="<?php echo $edit_mode ? esc_attr($edit_category->kolejnosc) : '10'; ?>"
                                       min="0">
                                <p class="description">Niższe liczby = wyżej w menu</p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" 
                               name="<?php echo $edit_mode ? 'edytuj_kategorie' : 'dodaj_kategorie'; ?>" 
                               class="button button-primary" 
                               value="<?php echo $edit_mode ? 'Zaktualizuj Kategorię' : 'Dodaj Kategorię'; ?>">
                        <?php if ($edit_mode): ?>
                            <a href="?page=menu-dnia-kategorie" class="button">Anuluj</a>
                        <?php endif; ?>
                    </p>
                </form>
            </div>
            
            <!-- Szybkie emoji -->
            <div style="flex: 0 0 200px; background: white; padding: 20px; border: 1px solid #ccc; border-radius: 5px;">
                <h3>Popularne Ikony</h3>
                <p style="font-size: 12px; color: #666; margin-bottom: 10px;">Kliknij aby skopiować:</p>
                <div style="font-size: 28px; line-height: 1.5;">
                    <span class="emoji-copy" title="Pizza">🍕</span>
                    <span class="emoji-copy" title="Makaron">🍝</span>
                    <span class="emoji-copy" title="Sałatka">🥗</span>
                    <span class="emoji-copy" title="Zupa">🍲</span>
                    <span class="emoji-copy" title="Burger">🍔</span>
                    <span class="emoji-copy" title="Mięso">🥩</span>
                    <span class="emoji-copy" title="Ryba">🐟</span>
                    <span class="emoji-copy" title="Deser">🍰</span>
                    <span class="emoji-copy" title="Lody">🍨</span>
                    <span class="emoji-copy" title="Kawa">☕</span>
                    <span class="emoji-copy" title="Napoje">🥤</span>
                    <span class="emoji-copy" title="Wino">🍷</span>
                    <span class="emoji-copy" title="Śniadanie">🍳</span>
                    <span class="emoji-copy" title="Lunch">🍽️</span>
                    <span class="emoji-copy" title="Kolacja">🌙</span>
                    <span class="emoji-copy" title="Gwiazda">⭐</span>
                </div>
                <p style="font-size: 11px; color: #999; margin-top: 10px;">Możesz też skopiować dowolne emoji z internetu</p>
            </div>
        </div>
        
        <!-- Lista kategorii -->
        <h2>Lista Kategorii</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 60px;">Ikona</th>
                    <th>Nazwa</th>
                    <th style="width: 150px;">Identyfikator</th>
                    <th style="width: 100px;">Kolejność</th>
                    <th style="width: 100px;">Liczba Dań</th>
                    <th style="width: 150px;">Akcje</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $table_dishes = $wpdb->prefix . 'menu_dnia';
                foreach ($kategorie as $kat): 
                    $dishes_count = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM $table_dishes WHERE kategoria = %s",
                        $kat->slug
                    ));
                ?>
                <tr>
                    <td style="text-align: center; font-size: 28px;"><?php echo esc_html($kat->ikona); ?></td>
                    <td><strong><?php echo esc_html($kat->nazwa); ?></strong></td>
                    <td><code><?php echo esc_html($kat->slug); ?></code></td>
                    <td style="text-align: center;"><?php echo $kat->kolejnosc; ?></td>
                    <td style="text-align: center;">
                        <?php if ($dishes_count > 0): ?>
                            <a href="?page=menu-dnia&filter_category=<?php echo urlencode($kat->slug); ?>"><?php echo $dishes_count; ?> dań</a>
                        <?php else: ?>
                            <span style="color: #999;">0 dań</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="?page=menu-dnia-kategorie&edit=1&id=<?php echo $kat->id; ?>" class="button button-small">Edytuj</a>
                        <?php if ($dishes_count == 0): ?>
                            <a href="<?php echo wp_nonce_url(
                                add_query_arg(array('page' => 'menu-dnia-kategorie', 'action' => 'delete', 'id' => $kat->id), admin_url('admin.php')),
                                'mdr_delete_category_' . $kat->id
                            ); ?>" 
                               class="button button-small" 
                               onclick="return confirm('Czy na pewno chcesz usunąć tę kategorię?')">Usuń</a>
                        <?php else: ?>
                            <span class="button button-small button-disabled" title="Nie można usunąć kategorii z daniami">Usuń</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div style="margin-top: 30px; padding: 15px; background: #f0f0f1; border-left: 4px solid #2271b1;">
            <h3>ℹ️ Jak to działa?</h3>
            <ul>
                <li><strong>Własne kategorie:</strong> Możesz tworzyć dowolne kategorie dopasowane do Twojego menu (np. "Pizza", "Makarony", "Burgery")</li>
                <li><strong>Kolejność:</strong> Określa w jakiej kolejności kategorie będą wyświetlane w menu (mniejsza liczba = wyżej)</li>
                <li><strong>Identyfikator:</strong> Unikalny kod kategorii (generowany automatycznie). Nie można go zmienić po utworzeniu.</li>
                <li><strong>Usuwanie:</strong> Można usunąć tylko puste kategorie. Najpierw usuń lub przenieś dania.</li>
            </ul>
        </div>
    </div>
    
    <style>
        .emoji-copy {
            cursor: pointer;
            display: inline-block;
            padding: 5px;
            transition: transform 0.2s;
        }
        .emoji-copy:hover {
            transform: scale(1.3);
        }
    </style>
    
    <script>
    jQuery(document).ready(function($) {
        $('.emoji-copy').on('click', function() {
            var emoji = $(this).text();
            $('#ikona').val(emoji);
            $(this).css('transform', 'scale(1.5)');
            setTimeout(() => $(this).css('transform', ''), 200);
        });
    });
    </script>
    <?php
}

// Strona narzędzi
function mdr_tools_page() {
    global $wpdb;
    
    // Sprawdź status tabel
    $table_name = $wpdb->prefix . 'menu_dnia';
    $table_excluded = $wpdb->prefix . 'menu_dnia_wykluczone';
    $table_categories = $wpdb->prefix . 'menu_dnia_kategorie';
    
    $table1_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
    $table2_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_excluded'") == $table_excluded;
    $table3_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_categories'") == $table_categories;
    
    $table1_count = $table1_exists ? $wpdb->get_var("SELECT COUNT(*) FROM $table_name") : 0;
    $table2_count = $table2_exists ? $wpdb->get_var("SELECT COUNT(*) FROM $table_excluded") : 0;
    $table3_count = $table3_exists ? $wpdb->get_var("SELECT COUNT(*) FROM $table_categories") : 0;
    
    ?>
    <div class="wrap">
        <h1>Narzędzia Menu Dnia</h1>
        
        <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ccc; border-radius: 5px;">
            <h2>Status Bazy Danych</h2>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Tabela</th>
                        <th>Status</th>
                        <th>Liczba rekordów</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code><?php echo $table_name; ?></code></td>
                        <td>
                            <?php if ($table1_exists): ?>
                                <span style="color: green;">✓ Istnieje</span>
                            <?php else: ?>
                                <span style="color: red;">✗ Nie istnieje</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $table1_count; ?> dań</td>
                    </tr>
                    <tr>
                        <td><code><?php echo $table_excluded; ?></code></td>
                        <td>
                            <?php if ($table2_exists): ?>
                                <span style="color: green;">✓ Istnieje</span>
                            <?php else: ?>
                                <span style="color: red;">✗ Nie istnieje</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $table2_count; ?> wykluczeń</td>
                    </tr>
                    <tr>
                        <td><code><?php echo $table_categories; ?></code></td>
                        <td>
                            <?php if ($table3_exists): ?>
                                <span style="color: green;">✓ Istnieje</span>
                            <?php else: ?>
                                <span style="color: red;">✗ Nie istnieje</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $table3_count; ?> kategorii</td>
                    </tr>
                </tbody>
            </table>
            
            <?php if (!$table1_exists || !$table2_exists || !$table3_exists): ?>
                <div class="notice notice-warning inline" style="margin: 20px 0;">
                    <p><strong>Uwaga!</strong> Niektóre tabele nie istnieją. Kliknij poniższy przycisk aby je utworzyć.</p>
                </div>
            <?php endif; ?>
            
            <p style="margin-top: 20px;">
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=menu-dnia-tools&mdr_fix_database=1'), 'mdr_fix_database'); ?>" 
                   class="button button-primary">
                    🔧 Utwórz/Napraw Tabele
                </a>
            </p>
            
            <?php if (isset($_GET['db_fixed'])): ?>
                <div class="notice notice-success inline" style="margin-top: 20px;">
                    <p><strong>Gotowe!</strong> Tabele zostały utworzone/naprawione pomyślnie!</p>
                </div>
            <?php endif; ?>
        </div>
        
        <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ccc; border-radius: 5px;">
            <h2>Eksport/Import</h2>
            <p>Eksportuj wszystkie dania do pliku JSON:</p>
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?mdr_export=1'), 'mdr_export_menu'); ?>" 
               class="button">
                📥 Eksportuj Menu
            </a>
        </div>
        
        <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ccc; border-radius: 5px;">
            <h2>Informacje o wtyczce</h2>
            <p><strong>Wersja:</strong> <?php echo MDR_VERSION; ?></p>
            <p><strong>Prefix bazy danych:</strong> <code><?php echo $wpdb->prefix; ?></code></p>
            <p><strong>Charset:</strong> <?php echo $wpdb->charset; ?></p>
            
            <h3 style="margin-top: 20px;">Funkcje wtyczki:</h3>
            <ul>
                <li>✅ Zarządzanie daniami z możliwością włączania/wyłączania widoczności</li>
                <li>✅ Własne kategorie z ikonami emoji</li>
                <li>✅ System dań dnia z przypisywaniem do dni tygodnia</li>
                <li>✅ Wykluczanie dni (dni tygodnia i konkretne daty)</li>
                <li>✅ Shortcode <code>[menu_dnia]</code> do wyświetlania pełnego menu</li>
                <li>✅ Shortcode <code>[danie_dnia]</code> do wyświetlania tylko dania dnia</li>
                <li>✅ Widget WordPress do łatwego umieszczania w sidebar</li>
                <li>✅ Responsywny design dostosowany do motywów WordPress</li>
            </ul>
        </div>
    </div>
    <?php
}

// Strona wykluczonych dni - POPRAWIONA
function mdr_excluded_days_page() {
    global $wpdb;
    $table_excluded = $wpdb->prefix . 'menu_dnia_wykluczone';
    
    // Sprawdź czy tabela istnieje
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_excluded'") == $table_excluded;
    
    if (!$table_exists) {
        ?>
        <div class="wrap">
            <h1>Wykluczone Dni - Problem z bazą danych</h1>
            <div class="notice notice-error">
                <p><strong>Błąd!</strong> Tabela wykluczonych dni nie istnieje w bazie danych.</p>
                <p>Przejdź do zakładki <strong>Narzędzia</strong> aby naprawić bazę danych, lub kliknij poniższy przycisk:</p>
            </div>
            <p>
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=menu-dnia&mdr_fix_database=1'), 'mdr_fix_database'); ?>" 
                   class="button button-primary button-hero">
                    🔧 Napraw Bazę Danych
                </a>
                <a href="<?php echo admin_url('admin.php?page=menu-dnia-tools'); ?>" class="button button-hero">
                    Przejdź do Narzędzi
                </a>
            </p>
        </div>
        <?php
        return;
    }
    
    // Obsługa dodawania dni tygodnia
    if (isset($_POST['dodaj_dzien_tygodnia']) && check_admin_referer('mdr_add_excluded_day', 'mdr_nonce')) {
        $dzien = sanitize_text_field($_POST['dzien_tygodnia']);
        $powod = sanitize_textarea_field($_POST['powod_dzien']);
        
        // Sprawdź czy już nie istnieje
        $istnieje = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_excluded WHERE typ = 'dzien_tygodnia' AND wartosc = %s",
            $dzien
        ));
        
        if (!$istnieje) {
            $result = $wpdb->insert($table_excluded, array(
                'typ' => 'dzien_tygodnia',
                'wartosc' => $dzien,
                'powod' => $powod
            ), array('%s', '%s', '%s'));
            
            if ($result) {
                echo '<div class="notice notice-success is-dismissible"><p><strong>Sukces!</strong> Dzień tygodnia został wykluczony!</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Błąd!</strong> Nie udało się dodać wykluczenia: ' . $wpdb->last_error . '</p></div>';
            }
        } else {
            echo '<div class="notice notice-warning is-dismissible"><p><strong>Uwaga!</strong> Ten dzień jest już wykluczony!</p></div>';
        }
    }
    
    // Obsługa dodawania konkretnej daty
    if (isset($_POST['dodaj_date']) && check_admin_referer('mdr_add_excluded_date', 'mdr_nonce_date')) {
        $data = sanitize_text_field($_POST['data']);
        $powod = sanitize_textarea_field($_POST['powod_data']);
        
        // Walidacja daty
        if (!empty($data) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
            // Sprawdź czy już nie istnieje
            $istnieje = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_excluded WHERE typ = 'data' AND wartosc = %s",
                $data
            ));
            
            if (!$istnieje) {
                $result = $wpdb->insert($table_excluded, array(
                    'typ' => 'data',
                    'wartosc' => $data,
                    'powod' => $powod
                ), array('%s', '%s', '%s'));
                
                if ($result) {
                    echo '<div class="notice notice-success is-dismissible"><p><strong>Sukces!</strong> Data została wykluczona!</p></div>';
                } else {
                    echo '<div class="notice notice-error is-dismissible"><p><strong>Błąd!</strong> Nie udało się dodać wykluczenia: ' . $wpdb->last_error . '</p></div>';
                }
            } else {
                echo '<div class="notice notice-warning is-dismissible"><p><strong>Uwaga!</strong> Ta data jest już wykluczona!</p></div>';
            }
        } else {
            echo '<div class="notice notice-error is-dismissible"><p><strong>Błąd!</strong> Nieprawidłowy format daty!</p></div>';
        }
    }
    
    // Obsługa usuwania
    if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id']) && check_admin_referer('mdr_delete_excluded_' . intval($_GET['id']))) {
        $result = $wpdb->delete($table_excluded, array('id' => intval($_GET['id'])), array('%d'));
        if ($result) {
            echo '<div class="notice notice-success is-dismissible"><p>Wykluczenie zostało usunięte!</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>Błąd podczas usuwania!</p></div>';
        }
    }
    
    // Pobierz wykluczone dni
    $wykluczone_dni = $wpdb->get_results("SELECT * FROM $table_excluded ORDER BY typ, wartosc");
    
    $dni_tygodnia = array('Poniedziałek', 'Wtorek', 'Środa', 'Czwartek', 'Piątek', 'Sobota', 'Niedziela');
    ?>
    
    <div class="wrap">
        <h1>Wykluczone Dni</h1>
        <p>Tutaj możesz zarządzać dniami, w które nie będzie wyświetlane danie dnia (np. dni zamknięcia restauracji).</p>
        
        <div style="display: flex; gap: 20px; margin: 20px 0;">
            
            <!-- Wykluczanie dni tygodnia -->
            <div style="flex: 1; background: white; padding: 20px; border: 1px solid #ccc; border-radius: 5px;">
                <h2>Wyklucz Dzień Tygodnia</h2>
                <p>Np. jeśli restauracja jest zawsze zamknięta w niedziele</p>
                <form method="post">
                    <?php wp_nonce_field('mdr_add_excluded_day', 'mdr_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="dzien_tygodnia">Dzień tygodnia:</label></th>
                            <td>
                                <select name="dzien_tygodnia" id="dzien_tygodnia" required style="width: 100%;">
                                    <?php foreach ($dni_tygodnia as $dzien): ?>
                                        <option value="<?php echo esc_attr($dzien); ?>"><?php echo esc_html($dzien); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="powod_dzien">Powód (opcjonalnie):</label></th>
                            <td>
                                <textarea name="powod_dzien" id="powod_dzien" rows="3" style="width: 100%;"></textarea>
                                <p class="description">Np. "Dzień wolny od pracy"</p>
                            </td>
                        </tr>
                    </table>
                    <input type="submit" name="dodaj_dzien_tygodnia" class="button button-primary" value="Wyklucz Dzień">
                </form>
            </div>
            
            <!-- Wykluczanie konkretnych dat -->
            <div style="flex: 1; background: white; padding: 20px; border: 1px solid #ccc; border-radius: 5px;">
                <h2>Wyklucz Konkretną Datę</h2>
                <p>Np. święta, specjalne wydarzenia</p>
                <form method="post">
                    <?php wp_nonce_field('mdr_add_excluded_date', 'mdr_nonce_date'); ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="data">Data:</label></th>
                            <td>
                                <input type="date" name="data" id="data" required style="width: 100%;" min="<?php echo date('Y-m-d'); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th><label for="powod_data">Powód (opcjonalnie):</label></th>
                            <td>
                                <textarea name="powod_data" id="powod_data" rows="3" style="width: 100%;"></textarea>
                                <p class="description">Np. "Boże Narodzenie", "Remonty"</p>
                            </td>
                        </tr>
                    </table>
                    <input type="submit" name="dodaj_date" class="button button-primary" value="Wyklucz Datę">
                </form>
            </div>
            
        </div>
        
        <!-- Lista wykluczonych dni -->
        <h2>Lista Wykluczonych Dni</h2>
        
        <?php
        $wykluczone_dni_tyg = array_filter($wykluczone_dni, function($w) { return $w->typ == 'dzien_tygodnia'; });
        $wykluczone_daty = array_filter($wykluczone_dni, function($w) { return $w->typ == 'data'; });
        ?>
        
        <?php if (!empty($wykluczone_dni_tyg)): ?>
        <h3>Wykluczone Dni Tygodnia</h3>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 150px;">Dzień</th>
                    <th>Powód</th>
                    <th style="width: 100px;">Akcje</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($wykluczone_dni_tyg as $wykluczony): ?>
                <tr>
                    <td><strong><?php echo esc_html($wykluczony->wartosc); ?></strong></td>
                    <td><?php echo esc_html($wykluczony->powod ?: '-'); ?></td>
                    <td>
                        <a href="<?php echo wp_nonce_url(
                            add_query_arg(array('page' => 'menu-dnia-wykluczone', 'action' => 'delete', 'id' => $wykluczony->id), admin_url('admin.php')),
                            'mdr_delete_excluded_' . $wykluczony->id
                        ); ?>" 
                           class="button button-small" 
                           onclick="return confirm('Czy na pewno chcesz usunąć to wykluczenie?')">Usuń</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p><em>Brak wykluczonych dni tygodnia</em></p>
        <?php endif; ?>
        
        <?php if (!empty($wykluczone_daty)): ?>
        <h3 style="margin-top: 30px;">Wykluczone Konkretne Daty</h3>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 150px;">Data</th>
                    <th>Powód</th>
                    <th style="width: 100px;">Akcje</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($wykluczone_daty as $wykluczony): ?>
                <tr>
                    <td><strong><?php echo date_i18n('j F Y', strtotime($wykluczony->wartosc)); ?></strong></td>
                    <td><?php echo esc_html($wykluczony->powod ?: '-'); ?></td>
                    <td>
                        <a href="<?php echo wp_nonce_url(
                            add_query_arg(array('page' => 'menu-dnia-wykluczone', 'action' => 'delete', 'id' => $wykluczony->id), admin_url('admin.php')),
                            'mdr_delete_excluded_' . $wykluczony->id
                        ); ?>" 
                           class="button button-small" 
                           onclick="return confirm('Czy na pewno chcesz usunąć to wykluczenie?')">Usuń</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p><em>Brak wykluczonych dat</em></p>
        <?php endif; ?>
        
        <div style="margin-top: 30px; padding: 15px; background: #f0f0f1; border-left: 4px solid #2271b1;">
            <h3>ℹ️ Jak to działa?</h3>
            <ul>
                <li><strong>Dni tygodnia:</strong> Jeśli wykluczysz np. "Niedziela", to w każdą niedzielę nie będzie wyświetlane danie dnia.</li>
                <li><strong>Konkretne daty:</strong> Możesz wykluczyć pojedyncze dni (np. 25 grudnia 2025) - wtedy tylko w tym konkretnym dniu nie będzie wyświetlane danie dnia.</li>
                <li>W wykluczone dni shortcode <code>[danie_dnia]</code> wyświetli komunikat o braku dania, a w <code>[menu_dnia]</code> nie pojawi się sekcja "Danie Dnia".</li>
            </ul>
        </div>
    </div>
    <?php
}

// Funkcja sprawdzająca czy dzień jest wykluczony
function mdr_czy_dzien_wykluczony($dzien_tygodnia = null, $data = null) {
    global $wpdb;
    $table_excluded = $wpdb->prefix . 'menu_dnia_wykluczone';
    
    if (!$dzien_tygodnia) {
        $dzien_tygodnia = date_i18n('l');
    }
    
    if (!$data) {
        $data = date('Y-m-d');
    }
    
    // Sprawdź czy dzień tygodnia jest wykluczony
    $dzien_wykluczony = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_excluded WHERE typ = 'dzien_tygodnia' AND wartosc = %s",
        $dzien_tygodnia
    ));
    
    if ($dzien_wykluczony > 0) {
        return true;
    }
    
    // Sprawdź czy konkretna data jest wykluczona
    $data_wykluczona = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_excluded WHERE typ = 'data' AND wartosc = %s",
        $data
    ));
    
    if ($data_wykluczona > 0) {
        return true;
    }
    
    return false;
}

// Strona główna panelu administracyjnego - POPRAWIONA
function mdr_admin_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'menu_dnia';
    $table_categories = $wpdb->prefix . 'menu_dnia_kategorie';
    
    // Sprawdź czy tabele istnieją
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
    $table_excluded = $wpdb->prefix . 'menu_dnia_wykluczone';
    $table_excluded_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_excluded'") == $table_excluded;
    $table_categories_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_categories'") == $table_categories;
    
    if (!$table_exists || !$table_excluded_exists || !$table_categories_exists) {
        ?>
        <div class="wrap">
            <h1>Menu Dnia - Problem z bazą danych</h1>
            <div class="notice notice-error">
                <p><strong>Błąd!</strong> Tabele w bazie danych nie istnieją lub są niekompletne.</p>
                <p>Przejdź do zakładki <strong>Narzędzia</strong> aby naprawić bazę danych, lub kliknij poniższy przycisk:</p>
            </div>
            <p>
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=menu-dnia&mdr_fix_database=1'), 'mdr_fix_database'); ?>" 
                   class="button button-primary button-hero">
                    🔧 Napraw Bazę Danych
                </a>
                <a href="<?php echo admin_url('admin.php?page=menu-dnia-tools'); ?>" class="button button-hero">
                    Przejdź do Narzędzi
                </a>
            </p>
        </div>
        <?php
        return;
    }
    
    // Pokaż komunikat po naprawieniu
    if (isset($_GET['db_fixed'])) {
        echo '<div class="notice notice-success is-dismissible"><p><strong>Sukces!</strong> Tabele zostały utworzone poprawnie!</p></div>';
    }
    
    // Obsługa usuwania
    if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id']) && check_admin_referer('mdr_delete_dish_' . intval($_GET['id']))) {
        $wpdb->delete($table_name, array('id' => intval($_GET['id'])), array('%d'));
        echo '<div class="notice notice-success is-dismissible"><p>Danie zostało usunięte!</p></div>';
    }
    
    // Obsługa zmiany dania dnia
    if (isset($_POST['ustaw_danie_dnia']) && check_admin_referer('mdr_set_dish_of_day', 'mdr_nonce_dish')) {
        $danie_id = intval($_POST['danie_id']);
        $dzien = sanitize_text_field($_POST['dzien_tygodnia']);
        
        // Wyczyść poprzednie danie dnia dla tego dnia
        $wpdb->update($table_name, 
            array('jest_daniem_dnia' => 0), 
            array('dzien_tygodnia' => $dzien),
            array('%d'),
            array('%s')
        );
        
        // Ustaw nowe danie dnia
        $wpdb->update($table_name, 
            array(
                'jest_daniem_dnia' => 1,
                'dzien_tygodnia' => $dzien
            ), 
            array('id' => $danie_id),
            array('%d', '%s'),
            array('%d')
        );
        
        echo '<div class="notice notice-success is-dismissible"><p>Danie dnia zostało zaktualizowane!</p></div>';
    }
    
    // Pobierz kategorie
    $kategorie = $wpdb->get_results("SELECT * FROM $table_categories ORDER BY kolejnosc, nazwa");
    
    // Filtrowanie po kategorii
    $filter_category = isset($_GET['filter_category']) ? sanitize_text_field($_GET['filter_category']) : '';
    
    // Pobierz dania
    if ($filter_category) {
        $dania = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE kategoria = %s ORDER BY nazwa_dania",
            $filter_category
        ));
    } else {
        $dania = $wpdb->get_results("SELECT * FROM $table_name ORDER BY kategoria, nazwa_dania");
    }
    
    $dni_tygodnia = array('Poniedziałek', 'Wtorek', 'Środa', 'Czwartek', 'Piątek', 'Sobota', 'Niedziela');
    ?>
    
    <div class="wrap">
        <h1>Menu Dnia - Zarządzanie 
            <a href="?page=menu-dnia-dodaj" class="page-title-action">Dodaj Nowe Danie</a>
        </h1>
        
        <!-- Filtr kategorii -->
        <div style="background: white; padding: 15px; margin: 20px 0; border: 1px solid #ccc; border-radius: 5px;">
            <strong>Filtruj po kategorii:</strong>
            <a href="?page=menu-dnia" class="button <?php echo !$filter_category ? 'button-primary' : ''; ?>">Wszystkie</a>
            <?php foreach ($kategorie as $kat): 
                $count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table_name WHERE kategoria = %s",
                    $kat->slug
                ));
            ?>
                <a href="?page=menu-dnia&filter_category=<?php echo urlencode($kat->slug); ?>" 
                   class="button <?php echo $filter_category == $kat->slug ? 'button-primary' : ''; ?>">
                    <?php echo esc_html($kat->ikona . ' ' . $kat->nazwa); ?> (<?php echo $count; ?>)
                </a>
            <?php endforeach; ?>
        </div>
        
        <!-- Szybkie ustawianie dania dnia -->
        <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ccc; border-radius: 5px;">
            <h2>Szybkie Ustawianie Dania Dnia</h2>
            <form method="post">
                <?php wp_nonce_field('mdr_set_dish_of_day', 'mdr_nonce_dish'); ?>
                <table class="form-table">
                    <tr>
                        <th>Wybierz dzień:</th>
                        <td>
                            <select name="dzien_tygodnia" required>
                                <?php foreach ($dni_tygodnia as $dzien): ?>
                                    <option value="<?php echo esc_attr($dzien); ?>"><?php echo esc_html($dzien); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Wybierz danie:</th>
                        <td>
                            <select name="danie_id" required style="min-width: 300px;">
                                <?php foreach ($dania as $danie): ?>
                                    <option value="<?php echo $danie->id; ?>">
                                        <?php echo esc_html($danie->nazwa_dania); ?> - <?php echo number_format($danie->cena, 2); ?> zł
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <input type="submit" name="ustaw_danie_dnia" class="button button-primary" value="Ustaw jako Danie Dnia">
            </form>
        </div>
        
        <!-- Lista wszystkich dań pogrupowana po kategoriach -->
        <h2>Lista Wszystkich Dań <span style="font-size: 14px; color: #666;">(Kliknij checkbox aby włączyć/wyłączyć wyświetlanie)</span></h2>
        
        <?php
        // Grupuj dania według kategorii
        $dania_grouped = array();
        foreach ($dania as $danie) {
            $dania_grouped[$danie->kategoria][] = $danie;
        }
        
        // Wyświetl dania pogrupowane
        foreach ($kategorie as $kat):
            if (!isset($dania_grouped[$kat->slug]) || empty($dania_grouped[$kat->slug])) {
                continue;
            }
            $dania_kategorii = $dania_grouped[$kat->slug];
        ?>
        
        <div class="mdr-category-section" style="margin-bottom: 30px;">
            <h3 style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px; border-radius: 5px; margin: 20px 0 0 0;">
                <span style="font-size: 24px; margin-right: 10px;"><?php echo esc_html($kat->ikona); ?></span>
                <?php echo esc_html($kat->nazwa); ?>
                <span style="font-size: 14px; opacity: 0.8; margin-left: 10px;">(<?php echo count($dania_kategorii); ?> dań)</span>
            </h3>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 50px;">ID</th>
                        <th>Nazwa Dania</th>
                        <th>Opis</th>
                        <th style="width: 120px;">Waga (g)</th>
                        <th style="width: 180px;">Alergeny</th>
                        <th style="width: 100px;">Cena</th>
                        <th style="width: 100px; text-align: center;">
                            <span title="Czy danie jest daniem dnia?">Danie Dnia</span>
                        </th>
                        <th style="width: 120px;">Dzień</th>
                        <th style="width: 100px; text-align: center;">
                            <span title="Czy danie jest widoczne w menu?">Widoczne</span>
                        </th>
                        <th style="width: 150px;">Akcje</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dania_kategorii as $danie): ?>
                    <tr>
                        <td><?php echo $danie->id; ?></td>
                        <td><strong><?php echo esc_html($danie->nazwa_dania); ?></strong></td>
                        <td><?php echo esc_html($danie->opis); ?></td>
                        <td><?php echo $danie->gramy ? number_format($danie->gramy, 0) . ' g' : '—'; ?></td>
                        <td><?php echo $danie->alergeny ? esc_html($danie->alergeny) : '—'; ?></td>
                        <td><?php echo number_format($danie->cena, 2); ?> zł</td>
                        <td style="text-align: center;">
                            <?php if ($danie->jest_daniem_dnia): ?>
                                <span style="color: green; font-size: 18px;" title="To danie jest obecnie daniem dnia dla dnia: <?php echo esc_attr($danie->dzien_tygodnia); ?>">✓</span>
                            <?php else: ?>
                                <span style="color: #ccc; font-size: 18px;">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($danie->dzien_tygodnia ?: '-'); ?></td>
                        <td style="text-align: center;">
                            <label class="mdr-toggle-switch" title="Kliknij aby włączyć/wyłączyć wyświetlanie">
                                <input type="checkbox" 
                                       class="mdr-quick-toggle" 
                                       data-id="<?php echo $danie->id; ?>"
                                       data-field="aktywne"
                                       <?php checked($danie->aktywne, 1); ?>>
                                <span class="mdr-slider"></span>
                            </label>
                        </td>
                        <td>
                            <a href="?page=menu-dnia-dodaj&edit=<?php echo $danie->id; ?>" class="button button-small">Edytuj</a>
                            <a href="<?php echo wp_nonce_url(
                                add_query_arg(array('page' => 'menu-dnia', 'action' => 'delete', 'id' => $danie->id), admin_url('admin.php')),
                                'mdr_delete_dish_' . $danie->id
                            ); ?>" 
                               class="button button-small" 
                               onclick="return confirm('Czy na pewno chcesz usunąć to danie?')">Usuń</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <?php endforeach; ?>
        
        <?php if (empty($dania)): ?>
            <div style="padding: 40px; text-align: center; background: white; border: 1px solid #ccc; border-radius: 5px;">
                <p style="font-size: 18px; color: #666;">Nie ma jeszcze żadnych dań w menu.</p>
                <p>
                    <a href="?page=menu-dnia-dodaj" class="button button-primary button-hero">Dodaj Pierwsze Danie</a>
                </p>
            </div>
        <?php endif; ?>
        
        <div style="margin-top: 20px; padding: 15px; background: #f0f0f1; border-left: 4px solid #2271b1;">
            <h3>📱 Jak używać wtyczki na stronie?</h3>
            <p><strong>Shortcode do wyświetlania pełnego menu:</strong></p>
            <p><code>[menu_dnia]</code> - wyświetla kompletne menu z wszystkimi daniami</p>
            
            <p><strong>Shortcode do wyświetlania tylko dania dnia:</strong></p>
            <p><code>[danie_dnia]</code> - wyświetla tylko danie dnia w ładnej ramce</p>
            
            <p><strong>Opcje shortcode [menu_dnia]:</strong></p>
            <ul>
                <li><code>[menu_dnia kategoria="pizza"]</code> - wyświetla tylko daną kategorię (użyj identyfikatora z zakładki Kategorie)</li>
                <li><code>[menu_dnia dzien="Piątek"]</code> - wyświetla menu na konkretny dzień</li>
                <li><code>[menu_dnia pokaz_danie_dnia="tak"]</code> - wyróżnia danie dnia</li>
            </ul>
        </div>
    </div>
    
    <style>
        .mdr-toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
            cursor: pointer;
        }
        
        .mdr-toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .mdr-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .3s;
            border-radius: 24px;
        }
        
        .mdr-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }
        
        .mdr-toggle-switch input:checked + .mdr-slider {
            background-color: #2271b1;
        }
        
        .mdr-toggle-switch input:checked + .mdr-slider:before {
            transform: translateX(26px);
        }
        
        .mdr-toggle-switch input:disabled + .mdr-slider {
            opacity: 0.5;
            cursor: not-allowed;
        }
    </style>
    
    <script>
    jQuery(document).ready(function($) {
        $('.mdr-quick-toggle').on('change', function() {
            var $checkbox = $(this);
            var dishId = $checkbox.data('id');
            var field = $checkbox.data('field');
            var value = $checkbox.is(':checked') ? 1 : 0;
            
            // Wyłącz checkbox podczas aktualizacji
            $checkbox.prop('disabled', true);
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'mdr_quick_update',
                    id: dishId,
                    field: field,
                    value: value,
                    nonce: '<?php echo wp_create_nonce('mdr_quick_update'); ?>'
                },
                success: function(response) {
                    $checkbox.prop('disabled', false);
                    if (response.success) {
                        // Pokaż krótkie potwierdzenie
                        var $row = $checkbox.closest('tr');
                        $row.css('background-color', '#d4edda');
                        setTimeout(function() {
                            $row.css('background-color', '');
                        }, 500);
                    } else {
                        alert('Wystąpił błąd: ' + (response.data || 'Nieznany błąd'));
                        $checkbox.prop('checked', !value);
                    }
                },
                error: function() {
                    $checkbox.prop('disabled', false);
                    alert('Wystąpił błąd połączenia');
                    $checkbox.prop('checked', !value);
                }
            });
        });
    });
    </script>
    <?php
}

// Strona dodawania/edycji dania
function mdr_add_dish_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'menu_dnia';
    $table_categories = $wpdb->prefix . 'menu_dnia_kategorie';
    
    // Sprawdź czy tabela istnieje
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
    $table_categories_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_categories'") == $table_categories;
    
    if (!$table_exists || !$table_categories_exists) {
        ?>
        <div class="wrap">
            <h1>Dodaj Danie - Problem z bazą danych</h1>
            <div class="notice notice-error">
                <p><strong>Błąd!</strong> Tabela dań nie istnieje w bazie danych.</p>
                <p>Przejdź do zakładki <strong>Narzędzia</strong> aby naprawić bazę danych, lub kliknij poniższy przycisk:</p>
            </div>
            <p>
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=menu-dnia&mdr_fix_database=1'), 'mdr_fix_database'); ?>" 
                   class="button button-primary button-hero">
                    🔧 Napraw Bazę Danych
                </a>
                <a href="<?php echo admin_url('admin.php?page=menu-dnia-tools'); ?>" class="button button-hero">
                    Przejdź do Narzędzi
                </a>
            </p>
        </div>
        <?php
        return;
    }
    
    $edit_mode = false;
    $danie = null;
    
    if (isset($_GET['edit'])) {
        $edit_mode = true;
        $id = intval($_GET['edit']);
        $danie = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id));
        
        if (!$danie) {
            echo '<div class="wrap"><div class="notice notice-error"><p>Nie znaleziono dania!</p></div></div>';
            return;
        }
    }
    
    // Pobierz kategorie
    $kategorie = $wpdb->get_results("SELECT * FROM $table_categories ORDER BY kolejnosc, nazwa");
    
    // Obsługa formularza
    if (isset($_POST['submit']) && check_admin_referer('mdr_save_dish', 'mdr_nonce_save')) {
        $gramy = isset($_POST['gramy']) && $_POST['gramy'] !== '' ? floatval($_POST['gramy']) : null;
        $alergeny = isset($_POST['alergeny']) ? sanitize_textarea_field($_POST['alergeny']) : '';

        $data = array(
            'nazwa_dania' => sanitize_text_field($_POST['nazwa_dania']),
            'opis' => sanitize_textarea_field($_POST['opis']),
            'cena' => floatval($_POST['cena']),
            'gramy' => $gramy,
            'alergeny' => $alergeny,
            'kategoria' => sanitize_text_field($_POST['kategoria']),
            'aktywne' => isset($_POST['aktywne']) ? 1 : 0
        );

        $format = array('%s', '%s', '%f', '%f', '%s', '%s', '%d');
        
        if ($edit_mode && isset($_GET['edit'])) {
            $result = $wpdb->update($table_name, $data, array('id' => intval($_GET['edit'])), $format, array('%d'));
            if ($result !== false) {
                echo '<div class="notice notice-success is-dismissible"><p>Danie zostało zaktualizowane! <a href="?page=menu-dnia">Wróć do listy dań</a></p></div>';
                $danie = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", intval($_GET['edit'])));
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>Błąd podczas aktualizacji: ' . $wpdb->last_error . '</p></div>';
            }
        } else {
            $result = $wpdb->insert($table_name, $data, $format);
            if ($result) {
                echo '<div class="notice notice-success is-dismissible"><p>Danie zostało dodane! <a href="?page=menu-dnia">Wróć do listy dań</a></p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>Błąd podczas dodawania: ' . $wpdb->last_error . '</p></div>';
            }
        }
    }
    ?>
    
    <div class="wrap">
        <h1><?php echo $edit_mode ? 'Edytuj Danie' : 'Dodaj Nowe Danie'; ?></h1>
        
        <form method="post">
            <?php wp_nonce_field('mdr_save_dish', 'mdr_nonce_save'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="nazwa_dania">Nazwa Dania*</label></th>
                    <td>
                        <input type="text" name="nazwa_dania" id="nazwa_dania" class="regular-text" 
                               value="<?php echo $edit_mode ? esc_attr($danie->nazwa_dania) : ''; ?>" required>
                        <p class="description">Pełna nazwa dania</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="opis">Opis</label></th>
                    <td>
                        <textarea name="opis" id="opis" rows="4" cols="50" class="large-text"><?php echo $edit_mode ? esc_textarea($danie->opis) : ''; ?></textarea>
                        <p class="description">Krótki opis dania, składniki (opcjonalnie)</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="gramy">Waga (g)</label></th>
                    <td>
                        <input type="number" name="gramy" id="gramy" step="1" min="0"
                               value="<?php echo ($edit_mode && $danie->gramy !== null) ? esc_attr($danie->gramy) : ''; ?>">
                        <p class="description">Podaj wagę porcji w gramach (opcjonalnie)</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="alergeny">Alergeny</label></th>
                    <td>
                        <textarea name="alergeny" id="alergeny" rows="3" cols="50" class="large-text"><?php echo $edit_mode ? esc_textarea($danie->alergeny) : ''; ?></textarea>
                        <p class="description">Wypisz alergeny obecne w daniu (np. gluten, orzechy)</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="cena">Cena (zł)*</label></th>
                    <td>
                        <input type="number" name="cena" id="cena" step="0.01" min="0"
                               value="<?php echo $edit_mode ? esc_attr($danie->cena) : ''; ?>" required>
                        <p class="description">Podaj cenę w złotych (np. 25.50)</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="kategoria">Kategoria*</label></th>
                    <td>
                        <select name="kategoria" id="kategoria" required>
                            <?php if (empty($kategorie)): ?>
                                <option value="inne">Inne</option>
                            <?php else: ?>
                                <?php foreach ($kategorie as $kat): ?>
                                    <option value="<?php echo esc_attr($kat->slug); ?>" 
                                            <?php echo ($edit_mode && $danie->kategoria == $kat->slug) ? 'selected' : ''; ?>>
                                        <?php echo esc_html($kat->ikona . ' ' . $kat->nazwa); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <p class="description">
                            Wybierz kategorię dla tego dania. 
                            <a href="?page=menu-dnia-kategorie" target="_blank">Zarządzaj kategoriami</a>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="aktywne">Widoczność</label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="aktywne" id="aktywne" value="1" 
                                   <?php echo (!$edit_mode || $danie->aktywne) ? 'checked' : ''; ?>>
                            Danie jest widoczne w menu
                        </label>
                        <p class="description">Odznacz, jeśli chcesz tymczasowo ukryć danie bez usuwania go</p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="submit" class="button button-primary" 
                       value="<?php echo $edit_mode ? 'Zaktualizuj Danie' : 'Dodaj Danie'; ?>">
                <a href="?page=menu-dnia" class="button">Anuluj</a>
            </p>
        </form>
    </div>
    <?php
}

// Shortcode do wyświetlania menu
add_shortcode('menu_dnia', 'mdr_display_menu');

function mdr_display_menu($atts) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'menu_dnia';
    $table_categories = $wpdb->prefix . 'menu_dnia_kategorie';
    
    $atts = shortcode_atts(array(
        'kategoria' => '',
        'dzien' => '',
        'pokaz_danie_dnia' => 'tak'
    ), $atts);
    
    $dzien_tygodnia = $atts['dzien'] ?: date_i18n('l');
    $data_dzisiaj = date_i18n('j F Y');
    $data_sql = date('Y-m-d');
    
    // Sprawdź czy dzień jest wykluczony
    $dzien_wykluczony = mdr_czy_dzien_wykluczony($dzien_tygodnia, $data_sql);
    
    // Pobierz danie dnia
    $danie_dnia = null;
    if ($atts['pokaz_danie_dnia'] == 'tak' && !$dzien_wykluczony) {
        $danie_dnia = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE jest_daniem_dnia = 1 AND dzien_tygodnia = %s AND aktywne = 1",
            $dzien_tygodnia
        ));
    }
    
    // Pobierz pozostałe dania
    $query = "SELECT * FROM $table_name WHERE aktywne = 1";
    if ($atts['kategoria']) {
        $query .= $wpdb->prepare(" AND kategoria = %s", $atts['kategoria']);
    }
    $query .= " ORDER BY kategoria, nazwa_dania";
    
    $dania = $wpdb->get_results($query);
    
    // Pobierz kategorie
    $kategorie = $wpdb->get_results("SELECT * FROM $table_categories ORDER BY kolejnosc, nazwa");
    
    ob_start();
    ?>
    <div class="mdr-menu-container">
        <style>
            .mdr-menu-container {
                padding: 20px;
                background: var(--theme-palette-color-8, var(--paletteColor8, #f9f9f9));
                border-radius: 8px;
                margin: 20px 0;
            }
            .mdr-danie-dnia {
                background: linear-gradient(135deg, var(--theme-palette-color-1, var(--paletteColor1, #8b7355)) 0%, var(--theme-palette-color-2, var(--paletteColor2, #6b5644)) 100%);
                color: var(--theme-palette-color-8, var(--paletteColor8, #fff));
                padding: 30px;
                border-radius: 8px;
                margin-bottom: 30px;
                text-align: center;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            }
            .mdr-danie-dnia h2 {
                color: var(--theme-palette-color-8, var(--paletteColor8, #fff));
                margin: 0 0 10px 0;
                font-size: 28px;
            }
            .mdr-danie-dnia .data {
                font-size: 16px;
                opacity: 0.9;
                margin-bottom: 15px;
                color: var(--theme-palette-color-7, var(--paletteColor7, #e8dcc8));
            }
            .mdr-danie-dnia .nazwa {
                font-size: 24px;
                font-weight: bold;
                margin: 15px 0;
                color: var(--theme-palette-color-7, var(--paletteColor7, #e8dcc8));
            }
            .mdr-danie-dnia .cena {
                font-size: 32px;
                font-weight: bold;
                margin: 20px 0;
                color: var(--theme-palette-color-6, var(--paletteColor6, #c9b896));
            }
            .mdr-kategoria {
                margin: 30px 0;
            }
            .mdr-kategoria h3 {
                color: var(--theme-headings-color, var(--headings-color, var(--theme-text-color, #1a1a1a)));
                border-bottom: 2px solid var(--theme-palette-color-1, var(--paletteColor1, #8b7355));
                padding-bottom: 10px;
                margin-bottom: 20px;
            }
            .mdr-kategoria h3 .ikona {
                font-size: 24px;
                margin-right: 8px;
            }
            .mdr-danie {
                background: var(--theme-palette-color-8, var(--paletteColor8, white));
                padding: 15px;
                margin: 10px 0;
                border-radius: 5px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                box-shadow: 0 2px 5px rgba(0,0,0,0.08);
                transition: transform 0.3s ease, box-shadow 0.3s ease;
                border-left: 3px solid transparent;
            }
            .mdr-danie:hover {
                transform: translateX(5px);
                box-shadow: 0 3px 10px rgba(0, 0, 0, 0.12);
                border-left-color: var(--theme-palette-color-1, var(--paletteColor1, #8b7355));
            }
            .mdr-danie-info {
                flex: 1;
            }
            .mdr-danie-nazwa {
                font-weight: bold;
                font-size: 18px;
                color: var(--theme-headings-color, var(--headings-color, var(--theme-text-color, #1a1a1a)));
            }
            .mdr-danie-opis {
                color: var(--theme-text-color, #666);
                margin-top: 5px;
                font-size: 14px;
                opacity: 0.8;
            }
            .mdr-danie-meta {
                margin-top: 6px;
                color: var(--theme-text-color, #666);
                font-size: 13px;
                opacity: 0.9;
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
            }
            .mdr-danie-meta .mdr-meta-item {
                background: var(--theme-palette-color-8, var(--paletteColor8, #f1f1f1));
                padding: 4px 8px;
                border-radius: 4px;
            }
            .mdr-danie-cena {
                font-size: 20px;
                font-weight: bold;
                color: var(--theme-palette-color-1, var(--paletteColor1, #8b7355));
                white-space: nowrap;
                margin-left: 20px;
            }
        </style>
        
        <?php if ($danie_dnia): ?>
        <div class="mdr-danie-dnia">
            <h2>🍽️ Danie Dnia - <?php echo esc_html($dzien_tygodnia); ?></h2>
            <div class="data"><?php echo esc_html($data_dzisiaj); ?></div>
            <div class="nazwa"><?php echo esc_html($danie_dnia->nazwa_dania); ?></div>
            <?php if ($danie_dnia->opis): ?>
                <div class="opis"><?php echo esc_html($danie_dnia->opis); ?></div>
            <?php endif; ?>
            <?php if ($danie_dnia->gramy || $danie_dnia->alergeny): ?>
                <div class="mdr-danie-meta">
                    <?php if ($danie_dnia->gramy): ?>
                        <span class="mdr-meta-item"><?php echo number_format($danie_dnia->gramy, 0); ?> g</span>
                    <?php endif; ?>
                    <?php if ($danie_dnia->alergeny): ?>
                        <span class="mdr-meta-item">Alergeny: <?php echo esc_html($danie_dnia->alergeny); ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="cena"><?php echo number_format($danie_dnia->cena, 2); ?> zł</div>
        </div>
        <?php endif; ?>
        
        <?php
        // Grupuj dania według kategorii
        $dania_grouped = array();
        foreach ($dania as $d) {
            $dania_grouped[$d->kategoria][] = $d;
        }
        
        foreach ($kategorie as $kat):
            if (!isset($dania_grouped[$kat->slug]) || empty($dania_grouped[$kat->slug])) {
                continue;
            }
            $dania_kategorii = $dania_grouped[$kat->slug];
        ?>
        <div class="mdr-kategoria">
            <h3>
                <span class="ikona"><?php echo esc_html($kat->ikona); ?></span>
                <?php echo esc_html($kat->nazwa); ?>
            </h3>
            <?php foreach ($dania_kategorii as $danie): ?>
                <?php if ($danie_dnia && $danie->id == $danie_dnia->id) continue; ?>
                <div class="mdr-danie">
                        <div class="mdr-danie-info">
                            <div class="mdr-danie-nazwa"><?php echo esc_html($danie->nazwa_dania); ?></div>
                            <?php if ($danie->opis): ?>
                                <div class="mdr-danie-opis"><?php echo esc_html($danie->opis); ?></div>
                            <?php endif; ?>
                            <?php if ($danie->gramy || $danie->alergeny): ?>
                                <div class="mdr-danie-meta">
                                    <?php if ($danie->gramy): ?>
                                        <span class="mdr-meta-item"><?php echo number_format($danie->gramy, 0); ?> g</span>
                                    <?php endif; ?>
                                    <?php if ($danie->alergeny): ?>
                                        <span class="mdr-meta-item">Alergeny: <?php echo esc_html($danie->alergeny); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="mdr-danie-cena"><?php echo number_format($danie->cena, 2); ?> zł</div>
                    </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

// Shortcode tylko dla dania dnia
add_shortcode('danie_dnia', 'mdr_display_danie_dnia');

function mdr_display_danie_dnia($atts) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'menu_dnia';
    
    $dzien_tygodnia = date_i18n('l');
    $data_dzisiaj = date_i18n('j F Y');
    $data_sql = date('Y-m-d');
    
    // Sprawdź czy dzień jest wykluczony
    if (mdr_czy_dzien_wykluczony($dzien_tygodnia, $data_sql)) {
        return '<div style="padding: 20px; text-align: center; background: #f0f0f1; border-radius: 8px; margin: 20px 0;">
                    <p style="font-size: 18px; color: #666;">Dzisiaj nie mamy dania dnia 😊</p>
                </div>';
    }
    
    $danie_dnia = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE jest_daniem_dnia = 1 AND dzien_tygodnia = %s AND aktywne = 1",
        $dzien_tygodnia
    ));
    
    if (!$danie_dnia) {
        return '<p>Brak dania dnia na dzisiaj.</p>';
    }
    
    ob_start();
    ?>
    <div class="mdr-danie-dnia-widget">
        <style>
            .mdr-danie-dnia-widget {
                background: linear-gradient(135deg, var(--theme-palette-color-1, var(--paletteColor1, #8b7355)) 0%, var(--theme-palette-color-2, var(--paletteColor2, #6b5644)) 100%);
                color: var(--theme-palette-color-8, var(--paletteColor8, #fff));
                padding: 25px;
                border-radius: 10px;
                text-align: center;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
                margin: 20px 0;
            }
            .mdr-danie-dnia-widget h3 {
                color: var(--theme-palette-color-8, var(--paletteColor8, #fff));
                margin: 0 0 10px 0;
                font-size: 22px;
            }
            .mdr-danie-dnia-widget .data {
                font-size: 15px;
                opacity: 0.9;
                margin-bottom: 15px;
                color: var(--theme-palette-color-7, var(--paletteColor7, #e8dcc8));
            }
            .mdr-danie-dnia-widget .nazwa {
                font-size: 20px;
                font-weight: bold;
                margin: 15px 0;
                color: var(--theme-palette-color-7, var(--paletteColor7, #e8dcc8));
            }
            .mdr-danie-dnia-widget .opis {
                margin: 10px 0;
                opacity: 0.95;
            }
            .mdr-danie-meta {
                margin: 12px 0 6px;
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                justify-content: center;
            }
            .mdr-danie-meta .mdr-meta-item {
                background: rgba(255, 255, 255, 0.12);
                padding: 6px 10px;
                border-radius: 4px;
                color: var(--theme-palette-color-8, var(--paletteColor8, #fff));
            }
            .mdr-danie-dnia-widget .cena {
                font-size: 28px;
                font-weight: bold;
                margin: 20px 0 10px 0;
                color: var(--theme-palette-color-6, var(--paletteColor6, #c9b896));
            }
        </style>
        
        <h3>🍽️ Danie Dnia</h3>
        <div class="data"><?php echo esc_html($data_dzisiaj); ?></div>
        <div class="nazwa"><?php echo esc_html($danie_dnia->nazwa_dania); ?></div>
        <?php if ($danie_dnia->opis): ?>
            <div class="opis"><?php echo esc_html($danie_dnia->opis); ?></div>
        <?php endif; ?>
        <?php if ($danie_dnia->gramy || $danie_dnia->alergeny): ?>
            <div class="mdr-danie-meta">
                <?php if ($danie_dnia->gramy): ?>
                    <span class="mdr-meta-item"><?php echo number_format($danie_dnia->gramy, 0); ?> g</span>
                <?php endif; ?>
                <?php if ($danie_dnia->alergeny): ?>
                    <span class="mdr-meta-item">Alergeny: <?php echo esc_html($danie_dnia->alergeny); ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="cena"><?php echo number_format($danie_dnia->cena, 2); ?> zł</div>
    </div>
    <?php
    return ob_get_clean();
}

// Dodaj style do panelu admina
add_action('admin_head', 'mdr_admin_styles');

function mdr_admin_styles() {
    ?>
    <style>
        .wp-list-table .column-id { width: 50px; }
        .wp-list-table .column-cena { width: 100px; }
        .wp-list-table .column-kategoria { width: 120px; }
        .wp-list-table .column-dzien { width: 120px; }
        .wp-list-table .column-aktywne { width: 80px; text-align: center; }
        .wp-list-table .column-jest_daniem_dnia { width: 100px; text-align: center; }
    </style>
    <?php
}

// Widget dla WordPress
add_action('widgets_init', 'mdr_register_widget');

function mdr_register_widget() {
    register_widget('MDR_Widget');
}

class MDR_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct(
            'mdr_widget',
            'Menu Dnia Widget',
            array('description' => 'Wyświetla menu restauracji lub danie dnia')
        );
    }
    
    public function widget($args, $instance) {
        echo $args['before_widget'];
        
        if (!empty($instance['title'])) {
            echo $args['before_title'] . apply_filters('widget_title', $instance['title']) . $args['after_title'];
        }
        
        if ($instance['typ'] == 'danie_dnia') {
            echo do_shortcode('[danie_dnia]');
        } else {
            echo do_shortcode('[menu_dnia]');
        }
        
        echo $args['after_widget'];
    }
    
    public function form($instance) {
        $title = !empty($instance['title']) ? $instance['title'] : 'Menu Dnia';
        $typ = !empty($instance['typ']) ? $instance['typ'] : 'menu';
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>">Tytuł:</label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" 
                   name="<?php echo esc_attr($this->get_field_name('title')); ?>" 
                   type="text" value="<?php echo esc_attr($title); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('typ')); ?>">Typ wyświetlania:</label>
            <select class="widefat" id="<?php echo esc_attr($this->get_field_id('typ')); ?>" 
                    name="<?php echo esc_attr($this->get_field_name('typ')); ?>">
                <option value="menu" <?php selected($typ, 'menu'); ?>>Pełne Menu</option>
                <option value="danie_dnia" <?php selected($typ, 'danie_dnia'); ?>>Tylko Danie Dnia</option>
            </select>
        </p>
        <?php
    }
    
    public function update($new_instance, $old_instance) {
        $instance = array();
        $instance['title'] = (!empty($new_instance['title'])) ? strip_tags($new_instance['title']) : '';
        $instance['typ'] = (!empty($new_instance['typ'])) ? strip_tags($new_instance['typ']) : 'menu';
        return $instance;
    }
}

// AJAX do szybkiej aktualizacji - POPRAWIONA
add_action('wp_ajax_mdr_quick_update', 'mdr_ajax_quick_update');

function mdr_ajax_quick_update() {
    // Sprawdź uprawnienia
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Brak uprawnień');
        return;
    }
    
    // Sprawdź nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mdr_quick_update')) {
        wp_send_json_error('Nieprawidłowy token bezpieczeństwa');
        return;
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'menu_dnia';
    
    $id = intval($_POST['id']);
    $field = sanitize_text_field($_POST['field']);
    $value = intval($_POST['value']);
    
    $allowed_fields = array('aktywne', 'jest_daniem_dnia');
    
    if (!in_array($field, $allowed_fields)) {
        wp_send_json_error('Nieprawidłowe pole');
        return;
    }
    
    $result = $wpdb->update(
        $table_name,
        array($field => $value),
        array('id' => $id),
        array('%d'),
        array('%d')
    );
    
    if ($result !== false) {
        wp_send_json_success('Zaktualizowano pomyślnie');
    } else {
        wp_send_json_error('Błąd aktualizacji: ' . $wpdb->last_error);
    }
}

// Eksport/Import menu
add_action('admin_init', 'mdr_handle_export_import');

function mdr_handle_export_import() {
    // Eksport
    if (isset($_GET['mdr_export']) && $_GET['mdr_export'] == '1' && current_user_can('manage_options')) {
        check_admin_referer('mdr_export_menu');
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'menu_dnia';
        $dania = $wpdb->get_results("SELECT * FROM $table_name", ARRAY_A);
        
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="menu_dnia_export_' . date('Y-m-d') . '.json"');
        echo json_encode($dania, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}