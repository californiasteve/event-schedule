<?php
if (!defined('ABSPATH')) exit;

class CS_Plugin {

    const CPT = 'cs_workshop';
    const TAX = 'cs_category';
    const EVENT_TAX = 'cs_event';
    const SCHEDULE_TAX = 'cs_schedule';


    // Post meta keys
    const META_DATE  = '_csched_date';
    const META_START = '_csched_start';
    const META_END   = '_csched_end';
    const META_ROOM  = '_csched_room';
    const META_EVENT = '_csched_event';
    const META_PREVIOUS_DAY = '_csched_previous_day';
    const META_SCHEDULE = '_csched_schedule'; // schedule slug // 1|0 display on previous schedule day
    const OPT_CSS = 'csched_custom_css';
    const OPT_LAYOUT = 'csched_default_layout';
    const OPT_HEADING = 'csched_heading_text';
    const OPT_SHOW_HEADING = 'csched_show_heading';
    const OPT_TIME_FMT = 'csched_time_format'; // 24|12
    const OPT_SHOW_DOW = 'csched_show_day_of_week'; // 1|0
    const OPT_SHOW_CONTROLS = 'csched_show_controls'; // 1|0
    const OPT_GROUP_BY = 'csched_default_group_by'; // date|category
    const OPT_SHOW_SEARCH = 'csched_show_search'; // 1|0
    const OPT_DAY_CUTOFF = 'csched_day_cutoff'; // legacy, unused since 1.8 (replaced by per-workshop checkbox)
    // After-midnight grace period: workshops starting between midnight and this many minutes
    // after it are shown at the end of the previous day. 0 = off.
    const OPT_DAY_EXTENSION = 'csched_day_extension';

    // Allow users to switch between table/cards/grid on the front-end controls bar
    const OPT_SHOW_VIEW_SWITCHER = 'csched_show_view_switcher'; // 1|0

    // Control widths (percent)
    const OPT_W_CAT = 'csched_width_category';
    const OPT_W_GROUP = 'csched_width_group';
    const OPT_W_ROOM = 'csched_width_room';

    // Front-end controls: order of selects (comma-separated keys)
    // Allowed: category, group, room, view
    const OPT_CONTROL_ORDER = 'csched_control_order';

    // Front-end controls: show reset filters button
    const OPT_SHOW_RESET = 'csched_show_reset_filters';


    const OPT_SCROLL_CURRENT = 'csched_scroll_current'; // 1|0
    const OPT_PAST_GRACE = 'csched_past_grace_minutes'; // integer minutes
    const OPT_SAVED_ROOMS = 'csched_saved_rooms'; // newline separated room list
    const OPT_CATEGORY_COLORS = 'csched_category_colors'; // slug=>hex map
    const OPT_TABLE_BAR_WIDTH = 'csched_table_bar_width'; // percent
    // Migration bookkeeping
    const OPT_DB_VERSION = 'csched_db_version';
    const OPT_MIGRATION_REPORT = 'csched_events_migration_report';
    const OPT_MIGRATION_NOTICE = 'csched_events_migration_notice'; // 1 = show admin notice once
    const DB_VERSION = '1.9.0';

    public static function init(): void {
        add_action('init', [__CLASS__, 'register_cpt_and_tax']);
        // Runs after taxonomies are registered (priority 10) so term functions work.
        add_action('init', [__CLASS__, 'maybe_migrate'], 20);
        add_action('wp_head', [__CLASS__, 'output_custom_css'], 50);
        add_action('wp_head', [__CLASS__, 'output_base_css'], 49);

        if (is_admin()) {
            CS_Admin::init();
        }

        CS_Shortcodes::init();
    }

