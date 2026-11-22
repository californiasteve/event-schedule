<?php
if (!defined('ABSPATH')) exit;

class CS_Admin {

    const META_DATE = '_csched_date';       // YYYY-MM-DD
    const META_START = '_csched_start';     // HH:MM (24h)
    const META_END = '_csched_end';         // HH:MM (24h)
    const META_ROOM = '_csched_room';       // string
    const META_EVENT = '_csched_event';     // event slug
    const META_PREVIOUS_DAY = '_csched_previous_day'; // 1|0
    const META_SCHEDULE = '_csched_schedule'; // schedule slug

    public static function init(): void {
        add_action('add_meta_boxes', [__CLASS__, 'add_meta_boxes']);
        add_action('save_post_' . CS_Plugin::CPT, [__CLASS__, 'save_meta'], 10, 2);

        add_filter('manage_' . CS_Plugin::CPT . '_posts_columns', [__CLASS__, 'columns']);
        add_action('manage_' . CS_Plugin::CPT . '_posts_custom_column', [__CLASS__, 'column_content'], 10, 2);
        add_filter('manage_edit-' . CS_Plugin::CPT . '_sortable_columns', [__CLASS__, 'sortable_columns']);
        add_action('pre_get_posts', [__CLASS__, 'admin_sorting']);

        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_post_csched_export_css', [__CLASS__, 'handle_export_css']);
        add_action('admin_post_csched_import_css', [__CLASS__, 'handle_import_css']);
        add_action('admin_post_csched_export_workshops_csv', [__CLASS__, 'handle_export_workshops_csv']);
        add_action('admin_post_csched_import_workshops_csv', [__CLASS__, 'handle_import_workshops_csv']);
        add_action('admin_post_csched_import_workshops_csv_mapped', [__CLASS__, 'handle_import_workshops_csv_mapped']);
        add_action('admin_notices', [__CLASS__, 'admin_notices']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
    }

    public static function enqueue_admin_assets(string $hook): void {
        // Only load on our settings page.
        if ($hook !== 'cs_workshop_page_csched-settings') return;

        wp_enqueue_script('jquery-ui-sortable');

        // Enable drag & drop ordering for the control order list.
        $js = <<<'JS'
jQuery(function($){
  var $list = $('#csched-control-order');
  if (!$list.length) return;

  $list.sortable({
    handle: '.csched-handle',
    update: function(){
      var order = [];
      $list.find('li').each(function(){
        order.push($(this).data('key'));
      });
      $('#CSCHED_CONTROL_ORDER_INPUT').val(order.join(','));
    }
  });
});
JS;

        $js = str_replace('CSCHED_CONTROL_ORDER_INPUT', esc_js(CS_Plugin::OPT_CONTROL_ORDER), $js);
        wp_add_inline_script('jquery-ui-sortable', $js);

        // Minimal admin styling for the sortable list.
        wp_register_style('csched-admin', false);
        wp_enqueue_style('csched-admin');
        $css = "#csched-control-order{max-width:520px;margin:8px 0 0;padding:0;}
"
             . "#csched-control-order li{list-style:none;border:1px solid #ccd0d4;border-radius:6px;background:#fff;padding:10px 12px;margin:0 0 8px;display:flex;align-items:center;gap:10px;}
"
             . ".csched-handle{cursor:move;color:#555;font-weight:700;}
";
        wp_add_inline_style('csched-admin', $css);
    }

    public static function add_meta_boxes(): void {
        add_meta_box(
            'csched_details',
            __('Workshop Details', 'convention-schedule'),
            [__CLASS__, 'render_metabox'],
            CS_Plugin::CPT,
            'normal',
            'high'
        );
    }

    public static function render_metabox(\WP_Post $post): void {
        wp_nonce_field('csched_save_meta', 'csched_nonce');

        $date  = get_post_meta($post->ID, CS_Plugin::META_DATE, true);
        $start = get_post_meta($post->ID, CS_Plugin::META_START, true);
        $end   = get_post_meta($post->ID, CS_Plugin::META_END, true);
        $room  = get_post_meta($post->ID, CS_Plugin::META_ROOM, true);
        $previous_day = (string)get_post_meta($post->ID, CS_Plugin::META_PREVIOUS_DAY, true);

        $date  = $date ? esc_attr($date) : '';
        $start = $start ? esc_attr($start) : '';
        $end   = $end ? esc_attr($end) : '';
        $room  = $room ? esc_attr($room) : '';

        echo '<p><label><strong>' . esc_html__('Date', 'convention-schedule') . '</strong></label><br>';
        echo '<input type="date" name="csched_date" value="' . $date . '" style="max-width: 220px; width:100%"></p>';

        echo '<p style="display:flex; gap:16px; flex-wrap:wrap;">';
        echo '<span><label><strong>' . esc_html__('Start Time', 'convention-schedule') . '</strong></label><br>';
        echo '<input type="time" name="csched_start" value="' . $start . '" style="max-width: 220px; width:100%"></span>';

        echo '<span><label><strong>' . esc_html__('End Time', 'convention-schedule') . '</strong></label><br>';
        echo '<input type="time" name="csched_end" value="' . $end . '" style="max-width: 220px; width:100%"></span>';
        echo '</p>';


        echo '<p>';
        echo '<label><input type="checkbox" name="csched_previous_day" value="1" ' . checked($previous_day, '1', false) . '> ';
        echo esc_html__('Display this workshop on the previous schedule day', 'convention-schedule') . '</label><br>';
        echo '<span class="description">' . esc_html__('Use this for after-midnight workshops that should appear under the prior day. Workshops inside the After-Midnight Grace Period (Settings) move there automatically; this box is for ones that start later.', 'convention-schedule') . '</span>';
        echo '</p>';

        $saved_rooms = self::get_saved_rooms();

        echo '<p><label><strong>' . esc_html__('Room', 'convention-schedule') . '</strong></label><br>';

        if (!empty($saved_rooms)) {
            echo '<select id="csched-room-select" style="max-width: 420px; width:100%; margin-bottom:8px;">';
            echo '<option value="">' . esc_html__('Select Saved Room', 'convention-schedule') . '</option>';
            foreach ($saved_rooms as $saved_room) {
                echo '<option value="' . esc_attr($saved_room) . '"' . selected($room, $saved_room, false) . '>' . esc_html($saved_room) . '</option>';
            }
            echo '</select><br>';
        } else {
            echo '<em>' . esc_html__('No saved rooms yet. Add rooms under Workshops → Settings → Saved Rooms.', 'convention-schedule') . '</em><br>';
        }

        echo '<input type="text" id="csched_room" name="csched_room" value="' . $room . '" placeholder="Room A / Main Hall / etc." style="max-width: 420px; width:100%" list="csched-room-list">';

        if (!empty($saved_rooms)) {
            echo '<datalist id="csched-room-list">';
            foreach ($saved_rooms as $saved_room) {
                echo '<option value="' . esc_attr($saved_room) . '">';
            }
            echo '</datalist>';
        }

        echo '</p>';

        echo '<script>
        document.addEventListener("DOMContentLoaded", function(){
            var sel = document.getElementById("csched-room-select");
            var inp = document.getElementById("csched_room");
            if (sel && inp) {
                sel.addEventListener("change", function(){
                    if (this.value) inp.value = this.value;
                });
            }
        });
        </script>';



        $schedule_terms = get_terms([
            'taxonomy' => CS_Plugin::SCHEDULE_TAX,
            'hide_empty' => false,
        ]);
        $current_schedule = (string)get_post_meta($post->ID, self::META_SCHEDULE, true);
        if ($current_schedule === '') {
            $existing_schedules = wp_get_post_terms($post->ID, CS_Plugin::SCHEDULE_TAX, ['fields' => 'slugs']);
            if (!is_wp_error($existing_schedules) && !empty($existing_schedules)) {
                $current_schedule = (string)$existing_schedules[0];
            }
        }

        echo '<p><label><strong>' . esc_html__('Schedule', 'convention-schedule') . '</strong></label><br>';
        echo '<select name="csched_schedule" style="max-width:420px;width:100%;">';
        echo '<option value="">' . esc_html__('Select Schedule', 'convention-schedule') . '</option>';
        if (!is_wp_error($schedule_terms) && !empty($schedule_terms)) {
            foreach ($schedule_terms as $schedule_term) {
                echo '<option value="' . esc_attr($schedule_term->slug) . '"' . selected($current_schedule, $schedule_term->slug, false) . '>' . esc_html($schedule_term->name) . '</option>';
            }
        }
        echo '</select></p>';
        echo '<p class="description">' . esc_html__('Manage schedules under Workshops → Schedules. Use the schedule slug in the shortcode to show this schedule on its own page, for example [convention_schedule schedule="2026-convention"].', 'convention-schedule') . '</p>';


        echo '<p style="margin-top:10px; color:#555;">' .
             esc_html__('Assign categories in the “Schedule Categories” box (right side).', 'convention-schedule') .
             '</p>';
    }

    public static function save_meta(int $post_id, \WP_Post $post): void {
        if (!isset($_POST['csched_nonce']) || !wp_verify_nonce($_POST['csched_nonce'], 'csched_save_meta')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if ($post->post_type !== CS_Plugin::CPT) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $date  = isset($_POST['csched_date']) ? sanitize_text_field($_POST['csched_date']) : '';
        $start = isset($_POST['csched_start']) ? sanitize_text_field($_POST['csched_start']) : '';
        $end   = isset($_POST['csched_end']) ? sanitize_text_field($_POST['csched_end']) : '';
        $room  = isset($_POST['csched_room']) ? sanitize_text_field($_POST['csched_room']) : '';
        $schedule = isset($_POST['csched_schedule']) ? sanitize_title(wp_unslash($_POST['csched_schedule'])) : '';
        $previous_day = isset($_POST['csched_previous_day']) ? '1' : '0';

        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = '';
        if ($start !== '' && !preg_match('/^\d{2}:\d{2}$/', $start)) $start = '';
        if ($end !== '' && !preg_match('/^\d{2}:\d{2}$/', $end)) $end = '';

        update_post_meta($post_id, CS_Plugin::META_DATE, $date);
        update_post_meta($post_id, CS_Plugin::META_START, $start);
        update_post_meta($post_id, CS_Plugin::META_END, $end);
        update_post_meta($post_id, CS_Plugin::META_ROOM, $room);
        update_post_meta($post_id, CS_Plugin::META_PREVIOUS_DAY, $previous_day);

        $old_schedules = wp_get_object_terms($post_id, CS_Plugin::SCHEDULE_TAX, ['fields' => 'slugs']);
        $old_schedule = (!is_wp_error($old_schedules) && !empty($old_schedules)) ? (string)$old_schedules[0] : (string)get_post_meta($post_id, CS_Plugin::META_SCHEDULE, true);

        update_post_meta($post_id, CS_Plugin::META_SCHEDULE, $schedule);
        if ($schedule !== '') {
            wp_set_object_terms($post_id, [$schedule], CS_Plugin::SCHEDULE_TAX, false);
        } else {
            wp_set_object_terms($post_id, [], CS_Plugin::SCHEDULE_TAX, false);
        }
        // Legacy Schedule Events are no longer edited here. Old event data is left alone so that
        // old event="" pages keep working, unless the schedule was deliberately changed: then the
        // workshop follows its new schedule and drops off the old event's page.
        if ($schedule !== $old_schedule) {
            wp_set_object_terms($post_id, [], CS_Plugin::EVENT_TAX, false);
            delete_post_meta($post_id, CS_Plugin::META_EVENT);
        }
    }

    public static function columns(array $cols): array {
        $new = [];
        foreach ($cols as $k => $v) {
            $new[$k] = $v;
            if ($k === 'title') {
                $new['cs_date']  = __('Date', 'convention-schedule');
                $new['cs_time']  = __('Time', 'convention-schedule');
                $new['cs_room']  = __('Room', 'convention-schedule');
                $new['cs_schedule_col'] = __('Schedule', 'convention-schedule');
                $new['cs_prev_day'] = __('Previous Day', 'convention-schedule');
            }
        }
        return $new;
    }

    public static function column_content(string $col, int $post_id): void {
        if ($col === 'cs_date') {
            echo esc_html(get_post_meta($post_id, CS_Plugin::META_DATE, true));
        } elseif ($col === 'cs_time') {
            $s = get_post_meta($post_id, CS_Plugin::META_START, true);
            $e = get_post_meta($post_id, CS_Plugin::META_END, true);
            $out = trim($s . ($e ? '–' . $e : ''));
            echo esc_html($out);
        } elseif ($col === 'cs_room') {
            echo esc_html(get_post_meta($post_id, CS_Plugin::META_ROOM, true));
        }
    }

    public static function sortable_columns(array $cols): array {
        $cols['cs_date'] = 'cs_date';
        $cols['cs_time'] = 'cs_time';
        return $cols;
    }

    public static function admin_sorting(\WP_Query $q): void {
        if (!is_admin() || !$q->is_main_query()) return;
        if ($q->get('post_type') !== CS_Plugin::CPT) return;

        $orderby = $q->get('orderby');

        if ($orderby === 'cs_date') {
            $q->set('meta_key', CS_Plugin::META_DATE);
            $q->set('orderby', 'meta_value');
            $q->set('meta_type', 'DATE');
        } elseif ($orderby === 'cs_time') {
            $q->set('meta_key', CS_Plugin::META_START);
            $q->set('orderby', 'meta_value');
        } else {
            $q->set('meta_query', [
                'cs_date_clause' => [
                    'key' => CS_Plugin::META_DATE,
                    'compare' => 'EXISTS',
                ],
                'cs_time_clause' => [
                    'key' => CS_Plugin::META_START,
                    'compare' => 'EXISTS',
                ],
            ]);
            $q->set('orderby', [
                'cs_date_clause' => 'ASC',
                'cs_time_clause' => 'ASC',
            ]);
        }
    }

    public static function admin_menu(): void {
        add_submenu_page(
            'edit.php?post_type=' . CS_Plugin::CPT,
            __('Schedule Settings', 'convention-schedule'),
            __('Settings', 'convention-schedule'),
            'manage_options',
            'csched-settings',
            [__CLASS__, 'render_settings_page']
        );
add_submenu_page(
            'edit.php?post_type=' . CS_Plugin::CPT,
            __('Setup & Shortcodes', 'convention-schedule'),
            __('Setup & Shortcodes', 'convention-schedule'),
            'manage_options',
            'csched-setup-usage',
            [__CLASS__, 'render_setup_usage_page']
        );
    }

    public static function register_settings(): void {
        register_setting('csched_settings', CS_Plugin::OPT_CSS, [
            'type' => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitize_css'],
            'default' => CS_Plugin::default_css(),
        ]);

        $simple = function($v){ return sanitize_text_field((string)$v); };

        register_setting('csched_settings', CS_Plugin::OPT_LAYOUT, ['type'=>'string','sanitize_callback'=>$simple,'default'=>'table']);
        register_setting('csched_settings', CS_Plugin::OPT_HEADING, ['type'=>'string','sanitize_callback'=>$simple,'default'=>'']);
        register_setting('csched_settings', CS_Plugin::OPT_SHOW_HEADING, ['type'=>'string','sanitize_callback'=>$simple,'default'=>'1']);
        register_setting('csched_settings', CS_Plugin::OPT_TIME_FMT, ['type'=>'string','sanitize_callback'=>$simple,'default'=>'24']);
        register_setting('csched_settings', CS_Plugin::OPT_SHOW_DOW, ['type'=>'string','sanitize_callback'=>$simple,'default'=>'0']);
        register_setting('csched_settings', CS_Plugin::OPT_SHOW_CONTROLS, ['type'=>'string','sanitize_callback'=>$simple,'default'=>'1']);
        register_setting('csched_settings', CS_Plugin::OPT_SHOW_VIEW_SWITCHER, ['type'=>'string','sanitize_callback'=>$simple,'default'=>'1']);
        register_setting('csched_settings', CS_Plugin::OPT_SHOW_RESET, ['type'=>'string','sanitize_callback'=>$simple,'default'=>'1']);
        register_setting('csched_settings', CS_Plugin::OPT_SHOW_SEARCH, ['type'=>'string','sanitize_callback'=>$simple,'default'=>'1']);
        register_setting('csched_settings', CS_Plugin::OPT_GROUP_BY, ['type'=>'string','sanitize_callback'=>$simple,'default'=>'date']);
        register_setting('csched_settings', CS_Plugin::OPT_DAY_CUTOFF, ['type'=>'string','sanitize_callback'=>[__CLASS__,'sanitize_cutoff'],'default'=>'01:00']);
        register_setting('csched_settings', CS_Plugin::OPT_DAY_EXTENSION, [
            'type' => 'integer',
            'sanitize_callback' => function($v){ return max(0, min(720, (int)$v)); },
            'default' => 0,
        ]);

        register_setting('csched_settings', CS_Plugin::OPT_CONTROL_ORDER, [
            'type' => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitize_control_order'],
            'default' => 'category,group,room,view',
        ]);

        register_setting('csched_settings', CS_Plugin::OPT_W_CAT, ['type'=>'string','sanitize_callback'=>$simple,'default'=>'15']);
        register_setting('csched_settings', CS_Plugin::OPT_W_GROUP, ['type'=>'string','sanitize_callback'=>$simple,'default'=>'15']);
        register_setting('csched_settings', CS_Plugin::OPT_W_ROOM, ['type'=>'string','sanitize_callback'=>$simple,'default'=>'15']);
    
        register_setting('csched_settings', CS_Plugin::OPT_SCROLL_CURRENT, [
            'type' => 'string',
            'sanitize_callback' => function($v){ return ((string)$v === '0') ? '0' : '1'; },
            'default' => '1',
        ]);

        register_setting('csched_settings', CS_Plugin::OPT_PAST_GRACE, [
            'type' => 'integer',
            'sanitize_callback' => [__CLASS__, 'sanitize_past_grace'],
            'default' => 30,
        ]);

        register_setting('csched_settings', CS_Plugin::OPT_SAVED_ROOMS, [
            'type' => 'string',
            'sanitize_callback' => [__CLASS__, 'sanitize_saved_rooms'],
            'default' => '',
        ]);


        register_setting('csched_settings', CS_Plugin::OPT_CATEGORY_COLORS, [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize_category_colors'],
            'default' => [],
        ]);


        register_setting('csched_settings', CS_Plugin::OPT_TABLE_BAR_WIDTH, [
            'type' => 'integer',
            'sanitize_callback' => [__CLASS__, 'sanitize_table_bar_width'],
            'default' => 25,
        ]);

}

    public static function sanitize_control_order($val): string {
        $allowed = ['schedule','category','group','room','view','search'];
        $raw = is_string($val) ? $val : '';
        // "event" was retired in 1.9.0; treat it as "schedule".
        $parts = array_map(function($p){ return $p === 'event' ? 'schedule' : $p; }, array_filter(array_map('trim', explode(',', strtolower($raw)))));
        $out = [];
        foreach ($parts as $p) {
            if (!in_array($p, $allowed, true)) continue;
            if (in_array($p, $out, true)) continue;
            $out[] = $p;
        }
        // Ensure all keys present
        foreach ($allowed as $k) {
            if (!in_array($k, $out, true)) $out[] = $k;
        }
        return implode(',', $out);
    }

    public static function sanitize_css($css): string {
        return trim((string)$css);
    }


    public static function sanitize_cutoff($val): string {
        // Accept HH:MM (24h) such as 01:00 or 01:30; default to 01:00 if invalid.
        $val = is_string($val) ? trim($val) : '';
        if ($val === '') return '01:00';

        // Allow "H:MM" -> normalize to "HH:MM"
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $val, $m)) {
            $h = (int)$m[1];
            $min = (int)$m[2];
            if ($h < 0 || $h > 23) return '01:00';
            if ($min < 0 || $min > 59) return '01:00';
            return sprintf('%02d:%02d', $h, $min);
        }
        return '01:00';
    }


public static function admin_notices(): void {
    if (current_user_can('manage_options') && get_option(CS_Plugin::OPT_MIGRATION_NOTICE) === '1') {
        $r = get_option(CS_Plugin::OPT_MIGRATION_REPORT, []);
        if (!is_array($r)) $r = [];
        $moved = (int)($r['workshops_moved'] ?? 0);
        $created = (int)($r['schedules_created'] ?? 0);
        $kept = (int)($r['kept_existing'] ?? 0);
        echo '<div class="notice notice-info is-dismissible"><p><strong>' . esc_html__('Convention Schedule: Schedule Events have been merged into Schedules.', 'convention-schedule') . '</strong> ';
        echo esc_html(sprintf(
            /* translators: 1: workshops moved, 2: schedules created, 3: workshops kept on their existing schedule */
            __('%1$d workshop(s) moved to a Schedule, %2$d Schedule(s) created from Schedule Events, %3$d workshop(s) kept their existing Schedule.', 'convention-schedule'),
            $moved, $created, $kept
        ));
        echo ' ' . esc_html__('Old event="" shortcodes keep working.', 'convention-schedule') . '</p></div>';
        delete_option(CS_Plugin::OPT_MIGRATION_NOTICE);
    }

    if (!isset($_GET['csched_msg'])) return;
    if (!current_user_can('manage_options')) return;

    $msg = sanitize_text_field((string)$_GET['csched_msg']);
    if ($msg === 'css_imported') {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('CSS imported successfully.', 'convention-schedule') . '</p></div>';
    } elseif ($msg === 'css_export_failed') {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('CSS export failed.', 'convention-schedule') . '</p></div>';
    } elseif ($msg === 'css_import_failed') {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('CSS import failed. Please upload a valid .css file.', 'convention-schedule') . '</p></div>';
    } elseif ($msg === 'css_import_empty') {
        echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('CSS file was empty. Nothing was changed.', 'convention-schedule') . '</p></div>';
    }
}

public static function handle_export_css(): void {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to export CSS.', 'convention-schedule'));
    }
    check_admin_referer('csched_export_css');

