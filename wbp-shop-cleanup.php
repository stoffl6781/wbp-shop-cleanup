<?php
/**
 * Plugin Name: Shop Cleanup – Pre-Launch Tool
 * Plugin URI:  https://purin.at
 * Description: Räumt Test-Daten vor dem Go-Live auf: WooCommerce-Bestellungen, Germanized Buchhaltungsdaten (Storeabill), PDF-Archive, Action Scheduler, Sessions, Transients. HPOS-kompatibel, AJAX-Batching für Shared Hosting.
 * Version:     1.0.0
 * Author:      Web-Beratung & Entwicklung Purin e.U.
 * Author URI:  https://purin.at
 * License:     GPL-2.0-or-later
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * !! WICHTIG !!
 * - Nur für Pre-Launch / Development verwenden.
 * - Vor Ausführung IMMER ein vollständiges DB-Backup anlegen.
 * - Nach Go-Live dieses Plugin deaktivieren UND löschen.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WBP_Shop_Cleanup {

    const CAPABILITY      = 'manage_options';
    const CONFIRM_PHRASE  = 'ALLES-LOESCHEN';
    const BATCH_SIZE      = 25;
    const NONCE_ACTION    = 'wbp_shop_cleanup';
    const MENU_SLUG       = 'wbp-shop-cleanup';

    public function init() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('wp_ajax_wbp_cleanup_batch', [$this, 'ajax_batch']);
    }

    public function register_menu() {
        add_management_page(
            'Shop Cleanup',
            'Shop Cleanup',
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'render_page']
        );
    }

    /* =========================================================
     *  STATISTIKEN
     * ========================================================= */
    private function get_stats() {
        global $wpdb;

        $stats = [
            'hpos'        => false,
            'orders'      => 0,
            'refunds'     => 0,
            'documents'   => 0,
            'as_actions'  => 0,
            'sessions'    => 0,
            'customers'   => 0,
            'pdf_dir'     => '',
            'pdf_files'   => 0,
        ];

        // HPOS-Erkennung
        if (
            class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
        ) {
            $stats['hpos'] = true;
            $table = $wpdb->prefix . 'wc_orders';
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'")) {
                $stats['orders'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table` WHERE type = 'shop_order'");
                $stats['refunds'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table` WHERE type = 'shop_order_refund'");
            }
        } else {
            $stats['orders']  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order'");
            $stats['refunds'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order_refund'");
        }

        // Storeabill
        $sb_docs = $wpdb->prefix . 'storeabill_documents';
        if ($wpdb->get_var("SHOW TABLES LIKE '$sb_docs'")) {
            $stats['documents'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$sb_docs`");
        }

        // Action Scheduler
        $as_tbl = $wpdb->prefix . 'actionscheduler_actions';
        if ($wpdb->get_var("SHOW TABLES LIKE '$as_tbl'")) {
            $stats['as_actions'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$as_tbl`");
        }

        // WC Sessions
        $sess = $wpdb->prefix . 'woocommerce_sessions';
        if ($wpdb->get_var("SHOW TABLES LIKE '$sess'")) {
            $stats['sessions'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$sess`");
        }

        // Customers (nur Rolle customer, keine Admins)
        $stats['customers'] = count(get_users([
            'role'    => 'customer',
            'fields'  => 'ID',
            'number'  => -1,
        ]));

        // PDF-Verzeichnis
        $uploads  = wp_upload_dir();
        $pdf_dirs = glob($uploads['basedir'] . '/storeabill-*', GLOB_ONLYDIR);
        if (!empty($pdf_dirs)) {
            $stats['pdf_dir'] = $pdf_dirs[0];
            try {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($stats['pdf_dir'], RecursiveDirectoryIterator::SKIP_DOTS)
                );
                foreach ($it as $file) {
                    if ($file->isFile() && strtolower($file->getExtension()) === 'pdf') {
                        // fonts/tmp nicht mitzählen
                        $rel = str_replace($stats['pdf_dir'], '', $file->getPathname());
                        if (strpos($rel, '/fonts/') === 0 || strpos($rel, '/tmp/') === 0) continue;
                        $stats['pdf_files']++;
                    }
                }
            } catch (Exception $e) {
                // ignore
            }
        }

        return $stats;
    }

    /* =========================================================
     *  ADMIN-SEITE
     * ========================================================= */
    public function render_page() {
        $stats = $this->get_stats();
        $nonce = wp_create_nonce(self::NONCE_ACTION);
        $phrase = self::CONFIRM_PHRASE;
        ?>
        <div class="wrap">
            <h1>🧹 Shop Cleanup – Pre-Launch Tool</h1>

            <div class="notice notice-error">
                <p><strong>⚠️ Achtung:</strong> Dieses Tool löscht Bestellungen und Buchhaltungsdaten <em>unwiderruflich</em>.
                Nur vor dem Go-Live verwenden! <strong>Immer vorher Datenbank-Backup anlegen.</strong></p>
            </div>

            <div class="card" style="max-width: 960px;">
                <h2>Aktueller Zustand</h2>
                <table class="widefat striped">
                    <tbody>
                        <tr>
                            <td style="width:50%"><strong>Bestellungen</strong></td>
                            <td><?php echo number_format_i18n($stats['orders']); ?>
                                <small style="opacity:.7">(Speicherung: <?php echo $stats['hpos'] ? 'HPOS' : 'klassisch wp_posts'; ?>)</small>
                            </td>
                        </tr>
                        <tr><td><strong>Gutschriften / Refunds</strong></td><td><?php echo number_format_i18n($stats['refunds']); ?></td></tr>
                        <tr><td><strong>Germanized Dokumente</strong> (Rechnungen, Stornos, Lieferscheine)</td><td><?php echo number_format_i18n($stats['documents']); ?></td></tr>
                        <tr>
                            <td><strong>PDF-Archive</strong></td>
                            <td>
                                <?php if ($stats['pdf_dir']): ?>
                                    <?php echo number_format_i18n($stats['pdf_files']); ?> PDF-Dateien in
                                    <code><?php echo esc_html(str_replace(ABSPATH, '', $stats['pdf_dir'])); ?></code>
                                <?php else: ?>
                                    <em>kein storeabill-Verzeichnis gefunden</em>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr><td><strong>Action Scheduler Einträge</strong></td><td><?php echo number_format_i18n($stats['as_actions']); ?></td></tr>
                        <tr><td><strong>WooCommerce Sessions</strong></td><td><?php echo number_format_i18n($stats['sessions']); ?></td></tr>
                        <tr><td><strong>Kunden (Rolle <code>customer</code>)</strong></td><td><?php echo number_format_i18n($stats['customers']); ?></td></tr>
                    </tbody>
                </table>
            </div>

            <form id="wbp-cleanup-form" style="max-width: 960px; margin-top: 20px;">
                <div class="card">
                    <h2>Was soll bereinigt werden?</h2>
                    <p><label><input type="checkbox" name="delete_orders" value="1" checked>
                        <strong>Bestellungen + Gutschriften</strong> löschen (HPOS-kompatibel, via <code>wc_get_orders()</code>)</label></p>
                    <p><label><input type="checkbox" name="delete_storeabill" value="1" checked>
                        <strong>Germanized Buchhaltungsdaten</strong> (alle <code>storeabill_*</code>-Tabellen leeren)</label></p>
                    <p><label><input type="checkbox" name="delete_pdfs" value="1" checked>
                        <strong>PDF-Archive im Filesystem</strong> (<code>uploads/storeabill-*</code>, ohne <code>fonts</code>/<code>tmp</code>)</label></p>
                    <p><label><input type="checkbox" name="clear_action_scheduler" value="1" checked>
                        <strong>Action Scheduler</strong> leeren</label></p>
                    <p><label><input type="checkbox" name="clear_sessions" value="1" checked>
                        <strong>WooCommerce Sessions</strong> leeren</label></p>
                    <p><label><input type="checkbox" name="clear_transients" value="1" checked>
                        <strong>Transients</strong> leeren</label></p>
                    <p><label><input type="checkbox" name="delete_customers" value="1">
                        <strong>Test-Kunden</strong> löschen (nur User mit <em>ausschließlich</em> Rolle <code>customer</code>)</label></p>
                </div>

                <div class="card" style="margin-top: 15px;">
                    <h2>Bestätigung</h2>
                    <p>Zur Absicherung bitte <code><?php echo esc_html($phrase); ?></code> eintippen:</p>
                    <p><input type="text" name="confirm" autocomplete="off" spellcheck="false"
                              style="width: 320px; font-family: monospace; font-size: 14px;"></p>
                    <p>
                        <button type="submit" class="button button-primary button-large">🗑️ Jetzt bereinigen</button>
                        <a href="<?php echo esc_url(admin_url('tools.php?page=' . self::MENU_SLUG)); ?>" class="button">Neu laden</a>
                    </p>
                </div>
            </form>

            <div id="wbp-cleanup-log" style="max-width: 960px; margin-top: 20px; background: #1e1e1e; color: #d4d4d4;
                 padding: 15px; font-family: ui-monospace, Menlo, monospace; font-size: 12.5px; line-height: 1.5;
                 max-height: 420px; overflow-y: auto; display: none; border-radius: 4px;"></div>

            <div class="card" style="max-width: 960px; margin-top: 20px;">
                <h2>Nach dem Cleanup manuell prüfen</h2>
                <ol>
                    <li>In <strong>WooCommerce → Einstellungen → Germanized → Rechnungen & Lieferscheine</strong> die Zählerstände
                        bei <em>Rechnungen</em>, <em>Stornierungen</em> und <em>Lieferscheine</em> kontrollieren bzw. auf <code>0</code> setzen und speichern
                        (Germanized entfernt Counter-Options bewusst nicht automatisch).</li>
                    <li>Unter <strong>WooCommerce → Status → Tools</strong> die Einträge
                        <em>„Transienten löschen"</em> und <em>„Produktsuch-Index neu erstellen"</em> ausführen.</li>
                    <li>Falls Analytics-Daten sichtbar bleiben: <strong>WooCommerce → Analytics → Einstellungen → Tools</strong>
                        → <em>„Analytics-Daten neu erstellen"</em>.</li>
                    <li><strong>Dieses Plugin deaktivieren und löschen</strong>, sobald der Shop live ist.</li>
                </ol>
            </div>
        </div>

        <script>
        (function($) {
            const ajaxurl      = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
            const nonce        = '<?php echo esc_js($nonce); ?>';
            const confirmPhrase= '<?php echo esc_js($phrase); ?>';
            const $form = $('#wbp-cleanup-form');
            const $log  = $('#wbp-cleanup-log');

            function log(msg, type) {
                type = type || 'info';
                const colors = { info: '#d4d4d4', success: '#4ec9b0', error: '#f48771', warn: '#dcdcaa', step: '#569cd6' };
                const time = new Date().toLocaleTimeString();
                const line = document.createElement('div');
                line.style.color = colors[type] || colors.info;
                line.textContent = '[' + time + '] ' + msg;
                $log.append(line);
                $log.scrollTop($log[0].scrollHeight);
            }

            async function runStep(step) {
                try {
                    const res = await $.post(ajaxurl, {
                        action: 'wbp_cleanup_batch',
                        _wpnonce: nonce,
                        step: step
                    });
                    return res || { success: false, data: { message: 'leere Antwort' } };
                } catch (e) {
                    return { success: false, data: { message: (e.responseText || e.statusText || 'Netzwerkfehler').substring(0, 200) } };
                }
            }

            async function runBatched(step, label) {
                log('▶ ' + label, 'step');
                let more = true, total = 0, safety = 1000;
                while (more && safety-- > 0) {
                    const r = await runStep(step);
                    if (!r.success) {
                        log('  ✗ Fehler: ' + ((r.data && r.data.message) || 'unbekannt'), 'error');
                        return false;
                    }
                    total += (r.data.deleted || 0);
                    log('  … ' + total + ' gelöscht (Batch: ' + (r.data.deleted || 0) + ')');
                    more = !!r.data.more;
                }
                log('  ✓ fertig (' + total + ')', 'success');
                return true;
            }

            async function runOnce(step, label) {
                log('▶ ' + label, 'step');
                const r = await runStep(step);
                if (r.success) {
                    log('  ✓ ' + (r.data.message || 'ok'), 'success');
                } else {
                    log('  ✗ ' + ((r.data && r.data.message) || 'Fehler'), 'error');
                }
            }

            $form.on('submit', async function(e) {
                e.preventDefault();

                const confirm = $form.find('input[name="confirm"]').val().trim();
                if (confirm !== confirmPhrase) {
                    alert('Bitte exakt "' + confirmPhrase + '" eintippen.');
                    return;
                }
                if (!window.confirm('Letzte Warnung: Wirklich alles markierte bereinigen? Nicht umkehrbar!')) return;

                $log.show().empty();
                $form.find('button,input').prop('disabled', true);

                const opts = {};
                $form.find('input[type=checkbox]').each(function() {
                    if (this.checked) opts[this.name] = 1;
                });

                log('Cleanup gestartet …');

                if (opts.delete_orders)          await runBatched('delete_orders_batch', 'Bestellungen & Refunds löschen');
                if (opts.delete_storeabill)      await runOnce('delete_storeabill',       'Germanized Buchhaltungsdaten leeren');
                if (opts.delete_pdfs)            await runOnce('delete_pdfs',             'PDF-Archive im Filesystem löschen');
                if (opts.clear_action_scheduler) await runOnce('clear_action_scheduler',  'Action Scheduler leeren');
                if (opts.clear_sessions)         await runOnce('clear_sessions',          'WC Sessions leeren');
                if (opts.clear_transients)       await runOnce('clear_transients',        'Transients leeren');
                if (opts.delete_customers)       await runBatched('delete_customers_batch', 'Test-Kunden löschen');

                log('✓ Cleanup abgeschlossen. Seite neu laden, um aktuellen Zustand zu prüfen.', 'success');
                $form.find('button,input').prop('disabled', false);
            });
        })(jQuery);
        </script>
        <?php
    }

    /* =========================================================
     *  AJAX DISPATCHER
     * ========================================================= */
    public function ajax_batch() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(['message' => 'Keine Berechtigung']);
        }
        check_ajax_referer(self::NONCE_ACTION);

        $step = isset($_POST['step']) ? sanitize_key($_POST['step']) : '';

        switch ($step) {
            case 'delete_orders_batch':      $this->step_delete_orders();         break;
            case 'delete_storeabill':        $this->step_delete_storeabill();     break;
            case 'delete_pdfs':              $this->step_delete_pdfs();           break;
            case 'clear_action_scheduler':   $this->step_clear_action_scheduler(); break;
            case 'clear_sessions':           $this->step_clear_sessions();        break;
            case 'clear_transients':         $this->step_clear_transients();      break;
            case 'delete_customers_batch':   $this->step_delete_customers();      break;
            default:
                wp_send_json_error(['message' => 'Unbekannter Step: ' . $step]);
        }
    }

    /* =========================================================
     *  STEPS
     * ========================================================= */
    private function step_delete_orders() {
        if (!function_exists('wc_get_orders')) {
            wp_send_json_error(['message' => 'WooCommerce nicht aktiv']);
        }

        // Erst Refunds (hängen an Orders), dann Orders
        $refunds = wc_get_orders([
            'limit'  => self::BATCH_SIZE,
            'type'   => 'shop_order_refund',
            'status' => 'any',
            'return' => 'objects',
        ]);

        $deleted = 0;
        foreach ($refunds as $o) {
            if ($o && $o->delete(true)) $deleted++;
        }

        // Wenn keine Refunds mehr, dann Orders
        if ($deleted === 0) {
            $orders = wc_get_orders([
                'limit'  => self::BATCH_SIZE,
                'type'   => 'shop_order',
                'status' => 'any',
                'return' => 'objects',
            ]);
            foreach ($orders as $o) {
                if ($o && $o->delete(true)) $deleted++;
            }
            wp_send_json_success([
                'deleted' => $deleted,
                'more'    => $deleted >= self::BATCH_SIZE,
            ]);
        }

        // Noch Refunds übrig → weitere Batches
        wp_send_json_success([
            'deleted' => $deleted,
            'more'    => true,
        ]);
    }

    private function step_delete_storeabill() {
        global $wpdb;

        $tables = [
            'storeabill_documents',
            'storeabill_documentmeta',
            'storeabill_document_items',
            'storeabill_document_itemmeta',
            'storeabill_document_notices',
            'storeabill_document_noticemeta',
            'storeabill_document_attachments',
            'storeabill_document_attachmentmeta',
        ];

        $cleared = [];
        $skipped = [];
        foreach ($tables as $t) {
            $full = $wpdb->prefix . $t;
            if ($wpdb->get_var("SHOW TABLES LIKE '$full'")) {
                $wpdb->query("TRUNCATE TABLE `$full`");
                $cleared[] = $t;
            } else {
                $skipped[] = $t;
            }
        }

        $msg = count($cleared) . ' Tabellen geleert';
        if ($cleared) $msg .= ': ' . implode(', ', $cleared);
        if ($skipped) $msg .= ' (nicht vorhanden: ' . implode(', ', $skipped) . ')';

        wp_send_json_success(['message' => $msg]);
    }

    private function step_delete_pdfs() {
        $uploads  = wp_upload_dir();
        $pdf_dirs = glob($uploads['basedir'] . '/storeabill-*', GLOB_ONLYDIR);

        if (empty($pdf_dirs)) {
            wp_send_json_success(['message' => 'Kein storeabill-Verzeichnis gefunden']);
        }

        $total = 0;
        foreach ($pdf_dirs as $base) {
            $entries = @scandir($base);
            if (!$entries) continue;

            foreach ($entries as $entry) {
                // fonts und tmp BEHALTEN (offizielle Vendidero-Empfehlung)
                if (in_array($entry, ['.', '..', 'fonts', 'tmp'], true)) continue;

                $path = $base . '/' . $entry;
                if (is_dir($path)) {
                    $total += $this->rrmdir($path);
                } elseif (is_file($path)) {
                    if (@unlink($path)) $total++;
                }
            }
        }

        wp_send_json_success(['message' => "$total Dateien/Ordner entfernt (fonts + tmp bleiben erhalten)"]);
    }

    private function rrmdir($dir) {
        if (!is_dir($dir)) return 0;
        $count = 0;
        $items = @scandir($dir);
        if (!$items) return 0;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $count += $this->rrmdir($path);
            } else {
                if (@unlink($path)) $count++;
            }
        }
        @rmdir($dir);
        return $count;
    }

    private function step_clear_action_scheduler() {
        global $wpdb;
        $tables = [
            'actionscheduler_logs',
            'actionscheduler_claims',
            'actionscheduler_actions',
            'actionscheduler_groups',
        ];
        $cleared = 0;
        foreach ($tables as $t) {
            $full = $wpdb->prefix . $t;
            if ($wpdb->get_var("SHOW TABLES LIKE '$full'")) {
                $wpdb->query("TRUNCATE TABLE `$full`");
                $cleared++;
            }
        }
        wp_send_json_success(['message' => "$cleared Action-Scheduler-Tabellen geleert"]);
    }

    private function step_clear_sessions() {
        global $wpdb;
        $table = $wpdb->prefix . 'woocommerce_sessions';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'")) {
            $wpdb->query("TRUNCATE TABLE `$table`");
            wp_send_json_success(['message' => 'WC-Sessions geleert']);
        }
        wp_send_json_success(['message' => 'Keine Sessions-Tabelle vorhanden']);
    }

    private function step_clear_transients() {
        global $wpdb;
        $count = (int) $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '\_transient\_%'
                OR option_name LIKE '\_site\_transient\_%'"
        );
        wp_send_json_success(['message' => "$count Transient-Einträge gelöscht"]);
    }

    private function step_delete_customers() {
        $users = get_users([
            'role'    => 'customer',
            'number'  => self::BATCH_SIZE,
            'fields'  => ['ID'],
        ]);

        if (empty($users)) {
            wp_send_json_success(['deleted' => 0, 'more' => false]);
        }

        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }

        $deleted = 0;
        foreach ($users as $u) {
            $user = get_userdata($u->ID);
            // Safety: nur User die WIRKLICH nur "customer" sind – keine Admins/Shop-Manager
            if (
                $user
                && is_array($user->roles)
                && count($user->roles) === 1
                && in_array('customer', $user->roles, true)
            ) {
                if (wp_delete_user($user->ID)) {
                    $deleted++;
                }
            }
        }

        wp_send_json_success([
            'deleted' => $deleted,
            'more'    => count($users) >= self::BATCH_SIZE,
        ]);
    }
}

(new WBP_Shop_Cleanup())->init();