    public static function activate(): void {
        self::register_cpt_and_tax();
        flush_rewrite_rules();

        // Always (re)run on activation. The migration is idempotent.
        self::migrate_events_to_schedules();
        update_option(self::OPT_DB_VERSION, self::DB_VERSION);

        // Initialize defaults
        if (get_option(self::OPT_CSS, null) === null) update_option(self::OPT_CSS, self::default_css());
        if (get_option(self::OPT_LAYOUT, null) === null) update_option(self::OPT_LAYOUT, 'table');
        if (get_option(self::OPT_HEADING, null) === null) update_option(self::OPT_HEADING, 'Schedule');
        if (get_option(self::OPT_SHOW_HEADING, null) === null) update_option(self::OPT_SHOW_HEADING, '1');
        if (get_option(self::OPT_TIME_FMT, null) === null) update_option(self::OPT_TIME_FMT, '24');
        if (get_option(self::OPT_SHOW_DOW, null) === null) update_option(self::OPT_SHOW_DOW, '0');
        if (get_option(self::OPT_SHOW_CONTROLS, null) === null) update_option(self::OPT_SHOW_CONTROLS, '1');
        if (get_option(self::OPT_SHOW_VIEW_SWITCHER, null) === null) update_option(self::OPT_SHOW_VIEW_SWITCHER, '1');
        if (get_option(self::OPT_GROUP_BY, null) === null) update_option(self::OPT_GROUP_BY, 'date');
        if (get_option(self::OPT_DAY_CUTOFF, null) === null) update_option(self::OPT_DAY_CUTOFF, '01:00');

        if (get_option(self::OPT_W_CAT, null) === null) update_option(self::OPT_W_CAT, '15');
        if (get_option(self::OPT_W_GROUP, null) === null) update_option(self::OPT_W_GROUP, '15');
        if (get_option(self::OPT_W_ROOM, null) === null) update_option(self::OPT_W_ROOM, '15');

        // Control order defaults include search once
        if (get_option(self::OPT_CONTROL_ORDER, null) === null) {
            update_option(self::OPT_CONTROL_ORDER, 'category,group,room,view,search');
        }

        if (get_option(self::OPT_SHOW_RESET, null) === null) update_option(self::OPT_SHOW_RESET, '1');
        if (get_option(self::OPT_SCROLL_CURRENT, null) === null) update_option(self::OPT_SCROLL_CURRENT, '1');
        if (get_option(self::OPT_PAST_GRACE, null) === null) update_option(self::OPT_PAST_GRACE, '30');
        if (get_option(self::OPT_TABLE_BAR_WIDTH, null) === null) update_option(self::OPT_TABLE_BAR_WIDTH, '25');
        if (get_option(self::OPT_SHOW_SEARCH, null) === null || (string)get_option(self::OPT_SHOW_SEARCH, '') === '') {
            update_option(self::OPT_SHOW_SEARCH, '1');
        }
    } 
    public static function register_cpt_and_tax(): void {


        register_taxonomy(self::SCHEDULE_TAX, [self::CPT], [
            'labels' => [
                'name' => __('Schedules', 'convention-schedule'),
                'singular_name' => __('Schedule', 'convention-schedule'),
                'menu_name' => __('Schedules', 'convention-schedule'),
                'all_items' => __('All Schedules', 'convention-schedule'),
                'edit_item' => __('Edit Schedule', 'convention-schedule'),
                'update_item' => __('Update Schedule', 'convention-schedule'),
                'add_new_item' => __('Add New Schedule', 'convention-schedule'),
                'new_item_name' => __('New Schedule Name', 'convention-schedule'),
                'search_items' => __('Search Schedules', 'convention-schedule'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'edit.php?post_type=' . self::CPT,
            'hierarchical' => false,
            'show_admin_column' => true,
            'rewrite' => false,
        ]);

        // Legacy "Schedule Events" taxonomy (retired in 1.9.0).
        // Still registered, but hidden everywhere, so existing data stays readable for the
        // one-time migration into Schedules and for old event="" shortcodes.
        register_taxonomy(self::EVENT_TAX, [self::CPT], [
            'labels' => [
                'name' => __('Schedule Events (legacy)', 'convention-schedule'),
                'singular_name' => __('Schedule Event (legacy)', 'convention-schedule'),
            ],
            'public' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'show_in_nav_menus' => false,
            'show_in_rest' => false,
            'show_tagcloud' => false,
            'show_in_quick_edit' => false,
            'show_admin_column' => false,
            'hierarchical' => false,
            'rewrite' => false,
            'query_var' => false,
        ]);

        register_taxonomy(self::TAX, [self::CPT], [
            'labels' => [
                'name' => __('Schedule Categories', 'convention-schedule'),
                'singular_name' => __('Schedule Category', 'convention-schedule'),
            ],
            'public' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'hierarchical' => true,
            'show_admin_column' => true,
            'rewrite' => ['slug' => 'schedule-category'],
        ]);

        register_post_type(self::CPT, [
            'labels' => [
                'name' => __('Workshops', 'convention-schedule'),
                'singular_name' => __('Workshop', 'convention-schedule'),
                'add_new' => __('Add Workshop', 'convention-schedule'),
                'add_new_item' => __('Add New Workshop', 'convention-schedule'),
                'edit_item' => __('Edit Workshop', 'convention-schedule'),
                'new_item' => __('New Workshop', 'convention-schedule'),
                'view_item' => __('View Workshop', 'convention-schedule'),
                'search_items' => __('Search Workshops', 'convention-schedule'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-calendar-alt',
            'supports' => ['title', 'editor', 'excerpt'],
            'has_archive' => false,
            'rewrite' => false,
        ]);
    }

    /**
     * Run pending data migrations once per DB version (covers plugin updates, which do not
     * fire the activation hook).
     */
    public static function maybe_migrate(): void {
        $current = (string) get_option(self::OPT_DB_VERSION, '');
        if ($current !== '' && version_compare($current, self::DB_VERSION, '>=')) return;

        self::migrate_events_to_schedules();
        update_option(self::OPT_DB_VERSION, self::DB_VERSION);
    }

    /**
     * Move Schedule Event assignments into Schedules.
     *
     * Safe to run any number of times:
     * - A workshop that already has a Schedule (term or saved slug) is never changed.
     * - A workshop with no Schedule but with a Schedule Event is assigned to the Schedule with
     *   the same slug. That Schedule is created (copying the event's name and description) only
     *   if it does not exist yet; an existing Schedule with that slug is reused.
     * - Legacy event terms and the _csched_event meta are left in place, never deleted.
     *
     * @return array{workshops_moved:int, schedules_created:int, kept_existing:int, ran_at:string}
     */
    public static function migrate_events_to_schedules(): array {
        $report = [
            'workshops_moved' => 0,
            'schedules_created' => 0,
            'kept_existing' => 0,
            'ran_at' => gmdate('c'),
        ];

        if (!taxonomy_exists(self::EVENT_TAX) || !taxonomy_exists(self::SCHEDULE_TAX)) {
            return $report;
        }

        $ids = get_posts([
            'post_type' => self::CPT,
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'suppress_filters' => true,
        ]);

        foreach ((array) $ids as $post_id) {
            $post_id = (int) $post_id;

            // Legacy event: taxonomy term first, saved slug as fallback.
            $event_slug = '';
            $event_term = null;
            $event_terms = wp_get_object_terms($post_id, self::EVENT_TAX);
            if (!is_wp_error($event_terms) && !empty($event_terms)) {
                $event_term = $event_terms[0];
                $event_slug = (string) $event_term->slug;
            }
            if ($event_slug === '') {
                // Saved slug is only trusted while that event still exists; a slug left behind
                // by a deleted event must not be resurrected as a new schedule.
                $meta_slug = sanitize_title((string) get_post_meta($post_id, self::META_EVENT, true));
                if ($meta_slug !== '') {
                    $maybe = get_term_by('slug', $meta_slug, self::EVENT_TAX);
                    if ($maybe) {
                        $event_term = $maybe;
                        $event_slug = $meta_slug;
                    }
                }
            }
            if ($event_slug === '') continue;

            // Existing schedule wins.
            $schedule_slugs = wp_get_object_terms($post_id, self::SCHEDULE_TAX, ['fields' => 'slugs']);
            $has_schedule_term = !is_wp_error($schedule_slugs) && !empty($schedule_slugs);
            $schedule_meta = (string) get_post_meta($post_id, self::META_SCHEDULE, true);
            if ($has_schedule_term || $schedule_meta !== '') {
                $current = $has_schedule_term ? (string) $schedule_slugs[0] : $schedule_meta;
                if ($current !== $event_slug) $report['kept_existing']++;
                continue;
            }

            // Find or create the matching schedule.
            $schedule_term = get_term_by('slug', $event_slug, self::SCHEDULE_TAX);
            if (!$schedule_term) {
                $name = $event_term ? (string) $event_term->name : ucwords(str_replace(['-', '_'], ' ', $event_slug));
                $desc = $event_term ? (string) $event_term->description : '';
                $created = wp_insert_term($name, self::SCHEDULE_TAX, ['slug' => $event_slug, 'description' => $desc]);
                if (is_wp_error($created)) {
                    // A schedule with the same name but a different slug blocks creation; reuse it.
                    $existing_id = $created->get_error_data('term_exists');
                    $schedule_term = $existing_id ? get_term((int) $existing_id, self::SCHEDULE_TAX) : null;
                    if (!$schedule_term || is_wp_error($schedule_term)) continue;
                } else {
                    $schedule_term = get_term((int) $created['term_id'], self::SCHEDULE_TAX);
                    if (!$schedule_term || is_wp_error($schedule_term)) continue;
                    $report['schedules_created']++;
                }
            }

            $set = wp_set_object_terms($post_id, [(int) $schedule_term->term_id], self::SCHEDULE_TAX, false);
            if (is_wp_error($set)) continue;
            update_post_meta($post_id, self::META_SCHEDULE, (string) $schedule_term->slug);
            $report['workshops_moved']++;
        }

        // Saved control order: "event" is no longer a control; it becomes "schedule".
        $order = (string) get_option(self::OPT_CONTROL_ORDER, '');
        if ($order !== '') {
            $parts = array_values(array_filter(array_map('trim', explode(',', strtolower($order)))));
            if (in_array('event', $parts, true)) {
                $out = [];
                foreach ($parts as $p) {
                    if ($p === 'event') $p = 'schedule';
                    if (!in_array($p, $out, true)) $out[] = $p;
                }
                update_option(self::OPT_CONTROL_ORDER, implode(',', $out));
            }
        }

        update_option(self::OPT_MIGRATION_REPORT, $report, false);
        // Only announce runs that actually changed something (re-runs stay quiet).
        if ($report['workshops_moved'] > 0 || $report['schedules_created'] > 0) {
            update_option(self::OPT_MIGRATION_NOTICE, '1', false);
        }

        return $report;
    }

    public static function output_base_css(): void {
        if (is_admin()) return;

        $w_cat = self::clamp_percent(get_option(self::OPT_W_CAT, '15'));
        $w_group = self::clamp_percent(get_option(self::OPT_W_GROUP, '15'));
        $w_room = self::clamp_percent(get_option(self::OPT_W_ROOM, '15'));
        $table_bar_width = self::clamp_percent(get_option(self::OPT_TABLE_BAR_WIDTH, '25'));

        // Expose CSS variables globally for theme overrides.
        echo "\n<!-- Convention Schedule Base CSS -->\n";
        echo "<style id=\"csched-base-css\">\n";
        // View switcher uses the group width by default (theme can override --csched-dd-width-view).
        echo ":root{--csched-gap:10px;--csched-control-height:40px;--csched-control-radius:8px;--csched-dd-width-cat:{$w_cat}%;--csched-dd-width-group:{$w_group}%;--csched-dd-width-room:{$w_room}%;--csched-table-bar-width:{$table_bar_width}%;--csched-dd-width-view:{$w_group}%;}\n";

        echo ".csched-wrap{width:100%;max-width:100%;}\n";
        echo ".csched-heading{margin:0 0 0.75rem;font-weight:700;font-size:1.35rem;}\n";

        // Controls
        echo ".csched-controls{display:flex;align-items:flex-end;justify-content:flex-start;gap:var(--csched-gap);flex-wrap:wrap;margin:0 0 0.75rem;}\n";
        echo ".csched-field{display:flex;flex-direction:column;align-items:flex-start;gap:6px;}\n";
        echo ".csched-field .csched-label{display:block;margin:0 0 4px 0;font-size:0.92rem;opacity:0.9;}\n";
        echo ".csched-field{display:flex;flex-direction:column;align-items:flex-start;gap:4px;}\n";
        echo ".csched-field .csched-label{font-size:12px;line-height:1;}\n";
        echo ".csched-controls .csched-input{width:100%;height:var(--csched-control-height,40px);border-radius:var(--csched-control-radius,6px);border:1px solid #ccc;padding:0 10px;box-sizing:border-box;}
.csched-control{height:var(--csched-control-height);border-radius:var(--csched-control-radius);font-family:var(--csched-control-font);}\n";
        echo ".csched-field select.csched-input{width:100%;height:var(--csched-control-height,40px);border-radius:var(--csched-control-radius,6px);border:1px solid #ccc;padding:0 10px;box-sizing:border-box;}
.csched-control{padding:8px 10px;width:100%;}\n";
        echo ".csched-controls .csched-cat{width:var(--csched-dd-width-cat);} \n";
        echo ".csched-controls .csched-group{width:var(--csched-dd-width-group);} \n";
        echo ".csched-controls .csched-room{width:var(--csched-dd-width-room);} \n";
        echo ".csched-controls .csched-view{width:var(--csched-dd-width-view);} \n";
        echo ".csched-controls .csched-apply{padding:8px 14px;cursor:pointer;}\n";
        echo ".csched-controls .csched-reset{padding:8px 14px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;}\n";

        // Table
        echo ".csched-day{margin:1.5rem 0 0.75rem;font-weight:700;font-size:1.25rem;}\n";
        echo ".csched-table{width:100%;border-collapse:collapse;}\n";
        echo ".csched-table th,.csched-table td{padding:10px;border:1px solid #ddd;vertical-align:top;}\n";
        echo ".csched-time{white-space:nowrap;width:140px;}\n";
        echo ".csched-room-col{white-space:nowrap;width:180px;}\n";
        echo ".csched-cats{font-size:0.9rem;opacity:0.9;}\n";
        echo ".csched-title{font-weight:600;}\n";
        echo ".csched-desc{margin-top:0.35rem;}\n";
        echo ".csched-empty{padding:12px;border:1px dashed #bbb;}\n";

        // Cards
        echo ".csched-cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;}\n";
        echo ".csched-card{border:1px solid #ddd;border-radius:10px;padding:12px;}\n";
        // Card head should always stack: time, room, title (regardless of card width)
        echo ".csched-card-head{display:flex;flex-direction:column;gap:4px;margin-bottom:6px;}\n";
        echo ".csched-card-time{font-weight:700;white-space:normal;}\n";
        echo ".csched-card-room{opacity:0.9;white-space:normal;}\n";

        // Grid (time blocks)
        echo ".csched-grid-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;border:1px solid #ddd;border-radius:10px;}\n";
        echo ".csched-grid{min-width:720px;width:100%;border-collapse:separate;border-spacing:0;}\n";
        echo ".csched-grid th,.csched-grid td{border-bottom:1px solid #eee;border-right:1px solid #eee;padding:10px;vertical-align:top;}\n";
        echo ".csched-grid tbody tr{height:52px;}
";
        echo ".csched-grid td{position:relative;}
";
        echo ".csched-grid th:last-child,.csched-grid td:last-child{border-right:none;}\n";
        echo ".csched-grid tr:last-child td{border-bottom:none;}\n";
        echo ".csched-grid-time{position:sticky;left:0;background:#fff;z-index:2;white-space:nowrap;width:120px;}\n";
        echo ".csched-grid thead th{position:sticky;top:0;background:#fff;z-index:3;}\n";
        echo ".csched-grid thead th.csched-grid-time{z-index:4;}\n";
        echo ".csched-grid-item{border:1px solid #ddd;border-radius:8px;padding:8px;margin-bottom:8px;}\n";
        echo ".csched-grid-item:last-child{margin-bottom:0;}\n";

        // Responsive
        echo "@media (max-width: 1024px){.csched-cards{grid-template-columns:repeat(2,minmax(0,1fr));}}\n";
        echo "@media (max-width: 768px){\n";
        echo "  .csched-field{flex-direction:row !important;align-items:center !important;gap:10px !important;}\n";
        echo "  .csched-field .csched-label{margin:0 !important;min-width:90px;white-space:nowrap;}\n";
        echo "  .csched-field select{flex:1 1 auto;width:auto !important;}\n";
        echo "  .csched-cards{grid-template-columns:1fr;}\n";
        // Stack the filter controls (Category/Group/Room/Apply) vertically on mobile.
        // Use !important to reliably override Elementor/theme form CSS.
        echo "  .csched-wrap .csched-controls{flex-direction:column!important;align-items:stretch!important;justify-content:flex-start!important;flex-wrap:nowrap!important;gap:12px!important;}\n";
        echo "  .csched-wrap .csched-controls .csched-field{width:100%!important;}\n";
        echo "  .csched-wrap .csched-controls .csched-cat,.csched-wrap .csched-controls .csched-group,.csched-wrap .csched-controls .csched-room{width:100%!important;}\n";
        echo "  .csched-wrap .csched-controls select.csched-input{width:100%;height:var(--csched-control-height,40px);border-radius:var(--csched-control-radius,6px);border:1px solid #ccc;padding:0 10px;box-sizing:border-box;}
.csched-control{min-width:0!important;width:100%!important;max-width:100%!important;}\n";
        echo "  .csched-wrap .csched-controls .csched-apply{width:100%!important;}\n";
        echo "  .csched-wrap .csched-controls .csched-reset{width:100%!important;}\n";
        echo "  .csched-table thead{display:none;}\n";
        echo "  .csched-table,.csched-table tbody,.csched-table tr,.csched-table td{display:block;width:100%;}\n";
        echo "  .csched-table tr{border:1px solid #ddd;border-radius:10px;margin:0 0 10px;padding:10px;}\n";
        echo "  .csched-table td{border:none;padding:6px 0;}\n";
        echo "  .csched-table td[data-label]:before{content:attr(data-label) ': ';font-weight:700;display:inline;}\n";
        echo "}\n";

        echo '\n.csched-category-bar{\n  display:block !important;\n  width:100% !important;\n  min-height:8px !important;\n  height:8px !important;\n  line-height:8px !important;\n  border-radius:4px !important;\n  margin:0 0 8px 0 !important;\n  background:var(--csched-cat-color,#666) !important;\n}\n.csched-table td .csched-category-bar{height:8px !important;min-height:8px !important;margin:0 0 8px 0 !important;}\n.csched-card .csched-category-bar{height:8px !important;min-height:8px !important;margin:-1px -1px 10px -1px !important;width:calc(100% + 2px) !important;border-radius:6px 6px 0 0 !important;}\n.csched-grid-item .csched-category-bar{height:6px !important;min-height:6px !important;margin:0 0 6px 0 !important;}\n';
        echo '\n.csched-category-bar{display:block!important;width:100%!important;height:8px!important;min-height:8px!important;border-radius:4px!important;margin:0 0 8px 0!important;background:currentColor;}\n.csched-cats[style*="border-left"]{margin-top:8px!important;margin-bottom:8px!important;}\n';
        echo '\n/* v1.7.0 card category bar cleanup */\n.csched-card > .csched-category-bar{\n  display:block !important;\n  width:100% !important;\n  height:8px !important;\n  min-height:8px !important;\n  margin:0 0 12px 0 !important;\n  border-radius:6px !important;\n}\n.csched-card .csched-card-head .csched-category-bar,\n.csched-card .csched-card-room + .csched-category-bar,\n.csched-card .csched-title + .csched-category-bar{\n  display:none !important;\n}\n.csched-card .csched-cats{\n  border-left:0 !important;\n  padding-left:0 !important;\n}\n';
        echo '\n/* Table category bar width setting */\n.csched-table .csched-category-bar{\n  width:var(--csched-table-bar-width) !important;\n  min-width:0 !important;\n  max-width:100% !important;\n}\n';
        echo '\n/* v1.7.2 force table category bar width */\n.csched-table .csched-table-category-bar{\n  width:var(--csched-table-bar-width) !important;\n  max-width:100% !important;\n}\n.csched-table .csched-category-bar:not(.csched-table-category-bar){\n  width:var(--csched-table-bar-width) !important;\n  max-width:100% !important;\n}\n.csched-card > .csched-category-bar,\n.csched-grid-item > .csched-category-bar{\n  width:100% !important;\n}\n';
        echo '\n/* v1.7.4 table category bar width setting */\n.csched-table .csched-table-category-bar,\n.csched-table .csched-category-bar{\n  width:var(--csched-table-bar-width) !important;\n  min-width:0 !important;\n  max-width:100% !important;\n}\n.csched-card > .csched-category-bar,\n.csched-grid-item > .csched-category-bar{\n  width:100% !important;\n}\n';
        echo '\n/* v1.7.6 stabilization: table/category bars */\n.csched-table .csched-table-category-bar,\n.csched-table .csched-category-bar{\n  width:var(--csched-table-bar-width) !important;\n  min-width:0 !important;\n  max-width:100% !important;\n}\n.csched-card > .csched-category-bar,\n.csched-grid-item > .csched-category-bar{\n  width:100% !important;\n}\n';
        echo "</style>\n";
    }

    public static function output_custom_css(): void {
        if (is_admin()) return;
        $css = (string) get_option(self::OPT_CSS, '');
        $css = trim($css);
        if ($css === '') return;
        echo "\n<!-- Convention Schedule Custom CSS -->\n";
        echo "<style id=\"csched-custom-css\">\n" . $css . "\n</style>\n";
    }

    public static function default_css(): string {
        return "/* Add your custom CSS overrides here. */\n";
    }

    public static function clamp_percent($val): string {
        $n = is_numeric($val) ? (float)$val : 15.0;
        if ($n < 5) $n = 5;
        if ($n > 50) $n = 50;
        // remove .0
        if (abs($n - round($n)) < 0.0001) $n = (int)round($n);
        return (string)$n;
    }
}