    $css = (string) get_option(CS_Plugin::OPT_CSS, CS_Plugin::default_css());
    $css = (string) apply_filters('csched_export_css', $css);

    $filename = 'csched-custom.css';
    nocache_headers();
    header('Content-Type: text/css; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    header('X-Content-Type-Options: nosniff');

    echo $css;
    exit;
}

public static function handle_import_css(): void {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to import CSS.', 'convention-schedule'));
    }
    check_admin_referer('csched_import_css');

    if (empty($_FILES['csched_css_file']) || !is_array($_FILES['csched_css_file'])) {
        wp_safe_redirect(add_query_arg('csched_msg', 'css_import_failed', wp_get_referer() ?: admin_url()));
        exit;
    }

    $f = $_FILES['csched_css_file'];

    if (!empty($f['error'])) {
        wp_safe_redirect(add_query_arg('csched_msg', 'css_import_failed', wp_get_referer() ?: admin_url()));
        exit;
    }

    $name = isset($f['name']) ? (string)$f['name'] : '';
    $tmp  = isset($f['tmp_name']) ? (string)$f['tmp_name'] : '';

    if (!$tmp || !file_exists($tmp)) {
        wp_safe_redirect(add_query_arg('csched_msg', 'css_import_failed', wp_get_referer() ?: admin_url()));
        exit;
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext !== 'css') {
        wp_safe_redirect(add_query_arg('csched_msg', 'css_import_failed', wp_get_referer() ?: admin_url()));
        exit;
    }

    $contents = file_get_contents($tmp);
    if ($contents === false) {
        wp_safe_redirect(add_query_arg('csched_msg', 'css_import_failed', wp_get_referer() ?: admin_url()));
        exit;
    }

    $contents = trim((string)$contents);
    if ($contents === '') {
        wp_safe_redirect(add_query_arg('csched_msg', 'css_import_empty', wp_get_referer() ?: admin_url()));
        exit;
    }

    $contents = (string) apply_filters('csched_import_css', $contents);

    update_option(CS_Plugin::OPT_CSS, $contents);

