<?php
if (!defined('ABSPATH')) exit;

class CS_Shortcodes {

    public static function init(): void {
        add_shortcode('convention_schedule', [__CLASS__, 'render_schedule']);
        add_shortcode('convention-schedule', [__CLASS__, 'render_schedule']);
    }

        private static function is_elementor_context(): bool {
        // Elementor editor often renders widgets/shortcodes via admin-ajax where is_admin() is true.
        if (!defined('ELEMENTOR_VERSION')) return false;
        if (class_exists('\\Elementor\\Plugin')) {
            try {
                $plugin = \Elementor\Plugin::$instance;
                if (isset($plugin->editor) && method_exists($plugin->editor, 'is_edit_mode') && $plugin->editor->is_edit_mode()) {
                    return true;
                }
                if (isset($plugin->preview) && method_exists($plugin->preview, 'is_preview_mode') && $plugin->preview->is_preview_mode()) {
                    return true;
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
        // Fallback for AJAX render calls
        if (wp_doing_ajax() && isset($_REQUEST['action']) && is_string($_REQUEST['action'])) {
            return (strpos($_REQUEST['action'], 'elementor') !== false);
        }
        return false;
    }

private static function time_display(string $hhmm, string $format): string {
        $hhmm = trim($hhmm);
        if ($hhmm === '' || !preg_match('/^\d{2}:\d{2}$/', $hhmm)) return $hhmm;
        if ($format !== '12') return $hhmm;
        $ts = strtotime('2000-01-01 ' . $hhmm . ':00');
        if (!$ts) return $hhmm;
        return date_i18n('g:i A', $ts);
    }

    private static function time_range_display(string $start, string $end, string $format): string {
        $s = self::time_display($start, $format);
        $e = self::time_display($end, $format);
        $out = trim($s . ($e ? '–' . $e : ''));
        return $out;
    }

    private static function pretty_date(string $ymd, bool $show_dow): string {
        $ts = strtotime($ymd);
        if (!$ts) return $ymd;

        $fmt = (string)get_option('date_format');
        $pretty = date_i18n($fmt, $ts);

        if (!$show_dow) return $pretty;

        // If date_format already includes weekday tokens, don't double it.
        // Common tokens: l, D (weekday). We'll do a simple contains check.
        if (strpos($fmt, 'l') !== false || strpos($fmt, 'D') !== false) return $pretty;

        $dow = date_i18n('l', $ts);
        return $dow . ', ' . $pretty;
    }


private static function normalize_hhmm(string $t): string {
    $t = trim($t);
    if ($t === '') return '';
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $t, $m)) {
        $h = (int)$m[1];
        $min = (int)$m[2];
        if ($h < 0 || $h > 23 || $min < 0 || $min > 59) return '';
        return sprintf('%02d:%02d', $h, $min);
    }
    return '';
}

private static function parse_time_to_minutes(string $t): ?int {
    $t = self::normalize_hhmm($t);
    if ($t === '') return null;
    [$h, $m] = array_map('intval', explode(':', $t));
    return $h * 60 + $m;
}

private static function minutes_to_hhmm(int $mins): string {
    $mins = $mins % 1440;
    if ($mins < 0) $mins += 1440;
    $h = intdiv($mins, 60);
    $m = $mins % 60;
    return sprintf('%02d:%02d', $h, $m);
}

private static function get_day_cutoff_minutes(string $override = ''): int {
    $cut = $override !== '' ? $override : (string)get_option(CS_Plugin::OPT_DAY_CUTOFF, '01:00');
    $cut = self::normalize_hhmm($cut);
    $m = self::parse_time_to_minutes($cut);
    if ($m === null) return 60; // default 01:00
    return max(0, min(1439, $m));
}


public static function effective_date_for_item(string $date, string $start, int $cutoff_minutes = 0, bool $previous_day = false): string {
        $date = trim($date);
        if ($date === '') {
            return '';
        }

        // Per-workshop control replaces the old global End of Day Cutoff behavior.
        if ($previous_day) {
            $ts = strtotime($date . ' -1 day');
            if ($ts) {
                return date('Y-m-d', $ts);
            }
        }

        return $date;
    }

public static function effective_sort_minutes(string $start, int $cutoff_minutes = 0, bool $previous_day = false): ?int {
        $minutes = self::parse_time_to_minutes($start);
        if ($minutes === null) {
            return null;
        }

        // Previous-day after-midnight items sort after previous day evening items.
        if ($previous_day && $minutes < 720) {
            return $minutes + 1440;
        }

        return $minutes;
    }

public static function get_rooms_for_filters(): array {
        // Return a distinct, human-readable list of rooms exactly as stored (trimmed),
        // so "Room 1" and "Room 2" remain separate options.
        $rooms = [];
        $items = self::get_items_for_filters();
        foreach ($items as $it) {
            $room = isset($it['room']) ? (string)$it['room'] : '';
            $room = trim(preg_replace('/\s+/', ' ', $room));
            if ($room === '') continue;
            $rooms[$room] = true;
        }
        $out = array_keys($rooms);
        natcasesort($out);
        return array_values($out);
    }


    
    

    /**
     * Back-compat alias. Earlier builds referenced get_rooms().
     * Returns a list of distinct room strings for filter dropdowns.
     */
    public static function get_rooms(): array {
        $idx = self::build_room_index();
        return $idx['canonical'];
    }


    
    public static function title_only_posts_search(string $search, \WP_Query $q): string {
        global $wpdb;
        $term = $q->get('csched_title_search');
        if (!$term) return $search;
        $like = '%' . $wpdb->esc_like((string)$term) . '%';
        return $wpdb->prepare(" AND ({$wpdb->posts}.post_title LIKE %s) ", $like);
    }

private static function render_controls(
        string $selected_schedule,
        bool $show_schedule_filter,
        string $selected_event,
        bool $show_event_filter,
        string $selected_cat,
        string $selected_group,
        string $selected_room,
        string $selected_view,
        bool $show_group_by,
        bool $show_view_switcher,
        bool $show_reset,
        bool $show_search,
        string $search_q,
        string $control_order_csv,
        bool $is_elementor = false
    ): string {
        
        $rendered_search = false;
$terms = get_terms([
            'taxonomy' => CS_Plugin::TAX,
            'hide_empty' => false,
        ]);
        if (is_wp_error($terms)) $terms = [];

        $schedule_terms = get_terms([
            'taxonomy' => CS_Plugin::SCHEDULE_TAX,
            'hide_empty' => false,
        ]);
        if (is_wp_error($schedule_terms)) $schedule_terms = [];

        $event_terms = get_terms([
            'taxonomy' => CS_Plugin::EVENT_TAX,
            'hide_empty' => false,
        ]);
        if (is_wp_error($event_terms)) $event_terms = [];

        $rooms = self::get_rooms();
        $base_url = remove_query_arg(['csched_schedule', 'csched_event', 'csched_cat', 'csched_group', 'csched_room', 'csched_view', 'csched_q']);

        // Control order: comma-separated keys stored in settings.
        $allowed = ['schedule', 'event', 'category', 'group', 'room', 'view', 'search'];
        $order = array_filter(array_map('trim', explode(',', strtolower((string)$control_order_csv))));
        $order = array_values(array_unique(array_intersect($order, $allowed)));
        foreach ($allowed as $k) {
            if (!in_array($k, $order, true)) $order[] = $k;
        }


	ob_start();

        // Elementor often renders shortcodes in a context that strips/changes form behavior.
        // In editor/preview we output a non-submitting preview bar.
        if ($is_elementor) {
            echo "<style>
.csched-sr{position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;}
.csched-controls{display:flex;align-items:flex-end;justify-content:flex-start;gap:var(--csched-gap,10px);flex-wrap:wrap;margin:0 0 14px;}
.csched-field{display:flex;flex-direction:column;align-items:flex-start;gap:6px;}
.csched-field .csched-label{display:block;margin:0 0 4px 0;font-size:0.92rem;opacity:0.9;}
.csched-control{height:var(--csched-control-height,40px);border:1px solid #c9c9c9;border-radius:var(--csched-control-radius,8px);background:#fff;padding:8px 10px;width:100%;}
.csched-input{height:var(--csched-control-height,40px);border:1px solid #c9c9c9;border-radius:var(--csched-control-radius,8px);background:#fff;padding:8px 10px;width:100%;box-sizing:border-box;}
.csched-controls .csched-schedule-filter{width:var(--csched-dd-width-schedule,18%);}
.csched-controls .csched-event-filter{width:var(--csched-dd-width-event,18%);}
.csched-controls .csched-cat{width:var(--csched-dd-width-cat,15%);}
.csched-controls .csched-group{width:var(--csched-dd-width-group,15%);}
.csched-controls .csched-room{width:var(--csched-dd-width-room,15%);}
.csched-controls .csched-view{width:var(--csched-dd-width-view,15%);}
.csched-controls .csched-search{width:var(--csched-dd-width-search,25%);}
.csched-controls .csched-apply{padding:8px 14px;cursor:pointer;width:auto;}
@media (max-width:768px){
  .csched-controls{flex-direction:column !important;align-items:stretch !important;flex-wrap:nowrap !important;}
  .csched-field{flex-direction:row !important;align-items:center !important;gap:10px !important;width:100% !important;}
  .csched-field .csched-label{margin:0 !important;min-width:90px;white-space:nowrap;}
  .csched-field select{flex:1 1 auto;width:auto !important;}
  .csched-apply{width:100% !important;}
}
.csched-controls--preview{opacity:.85;}
</style>";
            ?>
            <div class="csched-controls csched-controls--preview" aria-hidden="true">
                <?php
                $preview = [];
                if ($show_schedule_filter) {
                    $preview['schedule'] = function(){ ?>
                        <div class="csched-field csched-schedule-filter">
                            <span class="csched-label" id="csched_lbl_schedule_preview"><?php echo esc_html__('Schedule', 'convention-schedule'); ?></span>
                            <select id="csched_schedule_preview" class="csched-control" disabled aria-label="<?php echo esc_attr__('Schedule', 'convention-schedule'); ?>" aria-labelledby="csched_lbl_schedule_preview">
                                <option><?php echo esc_html__('All Schedules', 'convention-schedule'); ?></option>
                            </select>
                        </div>
                    <?php };
                }

                if ($show_event_filter) {
                    $preview['event'] = function(){ ?>
                        <div class="csched-field csched-event-filter">
                            <span class="csched-label" id="csched_lbl_event_preview"><?php echo esc_html__('Event', 'convention-schedule'); ?></span>
                            <select id="csched_event_preview" class="csched-control" disabled aria-label="<?php echo esc_attr__('Event', 'convention-schedule'); ?>" aria-labelledby="csched_lbl_event_preview">
                                <option><?php echo esc_html__('All Events', 'convention-schedule'); ?></option>
                            </select>
                        </div>
                    <?php };
                }

                $preview['category'] = function(){ ?>
                    <div class="csched-field csched-cat">
                        <span class="csched-label" id="csched_lbl_cat_preview"><?php echo esc_html__('Category', 'convention-schedule'); ?></span>
                        <select id="csched_cat_preview" class="csched-control" disabled aria-label="<?php echo esc_attr__('Category', 'convention-schedule'); ?>" aria-labelledby="csched_lbl_cat_preview">
                            <option><?php echo esc_html__('All Categories', 'convention-schedule'); ?></option>
                        </select>
                    </div>
                <?php };

                if ($show_group_by) {
                    $preview['group'] = function(){ ?>
                        <div class="csched-field csched-group">
                            <span class="csched-label" id="csched_lbl_group_preview"><?php echo esc_html__('Group By', 'convention-schedule'); ?></span>
                            <select id="csched_group_preview" class="csched-control" disabled aria-label="<?php echo esc_attr__('Group By', 'convention-schedule'); ?>" aria-labelledby="csched_lbl_group_preview">
                                <option><?php echo esc_html__('Group: Date', 'convention-schedule'); ?></option>
                            </select>
                        </div>
                    <?php };
                }

                $preview['room'] = function(){ ?>
                    <div class="csched-field csched-room">
                        <span class="csched-label" id="csched_lbl_room_preview"><?php echo esc_html__('Room', 'convention-schedule'); ?></span>
                        <select id="csched_room_preview" class="csched-control" disabled aria-label="<?php echo esc_attr__('Room', 'convention-schedule'); ?>" aria-labelledby="csched_lbl_room_preview">
                            <option><?php echo esc_html__('All Rooms', 'convention-schedule'); ?></option>
                        </select>
                    </div>
                <?php };


                if ($show_search) {
                    $preview['search'] = function() { ?>
                        <div class="csched-field csched-search">
                            <span class="csched-label" id="csched_lbl_q_preview"><?php echo esc_html__('Search', 'convention-schedule'); ?></span>
                            <input id="csched_q_preview" class="csched-input" type="search" placeholder="<?php echo esc_attr__('Title…', 'convention-schedule'); ?>" aria-label="<?php echo esc_attr__('Search by title', 'convention-schedule'); ?>" aria-labelledby="csched_lbl_q_preview">
                        </div>
                    <?php };
                }


                if ($show_search) {
                    $controls['search'] = function() use ($search_q) { ?>
                        <div class="csched-field csched-search">
                            <span class="csched-label" id="csched_lbl_q"><?php echo esc_html__('Search', 'convention-schedule'); ?></span>
                            <input class="csched-input" type="search" name="csched_q" value="<?php echo esc_attr($search_q); ?>" placeholder="<?php echo esc_attr__('Title…', 'convention-schedule'); ?>" aria-label="<?php echo esc_attr__('Search by title', 'convention-schedule'); ?>" aria-labelledby="csched_lbl_q">
                        </div>
                    <?php };
                }

                if ($show_view_switcher) {
                    $preview['view'] = function(){ ?>
                        <div class="csched-field csched-view">
                            <span class="csched-label" id="csched_lbl_view_preview"><?php echo esc_html__('View', 'convention-schedule'); ?></span>
                            <select id="csched_view_preview" class="csched-control" disabled aria-label="<?php echo esc_attr__('View', 'convention-schedule'); ?>" aria-labelledby="csched_lbl_view_preview">
                                <option><?php echo esc_html__('Table', 'convention-schedule'); ?></option>
                            </select>
                        </div>
                    <?php };
                }

                foreach ($order as $k) {
                    if (isset($preview[$k]) && is_callable($preview[$k])) {
                        $preview[$k]();
                    }
                }
                ?>

                <?php if ($show_reset): ?>
                    <button class="csched-control csched-reset" type="button" disabled><?php echo esc_html__('Reset Filters', 'convention-schedule'); ?></button>
                <?php endif; ?>

                <button class="csched-control csched-apply" type="button"><?php echo esc_html__('Apply', 'convention-schedule'); ?></button>
            </div>
            <?php
        } else {
            ?>
            <form class="csched-controls" method="get" action="<?php echo esc_url($base_url); ?>">
                <?php
                // Preserve other query args.
                foreach ($_GET as $k => $v) {
                    if (in_array($k, ['csched_schedule', 'csched_event', 'csched_cat', 'csched_group', 'csched_room', 'csched_view', 'csched_q'], true)) continue;
                    if (is_array($v)) continue;
                    echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
                }

                $controls = [];
                if ($show_schedule_filter) {
                    $controls['schedule'] = function() use ($schedule_terms, $selected_schedule) { ?>
                        <div class="csched-field csched-schedule-filter">
                            <span class="csched-label" id="csched_lbl_schedule"><?php echo esc_html__('Schedule', 'convention-schedule'); ?></span>
                            <select id="csched_schedule" class="csched-control" name="csched_schedule" aria-label="<?php echo esc_attr__('Schedule', 'convention-schedule'); ?>" aria-labelledby="csched_lbl_schedule">
                                <option value=""><?php echo esc_html__('All Schedules', 'convention-schedule'); ?></option>
                                <?php foreach ($schedule_terms as $t): ?>
                                    <option value="<?php echo esc_attr($t->slug); ?>" <?php selected($selected_schedule, $t->slug); ?>><?php echo esc_html($t->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php };
                } elseif ($selected_schedule !== '') {
                    echo '<input type="hidden" name="csched_schedule" value="' . esc_attr($selected_schedule) . '">';
                }

                if ($show_event_filter) {
                    $controls['event'] = function() use ($event_terms, $selected_event) { ?>
                        <div class="csched-field csched-event-filter">
                            <span class="csched-label" id="csched_lbl_event"><?php echo esc_html__('Event', 'convention-schedule'); ?></span>
                            <select id="csched_event" class="csched-control" name="csched_event" aria-label="<?php echo esc_attr__('Event', 'convention-schedule'); ?>" aria-labelledby="csched_lbl_event">
                                <option value=""><?php echo esc_html__('All Events', 'convention-schedule'); ?></option>
                                <?php foreach ($event_terms as $t): ?>
                                    <option value="<?php echo esc_attr($t->slug); ?>" <?php selected($selected_event, $t->slug); ?>><?php echo esc_html($t->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php };
                } elseif ($selected_event !== '') {
                    echo '<input type="hidden" name="csched_event" value="' . esc_attr($selected_event) . '">';
                }

                $controls['category'] = function() use ($terms, $selected_cat) { ?>
                    <div class="csched-field csched-cat">
                        <span class="csched-label" id="csched_lbl_cat"><?php echo esc_html__('Category', 'convention-schedule'); ?></span>
                        <select id="csched_cat" class="csched-control" name="csched_cat" aria-label="<?php echo esc_attr__('Category', 'convention-schedule'); ?>" aria-labelledby="csched_lbl_cat">
                            <option value=""><?php echo esc_html__('All Categories', 'convention-schedule'); ?></option>
                            <?php foreach ($terms as $t): ?>
                                <option value="<?php echo esc_attr($t->slug); ?>" <?php selected($selected_cat, $t->slug); ?>><?php echo esc_html($t->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php };

                if ($show_group_by) {
                    $controls['group'] = function() use ($selected_group) { ?>
                        <div class="csched-field csched-group">
                            <span class="csched-label" id="csched_lbl_group"><?php echo esc_html__('Group By', 'convention-schedule'); ?></span>
                            <select id="csched_group" class="csched-control" name="csched_group" aria-label="<?php echo esc_attr__('Group By', 'convention-schedule'); ?>" aria-labelledby="csched_lbl_group">
                                <option value="date" <?php selected($selected_group, 'date'); ?>><?php echo esc_html__('Group: Date', 'convention-schedule'); ?></option>
                                <option value="category" <?php selected($selected_group, 'category'); ?>><?php echo esc_html__('Group: Category', 'convention-schedule'); ?></option>
                            </select>
                        </div>
                    <?php };
                } else {
                    echo '<input type="hidden" name="csched_group" value="' . esc_attr($selected_group) . '">';
                }

                $controls['room'] = function() use ($rooms, $selected_room) { ?>
                    <div class="csched-field csched-room">
                        <span class="csched-label" id="csched_lbl_room"><?php echo esc_html__('Room', 'convention-schedule'); ?></span>
                        <select id="csched_room" class="csched-control" name="csched_room" aria-label="<?php echo esc_attr__('Room', 'convention-schedule'); ?>" aria-labelledby="csched_lbl_room">
                            <option value=""><?php echo esc_html__('All Rooms', 'convention-schedule'); ?></option>
                            <?php foreach ($rooms as $r): ?>
                                <option value="<?php echo esc_attr($r); ?>" <?php selected($selected_room, $r); ?>><?php echo esc_html($r); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php };

                if ($show_view_switcher) {
                    $controls['view'] = function() use ($selected_view) { ?>
                        <div class="csched-field csched-view">
                            <span class="csched-label" id="csched_lbl_view"><?php echo esc_html__('View', 'convention-schedule'); ?></span>
                            <select id="csched_view" class="csched-control" name="csched_view" aria-label="<?php echo esc_attr__('View', 'convention-schedule'); ?>" aria-labelledby="csched_lbl_view">
                                <option value="table" <?php selected($selected_view, 'table'); ?>><?php echo esc_html__('Table', 'convention-schedule'); ?></option>
                                <option value="cards" <?php selected($selected_view, 'cards'); ?>><?php echo esc_html__('Cards', 'convention-schedule'); ?></option>
                                <option value="grid" <?php selected($selected_view, 'grid'); ?>><?php echo esc_html__('Grid', 'convention-schedule'); ?></option>
                            </select>
                        </div>
                    <?php };
                } else {
                    echo '<input type="hidden" name="csched_view" value="' . esc_attr($selected_view) . '">';
                }

                if ($show_search) {
                    $controls['search'] = function() use ($search_q) {
                        echo self::render_search_field($search_q);
                    };
                }

                foreach ($order as $k) {
                    if (isset($controls[$k]) && is_callable($controls[$k])) {
                        $controls[$k]();
                    }
                }
                ?>

                <?php if ($show_reset): ?>
                    <a class="csched-control csched-reset" href="<?php echo esc_url($base_url); ?>"><?php echo esc_html__('Reset Filters', 'convention-schedule'); ?></a>
                <?php endif; ?>
                <button class="csched-control csched-apply" type="submit"><?php echo esc_html__('Apply', 'convention-schedule'); ?></button>
            </form>
            <?php
        }
        return (string) ob_get_clean();
    }

    private static function category_color_for_item(array $it): string {
        if (empty($it['cats']) || !is_array($it['cats'])) return '';

        $colors = get_option(CS_Plugin::OPT_CATEGORY_COLORS, []);
        if (!is_array($colors)) $colors = [];

        $first_slug = '';
        foreach ($it['cats'] as $cat) {
            $slug = isset($cat['slug']) ? sanitize_title((string)$cat['slug']) : '';
            if ($slug === '') continue;
            if ($first_slug === '') $first_slug = $slug;
            if (!empty($colors[$slug])) {
                $hex = sanitize_hex_color((string)$colors[$slug]);
                if ($hex) return $hex;
            }
        }

        if ($first_slug === '') return '';
        $palette = ['#2f6fed', '#008a5b', '#d97706', '#b91c1c', '#7c3aed', '#0f766e', '#be185d', '#4b5563'];
        return $palette[abs(crc32($first_slug)) % count($palette)];
    }

    private static function category_colorbar_html(array $it): string {
        $color = self::category_color_for_item($it);
        if ($color === '') return '';
        return '<span class="csched-category-bar" style="display:block;width:100%;height:8px;min-height:8px;margin:0 0 12px 0;border-radius:6px;background:' . esc_attr($color) . ';" aria-hidden="true"></span>';
    }




    private static function normalize_smart_shortcode_atts($atts) {
        if (!is_array($atts)) return $atts;
        $out = [];
        foreach ($atts as $k => $v) {
            $key = is_string($k) ? str_replace(["\xE2\x80\x9C", "\xE2\x80\x9D", "“", "”", "‘", "’"], '', $k) : $k;
            if (is_string($v)) {
                $v = str_replace(["\xE2\x80\x9C", "\xE2\x80\x9D", "“", "”", "‘", "’"], '"', $v);
                $v = trim($v, "\"' \t\n\r\0\x0B");
            }
            $out[$key] = $v;
        }
        return $out;
    }


    private static function table_category_colorbar_html(array $it): string {
        $color = self::category_color_for_item($it);
        if ($color === '') return '';

        $width = (int) get_option(CS_Plugin::OPT_TABLE_BAR_WIDTH, 25);
        if ($width < 1) $width = 1;
        if ($width > 100) $width = 100;

        return '<span class="csched-category-bar csched-table-category-bar" style="display:block;width:' . esc_attr((string)$width) . '% !important;max-width:100% !important;min-width:0 !important;height:8px;min-height:8px;margin:0 0 8px 0;border-radius:4px;background:' . esc_attr($color) . ';" aria-hidden="true"></span>';
    }


public static function render_schedule($atts): string {
        $atts = self::normalize_smart_shortcode_atts($atts);
        $atts = shortcode_atts([
            'schedule' => '',
            'show_schedule_filter' => '',
            'event' => '',
            'show_event_filter' => '',
            'scroll_current' => '',
            'past_grace' => '',
            'layout' => '',
            'heading' => '',
            'show_heading' => '',
            'show_controls' => '',
            'show_view_switcher' => '',
            'show_reset' => '',
            'show_search' => '',
            'group_by' => '',
            'category' => '',
            'room' => '',
            'time_format' => '',
            'show_day_of_week' => '',
            'day_cutoff' => '',
            'show_categories' => '1',
            'show_description' => '1',
            'order' => 'ASC',
        ], $atts, 'convention_schedule');

        $scroll_current = ($atts['scroll_current'] !== '')
            ? ($atts['scroll_current'] !== '0')
            : ((string)get_option(CS_Plugin::OPT_SCROLL_CURRENT, '1') !== '0');
        $past_grace = ($atts['past_grace'] !== '')
            ? max(0, min(1440, (int)$atts['past_grace']))
            : max(0, min(1440, (int)get_option(CS_Plugin::OPT_PAST_GRACE, 30)));

        $layout = sanitize_text_field($atts['layout']);
        if ($layout === '') $layout = (string)get_option(CS_Plugin::OPT_LAYOUT, 'table');
        if (!in_array($layout, ['table','cards','grid'], true)) $layout = 'table';

        $heading = sanitize_text_field($atts['heading']);
        if ($heading === '') $heading = (string)get_option(CS_Plugin::OPT_HEADING, '');

        $show_heading = $atts['show_heading'] !== '' ? ($atts['show_heading'] === '1') : ((string)get_option(CS_Plugin::OPT_SHOW_HEADING, '1') === '1');

        $show_controls = $atts['show_controls'] !== '' ? ($atts['show_controls'] === '1') : ((string)get_option(CS_Plugin::OPT_SHOW_CONTROLS, '1') === '1');

        $show_view_switcher = $atts['show_view_switcher'] !== ''
            ? ($atts['show_view_switcher'] === '1')
            : ((string)get_option(CS_Plugin::OPT_SHOW_VIEW_SWITCHER, '1') === '1');

        $show_reset = $atts['show_reset'] !== ''
            ? ($atts['show_reset'] === '1')
            : ((string)get_option(CS_Plugin::OPT_SHOW_RESET, '1') === '1');


        $show_search = $atts['show_search'] !== ''
            ? ($atts['show_search'] === '1')
            : ((string)get_option(CS_Plugin::OPT_SHOW_SEARCH, '1') !== '0');

        $show_schedule_filter = $atts['show_schedule_filter'] !== ''
            ? ($atts['show_schedule_filter'] === '1')
            : false;

        $show_event_filter = $atts['show_event_filter'] !== ''
            ? ($atts['show_event_filter'] === '1')
            : false;
$control_order_csv = (string) get_option(CS_Plugin::OPT_CONTROL_ORDER, 'category,group,room,view');

        
        // Ensure the Schedule control can render when enabled.
        if ($show_schedule_filter && strpos(',' . $control_order_csv . ',', ',schedule,') === false) {
            $control_order_csv = rtrim($control_order_csv, ", ");
            $control_order_csv = $control_order_csv ? ('schedule,' . $control_order_csv) : 'schedule';
        }

        // Ensure the Event control can render when enabled.
        if ($show_event_filter && strpos(',' . $control_order_csv . ',', ',event,') === false) {
            $control_order_csv = rtrim($control_order_csv, ", ");
            $control_order_csv = $control_order_csv ? ('event,' . $control_order_csv) : 'event';
        }

        // Ensure the Search control can render even if older saved control order doesn't include it.
        if ($show_search && strpos(',' . $control_order_csv . ',', ',search,') === false) {
            $control_order_csv = rtrim($control_order_csv, ", ");
            $control_order_csv = $control_order_csv ? ($control_order_csv . ',search') : 'search';
        }
$time_fmt = sanitize_text_field($atts['time_format']);
        if ($time_fmt === '') $time_fmt = (string)get_option(CS_Plugin::OPT_TIME_FMT, '24');
        $time_fmt = ($time_fmt === '12') ? '12' : '24';

        $show_dow = $atts['show_day_of_week'] !== '' ? ($atts['show_day_of_week'] === '1') : ((string)get_option(CS_Plugin::OPT_SHOW_DOW, '0') === '1');

        $order = (strtoupper($atts['order']) === 'DESC') ? 'DESC' : 'ASC';

        $show_cats = ($atts['show_categories'] === '0') ? false : true;
        $show_desc = ($atts['show_description'] === '0') ? false : true;

        // Elementor editor/preview context detection (Elementor often renders shortcodes via admin-ajax).
        $is_elementor = self::is_elementor_context();
        // End-of-day cutoff minutes (events after midnight can be treated as previous day)
        $cutoff_minutes = self::get_day_cutoff_minutes( isset($atts['day_cutoff']) ? sanitize_text_field((string)$atts['day_cutoff']) : '' );


        // Determine requested filters from URL first (when controls enabled)
        $selected_schedule = '';
        $selected_event = '';
        $selected_cat = '';
        $selected_group = '';
        $selected_room = '';
        $search_q = '';
        $selected_view = '';

        if ($show_controls) {
            $selected_schedule = isset($_GET['csched_schedule']) ? sanitize_title(wp_unslash((string)$_GET['csched_schedule'])) : '';
            $selected_event = isset($_GET['csched_event']) ? sanitize_title(wp_unslash((string)$_GET['csched_event'])) : '';
            $selected_cat = isset($_GET['csched_cat']) ? sanitize_text_field((string)$_GET['csched_cat']) : '';
            $selected_group = isset($_GET['csched_group']) ? sanitize_text_field((string)$_GET['csched_group']) : '';
            $selected_room = isset($_GET['csched_room']) ? sanitize_text_field((string)$_GET['csched_room']) : '';
            $search_q = isset($_GET['csched_q']) ? sanitize_text_field(wp_unslash((string)$_GET['csched_q'])) : '';
            if ($show_view_switcher) {
                $selected_view = isset($_GET['csched_view']) ? sanitize_text_field((string)$_GET['csched_view']) : '';
            }
        }

        // If controls are off, view switcher cannot be used.
        if (!$show_controls) $show_view_switcher = false;

        // Determine effective layout from view switcher (URL) if enabled
        if ($show_view_switcher && in_array($selected_view, ['table','cards','grid'], true)) {
            $layout = $selected_view;
        }

        // Ensure a stable selected view value for rendering controls
        if ($selected_view === '' || !in_array($selected_view, ['table','cards','grid'], true)) {
            $selected_view = $layout;
        }

        // Allow shortcode defaults to apply if no URL selection
        if ($selected_schedule === '') $selected_schedule = sanitize_title($atts['schedule']);
        if ($selected_event === '') $selected_event = sanitize_title($atts['event']);
        if ($selected_cat === '') $selected_cat = sanitize_text_field($atts['category']);
        if ($selected_room === '') $selected_room = sanitize_text_field($atts['room']);

        $group_by = sanitize_text_field($atts['group_by']);
        if ($group_by === '') $group_by = (string)get_option(CS_Plugin::OPT_GROUP_BY, 'date');
        if ($selected_group !== '') $group_by = $selected_group;
        if (!in_array($group_by, ['date','category'], true)) $group_by = 'date';

        // Build query
        // Do NOT require date/time meta rows here. Some manually-created/imported workshops may
        // have a title/content/category but a missing date/start meta row, and an EXISTS meta_query
        // silently excludes them. We query published workshops first, then sort/group in PHP.
        $args = [
            'post_type' => CS_Plugin::CPT,
            'post_status' => ['publish', 'future'],
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ];

        $tax_query = [];

        if ($selected_schedule !== '') {
            $tax_query[] = [
                'taxonomy' => CS_Plugin::SCHEDULE_TAX,
                'field' => 'slug',
                'terms' => $selected_schedule,
            ];
        }

        if ($selected_event !== '') {
            $tax_query[] = [
                'taxonomy' => CS_Plugin::EVENT_TAX,
                'field' => 'slug',
                'terms' => $selected_event,
            ];
        }

        if ($selected_cat !== '') {
            $tax_query[] = [
                'taxonomy' => CS_Plugin::TAX,
                'field' => 'slug',
                'terms' => $selected_cat,
            ];
        }

        if (!empty($tax_query)) {
            if (count($tax_query) > 1) $tax_query['relation'] = 'AND';
            $args['tax_query'] = $tax_query;
        }


        if ($show_search && $search_q !== '') {
            $args['csched_title_search'] = $search_q;
            add_filter('posts_search', [__CLASS__, 'title_only_posts_search'], 10, 2);
        }

        $q = new WP_Query($args);

        if ($show_search && $search_q !== '') {
            remove_filter('posts_search', [__CLASS__, 'title_only_posts_search'], 10);
        }

        if (!$q->have_posts()) {
            return '<div class="csched-wrap" data-csched-scroll-current="0"><!-- csched: query returned no published workshops --><div class="csched-empty">No scheduled items found.</div></div>';
        }

        // Build grouped data
        $room_index = self::build_room_index();

        $items = [];
        foreach ($q->posts as $p) {
            $date = (string)get_post_meta($p->ID, CS_Admin::META_DATE, true);
            $start = (string)get_post_meta($p->ID, CS_Admin::META_START, true);
            $end = (string)get_post_meta($p->ID, CS_Admin::META_END, true);
            $roomv = (string)get_post_meta($p->ID, CS_Admin::META_ROOM, true);
            $previous_day = (string)get_post_meta($p->ID, CS_Admin::META_PREVIOUS_DAY, true);

            $expanded_rooms = self::expanded_rooms_for_item($roomv, $room_index);
            if ($selected_room !== '' && !in_array($selected_room, $expanded_rooms, true)) {
                continue;
            }
            $cats = [];
            $terms = get_the_terms($p->ID, CS_Plugin::TAX);
            if (!is_wp_error($terms) && !empty($terms)) {
                foreach ($terms as $t) $cats[] = ['name'=>$t->name,'slug'=>$t->slug];
            }

            $items[] = [
                'post' => $p,
                'date' => $date,
                'start' => $start,
                'end' => $end,
                'room' => $roomv,
                'cats' => $cats,
                'previous_day' => ($previous_day === '1'),
                'effective_date' => self::effective_date_for_item($date, $start, $cutoff_minutes, $previous_day === '1'),
                'sort_minutes' => self::effective_sort_minutes($start, $cutoff_minutes, $previous_day === '1'),
            ];
        }

        // Grouping logic: date or category (table/cards). grid always date-driven with room columns.
        $show_group_by_select = ($layout !== 'grid'); // grid doesn't truly "group by category"
        $effective_group_by = ($layout === 'grid') ? 'date' : $group_by;

        $groups = []; // key => list
        if ($effective_group_by === 'category') {
            foreach ($items as $it) {
                if (empty($it['cats'])) {
                    $key = '__uncat';
                    $label = __('Uncategorized', 'convention-schedule');
                } else {
                    // use first category as primary for grouping
                    $key = $it['cats'][0]['slug'];
                    $label = $it['cats'][0]['name'];
                }
                if (!isset($groups[$key])) $groups[$key] = ['label'=>$label,'items'=>[]];
                $groups[$key]['items'][] = $it;
            }
            // sort groups by label
            uasort($groups, function($a,$b){ return strcasecmp($a['label'], $b['label']); });
        } else {
            foreach ($items as $it) {
                $key = ($it['effective_date'] ?: $it['date']) ?: 'unscheduled';
                if (!isset($groups[$key])) $groups[$key] = ['label'=>$key,'items'=>[]];
                $groups[$key]['items'][] = $it;
            }
            $keys = array_keys($groups);
            sort($keys);
            if ($order === 'DESC') $keys = array_reverse($keys);
            $sorted = [];
            foreach ($keys as $k) $sorted[$k] = $groups[$k];
            $groups = $sorted;
        }

	        // Sort items inside each group using cutoff-adjusted minutes.
	        // This ensures that after-midnight items (treated as previous day) appear at the end of that day.
	        foreach ($groups as $k => $g) {
	            if (empty($groups[$k]['items']) || !is_array($groups[$k]['items'])) continue;
	            usort($groups[$k]['items'], function($a, $b) use ($order) {
	                $ad = $a['effective_date'] ?? ($a['date'] ?? '');
	                $bd = $b['effective_date'] ?? ($b['date'] ?? '');
	                if ($ad !== $bd) {
	                    $cmp = strcmp((string)$ad, (string)$bd);
	                    return ($order === 'DESC') ? -$cmp : $cmp;
	                }
	                $am = $a['sort_minutes'] ?? null;
	                $bm = $b['sort_minutes'] ?? null;
	                if ($am === null) $am = -1;
	                if ($bm === null) $bm = -1;
	                if ($am === $bm) return 0;
	                return ($order === 'DESC') ? (($am < $bm) ? 1 : -1) : (($am < $bm) ? -1 : 1);
	            });
	        }

        $behavior_attrs = ' data-csched-scroll-current="' . ($scroll_current ? '1' : '0') . '" data-csched-past-grace="' . esc_attr((string)$past_grace) . '"';

        ob_start();
        ?>
        <div class="csched-wrap"<?php echo $behavior_attrs; ?>>
            <?php if ($show_heading && $heading !== ''): ?>
                <div class="csched-heading"><?php echo esc_html($heading); ?></div>
            <?php endif; ?>

            <?php if ($show_controls): ?>
                <?php echo self::render_controls($selected_schedule, $show_schedule_filter, $selected_event, $show_event_filter, $selected_cat, $group_by, $selected_room, $selected_view, $show_group_by_select, $show_view_switcher, $show_reset, $show_search, $search_q, $control_order_csv, $is_elementor); ?>
            <?php endif; ?>

            <?php if ($layout === 'cards'): ?>
                <?php foreach ($groups as $gkey => $g): ?>
                    <?php
                    $label = ($effective_group_by === 'date') ? (($gkey === 'unscheduled') ? __('Unscheduled', 'convention-schedule') : self::pretty_date($gkey, $show_dow)) : $g['label'];
                    ?>
                    <div class="csched-day csched-current-day-marker" data-csched-day="<?php echo esc_attr($gkey); ?>"><?php echo esc_html($label); ?></div>
                    <div class="csched-cards">
                        <?php foreach ($g['items'] as $it): ?>
                            <?php
                            $p = $it['post'];
                            $time = self::time_range_display($it['start'], $it['end'], $time_fmt);
                            $roomv = $it['room'];
                            $cats_names = [];
                            if ($show_cats && !empty($it['cats'])) foreach ($it['cats'] as $c) $cats_names[] = $c['name'];

                            $desc = '';
                            if ($show_desc) {
                                $desc_raw = $p->post_content ? apply_filters('the_content', $p->post_content) : '';
                                $desc = trim($desc_raw);
                            }
                            ?>
                            <div class="csched-card csched-event" data-csched-date="<?php echo esc_attr($it['date']); ?>" data-csched-start="<?php echo esc_attr($it['start']); ?>" data-csched-end="<?php echo esc_attr($it['end']); ?>">
                                <?php echo self::category_colorbar_html($it); ?>


<div class="csched-card-head">
                                    <div class="csched-card-time"><?php echo esc_html($time); ?></div>
                                    <?php if ($roomv !== ''): ?><div class="csched-card-room"><?php echo esc_html($roomv); ?></div><?php endif; ?>
<div class="csched-title"><?php echo esc_html(get_the_title($p)); ?></div>
                                </div>
                                <?php if (!empty($cats_names)): ?>
                                    <div class="csched-cats"><?php echo esc_html(implode(' • ', $cats_names)); ?></div>
                                <?php endif; ?>
                                <?php if ($show_desc && $desc !== ''): ?>
                                    <div class="csched-desc"><?php echo $desc; ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>

            <?php elseif ($layout === 'grid'): ?>
                <?php
                // Grid is always per date group
                foreach ($groups as $date_key => $g):
                    $date_label = self::pretty_date($date_key, $show_dow);

                    // derive room columns
                    $rooms = [];
                    foreach ($g['items'] as $it) {
                        $r = trim((string)$it['room']);
                        if ($r === '') $r = __('(No Room)', 'convention-schedule');
                        $rooms[$r] = true;
                    }
                    $rooms = array_keys($rooms);
                    sort($rooms, SORT_NATURAL | SORT_FLAG_CASE);

                    
// derive 15-minute time slots (use cutoff-adjusted minutes so after-midnight appears at end of previous day)
$slot = 15;
$min_sm = null;
$max_em = null;

// bucket items by room and compute an "end_sort_minutes" for grid spanning
$items_by_room = [];
foreach ($g['items'] as &$it) {
    $sm = isset($it['sort_minutes']) ? $it['sort_minutes'] : null;
    if ($sm === null) continue;

    $start_base = self::parse_time_to_minutes($it['start']);
    if ($start_base === null) continue;

    $end_base = self::parse_time_to_minutes($it['end']);
    if ($end_base === null) $end_base = $start_base + $slot;

    // Handle sessions that cross midnight (end earlier than start)
    if ($end_base < $start_base) $end_base += 1440;

    // Apply cutoff adjustment to end as well
    if ($cutoff_minutes > 0) {
        if ($start_base < $cutoff_minutes) $start_base += 1440; // mirror effective_sort_minutes
        if ($end_base < $cutoff_minutes) $end_base += 1440;
    }

    // If end is still before start (edge cases), clamp
    if ($end_base < $start_base) $end_base = $start_base + $slot;

    $it['end_sort_minutes'] = (int)$end_base;

    if ($min_sm === null || $sm < $min_sm) $min_sm = $sm;
    if ($max_em === null || $end_base > $max_em) $max_em = $end_base;

    $room_key = trim((string)$it['room']);
    if ($room_key === '') $room_key = __('(No Room)', 'convention-schedule');
    if (!isset($items_by_room[$room_key])) $items_by_room[$room_key] = [];
    $items_by_room[$room_key][] = $it;
}
unset($it);

if ($min_sm === null) $min_sm = 0;
if ($max_em === null) $max_em = $min_sm + $slot;

// Align to 15-minute boundaries
$aligned_min = (int)(floor($min_sm / $slot) * $slot);
$aligned_max = (int)(ceil($max_em / $slot) * $slot);
if ($aligned_max <= $aligned_min) $aligned_max = $aligned_min + $slot;

// Build time slots array
$times = [];
for ($t = $aligned_min; $t <= $aligned_max; $t += $slot) $times[] = $t;
if ($order === 'DESC') $times = array_reverse($times);

// Build grid cell maps per room with rowspans
$grid_start = [];
$grid_skip  = [];

foreach ($rooms as $r) {
    $grid_start[$r] = [];
    $grid_skip[$r]  = [];
    $room_items = $items_by_room[$r] ?? [];
    if (empty($room_items)) continue;

    usort($room_items, function($a, $b){
        return ((int)$a['sort_minutes']) <=> ((int)$b['sort_minutes']);
    });

    foreach ($room_items as $it) {
        $sm = (int)$it['sort_minutes'];
        $em = isset($it['end_sort_minutes']) ? (int)$it['end_sort_minutes'] : ($sm + $slot);

        $start_idx = (int)floor(($sm - $aligned_min) / $slot);
        if ($start_idx < 0) $start_idx = 0;
        $dur = max(1, (int)ceil(($em - $sm) / $slot));

        // Clamp duration so we don't exceed the grid
        $max_rows = count($times);
        if ($start_idx >= $max_rows) continue;
        if ($start_idx + $dur > $max_rows) $dur = $max_rows - $start_idx;

        // If any row in the span is already occupied by a rowspan, fall back to single-row placement
        $collision = false;
        if ($dur > 1) {
            for ($k = $start_idx; $k < $start_idx + $dur; $k++) {
                if (!empty($grid_skip[$r][$k]) || (isset($grid_start[$r][$k]) && $k !== $start_idx)) {
                    $collision = true;
                    break;
                }
            }
        }
        if ($collision) $dur = 1;

        if (!isset($grid_start[$r][$start_idx])) {
            $grid_start[$r][$start_idx] = [
                'rowspan' => $dur,
                'items'   => [],
            ];
        } else {
            // If there are multiple items with same start, do not rowspan (list them in one cell)
            $grid_start[$r][$start_idx]['rowspan'] = 1;
            $dur = 1;
        }

        $grid_start[$r][$start_idx]['items'][] = $it;

        // Mark skipped rows for rowspan
        if ($dur > 1) {
            for ($k = $start_idx + 1; $k < $start_idx + $dur; $k++) {
                $grid_skip[$r][$k] = true;
            }
        }
    }
}

?>
                    <div class="csched-day csched-current-day-marker" data-csched-day="<?php echo esc_attr($date_key); ?>"><?php echo esc_html($date_label); ?></div>

                    <div class="csched-grid-wrap">
                        <table class="csched-grid">
                            <thead>
                                <tr>
                                    <th class="csched-grid-time"><?php echo esc_html__('Time', 'convention-schedule'); ?></th>
                                    <?php foreach ($rooms as $r): ?>
                                        <th><?php echo esc_html($r); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($times as $i => $t): ?>
                                    <tr>
                                        <td class="csched-grid-time"><?php echo esc_html(self::time_display(self::minutes_to_hhmm((int)$t), $time_fmt)); ?></td>
                                        <?php foreach ($rooms as $r): ?>
                                            <?php
                                            if (!empty($grid_skip[$r][$i])) {
                                                continue;
                                            }

                                            $cell = $grid_start[$r][$i] ?? null;
                                            if ($cell) {
                                                $rowspan = (int)$cell['rowspan'];
                                                echo '<td' . ($rowspan > 1 ? ' rowspan="' . esc_attr($rowspan) . '"' : '') . '>';
                                                foreach ($cell['items'] as $it) {
                                                    $p = $it['post'];
                                                    $time = self::time_range_display($it['start'], $it['end'], $time_fmt);

                                                    $cats_names = [];
                                                    if ($show_cats && !empty($it['cats'])) foreach ($it['cats'] as $c) $cats_names[] = $c['name'];

                                                    $desc = '';
                                                    if ($show_desc) {
                                                        $desc_raw = $p->post_content ? apply_filters('the_content', $p->post_content) : '';
                                                        $desc = trim($desc_raw);
                                                    }

                                                    echo '<div class="csched-grid-item csched-event" data-csched-date="' . esc_attr($it['date']) . '" data-csched-start="' . esc_attr($it['start']) . '" data-csched-end="' . esc_attr($it['end']) . '">';
                                                    echo self::category_colorbar_html($it);
                                                    echo '<div class="csched-meta">' . esc_html($time) . '</div>';
                                                    echo '<div class="csched-title">' . esc_html(get_the_title($p)) . '</div>';
                                                    if (!empty($cats_names)) {
                                                        echo '<div class="csched-cats">' . esc_html(implode(' • ', $cats_names)) . '</div>';
                                                    }
                                                    if ($show_desc && $desc !== '') {
                                                        echo '<div class="csched-desc">' . $desc . '</div>';
                                                    }
                                                    echo '</div>';
                                                }
                                                echo '</td>';
                                            } else {
                                                echo '<td></td>';
                                            }
                                            ?>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>

            <?php else: /* table */ ?>
                <?php foreach ($groups as $gkey => $g): ?>
                    <?php
                    $label = ($effective_group_by === 'date') ? (($gkey === 'unscheduled') ? __('Unscheduled', 'convention-schedule') : self::pretty_date($gkey, $show_dow)) : $g['label'];
                    ?>
                    <div class="csched-day csched-current-day-marker" data-csched-day="<?php echo esc_attr($gkey); ?>"><?php echo esc_html($label); ?></div>

                    <table class="csched-table">
                        <thead>
                            <tr>
                                <th class="csched-time"><?php echo esc_html__('Time', 'convention-schedule'); ?></th>
                                <th><?php echo esc_html__('Session', 'convention-schedule'); ?></th>
                                <th class="csched-room-col"><?php echo esc_html__('Room', 'convention-schedule'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($g['items'] as $it): ?>
                                <?php
                                $p = $it['post'];
                                $time = self::time_range_display($it['start'], $it['end'], $time_fmt);
                                $roomv = $it['room'];
                                $cats_names = [];
                                if ($show_cats && !empty($it['cats'])) foreach ($it['cats'] as $c) $cats_names[] = $c['name'];

                                $desc = '';
                                if ($show_desc) {
                                    $desc_raw = $p->post_content ? apply_filters('the_content', $p->post_content) : '';
                                    $desc = trim($desc_raw);
                                }
                                ?>
                                <tr class="csched-event" data-csched-date="<?php echo esc_attr($it['date']); ?>" data-csched-start="<?php echo esc_attr($it['start']); ?>" data-csched-end="<?php echo esc_attr($it['end']); ?>">
                                    <td class="csched-time" data-label="<?php echo esc_attr__('Time', 'convention-schedule'); ?>"><?php echo esc_html($time); ?></td>
                                    <td data-label="<?php echo esc_attr__('Session', 'convention-schedule'); ?>">
                                        <?php echo self::table_category_colorbar_html($it); ?>
                                        <div class="csched-title"><?php echo esc_html(get_the_title($p)); ?></div>
                                        <?php if (!empty($cats_names)): ?>
                                            <div class="csched-cats"><?php echo esc_html(implode(' • ', $cats_names)); ?></div>
                                        <?php endif; ?>
                                        <?php if ($show_desc && $desc !== ''): ?>
                                            <div class="csched-desc"><?php echo $desc; ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="csched-room-col" data-label="<?php echo esc_attr__('Room', 'convention-schedule'); ?>"><?php echo esc_html($roomv); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php

        ?>
        <script>
        (function(){
          if (window.__cschedCurrentTimeInit) return;
          window.__cschedCurrentTimeInit = true;
          function parseLocal(date,time){
            if(!date) return null;
            var p=String(date).split('-').map(Number); if(p.length<3) return null;
            var t=String(time||'00:00').split(':').map(Number);
            return new Date(p[0],p[1]-1,p[2],t[0]||0,t[1]||0,0,0);
          }
          function initSchedule(root){
            if(!root || root.dataset.cschedReady==='1') return;
            root.dataset.cschedReady='1';
            var doScroll=root.getAttribute('data-csched-scroll-current')!=='0';
            var grace=parseInt(root.getAttribute('data-csched-past-grace')||'0',10); if(isNaN(grace)||grace<0) grace=0;
            var now=new Date(), current=null, next=null;
            Array.prototype.slice.call(root.querySelectorAll('.csched-event[data-csched-date]')).forEach(function(el){
              var s=parseLocal(el.getAttribute('data-csched-date'),el.getAttribute('data-csched-start')||'00:00');
              var e=parseLocal(el.getAttribute('data-csched-date'),el.getAttribute('data-csched-end')||el.getAttribute('data-csched-start')||'00:00');
              if(!s||!e) return; if(e<s) e.setDate(e.getDate()+1);
              var graceEnd=new Date(e.getTime()+grace*60000);
              if(now>graceEnd) el.classList.add('csched-past-event');
              if(now>=s && now<=graceEnd){ if(!current || s<current.start) current={el:el,start:s}; }
              else if(s>now){ if(!next || s<next.start) next={el:el,start:s}; }
            });
            var target=current?current.el:(next?next.el:null);
            if(current) current.el.classList.add('csched-current-event');
            if(doScroll && target){ setTimeout(function(){ try{target.scrollIntoView({behavior:'smooth',block:'center'});}catch(e){target.scrollIntoView();} },350); }
            else if(doScroll){
              var y=now.getFullYear(),m=String(now.getMonth()+1).padStart(2,'0'),d=String(now.getDate()).padStart(2,'0');
              var marker=root.querySelector('[data-csched-day="'+y+'-'+m+'-'+d+'"]');
              if(marker) setTimeout(function(){ try{marker.scrollIntoView({behavior:'smooth',block:'start'});}catch(e){marker.scrollIntoView();} },350);
            }
          }
          function initAll(){ document.querySelectorAll('.csched-wrap[data-csched-scroll-current]').forEach(initSchedule); }
          if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',initAll); else initAll();
        })();
        </script>
        <?php
        wp_reset_postdata();
        return (string) ob_get_clean();
    }
    /**
     * Render the Title Search field HTML (public + Elementor).
     */
    private static function render_search_field(string $search_q): string {
        $id = 'csched_lbl_q';
        $label = esc_html__('Search', 'convention-schedule');
        $ph = esc_attr__('Title…', 'convention-schedule');
        $val = esc_attr($search_q);

        return '<div class="csched-field csched-search">' .
               '<span class="csched-label" id="' . esc_attr($id) . '">' . $label . '</span>' .
               '<input id="csched_q" class="csched-control csched-input" type="search" name="csched_q" value="' . $val . '" placeholder="' . $ph . '" aria-label="' . esc_attr__('Search by title', 'convention-schedule') . '" aria-labelledby="' . esc_attr($id) . '" />' .
               '</div>';
    }


    private static function get_items_for_filters(): array {
        $q = new WP_Query([
            'post_type'      => CS_Plugin::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);
        $items = [];
        foreach ($q->posts as $pid) {
            $items[] = [
                'room' => (string) get_post_meta($pid, CS_Admin::META_ROOM, true),
            ];
        }
        return $items;
    }


    /**
     * Build a room index that treats numbered rooms (e.g. "Aspen 1", "Aspen 2") as canonical rooms,
     * while base-only ("Aspen") and combo/range labels ("Aspen 1 & 2", "Glen 1-3") expand into
     * their component numbered rooms (not separate rooms).
     *
     * Returns:
     *  - canonical: room labels for dropdown/grid columns
     *  - expand: map raw room label => array of canonical room labels
     *  - by_base: map base => ordered array of canonical numbered rooms
     */
    private static function build_room_index(): array {
        $raw_rooms = [];
        foreach (self::get_items_for_filters() as $it) {
            $r = isset($it['room']) ? (string)$it['room'] : '';
            $r = trim(preg_replace('/\s+/', ' ', $r));
            if ($r !== '') $raw_rooms[$r] = true;
        }
        $raw_rooms = array_keys($raw_rooms);

        // Detect numbered rooms by base: "Base 1"
        $by_base = [];
        $numbered = [];
        foreach ($raw_rooms as $r) {
            if (preg_match('/^(.*?)[\s]+(\d+)\s*$/u', $r, $m)) {
                $base = trim(preg_replace('/\s+/', ' ', $m[1]));
                $num  = (int)$m[2];
                if ($base !== '' && $num > 0) {
                    $label = $base . ' ' . $num;
                    $numbered[$label] = true;
                    $by_base[$base][$num] = $label;
                }
            }
        }
        foreach ($by_base as $base => $nums) {
            ksort($nums, SORT_NUMERIC);
            $by_base[$base] = array_values($nums);
        }

        // Canonical list: all numbered rooms + standalone rooms (excluding base-only and combos that expand)
        $canonical = [];
        foreach ($numbered as $label => $_) $canonical[$label] = true;

        foreach ($raw_rooms as $r) {
            // If "Aspen" base exists and has numbered rooms, exclude it from canonical.
            if (!preg_match('/\d/u', $r) && isset($by_base[$r])) {
                continue;
            }

            // If combo/range expands to multiple canonical rooms, exclude it from canonical.
            if ((strpos($r, '&') !== false || strpos($r, '-') !== false || strpos($r, '–') !== false) && preg_match('/\d/u', $r)) {
                $exp = self::expand_room_label($r, $by_base);
                if (count($exp) > 1) continue;
            }

            // Keep non-numbered standalone rooms (that are not bases for numbered rooms)
            if (!preg_match('/\d/u', $r)) {
                $canonical[$r] = true;
            } else {
                // Keep any numbered-looking room that doesn't match our strict regex
                if (!isset($canonical[$r])) $canonical[$r] = true;
            }
        }

        $canonical_list = array_keys($canonical);
        natcasesort($canonical_list);
        $canonical_list = array_values($canonical_list);

        $expand = [];
        foreach ($raw_rooms as $r) {
            $expand[$r] = self::expand_room_label($r, $by_base);
        }

        return [
            'canonical' => $canonical_list,
            'expand'    => $expand,
            'by_base'   => $by_base,
        ];
    }

    /**
     * Expand a raw room label into canonical room labels.
     */
    private static function expand_room_label(string $room, array $by_base): array {
        $room = trim(preg_replace('/\s+/', ' ', $room));
        if ($room === '') return [];

        // Exact numbered
        if (preg_match('/^(.*?)[\s]+(\d+)\s*$/u', $room, $m)) {
            $base = trim(preg_replace('/\s+/', ' ', $m[1]));
            $num  = (int)$m[2];
            if ($base !== '' && $num > 0) return [$base . ' ' . $num];
        }

        // Base-only expands to all numbered rooms
        if (!preg_match('/\d/u', $room) && isset($by_base[$room])) {
            return $by_base[$room];
        }

        // Combo/range: infer base from first "Base N"
        if (preg_match('/^(.*?)[\s]+(\d+)/u', $room, $m)) {
            $base = trim(preg_replace('/\s+/', ' ', $m[1]));
            if ($base !== '' && isset($by_base[$base])) {
                $nums = [];

                // Ranges like 1-3 or 1–3
                if (preg_match_all('/(\d+)\s*[-–]\s*(\d+)/u', $room, $rm, PREG_SET_ORDER)) {
                    foreach ($rm as $pair) {
                        $a = (int)$pair[1]; $b = (int)$pair[2];
                        if ($a > 0 && $b > 0) {
                            $lo = min($a,$b); $hi = max($a,$b);
                            for ($n=$lo; $n<=$hi; $n++) $nums[$n] = true;
                        }
                    }
                } else {
                    // Otherwise grab all digits
                    if (preg_match_all('/\b(\d+)\b/u', $room, $dm)) {
                        foreach ($dm[1] as $d) {
                            $n = (int)$d;
                            if ($n > 0) $nums[$n] = true;
                        }
                    }
                }

                if ($nums) {
                    ksort($nums, SORT_NUMERIC);
                    $out = [];
                    foreach (array_keys($nums) as $n) {
                        $label = $base . ' ' . $n;
                        if (in_array($label, $by_base[$base], true)) $out[] = $label;
                    }
                    if ($out) return $out;
                }
            }
        }

        // Default: stand-alone room
        return [$room];
    }

    private static function expanded_rooms_for_item(string $room, array $room_index): array {
        $room = trim(preg_replace('/\s+/', ' ', $room));
        if ($room === '') return [];
        if (isset($room_index['expand'][$room])) return $room_index['expand'][$room];
        return [$room];
    }


}