    wp_safe_redirect(add_query_arg('csched_msg', 'css_imported', wp_get_referer() ?: admin_url()));
    exit;
}

    
    public static function sanitize_past_grace($v): int {
        $v = is_numeric($v) ? (int)$v : 30;
        if ($v < 0) $v = 0;
        if ($v > 1440) $v = 1440;
        return $v;
    }


    public static function sanitize_saved_rooms($v): string {
        $lines = preg_split('/\r\n|\r|\n/', (string)$v);
        $clean = [];
        foreach ($lines as $line) {
            $line = trim(preg_replace('/\s+/', ' ', (string)$line));
            if ($line === '') continue;
            $clean[$line] = true;
        }
        $out = array_keys($clean);
        natcasesort($out);
        return implode("\n", $out);
    }

    public static function get_saved_rooms(): array {
        $raw = (string)get_option(CS_Plugin::OPT_SAVED_ROOMS, '');
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $rooms = [];
        foreach ($lines as $line) {
            $line = trim(preg_replace('/\s+/', ' ', (string)$line));
            if ($line === '') continue;
            $rooms[$line] = true;
        }
        $out = array_keys($rooms);
        natcasesort($out);
        return array_values($out);
    }


    public static function sanitize_category_colors($v): array {
        $out = [];
        if (!is_array($v)) {
            return $out;
        }

        foreach ($v as $slug => $hex) {
            $raw_slug = (string) $slug;

            // Checkbox fields named "{slug}_clear" should remove that category color.
            if (str_ends_with($raw_slug, '_clear')) {
                continue;
            }

            if (isset($v[$raw_slug . '_clear']) && (string) $v[$raw_slug . '_clear'] === '1') {
                continue;
            }

            $slug = sanitize_title($raw_slug);
            $hex = sanitize_hex_color((string) $hex);

            if ($slug !== '' && $hex) {
                $out[$slug] = $hex;
            }
        }

        return $out;
    }


    public static function sanitize_table_bar_width($v): int {
        $v = is_numeric($v) ? (int)$v : 25;
        if ($v < 1) $v = 1;
        if ($v > 100) $v = 100;
        return $v;
    }

public static function render_settings_page(): void {
        if (!current_user_can('manage_options')) return;

        $css = (string) get_option(CS_Plugin::OPT_CSS, CS_Plugin::default_css());
        $layout = (string) get_option(CS_Plugin::OPT_LAYOUT, 'table');
        $heading = (string) get_option(CS_Plugin::OPT_HEADING, '');
        $show_heading = (string) get_option(CS_Plugin::OPT_SHOW_HEADING, '1');
        $time_fmt = (string) get_option(CS_Plugin::OPT_TIME_FMT, '24');
        $show_dow = (string) get_option(CS_Plugin::OPT_SHOW_DOW, '0');
        $show_controls = (string) get_option(CS_Plugin::OPT_SHOW_CONTROLS, '1');
        $show_view_switcher = (string) get_option(CS_Plugin::OPT_SHOW_VIEW_SWITCHER, '1');
        $show_reset = (string) get_option(CS_Plugin::OPT_SHOW_RESET, '1');
        $show_search = (string) get_option(CS_Plugin::OPT_SHOW_SEARCH, '1');
        $group_by = (string) get_option(CS_Plugin::OPT_GROUP_BY, 'date');
        $day_cutoff = (string) get_option(CS_Plugin::OPT_DAY_CUTOFF, '01:00');

        $control_order = (string) get_option(CS_Plugin::OPT_CONTROL_ORDER, 'category,group,room,view,search');

        $w_cat = (string) get_option(CS_Plugin::OPT_W_CAT, '15');
        $w_group = (string) get_option(CS_Plugin::OPT_W_GROUP, '15');
        $w_room = (string) get_option(CS_Plugin::OPT_W_ROOM, '15');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Schedule Settings', 'convention-schedule'); ?></h1>

<?php
$notices = [];

if (!empty($_GET['csched_css_exported'])) {
    $notices[] = ['success', __('CSS exported.', 'convention-schedule')];
}
if (!empty($_GET['csched_css_imported'])) {
    $notices[] = ['success', __('CSS imported.', 'convention-schedule')];
}
if (!empty($_GET['csched_import_done'])) {
    $c = isset($_GET['created']) ? (int)$_GET['created'] : 0;
    $u = isset($_GET['updated']) ? (int)$_GET['updated'] : 0;
    $s = isset($_GET['skipped']) ? (int)$_GET['skipped'] : 0;
    $notices[] = ['success', sprintf(__('Workshops CSV imported. Created: %d, Updated: %d, Skipped: %d.', 'convention-schedule'), $c, $u, $s)];
}
if (!empty($_GET['csched_import_error'])) {
    if ($_GET['csched_import_error'] === 'expired') {
        $notices[] = ['error', __('That CSV import expired (imports wait one hour for column matching). Please upload the file again.', 'convention-schedule')];
    } else {
        $notices[] = ['error', __('Workshops CSV import failed. Please verify the file and try again.', 'convention-schedule')];
    }
}

foreach ($notices as [$type, $msg]) {
    echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($msg) . '</p></div>';
}

if (!empty($_GET['csched_map_cancel'])) {
    delete_transient(self::import_transient_key(sanitize_text_field(wp_unslash((string)$_GET['csched_map_cancel']))));
    echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__('CSV import cancelled. Nothing was imported.', 'convention-schedule') . '</p></div>';
}

// CSV column matching step replaces the settings form until it is finished or cancelled.
if (!empty($_GET['csched_map'])) {
    $csched_token = sanitize_text_field(wp_unslash((string)$_GET['csched_map']));
    if (self::render_import_mapping_screen($csched_token)) {
        echo '</div>';
        return;
    }
    echo '<div class="notice notice-error"><p>' . esc_html__('That CSV import expired (imports wait one hour for column matching). Please upload the file again.', 'convention-schedule') . '</p></div>';
}
?>

            <form method="post" action="options.php">
                <?php settings_fields('csched_settings'); ?>

                <h2><?php echo esc_html__('Display Defaults', 'convention-schedule'); ?></h2>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(CS_Plugin::OPT_LAYOUT); ?>"><?php echo esc_html__('Default Layout', 'convention-schedule'); ?></label></th>
                        <td>
                            <select name="<?php echo esc_attr(CS_Plugin::OPT_LAYOUT); ?>" id="<?php echo esc_attr(CS_Plugin::OPT_LAYOUT); ?>">
                                <option value="table" <?php selected($layout, 'table'); ?>>Table</option>
                                <option value="cards" <?php selected($layout, 'cards'); ?>>Cards</option>
                                <option value="grid" <?php selected($layout, 'grid'); ?>>Grid by Time Blocks</option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php echo esc_html__('Heading', 'convention-schedule'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(CS_Plugin::OPT_SHOW_HEADING); ?>" value="1" <?php checked($show_heading, '1'); ?>>
                                <?php echo esc_html__('Show heading above schedule', 'convention-schedule'); ?>
                            </label>
                            <br><br>
                            <input type="text" name="<?php echo esc_attr(CS_Plugin::OPT_HEADING); ?>" value="<?php echo esc_attr($heading); ?>" class="regular-text" placeholder="Event Schedule">
                            <p class="description"><?php echo esc_html__('Leave blank to use shortcode heading="" or no heading.', 'convention-schedule'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(CS_Plugin::OPT_TIME_FMT); ?>"><?php echo esc_html__('Time Format (Front-End)', 'convention-schedule'); ?></label></th>
                        <td>
                            <select name="<?php echo esc_attr(CS_Plugin::OPT_TIME_FMT); ?>" id="<?php echo esc_attr(CS_Plugin::OPT_TIME_FMT); ?>">
                                <option value="24" <?php selected($time_fmt, '24'); ?>>24-hour (14:30)</option>
                                <option value="12" <?php selected($time_fmt, '12'); ?>>12-hour (2:30 PM)</option>
                            </select>
                        </td>
                    </tr>



                    <tr>
                        <th scope="row"><?php echo esc_html__('Day of Week', 'convention-schedule'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(CS_Plugin::OPT_SHOW_DOW); ?>" value="1" <?php checked($show_dow, '1'); ?>>
                                <?php echo esc_html__('Show day of week in date headings', 'convention-schedule'); ?>
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(CS_Plugin::OPT_DAY_EXTENSION); ?>"><?php echo esc_html__('After-Midnight Grace Period', 'convention-schedule'); ?></label></th>
                        <td>
                            <?php $csched_ext = (int)get_option(CS_Plugin::OPT_DAY_EXTENSION, 0); ?>
                            <select id="<?php echo esc_attr(CS_Plugin::OPT_DAY_EXTENSION); ?>" name="<?php echo esc_attr(CS_Plugin::OPT_DAY_EXTENSION); ?>">
                                <option value="0" <?php selected($csched_ext, 0); ?>><?php echo esc_html__('Off: each day ends at midnight', 'convention-schedule'); ?></option>
                                <?php foreach ([30, 60, 90, 120, 150, 180, 240, 300, 360] as $m):
                                    $label = sprintf(
                                        /* translators: %s: time such as 2:00 AM */
                                        __('Extend each day until %s', 'convention-schedule'),
                                        gmdate('g:i A', $m * 60)
                                    ); ?>
                                    <option value="<?php echo (int)$m; ?>" <?php selected($csched_ext, $m); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description" style="max-width:640px;"><?php echo esc_html__('Workshops that start after midnight but before this time are shown at the end of the previous day, as a continuation of it. Example: with "until 2:00 AM", a dance at 12:01 AM Saturday is listed last under Friday. Its time still shows as 12:01 AM. Individual workshops can also be moved with the "Display this workshop on the previous schedule day" box.', 'convention-schedule'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php echo esc_html__('Top Controls', 'convention-schedule'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(CS_Plugin::OPT_SHOW_CONTROLS); ?>" value="1" <?php checked($show_controls, '1'); ?>>
                                <?php echo esc_html__('Show Category / Group By / Room controls above schedule', 'convention-schedule'); ?>
                            </label>
                            <br>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(CS_Plugin::OPT_SHOW_VIEW_SWITCHER); ?>" value="1" <?php checked($show_view_switcher, '1'); ?>>
                                <?php echo esc_html__('Allow users to switch view (Table / Cards / Grid)', 'convention-schedule'); ?>
                            </label>
                            <br>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(CS_Plugin::OPT_SHOW_RESET); ?>" value="1" <?php checked($show_reset, '1'); ?>>
                                <?php echo esc_html__('Show “Reset Filters” button', 'convention-schedule'); ?>
                            </label>
                            <br>
                            <label>
                                <input type="hidden" name="<?php echo esc_attr(CS_Plugin::OPT_SHOW_SEARCH); ?>" value="0">
                                <input type="checkbox" name="<?php echo esc_attr(CS_Plugin::OPT_SHOW_SEARCH); ?>" value="1" <?php checked($show_search, '1'); ?>>
                                <?php echo esc_html__('Show Title Search box', 'convention-schedule'); ?>
                            </label>


                            <br><br>
                            <label><strong><?php echo esc_html__('Control Order (Drag & Drop)', 'convention-schedule'); ?></strong></label>
                            <p class="description"><?php echo esc_html__('Reorder the front-end dropdowns (Schedule, Category, Group By, Room, View, Search). Apply/Reset buttons stay at the end.', 'convention-schedule'); ?></p>

                            <?php
                            $allowed = ['schedule' => __('Schedule', 'convention-schedule'), 'category' => __('Category', 'convention-schedule'), 'group' => __('Group By', 'convention-schedule'), 'room' => __('Room', 'convention-schedule'), 'view' => __('View', 'convention-schedule'), 'search' => __('Search', 'convention-schedule')];
                            $keys = array_filter(array_map('trim', explode(',', $control_order)));
                            $keys = array_values(array_unique(array_intersect($keys, array_keys($allowed))));
                            foreach (array_keys($allowed) as $k) { if (!in_array($k, $keys, true)) $keys[] = $k; }
                            ?>
                            <input type="hidden" name="<?php echo esc_attr(CS_Plugin::OPT_CONTROL_ORDER); ?>" id="<?php echo esc_attr(CS_Plugin::OPT_CONTROL_ORDER); ?>" value="<?php echo esc_attr(implode(',', $keys)); ?>">
                            <ul id="csched-control-order">
                                <?php foreach ($keys as $k): ?>
                                    <li data-key="<?php echo esc_attr($k); ?>">
                                        <span class="csched-handle">≡</span>
                                        <span><?php echo esc_html($allowed[$k]); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <br><br>
                            <label for="<?php echo esc_attr(CS_Plugin::OPT_GROUP_BY); ?>"><strong><?php echo esc_html__('Default Group By', 'convention-schedule'); ?></strong></label><br>
                            <select name="<?php echo esc_attr(CS_Plugin::OPT_GROUP_BY); ?>" id="<?php echo esc_attr(CS_Plugin::OPT_GROUP_BY); ?>">
                                <option value="date" <?php selected($group_by, 'date'); ?>>Date</option>
                                <option value="category" <?php selected($group_by, 'category'); ?>>Category</option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php echo esc_html__('Dropdown Widths', 'convention-schedule'); ?></th>
                        <td>
                            <p class="description"><?php echo esc_html__('These set CSS variables used by the top controls. Valid range: 5–50 (%).', 'convention-schedule'); ?></p>
                            <label>Category Width (%)</label><br>
                            <input type="number" min="5" max="50" step="1" name="<?php echo esc_attr(CS_Plugin::OPT_W_CAT); ?>" value="<?php echo esc_attr($w_cat); ?>" style="width:90px;">
                            <br><br>
                            <label>Group By Width (%)</label><br>
                            <input type="number" min="5" max="50" step="1" name="<?php echo esc_attr(CS_Plugin::OPT_W_GROUP); ?>" value="<?php echo esc_attr($w_group); ?>" style="width:90px;">
                            <br><br>
                            <label>Room Width (%)</label><br>
                            <input type="number" min="5" max="50" step="1" name="<?php echo esc_attr(CS_Plugin::OPT_W_ROOM); ?>" value="<?php echo esc_attr($w_room); ?>" style="width:90px;">
                            <p class="description"><?php echo esc_html__('Theme developers can override: --csched-dd-width-cat, --csched-dd-width-group, --csched-dd-width-room', 'convention-schedule'); ?></p>
                        </td>
                    </tr>
                </table>

                
                
                
                <h2><?php echo esc_html__('Schedule-specific shortcode examples', 'convention-schedule'); ?></h2>
                <p><?php echo esc_html__('Create Schedules under Workshops → Schedules, assign each workshop to a Schedule, then place separate shortcodes on separate pages.', 'convention-schedule'); ?></p>
                <p><code>[convention_schedule schedule="2026-convention"]</code></p>
                <p><code>[convention_schedule schedule="2026-convention" show_schedule_filter="0"]</code></p>
                <p><code>[convention_schedule show_schedule_filter="1"]</code></p>


                <h2><?php echo esc_html__('Category Color Bars', 'convention-schedule'); ?></h2>
                <p><?php echo esc_html__('Choose a color for each schedule category. The color appears as a small bar on table rows, cards, and grid items.', 'convention-schedule'); ?></p>

                <?php
                $csched_category_colors = get_option(CS_Plugin::OPT_CATEGORY_COLORS, []);
                if (!is_array($csched_category_colors)) $csched_category_colors = [];
                $csched_terms = get_terms([
                    'taxonomy' => CS_Plugin::TAX,
                    'hide_empty' => false,
                ]);
                ?>

                
                <p style="margin-top:16px;">
                    <label>
                        <strong><?php echo esc_html__('Table Category Bar Width (%)', 'convention-schedule'); ?></strong><br>
                        <input
                            type="number"
                            min="1"
                            max="100"
                            step="1"
                            name="<?php echo esc_attr(CS_Plugin::OPT_TABLE_BAR_WIDTH); ?>"
                            value="<?php echo esc_attr((string)get_option(CS_Plugin::OPT_TABLE_BAR_WIDTH, 25)); ?>"
                            style="width:100px;"
                        >
                    </label>
                    <br><span class="description"><?php echo esc_html__('Controls the category color bar width in table view only. Cards and grid remain full width.', 'convention-schedule'); ?></span>
                </p>

<?php if (!is_wp_error($csched_terms) && !empty($csched_terms)): ?>
                    <table class="widefat striped" style="max-width:760px;">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__('Category', 'convention-schedule'); ?></th>
                                <th><?php echo esc_html__('Slug', 'convention-schedule'); ?></th>
                                <th><?php echo esc_html__('Color', 'convention-schedule'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($csched_terms as $csched_term): ?>
                                <?php
                                $csched_slug = $csched_term->slug;
                                $csched_color = isset($csched_category_colors[$csched_slug]) ? $csched_category_colors[$csched_slug] : '';
                                ?>
                                <tr>
                                    <td><?php echo esc_html($csched_term->name); ?></td>
                                    <td><code><?php echo esc_html($csched_slug); ?></code></td>
                                    <td>
                                        <input
                                            type="color"
                                            name="<?php echo esc_attr(CS_Plugin::OPT_CATEGORY_COLORS); ?>[<?php echo esc_attr($csched_slug); ?>]"
                                            value="<?php echo esc_attr($csched_color ?: '#666666'); ?>"
                                        >
                                        <label style="margin-left:8px;">
                                            <input
                                                type="checkbox"
                                                name="<?php echo esc_attr(CS_Plugin::OPT_CATEGORY_COLORS); ?>[<?php echo esc_attr($csched_slug); ?>_clear]"
                                                value="1"
                                            >
                                            <?php echo esc_html__('Clear', 'convention-schedule'); ?>
                                        </label>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p><?php echo esc_html__('No schedule categories found yet. Add categories first, then return here to assign colors.', 'convention-schedule'); ?></p>
                <?php endif; ?>


                <h2><?php echo esc_html__('Saved Rooms', 'convention-schedule'); ?></h2>
                <p><?php echo esc_html__('Add one room per line. These rooms populate the Room dropdown on the Add/Edit Workshop page.', 'convention-schedule'); ?></p>

                <textarea
                    name="<?php echo esc_attr(CS_Plugin::OPT_SAVED_ROOMS); ?>"
                    rows="12"
                    style="width:100%;max-width:700px;font-family:monospace;"
                ><?php echo esc_textarea((string)get_option(CS_Plugin::OPT_SAVED_ROOMS, '')); ?></textarea>


                <h2><?php echo esc_html__('Current Day / Time Behavior', 'convention-schedule'); ?></h2>
                <p><?php echo esc_html__('These options affect the public schedule display. Past events are detected using the browser clock and each workshop date/time.', 'convention-schedule'); ?></p>
                <input type="hidden" name="<?php echo esc_attr(CS_Plugin::OPT_SCROLL_CURRENT); ?>" value="0">
                <p><label><input type="checkbox" name="<?php echo esc_attr(CS_Plugin::OPT_SCROLL_CURRENT); ?>" value="1" <?php checked(get_option(CS_Plugin::OPT_SCROLL_CURRENT, '1'), '1'); ?>> <?php echo esc_html__('Scroll to current day/time on page load', 'convention-schedule'); ?></label></p>
                <p><label><strong><?php echo esc_html__('Past Event Grace Period (minutes)', 'convention-schedule'); ?></strong><br><input type="number" min="0" max="1440" step="1" name="<?php echo esc_attr(CS_Plugin::OPT_PAST_GRACE); ?>" value="<?php echo esc_attr((string)get_option(CS_Plugin::OPT_PAST_GRACE, 30)); ?>" style="width:120px;"></label><br><span class="description"><?php echo esc_html__('Example: 30 means an event is not blurred until 30 minutes after its end time. If no end time is set, the start time is used.', 'convention-schedule'); ?></span></p>
<h2><?php echo esc_html__('Custom CSS', 'convention-schedule'); ?></h2>
                <p><?php echo esc_html__('This CSS is output on the front-end after the base styles.', 'convention-schedule'); ?></p>

                <textarea name="<?php echo esc_attr(CS_Plugin::OPT_CSS); ?>" rows="14" style="width:100%; max-width: 100%; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;"><?php
                    echo esc_textarea($css);
                ?></textarea>

<?php submit_button(__('Save Settings', 'convention-schedule')); ?>
            </form>

<h2><?php echo esc_html__('CSS Import / Export', 'convention-schedule'); ?></h2>
<p><?php echo esc_html__('Export your current custom CSS to a .css file, or import a .css file to replace the custom CSS stored in settings.', 'convention-schedule'); ?></p>

<div style="display:flex; gap:16px; flex-wrap:wrap; align-items:flex-start; margin: 10px 0 18px;">
    <div>
        <a class="button button-secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=csched_export_css'), 'csched_export_css')); ?>">
            <?php echo esc_html__('Export CSS (.css)', 'convention-schedule'); ?>
        </a>
    </div>

    <div style="min-width: 320px;">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <input type="hidden" name="action" value="csched_import_css">
            <?php wp_nonce_field('csched_import_css'); ?>
            <input type="file" name="csched_css_file" accept=".css,text/css" required>
            <button type="submit" class="button button-secondary"><?php echo esc_html__('Import CSS', 'convention-schedule'); ?></button>
        </form>
        <p class="description" style="margin-top:6px;"><?php echo esc_html__('Import replaces the Custom CSS field above. Recommended: export first for backup.', 'convention-schedule'); ?></p>
    </div>
</div>

<h2><?php echo esc_html__('Workshops CSV Import/Export', 'convention-schedule'); ?></h2>
<p><?php echo esc_html__('Export all workshops to CSV, or import from CSV to create/update workshops.', 'convention-schedule'); ?></p>

<div style="display:flex; gap:18px; flex-wrap:wrap; align-items:flex-start; margin: 12px 0 18px;">
    <div style="min-width: 320px;">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="csched_export_workshops_csv">
            <?php wp_nonce_field('csched_export_workshops_csv'); ?>
            <button type="submit" class="button button-secondary"><?php echo esc_html__('Export Workshops CSV', 'convention-schedule'); ?></button>
        </form>
        <p class="description" style="margin-top:6px;"><?php echo esc_html__('Exports: id, title, description, date, start, end, room, categories (slugs separated by |), schedule (slug), previous_day (1/0).', 'convention-schedule'); ?></p>
    </div>

    <div style="min-width: 320px;">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <input type="hidden" name="action" value="csched_import_workshops_csv">
            <?php wp_nonce_field('csched_import_workshops_csv'); ?>
            <input type="file" name="csched_workshops_csv_file" accept=".csv,text/csv,.txt" required>
            <button type="submit" class="button button-secondary"><?php echo esc_html__('Import Workshops CSV', 'convention-schedule'); ?></button>
            <label style="flex-basis:100%;"><input type="checkbox" name="csched_review_columns" value="1"> <?php echo esc_html__('Let me review how columns are matched before importing', 'convention-schedule'); ?></label>
        </form>
        <p class="description" style="margin-top:6px;"><?php echo esc_html__('Import creates new workshops; if an existing id is provided, that workshop is updated. Schedules and categories are created if missing. If your file\'s column names don\'t match, you\'ll be asked which field each column goes into before anything is imported. If the file has no schedule column, you\'ll choose an existing schedule or create a new one.', 'convention-schedule'); ?></p>
    </div>
</div>

        </div>
        <?php
    }

    public static function handle_export_workshops_csv(): void {
    if (!current_user_can('manage_options')) wp_die(__('Unauthorized', 'convention-schedule'));
    check_admin_referer('csched_export_workshops_csv');

    $filename = 'csched-workshops-' . date('Y-m-d') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);

    $out = fopen('php://output', 'w');

    // UTF-8 BOM for Excel compatibility
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($out, ['id', 'title', 'description', 'date', 'start', 'end', 'room', 'categories', 'schedule', 'previous_day']);

    $q = new WP_Query([
        'post_type' => CS_Plugin::CPT,
        'post_status' => ['publish', 'draft', 'pending', 'private'],
        'posts_per_page' => -1,
        'orderby' => 'ID',
        'order' => 'ASC',
        'no_found_rows' => true,
    ]);

    foreach ($q->posts as $p) {
        $date  = (string) get_post_meta($p->ID, CS_Plugin::META_DATE, true);
        $start = (string) get_post_meta($p->ID, CS_Plugin::META_START, true);
        $end   = (string) get_post_meta($p->ID, CS_Plugin::META_END, true);
        $room  = (string) get_post_meta($p->ID, CS_Plugin::META_ROOM, true);

        $terms = get_the_terms($p->ID, CS_Plugin::TAX);
        $slugs = [];
        if (!is_wp_error($terms) && !empty($terms)) {
            foreach ($terms as $t) $slugs[] = $t->slug;
        }
        $cats = implode('|', $slugs);


        $schedule_terms = get_the_terms($p->ID, CS_Plugin::SCHEDULE_TAX);
        $schedule_slug = '';
        if (!is_wp_error($schedule_terms) && !empty($schedule_terms)) {
            $schedule_slug = (string)$schedule_terms[0]->slug;
        }
        if ($schedule_slug === '') {
            $schedule_slug = (string)get_post_meta($p->ID, CS_Plugin::META_SCHEDULE, true);
        }

        fputcsv($out, [
            (int)$p->ID,
            (string)$p->post_title,
            (string)$p->post_content,
            $date,
            $start,
            $end,
            $room,
            $cats,
            $schedule_slug,
            ((string)get_post_meta($p->ID, CS_Plugin::META_PREVIOUS_DAY, true) === '1') ? '1' : '0',
        ]);
    }

    fclose($out);
    exit;
}


private static function normalize_import_date(string $date): string {
    $date = trim($date);
    if ($date === '') return '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return $date;

    $ts = strtotime($date);
    if ($ts) return date('Y-m-d', $ts);

    $formats = ['n/j/y','n/j/Y','m/d/y','m/d/Y','Y/m/d','Y.n.j','n-j-y','n-j-Y'];
    foreach ($formats as $fmt) {
        $dt = \DateTime::createFromFormat($fmt, $date);
        if ($dt instanceof \DateTime) return $dt->format('Y-m-d');
    }
    return '';
}

private static function normalize_import_time(string $time): string {
    $time = trim($time);
    if ($time === '') return '';
    $time = preg_replace('/\s+/', ' ', $time);

    if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
        $h = (int)$m[1];
        $min = (int)$m[2];
        if ($h < 0 || $h > 23 || $min < 0 || $min > 59) return '';
        return sprintf('%02d:%02d', $h, $min);
    }

    if (preg_match('/^(\d{1,2})(?::(\d{2}))?\s*(am|pm)$/i', $time, $m)) {
        $h = (int)$m[1];
        $min = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 0;
        $ampm = strtolower($m[3]);

        if ($h < 1 || $h > 12 || $min < 0 || $min > 59) return '';
        if ($ampm === 'pm' && $h !== 12) $h += 12;
        if ($ampm === 'am' && $h === 12) $h = 0;

        return sprintf('%02d:%02d', $h, $min);
    }

    try {
        $dt = new \DateTime($time);
        return $dt->format('H:i');
    } catch (\Exception $e) {
        return '';
    }
}

/**
 * Fields a CSV column can be matched to. Keys are the canonical header names used by export.
 */
private static function import_fields(): array {
    return [
        'id'           => __('Workshop ID (updates an existing workshop)', 'convention-schedule'),
        'title'        => __('Title (required)', 'convention-schedule'),
        'description'  => __('Description', 'convention-schedule'),
        'date'         => __('Date', 'convention-schedule'),
        'start'        => __('Start time', 'convention-schedule'),
        'end'          => __('End time', 'convention-schedule'),
        'time_range'   => __('Time range, e.g. "9:00 AM - 10:30 AM" (start and end in one column)', 'convention-schedule'),
        'room'         => __('Room', 'convention-schedule'),
        'categories'   => __('Categories (separated by | , or ;)', 'convention-schedule'),
        'schedule'     => __('Schedule', 'convention-schedule'),
        'previous_day' => __('Show on previous day (1/0, yes/no)', 'convention-schedule'),
    ];
}

/** Header spellings recognised automatically for each field (compared lowercase, letters/digits only). */
private static function import_synonyms(): array {
    return [
        'id'           => ['id', 'workshopid', 'postid', 'sessionid'],
        'title'        => ['title', 'name', 'workshop', 'workshopname', 'workshoptitle', 'session', 'sessionname', 'sessiontitle', 'topic', 'subject'],
        'description'  => ['description', 'desc', 'details', 'summary', 'abstract', 'notes', 'content', 'body'],
        'date'         => ['date', 'day', 'sessiondate', 'workshopdate', 'eventdate'],
        'start'        => ['start', 'starttime', 'begin', 'begins', 'begintime', 'timestart', 'from'],
        'end'          => ['end', 'endtime', 'finish', 'ends', 'finishtime', 'timeend', 'to', 'until'],
        'time_range'   => ['time', 'times', 'timeslot', 'slot', 'timerange', 'hours'],
        'room'         => ['room', 'location', 'venue', 'place', 'roomname', 'hall', 'where'],
        'categories'   => ['categories', 'category', 'cats', 'cat', 'type', 'types', 'tags', 'track', 'tracks'],
        'schedule'     => ['schedule', 'schedulename', 'scheduleslug', 'event', 'convention', 'conference'],
        'previous_day' => ['previousday', 'prevday', 'showonpreviousday', 'previous'],
    ];
}

private static function normalize_header(string $h): string {
    $h = (string)preg_replace('/^\xEF\xBB\xBF/', '', $h);
    return strtolower(trim($h));
}

/**
 * Read an uploaded CSV into rows. Handles the UTF-8 BOM, Windows-1252 files from Excel,
 * and comma, semicolon or tab delimiters.
 *
 * @return array|null [rows including the header row, delimiter]
 */
private static function read_csv_rows(string $raw): ?array {
    $raw = (string)preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    if (trim($raw) === '') return null;
    if (function_exists('mb_check_encoding') && !mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }

    // Pick the delimiter that splits the header line into the most columns.
    $first = (string)strtok($raw, "\r\n");
    $delim = ',';
    $best = 0;
    foreach ([',', ';', "\t"] as $d) {
        $n = count(str_getcsv($first, $d, '"', '\\'));
        if ($n > $best) { $best = $n; $delim = $d; }
    }

    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $rows = [];
    while (($row = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
        if (!is_array($row)) continue;
        $rows[] = array_map(function($v){ return $v === null ? '' : (string)$v; }, $row);
    }
    fclose($fh);
    return empty($rows) ? null : [$rows, $delim];
}

/**
 * Exact match: every column header is a field name this plugin exports (or the legacy "event").
 * Returns field => column index, or null when the file needs the matching screen.
 */
private static function exact_column_map(array $header): ?array {
    $known = array_merge(array_keys(self::import_fields()), ['event']);
    $map = [];
    foreach ($header as $i => $h) {
        $h = self::normalize_header((string)$h);
        if ($h === '') continue;
        if (!in_array($h, $known, true) || isset($map[$h])) return null;
        $map[$h] = (int)$i;
    }
    return isset($map['title']) ? $map : null;
}

/** Best guess for the matching screen: column index => field ('' = don't import). */
private static function guess_column_map(array $header): array {
    $syn = self::import_synonyms();
    // Score every (column, field) pair: exact field name beats a synonym, and earlier
    // synonyms in the list beat later ones. Then assign best scores first, one column per field.
    $cands = [];
    foreach ($header as $i => $h) {
        $raw = self::normalize_header((string)$h);
        $norm = (string)preg_replace('/[^a-z0-9]/', '', $raw);
        if ($norm === '') continue;
        // "Workshop #", "Session No.", "Session Number", "Workshop ID" are IDs, not titles.
        if (preg_match('/(#|\bno\.?$|\bnumber$|\bid$)/', $raw)) {
            $cands[] = [0, (int)$i, 'id'];
            continue;
        }
        foreach ($syn as $field => $words) {
            if ($norm === str_replace('_', '', $field)) { $cands[] = [0, (int)$i, $field]; continue; }
            $pos = array_search($norm, $words, true);
            if ($pos !== false) $cands[] = [1 + (int)$pos, (int)$i, $field];
        }
    }
    usort($cands, function($x, $y){ return ($x[0] <=> $y[0]) ?: ($x[1] <=> $y[1]); });
    $guess = [];
    $taken = [];
    foreach ($cands as [$score, $i, $field]) {
        if (isset($guess[$i]) || isset($taken[$field])) continue;
        $guess[$i] = $field;
        $taken[$field] = true;
    }
    foreach ($header as $i => $h) if (!isset($guess[$i])) $guess[$i] = '';
    ksort($guess);
    return $guess;
}

private static function import_transient_key(string $token): string {
    return 'csched_imp_' . get_current_user_id() . '_' . preg_replace('/[^A-Za-z0-9]/', '', $token);
}

private static function settings_url(array $args = []): string {
    return add_query_arg($args, admin_url('edit.php?post_type=' . CS_Plugin::CPT . '&page=csched-settings'));
}

private static function redirect_import_done(array $result): void {
    wp_safe_redirect(self::settings_url([
        'csched_import_done' => 1,
        'created' => (int)$result['created'],
        'updated' => (int)$result['updated'],
        'skipped' => (int)$result['skipped'],
    ]));
    exit;
}

/**
 * Step 1: upload. Files whose headers already match import straight away; anything else
 * (or when "review column matching" is ticked) goes to the column-matching screen.
 */
public static function handle_import_workshops_csv(): void {
    if (!current_user_can('manage_options')) wp_die(__('Unauthorized', 'convention-schedule'));
    check_admin_referer('csched_import_workshops_csv');

    if (empty($_FILES['csched_workshops_csv_file']['tmp_name'])) {
        self::redirect_settings_notice('csched_import_error', 'missing_file');
    }
    $file = $_FILES['csched_workshops_csv_file'];
    if (!empty($file['error'])) {
        self::redirect_settings_notice('csched_import_error', 'upload_error');
    }

    $raw = @file_get_contents($file['tmp_name']);
    if ($raw === false) {
        self::redirect_settings_notice('csched_import_error', 'read_error');
    }
    $parsed = self::read_csv_rows((string)$raw);
    if (!$parsed) {
        self::redirect_settings_notice('csched_import_error', 'bad_header');
    }
    [$rows, $delim] = $parsed;
    $header = $rows[0];

    $force_review = !empty($_POST['csched_review_columns']);
    $exact = self::exact_column_map($header);
    $exact_has_schedule = $exact !== null && (isset($exact['schedule']) || isset($exact['event']));
    if (!$force_review && $exact_has_schedule) {
        self::redirect_import_done(self::run_import(array_slice($rows, 1), $exact));
    }

    // Needs the matching screen (columns don't match, no schedule column, or review requested).
    // Keep the parsed file for this user for one hour.
    $data = [
        'rows' => $rows,
        'filename' => sanitize_file_name((string)($file['name'] ?? 'import.csv')),
        'delimiter' => $delim,
        'reason' => $exact !== null ? ($exact_has_schedule ? 'review' : 'no_schedule') : 'mismatch',
    ];
    if ($exact !== null) {
        // Headers already match: pre-fill the screen with them (legacy "event" -> schedule).
        $chosen = array_fill(0, count($header), '');
        foreach ($exact as $field => $i) $chosen[$i] = $field === 'event' ? (isset($exact['schedule']) ? '' : 'schedule') : $field;
        $data['chosen'] = $chosen;
    }
    $token = wp_generate_password(16, false, false);
    set_transient(self::import_transient_key($token), $data, HOUR_IN_SECONDS);

    wp_safe_redirect(self::settings_url(['csched_map' => $token]));
    exit;
}

/** Step 2: the column-matching form was submitted. */
public static function handle_import_workshops_csv_mapped(): void {
    if (!current_user_can('manage_options')) wp_die(__('Unauthorized', 'convention-schedule'));
    check_admin_referer('csched_import_workshops_csv_mapped');

    $token = isset($_POST['csched_map']) ? sanitize_text_field(wp_unslash((string)$_POST['csched_map'])) : '';
    $data = $token !== '' ? get_transient(self::import_transient_key($token)) : false;
    if (!is_array($data) || empty($data['rows'])) {
        self::redirect_settings_notice('csched_import_error', 'expired');
    }

    $fields = self::import_fields();
    $posted = (isset($_POST['csched_col']) && is_array($_POST['csched_col'])) ? wp_unslash($_POST['csched_col']) : [];
    $header = $data['rows'][0];
    $map = [];
    $chosen = [];
    $dupes = [];
    foreach ($header as $i => $h) {
        $field = isset($posted[$i]) ? sanitize_key((string)$posted[$i]) : '';
        if ($field === '' || !isset($fields[$field])) { $chosen[$i] = ''; continue; }
        $chosen[$i] = $field;
        if (isset($map[$field])) { $dupes[$field] = true; continue; }
        $map[$field] = (int)$i;
    }

    // No column holds the schedule: the user picks one schedule for all rows (or a new one).
    $target = isset($_POST['csched_target_schedule']) ? sanitize_text_field(wp_unslash((string)$_POST['csched_target_schedule'])) : '';
    $new_name = isset($_POST['csched_new_schedule']) ? sanitize_text_field(wp_unslash((string)$_POST['csched_new_schedule'])) : '';
    $default_schedule = '';

    $error = '';
    if (!isset($map['title'])) $error = 'no_title';
    elseif (!empty($dupes)) $error = 'duplicate';
    elseif (!isset($map['schedule'])) {
        if ($target === '') {
            $error = 'no_schedule';
        } elseif ($target === '__new__') {
            if ($new_name === '' || sanitize_title($new_name) === '') {
                $error = 'no_schedule_name';
            } else {
                $default_schedule = self::find_or_create_schedule($new_name);
                if ($default_schedule === '') $error = 'no_schedule_name';
            }
        } elseif ($target !== '__none__') {
            $term = get_term_by('slug', sanitize_title($target), CS_Plugin::SCHEDULE_TAX);
            if ($term) $default_schedule = (string)$term->slug; else $error = 'no_schedule';
        }
    }

    if ($error !== '') {
        // Keep the choices so the screen re-opens as the user left it.
        $data['chosen'] = $chosen;
        $data['target'] = $target;
        $data['new_name'] = $new_name;
        set_transient(self::import_transient_key($token), $data, HOUR_IN_SECONDS);
        wp_safe_redirect(self::settings_url(['csched_map' => $token, 'csched_map_error' => $error]));
        exit;
    }

    delete_transient(self::import_transient_key($token));
    self::redirect_import_done(self::run_import(array_slice($data['rows'], 1), $map, $default_schedule));
}

/** Return the slug of the schedule with this name (or slug), creating it if needed. '' on failure. */
private static function find_or_create_schedule(string $name): string {
    $slug = sanitize_title($name);
    if ($slug === '') return '';
    $term = get_term_by('slug', $slug, CS_Plugin::SCHEDULE_TAX);
    if (!$term) $term = get_term_by('name', $name, CS_Plugin::SCHEDULE_TAX);
    if ($term) return (string)$term->slug;
    $created = wp_insert_term($name, CS_Plugin::SCHEDULE_TAX, ['slug' => $slug]);
    if (is_wp_error($created)) {
        $existing = $created->get_error_data('term_exists');
        $term = $existing ? get_term((int)$existing, CS_Plugin::SCHEDULE_TAX) : null;
        return ($term && !is_wp_error($term)) ? (string)$term->slug : '';
    }
    $term = get_term((int)$created['term_id'], CS_Plugin::SCHEDULE_TAX);
    return ($term && !is_wp_error($term)) ? (string)$term->slug : '';
}

/** The column-matching screen (shown on the Settings page when ?csched_map= is present). */
private static function render_import_mapping_screen(string $token): bool {
    $data = get_transient(self::import_transient_key($token));
    if (!is_array($data) || empty($data['rows'])) return false;

    $rows = $data['rows'];
    $header = $rows[0];
    $samples = array_slice($rows, 1, 3);
    $count = max(0, count($rows) - 1);
    $fields = self::import_fields();
    $chosen = (isset($data['chosen']) && is_array($data['chosen'])) ? $data['chosen'] : self::guess_column_map($header);
    $err = isset($_GET['csched_map_error']) ? sanitize_key((string)$_GET['csched_map_error']) : '';
    ?>
    <?php
    $reason = (string)($data['reason'] ?? 'mismatch');
    $target = (string)($data['target'] ?? '');
    $new_name = (string)($data['new_name'] ?? '');
    $schedule_terms = get_terms(['taxonomy' => CS_Plugin::SCHEDULE_TAX, 'hide_empty' => false]);
    if (is_wp_error($schedule_terms)) $schedule_terms = [];
    // With no schedules yet, "new schedule" is the natural default.
    if ($target === '' && empty($schedule_terms)) $target = '__new__';
    ?>
    <h2><?php echo esc_html($reason === 'no_schedule' ? __('Choose a Schedule for This Import', 'convention-schedule') : __('Match CSV Columns', 'convention-schedule')); ?></h2>
    <?php if ($reason === 'no_schedule'): ?>
        <p style="max-width:820px;"><strong><?php echo esc_html__('Your file has no Schedule column. Choose the schedule these workshops belong to below. The columns have already been matched.', 'convention-schedule'); ?></strong></p>
    <?php endif; ?>
    <p style="max-width:820px;">
        <?php echo esc_html(sprintf(
            /* translators: 1: file name, 2: number of rows */
            __('Choose which workshop field each column of %1$s goes into, then import. We filled in our best guesses. %2$d row(s) will be imported.', 'convention-schedule'),
            (string)$data['filename'], $count
        )); ?>
    </p>
    <?php if ($err === 'no_title'): ?>
        <div class="notice notice-error inline"><p><?php echo esc_html__('Choose the column that holds the workshop title. Title is required.', 'convention-schedule'); ?></p></div>
    <?php elseif ($err === 'no_schedule'): ?>
        <div class="notice notice-error inline"><p><?php echo esc_html__('Choose a schedule for these workshops, create a new one, or pick "Don\'t assign a schedule".', 'convention-schedule'); ?></p></div>
    <?php elseif ($err === 'no_schedule_name'): ?>
        <div class="notice notice-error inline"><p><?php echo esc_html__('Enter a name for the new schedule.', 'convention-schedule'); ?></p></div>
    <?php elseif ($err === 'duplicate'): ?>
        <div class="notice notice-error inline"><p><?php echo esc_html__('Each field can only be used once. Two or more columns are set to the same field.', 'convention-schedule'); ?></p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="csched_import_workshops_csv_mapped">
        <input type="hidden" name="csched_map" value="<?php echo esc_attr($token); ?>">
        <?php wp_nonce_field('csched_import_workshops_csv_mapped'); ?>
        <table class="widefat striped" style="max-width:1100px;">
            <thead><tr>
                <th style="width:22%;"><?php echo esc_html__('Column in your file', 'convention-schedule'); ?></th>
                <th style="width:38%;"><?php echo esc_html__('Sample values', 'convention-schedule'); ?></th>
                <th><?php echo esc_html__('Import into', 'convention-schedule'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($header as $i => $h):
                $label = trim((string)preg_replace('/^\xEF\xBB\xBF/', '', (string)$h));
                $vals = [];
                foreach ($samples as $r) {
                    $v = trim((string)($r[$i] ?? ''));
                    if ($v !== '') $vals[] = mb_strimwidth($v, 0, 60, '…');
                }
                $sel = (string)($chosen[$i] ?? '');
                ?>
                <tr>
                    <td><strong><?php echo $label !== '' ? esc_html($label) : '<em>' . esc_html(sprintf(__('(column %d, no header)', 'convention-schedule'), $i + 1)) . '</em>'; ?></strong></td>
                    <td style="color:#555;"><?php echo $vals ? esc_html(implode(' · ', $vals)) : '<em>' . esc_html__('(empty)', 'convention-schedule') . '</em>'; ?></td>
                    <td>
                        <select name="csched_col[<?php echo (int)$i; ?>]" style="max-width:100%;">
                            <option value=""><?php echo esc_html__('— Don\'t import —', 'convention-schedule'); ?></option>
                            <?php foreach ($fields as $key => $flabel): ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($sel, $key); ?>><?php echo esc_html($flabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div id="csched-schedule-pick" style="max-width:1100px;margin:16px 0;padding:14px 16px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;">
            <h3 style="margin:0 0 6px;"><?php echo esc_html__('Schedule for these workshops', 'convention-schedule'); ?></h3>
            <p style="margin:0 0 10px;"><?php echo esc_html__('No column is set to Schedule, so choose which schedule every workshop in this file goes into.', 'convention-schedule'); ?></p>
            <fieldset>
                <?php foreach ($schedule_terms as $t): ?>
                    <label style="display:block;margin:0 0 6px;">
                        <input type="radio" name="csched_target_schedule" value="<?php echo esc_attr($t->slug); ?>" <?php checked($target, $t->slug); ?>>
                        <?php echo esc_html($t->name); ?>
                        <span style="color:#646970;">(<?php echo esc_html(sprintf(_n('%d workshop', '%d workshops', (int)$t->count, 'convention-schedule'), (int)$t->count)); ?>)</span>
                    </label>
                <?php endforeach; ?>
                <label style="display:block;margin:0 0 6px;">
                    <input type="radio" name="csched_target_schedule" value="__new__" <?php checked($target, '__new__'); ?>>
                    <?php echo esc_html__('New schedule:', 'convention-schedule'); ?>
                    <input type="text" name="csched_new_schedule" value="<?php echo esc_attr($new_name); ?>" placeholder="<?php echo esc_attr__('e.g. 2027 Convention', 'convention-schedule'); ?>" class="regular-text" style="margin-left:4px;">
                </label>
                <label style="display:block;margin:0;color:#646970;">
                    <input type="radio" name="csched_target_schedule" value="__none__" <?php checked($target, '__none__'); ?>>
                    <?php echo esc_html__('Don\'t assign a schedule (updated workshops keep the schedule they have)', 'convention-schedule'); ?>
                </label>
            </fieldset>
        </div>
        <script>
        (function(){
            var box = document.getElementById('csched-schedule-pick');
            if (!box) return;
            var form = box.closest('form');
            function sync(){
                var mapped = Array.prototype.some.call(form.querySelectorAll('select[name^="csched_col["]'), function(s){ return s.value === 'schedule'; });
                box.style.display = mapped ? 'none' : '';
            }
            form.addEventListener('change', function(e){
                if (e.target.name && e.target.name.indexOf('csched_col[') === 0) sync();
            });
            // Typing a new name selects the "New schedule" option.
            var nameInput = box.querySelector('input[name="csched_new_schedule"]');
            nameInput.addEventListener('input', function(){ box.querySelector('input[value="__new__"]').checked = true; });
            sync();
        })();
        </script>

        <p class="description" style="max-width:820px;">
            <?php echo esc_html__('Columns set to "Don\'t import" are ignored. When a Workshop ID matches an existing workshop, that workshop is updated and fields you don\'t import are left as they are. Dates and times can be in most common formats, such as 2026-05-01, 5/1/2026, 14:00 or 2:00 PM.', 'convention-schedule'); ?>
        </p>
        <p>
            <button type="submit" class="button button-primary"><?php echo esc_html__('Import Workshops', 'convention-schedule'); ?></button>
            <a class="button" href="<?php echo esc_url(self::settings_url(['csched_map_cancel' => $token])); ?>"><?php echo esc_html__('Cancel', 'convention-schedule'); ?></a>
        </p>
    </form>
    <?php
    return true;
}

/** Split "9:00 AM - 10:30 AM" (also "9-10am", "9:00 to 10:00") into [start, end]. */
private static function split_time_range(string $v): array {
    $v = trim($v);
    if ($v === '') return ['', ''];
    $parts = preg_split('/\s*(?:-|–|—|\bto\b)\s*/iu', $v, 2);
    $start = trim((string)($parts[0] ?? ''));
    $end = trim((string)($parts[1] ?? ''));
    // "9-10am" / "11-1pm": give a bare start the am/pm that puts it before the end.
    if ($end !== '' && preg_match('/(am|pm)$/i', $end, $m) && preg_match('/^\d{1,2}(:\d{2})?$/', $start)) {
        $same = $start . ' ' . strtolower($m[1]);
        $other = $start . ' ' . (strtolower($m[1]) === 'pm' ? 'am' : 'pm');
        $end_t = self::normalize_import_time($end);
        $same_t = self::normalize_import_time($same);
        $start = ($same_t !== '' && $end_t !== '' && $same_t > $end_t) ? $other : $same;
    }
    return [$start, $end];
}

/**
 * Import data rows using a field => column index map.
 * @return array{created:int, updated:int, skipped:int}
 */
private static function run_import(array $rows, array $col_index, string $default_schedule = ''): array {
    $created = 0;
    $updated = 0;
    $skipped = 0;
    $has = function(string $key) use ($col_index) { return isset($col_index[$key]); };

    foreach ($rows as $row) {
        if (!is_array($row) || count(array_filter($row, fn($v) => $v !== null && trim((string)$v) !== '')) === 0) {
            continue;
        }

        $get = function(string $key) use ($col_index, $row) {
            if (!isset($col_index[$key])) return '';
            $i = $col_index[$key];
            return isset($row[$i]) ? (string)$row[$i] : '';
        };

        $id_raw = trim($get('id'));
        $title = sanitize_text_field($get('title'));
        if ($title === '') { $skipped++; continue; }

        $desc = (string)$get('description');
        // Allow HTML from admins; will still be KSES-filtered on save
        $desc = wp_kses_post($desc);

        $date  = self::normalize_import_date(sanitize_text_field($get('date')));
        $start_raw = $get('start');
        $end_raw = $get('end');
        if ($has('time_range')) {
            [$range_start, $range_end] = self::split_time_range($get('time_range'));
            if (!$has('start')) $start_raw = $range_start;
            if (!$has('end')) $end_raw = $range_end;
        }
        $start = self::normalize_import_time(sanitize_text_field($start_raw));
        $end   = self::normalize_import_time(sanitize_text_field($end_raw));
        $room  = sanitize_text_field($get('room'));
        // Schedule column. Older exports (1.8.x) also had an "event" column: when the schedule
        // cell is blank, the event value is used as the schedule.
        $has_schedule_col = $has('schedule') || $has('event');
        $schedule_raw = trim($get('schedule'));
        if ($schedule_raw === '') $schedule_raw = trim($get('event'));
        $schedule_slug = sanitize_title($schedule_raw);
        if (!$has_schedule_col && $default_schedule !== '') {
            // Schedule chosen on the import screen applies to every row.
            $has_schedule_col = true;
            $schedule_raw = $default_schedule;
            $schedule_slug = $default_schedule;
        }
        $has_previous_day_col = $has('previous_day');
        $previous_day = trim($get('previous_day'));
        $previous_day = in_array(strtolower($previous_day), ['1','yes','true','y'], true) ? '1' : '0';

        $postarr = [
            'post_type' => CS_Plugin::CPT,
            'post_title' => $title,
            'post_status' => 'publish',
        ];
        if ($has('description')) $postarr['post_content'] = $desc;

        $post_id = 0;
        $is_update = false;

        if ($id_raw !== '' && ctype_digit($id_raw)) {
            $existing = get_post((int)$id_raw);
            if ($existing && $existing->post_type === CS_Plugin::CPT) {
                $postarr['ID'] = (int)$id_raw;
                // Keep the existing status (drafts stay drafts) when updating.
                unset($postarr['post_status']);
                $post_id = wp_update_post($postarr, true);
                $is_update = true;
            }
        }

        if (!$is_update) {
            $post_id = wp_insert_post($postarr, true);
        }

        if (is_wp_error($post_id) || !$post_id) {
            $skipped++;
            continue;
        }

        // On updates, only overwrite fields the file actually provides.
        if (!$is_update || $has('date')) update_post_meta($post_id, CS_Plugin::META_DATE, $date);
        if (!$is_update || $has('start') || $has('time_range')) update_post_meta($post_id, CS_Plugin::META_START, $start);
        if (!$is_update || $has('end') || $has('time_range')) update_post_meta($post_id, CS_Plugin::META_END, $end);
        if (!$is_update || $has('room')) update_post_meta($post_id, CS_Plugin::META_ROOM, $room);
        if ($has_previous_day_col) {
            update_post_meta($post_id, CS_Plugin::META_PREVIOUS_DAY, $previous_day);
        }

        // Only touch the schedule when the file has a schedule (or legacy event) column,
        // so a CSV without one does not wipe existing assignments on update.
        if ($has_schedule_col) {
            update_post_meta($post_id, CS_Plugin::META_SCHEDULE, $schedule_slug);
            if ($schedule_slug !== '') {
                $schedule_term = get_term_by('slug', $schedule_slug, CS_Plugin::SCHEDULE_TAX);
                if (!$schedule_term) {
                    // A bare slug ("spring-gathering") becomes a readable name ("Spring Gathering").
                    $schedule_name = ($schedule_raw !== '' && $schedule_raw !== $schedule_slug)
                        ? sanitize_text_field($schedule_raw)
                        : ucwords(str_replace(['-', '_'], ' ', $schedule_slug));
                    $created_schedule = wp_insert_term($schedule_name, CS_Plugin::SCHEDULE_TAX, ['slug' => $schedule_slug]);
                    if (!is_wp_error($created_schedule) && !empty($created_schedule['term_id'])) {
                        wp_set_object_terms($post_id, [(int)$created_schedule['term_id']], CS_Plugin::SCHEDULE_TAX, false);
                    }
                } else {
                    wp_set_object_terms($post_id, [(int)$schedule_term->term_id], CS_Plugin::SCHEDULE_TAX, false);
                }
            } else {
                wp_set_object_terms($post_id, [], CS_Plugin::SCHEDULE_TAX, false);
            }
        }

        // Categories: slugs separated by |
        $cats_raw = trim($get('categories'));
        if ($cats_raw !== '') {
            // Slugs or names, separated by | (export format), comma or semicolon.
            $slugs = array_filter(array_map('trim', preg_split('/[|,;]/', $cats_raw)));
            $term_ids = [];
            foreach ($slugs as $slug) {
                $slug = sanitize_title($slug);
                if ($slug === '') continue;

                $term = get_term_by('slug', $slug, CS_Plugin::TAX);
                if (!$term) {
                    $created_term = wp_insert_term(ucwords(str_replace(['-', '_'], ' ', $slug)), CS_Plugin::TAX, ['slug' => $slug]);
                    if (!is_wp_error($created_term) && !empty($created_term['term_id'])) {
                        $term_ids[] = (int)$created_term['term_id'];
                    }
                } else {
                    $term_ids[] = (int)$term->term_id;
                }
            }
            if (!empty($term_ids)) {
                wp_set_object_terms($post_id, $term_ids, CS_Plugin::TAX, false);
            }
        }

        if ($is_update) $updated++; else $created++;
    }

    return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
}

private static function redirect_settings_notice(string $key, string $val): void {
    wp_safe_redirect(add_query_arg([$key => $val], admin_url('edit.php?post_type=' . CS_Plugin::CPT . '&page=csched-settings')));
    exit;
}

public static function render_setup_usage_page(): void {
        if (!current_user_can('manage_options')) return;
        ?>
        <div class="wrap csched-help-page">
            <h1><?php echo esc_html__('Convention Schedule — Setup & Shortcodes', 'convention-schedule'); ?></h1>
            <p class="description" style="max-width:900px;font-size:14px;">
                <?php echo esc_html__('Use this page as the setup guide for creating one or many schedules, assigning workshops, displaying schedules on pages, and using filters and shortcode options.', 'convention-schedule'); ?>
            </p>

            <style>
                .csched-help-page .csched-help-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 20px;margin:16px 0;max-width:1050px;}
                .csched-help-page h2{margin-top:0;}
                .csched-help-page code{font-size:13px;}
                .csched-help-page .csched-code{display:block;background:#f6f7f7;border:1px solid #dcdcde;border-radius:5px;padding:10px 12px;margin:8px 0;overflow:auto;}
                .csched-help-page table{max-width:1050px;}
                .csched-help-page .csched-note{border-left:4px solid #2271b1;padding:8px 12px;background:#f0f6fc;margin:12px 0;}
            </style>

            <div class="csched-help-card">
                <h2><?php echo esc_html__('1. How Workshops Are Organized', 'convention-schedule'); ?></h2>
                <table class="widefat striped">
                    <tbody>
                        <tr><td style="width:180px;"><strong><?php echo esc_html__('Schedules', 'convention-schedule'); ?></strong></td><td><?php echo esc_html__('One per convention or workshop schedule, for example 2026 Convention or Service Workshops. Each workshop belongs to one schedule, and each schedule can have its own page.', 'convention-schedule'); ?></td></tr>
                        <tr><td><strong><?php echo esc_html__('Workshops', 'convention-schedule'); ?></strong></td><td><?php echo esc_html__('The individual sessions, each with a date, start/end time, room, and description.', 'convention-schedule'); ?></td></tr>
                        <tr><td><strong><?php echo esc_html__('Categories', 'convention-schedule'); ?></strong></td><td><?php echo esc_html__('Classify workshops (Main Meeting, Workshop, Meal, Entertainment, Service). Categories drive the filter dropdown and color bars.', 'convention-schedule'); ?></td></tr>
                    </tbody>
                </table>
                <div class="csched-note"><?php echo esc_html__('Schedule Events were merged into Schedules in version 1.9.0. Existing event assignments were moved to a schedule with the same slug, so old event="" shortcodes still show the same workshops.', 'convention-schedule'); ?></div>
            </div>

            <div class="csched-help-card">
                <h2><?php echo esc_html__('2. Basic Setup', 'convention-schedule'); ?></h2>
                <ol>
                    <li><?php echo wp_kses_post(__('Go to <strong>Workshops → Schedules</strong> and create a schedule for each convention or workshop schedule.', 'convention-schedule')); ?></li>
                    <li><?php echo wp_kses_post(__('Go to <strong>Workshops → Schedule Categories</strong> and create categories such as Main Meeting, Workshop, Meal, Entertainment, or Service.', 'convention-schedule')); ?></li>
                    <li><?php echo wp_kses_post(__('Go to <strong>Workshops → Settings</strong> to configure layout, time format, controls, category colors, saved rooms, and table category bar width.', 'convention-schedule')); ?></li>
                    <li><?php echo wp_kses_post(__('Go to <strong>Workshops → Add Workshop</strong>, enter the date/time and room, choose its schedule and categories, and publish.', 'convention-schedule')); ?></li>
                    <li><?php echo wp_kses_post(__('Create a WordPress page and add the schedule shortcode using a Shortcode block or Elementor Shortcode widget.', 'convention-schedule')); ?></li>
                </ol>
            </div>

            <div class="csched-help-card">
                <h2><?php echo esc_html__('3. Multiple Schedules on One Website', 'convention-schedule'); ?></h2>
                <p><?php echo esc_html__('Schedules are independent sets of workshops. This is useful when one website hosts more than one convention, conference, retreat, track, or yearly event.', 'convention-schedule'); ?></p>
                <p><strong><?php echo esc_html__('Example:', 'convention-schedule'); ?></strong></p>
                <ul>
                    <li><?php echo esc_html__('Schedule: 2026 Convention → slug: 2026-convention', 'convention-schedule'); ?></li>
                    <li><?php echo esc_html__('Schedule: 2027 Convention → slug: 2027-convention', 'convention-schedule'); ?></li>
                    <li><?php echo esc_html__('Schedule: Service Workshops → slug: service-workshops', 'convention-schedule'); ?></li>
                </ul>
                <span class="csched-code"><code>[convention_schedule schedule="2026-convention"]</code></span>
                <span class="csched-code"><code>[convention_schedule schedule="2027-convention"]</code></span>
                <span class="csched-code"><code>[convention_schedule schedule="service-workshops"]</code></span>
                <div class="csched-note"><?php echo esc_html__('A workshop assigned to one schedule will only appear on pages filtering for that schedule.', 'convention-schedule'); ?></div>
            </div>

            <div class="csched-help-card">
                <h2><?php echo esc_html__('4. Common Use Cases', 'convention-schedule'); ?></h2>
                <table class="widefat striped">
                    <thead><tr><th><?php echo esc_html__('Use Case', 'convention-schedule'); ?></th><th><?php echo esc_html__('Recommended Shortcode', 'convention-schedule'); ?></th></tr></thead>
                    <tbody>
                        <tr><td><?php echo esc_html__('Single schedule using admin defaults', 'convention-schedule'); ?></td><td><code>[convention_schedule]</code></td></tr>
                        <tr><td><?php echo esc_html__('Dedicated page for one schedule', 'convention-schedule'); ?></td><td><code>[convention_schedule schedule="main-convention"]</code></td></tr>
                        <tr><td><?php echo esc_html__('Cards view for one schedule', 'convention-schedule'); ?></td><td><code>[convention_schedule schedule="main-convention" layout="cards"]</code></td></tr>
                        <tr><td><?php echo esc_html__('Grid view in 12-hour format', 'convention-schedule'); ?></td><td><code>[convention_schedule layout="grid" time_format="12"]</code></td></tr>
                        <tr><td><?php echo esc_html__('Only one category', 'convention-schedule'); ?></td><td><code>[convention_schedule category="workshops"]</code></td></tr>
                        <tr><td><?php echo esc_html__('Only one room', 'convention-schedule'); ?></td><td><code>[convention_schedule room="Aspen 1"]</code></td></tr>
                        <tr><td><?php echo esc_html__('Allow visitors to choose schedules', 'convention-schedule'); ?></td><td><code>[convention_schedule show_schedule_filter="1"]</code></td></tr>
                        <tr><td><?php echo esc_html__('Hide all front-end controls', 'convention-schedule'); ?></td><td><code>[convention_schedule show_controls="0"]</code></td></tr>
                    </tbody>
                </table>
            </div>

            <div class="csched-help-card">
                <h2><?php echo esc_html__('5. Shortcode Reference', 'convention-schedule'); ?></h2>
                <p><code>[convention_schedule]</code></p>
                <table class="widefat striped">
                    <thead><tr><th><?php echo esc_html__('Attribute', 'convention-schedule'); ?></th><th><?php echo esc_html__('Values / Purpose', 'convention-schedule'); ?></th></tr></thead>
                    <tbody>
                        <tr><td><code>schedule</code></td><td><?php echo esc_html__('Schedule slug, for example 2026-convention', 'convention-schedule'); ?></td></tr>
                        <tr><td><code>show_schedule_filter</code></td><td><code>1</code> / <code>0</code></td></tr>
                        <tr><td><code>layout</code></td><td><code>table</code>, <code>cards</code>, <code>grid</code></td></tr>
                        <tr><td><code>heading</code></td><td><?php echo esc_html__('Override the schedule heading', 'convention-schedule'); ?></td></tr>
                        <tr><td><code>show_heading</code></td><td><code>1</code> / <code>0</code></td></tr>
                        <tr><td><code>show_controls</code></td><td><code>1</code> / <code>0</code></td></tr>
                        <tr><td><code>show_view_switcher</code></td><td><code>1</code> / <code>0</code></td></tr>
                        <tr><td><code>show_search</code></td><td><code>1</code> / <code>0</code></td></tr>
                        <tr><td><code>group_by</code></td><td><code>date</code> / <code>category</code></td></tr>
                        <tr><td><code>category</code></td><td><?php echo esc_html__('Category slug', 'convention-schedule'); ?></td></tr>
                        <tr><td><code>room</code></td><td><?php echo esc_html__('Room name', 'convention-schedule'); ?></td></tr>
                        <tr><td><code>time_format</code></td><td><code>12</code> / <code>24</code></td></tr>
                        <tr><td><code>show_day_of_week</code></td><td><code>1</code> / <code>0</code></td></tr>
                        <tr><td><code>show_categories</code></td><td><code>1</code> / <code>0</code></td></tr>
                        <tr><td><code>show_description</code></td><td><code>1</code> / <code>0</code></td></tr>
                        <tr><td><code>order</code></td><td><code>ASC</code> / <code>DESC</code></td></tr>
                        <tr><td><code>day_extension</code></td><td><?php echo esc_html__('After-midnight grace period for this page, in minutes (120) or as a time (02:00). 0 turns it off.', 'convention-schedule'); ?></td></tr>
                    </tbody>
                </table>
                <p class="description"><?php echo wp_kses_post(__('Older shortcodes still work: <code>event="slug"</code> behaves like <code>schedule="slug"</code>, and <code>show_event_filter="1"</code> shows the Schedule dropdown. New pages should use <code>schedule</code> and <code>show_schedule_filter</code>.', 'convention-schedule')); ?></p>
            </div>

            <div class="csched-help-card">
                <h2><?php echo esc_html__('6. After-Midnight Workshops', 'convention-schedule'); ?></h2>
                <p><?php echo wp_kses_post(__('To keep late-night items (dances, meetings, socials) with the evening they belong to, go to <strong>Workshops → Settings → After-Midnight Grace Period</strong> and choose how far past midnight each day extends, for example until 2:00 AM. A dance at 12:01 AM Saturday is then listed at the end of Friday, and its time still reads 12:01 AM.', 'convention-schedule')); ?></p>
                <p><?php echo wp_kses_post(__('For a single workshop that starts later than the grace period but should still appear under the prior day, edit it and check <strong>Display this workshop on the previous schedule day</strong>.', 'convention-schedule')); ?></p>
                <p><?php echo wp_kses_post(__('A page can use its own grace period with the shortcode attribute <code>day_extension</code>, in minutes or as a time: <code>[convention_schedule day_extension="02:00"]</code>. Use <code>day_extension="0"</code> to turn it off for that page.', 'convention-schedule')); ?></p>
            </div>

            <div class="csched-help-card">
                <h2><?php echo esc_html__('7. Rooms and Combined Rooms', 'convention-schedule'); ?></h2>
                <p><?php echo wp_kses_post(__('Saved rooms are managed in <strong>Workshops → Settings → Saved Rooms</strong> and appear in the Add/Edit Workshop room selector.', 'convention-schedule')); ?></p>
                <p><?php echo esc_html__('Numbered rooms such as Aspen 1 and Aspen 2 remain distinct. Combined labels such as Aspen or Aspen 1 & 2 can represent both numbered rooms in grid/filter logic.', 'convention-schedule'); ?></p>
            </div>

            <div class="csched-help-card">
                <h2><?php echo esc_html__('8. Category Colors', 'convention-schedule'); ?></h2>
                <p><?php echo wp_kses_post(__('Assign category colors under <strong>Workshops → Settings → Category Color Bars</strong>. Cards and grid use full-width bars; table bar width can be set independently.', 'convention-schedule')); ?></p>
            </div>

            <div class="csched-help-card">
                <h2><?php echo esc_html__('9. CSV Import / Export', 'convention-schedule'); ?></h2>
                <p><?php echo esc_html__('Use the CSV tools in Settings to move or bulk-edit workshops. Exports include workshop ID, title, description, date, start/end time, room, categories, schedule, and previous-day flag.', 'convention-schedule'); ?></p>
                <p><?php echo esc_html__('Use schedule slugs in the schedule column and category slugs separated by | in the categories column. Missing schedules and categories are created during import. CSV files exported from older versions still import: a value in the old event column is used as the schedule when the schedule cell is blank.', 'convention-schedule'); ?></p>
                <p><?php echo esc_html__('Files from other sources (spreadsheets, registration systems) can be imported too. If the column names don\'t match, a matching screen shows each column with sample values so you can choose which field it goes into, such as Session Name → Title or Location → Room. A single "9:00 AM - 10:30 AM" column can be split into start and end times. Tick "Let me review how columns are matched" on the upload form to see that screen for any file.', 'convention-schedule'); ?></p>
            </div>

            <div class="csched-help-card">
                <h2><?php echo esc_html__('10. Elementor / WordPress Placement', 'convention-schedule'); ?></h2>
                <ul>
                    <li><?php echo wp_kses_post(__('Elementor: use the <strong>Shortcode</strong> widget.', 'convention-schedule')); ?></li>
                    <li><?php echo wp_kses_post(__('Block Editor: use the <strong>Shortcode</strong> block.', 'convention-schedule')); ?></li>
                    <li><?php echo esc_html__('Use straight quotes in shortcode attributes, not curly/smart quotes.', 'convention-schedule'); ?></li>
                </ul>
                <span class="csched-code"><code>[convention_schedule layout="table"]</code></span>
            </div>

            <div class="csched-help-card">
                <h2><?php echo esc_html__('11. Troubleshooting', 'convention-schedule'); ?></h2>
                <ul>
                    <li><?php echo esc_html__('Shortcode appears as text: use a Shortcode block/widget and confirm the plugin is active.', 'convention-schedule'); ?></li>
                    <li><?php echo esc_html__('No workshops display: confirm they are published and assigned to the schedule/category being filtered.', 'convention-schedule'); ?></li>
                    <li><?php echo esc_html__('Wrong schedule page: verify the schedule slug exactly matches the shortcode.', 'convention-schedule'); ?></li>
                    <li><?php echo esc_html__('Changes do not appear: clear WordPress, Elementor, server, and CDN caches.', 'convention-schedule'); ?></li>
                </ul>
            </div>
        
            <hr style="margin:32px 0;">
            <h2><?php echo esc_html__('Shortcodes', 'convention-schedule'); ?></h2>
            <p><?php echo esc_html__('Use these shortcodes to place one or more schedules on pages, posts, or Elementor Shortcode widgets.', 'convention-schedule'); ?></p>

            

            <h2><code>[convention_schedule]</code></h2>
            <p><?php echo esc_html__('Displays the workshop schedule using the selected layout and filters.', 'convention-schedule'); ?></p>

            <h3><?php echo esc_html__('Attributes', 'convention-schedule'); ?></h3>
            <ul>
                <li><code>layout</code> — <code>table</code> | <code>cards</code> | <code>grid</code></li>
                <li><code>heading</code> — override heading text (optional)</li>
                <li><code>show_heading</code> — <code>1</code> or <code>0</code></li>
                <li><code>show_controls</code> — <code>1</code> or <code>0</code></li>
                <li><code>show_view_switcher</code> — <code>1</code> or <code>0</code> (requires show_controls=1)</li>
                <li><code>group_by</code> — <code>date</code> | <code>category</code> (table/cards; grid uses filter only)</li>
                <li><code>schedule</code> — schedule slug filter (optional)</li>
                <li><code>show_schedule_filter</code> — <code>1</code> or <code>0</code></li>
                <li><code>category</code> — category slug filter (optional)</li>
                <li><code>room</code> — exact room filter (optional)</li>
                <li><code>time_format</code> — <code>12</code> or <code>24</code></li>
                <li><code>show_day_of_week</code> — <code>1</code> or <code>0</code></li>
            </ul>

            <h3><?php echo esc_html__('Examples', 'convention-schedule'); ?></h3>
            <p><code>[convention_schedule]</code></p>
            <p><code>[convention_schedule schedule="main-convention"]</code></p>
            <p><code>[convention_schedule schedule="service-workshops"]</code></p>
            <p><code>[convention_schedule layout="cards" show_controls="1"]</code></p>
            <p><code>[convention_schedule layout="grid" time_format="12"]</code></p>
            <p><code>[convention_schedule group_by="category" category="workshops"]</code></p>

            <h3><?php echo esc_html__('CSS Variables', 'convention-schedule'); ?></h3>
            <ul>
                <li><code>--csched-dd-width-cat</code></li>
                <li><code>--csched-dd-width-group</code></li>
                <li><code>--csched-dd-width-room</code></li>
                <li><code>--csched-gap</code></li>
                <li><code>--csched-control-height</code></li>
                <li><code>--csched-control-radius</code></li>
            </ul>
        
</div>
        <?php
    }


}
