<?php
/**
 * Plugin Name: Schreuder Bonusan POS Bestellingen
 * Description: Maakt per locatie een aaneengesloten Bonusan-bestellijst vanuit WooCommerce/YITH POS-orders, toont eerst een controle en verzendt daarna het Excel-bestand.
 * Version: 1.12.0
 * Author: Schreuder Natuurgeneeswijzen
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * WC requires at least: 7.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once plugin_dir_path( __FILE__ ) . 'includes/class-sbp-ledger.php';

add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

final class Schreuder_Bonusan_POS {
    const OPTION = 'sbp_settings';
    const NONCE  = 'sbp_nonce';
    const VERSION = '1.12.0';

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        // Beginsaldo uit het oude meta-veld van v1.7.0 wordt eenmalig overgenomen bij het eerste gebruik van een voorraadrij.
        SBP_Ledger::set_seed_provider( function ( $product_id, $location ) {
            $v = get_post_meta( (int)$product_id, '_sbp_loc_stock_' . sanitize_key( $location ), true );
            return is_numeric( $v ) ? (float)$v : 0.0;
        } );
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'wp_ajax_sbp_preview', array( $this, 'ajax_preview' ) );
        add_action( 'wp_ajax_sbp_send', array( $this, 'ajax_send' ) );
        add_action( 'wp_ajax_sbp_update', array( $this, 'ajax_update' ) );
        add_action( 'wp_ajax_sbp_download', array( $this, 'ajax_download' ) );
        add_action( 'wp_ajax_sbp_product_search', array( $this, 'ajax_product_search' ) );
        add_action( 'wp_ajax_sbp_add_product', array( $this, 'ajax_add_product' ) );
        add_action( 'wp_ajax_sbp_remove_product', array( $this, 'ajax_remove_product' ) );
        add_action( 'wp_ajax_sbp_planner_search', array( $this, 'ajax_planner_search' ) );
        add_action( 'wp_ajax_sbp_planner_add', array( $this, 'ajax_planner_add' ) );
        add_action( 'wp_ajax_sbp_planner_remove', array( $this, 'ajax_planner_remove' ) );
        add_action( 'wp_ajax_sbp_adopt_advice', array( $this, 'ajax_adopt_advice' ) );

        // Locatievoorraad v1.7.0.
        add_action( 'wp_ajax_sbp_stock_product_search', array( $this, 'ajax_stock_product_search' ) );
        add_action( 'wp_ajax_sbp_stock_add_product', array( $this, 'ajax_stock_add_product' ) );
        add_action( 'wp_ajax_sbp_stock_save', array( $this, 'ajax_stock_save' ) );
        add_action( 'wp_ajax_sbp_stock_remove_product', array( $this, 'ajax_stock_remove_product' ) );
        add_action( 'wp_ajax_sbp_stock_mode', array( $this, 'ajax_stock_mode' ) );
        add_action( 'wp_ajax_sbp_stock_quick_add', array( $this, 'ajax_stock_quick_add' ) );
        add_action( 'wp_ajax_sbp_stock_receive', array( $this, 'ajax_stock_receive' ) );
        add_action( 'wp_ajax_sbp_stock_import_preview', array( $this, 'ajax_stock_import_preview' ) );
        add_action( 'wp_ajax_sbp_stock_import_apply', array( $this, 'ajax_stock_import_apply' ) );
        add_action( 'wp_ajax_sbp_stock_adjust', array( $this, 'ajax_stock_adjust' ) );
        add_action( 'wp_ajax_sbp_stock_transfer', array( $this, 'ajax_stock_transfer' ) );
        add_action( 'wp_ajax_sbp_assign_order_location', array( $this, 'ajax_assign_order_location' ) );
        // Voorraadboekingen zijn idempotent (zie reconcile_order); meerdere hooks per order zijn dus veilig.
        add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_changed' ), 20, 4 );
        add_action( 'woocommerce_update_order', array( $this, 'reconcile_order' ), 50, 2 );
        add_action( 'woocommerce_order_refunded', array( $this, 'on_order_refunded' ), 20, 2 );
        add_action( 'woocommerce_refund_deleted', array( $this, 'on_refund_deleted' ), 20, 2 );
        add_action( 'woocommerce_before_trash_order', array( $this, 'on_order_trashed' ), 10, 2 );
        add_action( 'woocommerce_before_delete_order', array( $this, 'on_order_trashed' ), 10, 2 );
        add_action( 'wp_trash_post', array( $this, 'on_post_trashed' ) );
        add_action( 'before_delete_post', array( $this, 'on_post_trashed' ) );
        add_action( 'untrashed_post', array( $this, 'on_post_untrashed' ) );
        add_action( 'sbp_reconcile_order', array( $this, 'reconcile_order' ) );
        add_action( 'init', array( $this, 'maybe_upgrade' ) );
        add_action( 'admin_post_sbp_stock_log_csv', array( $this, 'download_stock_log_csv' ) );
        add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
        add_action( 'admin_notices', array( $this, 'pending_stock_location_notice' ) );
        add_action( 'admin_notices', array( $this, 'stock_level_notice' ) );

        // Compatibility hooks for YITH POS versions that expose a register-close action.
        foreach ( array( 'yith_pos_register_closed', 'yith_pos_after_register_close', 'yith_pos_register_session_closed' ) as $hook ) {
            add_action( $hook, array( $this, 'maybe_prepare_after_close' ), 10, 3 );
        }
    }

    public function dependency_notice() {
        if ( current_user_can( 'manage_woocommerce' ) && ! class_exists( 'WooCommerce' ) ) {
            echo '<div class="notice notice-error"><p><strong>Schreuder Bonusan POS:</strong> WooCommerce moet actief zijn.</p></div>';
        }
        if ( current_user_can( 'manage_woocommerce' ) && ! class_exists( 'ZipArchive' ) ) {
            echo '<div class="notice notice-error"><p><strong>Schreuder Bonusan POS:</strong> de PHP-extensie ZipArchive ontbreekt. Vraag de hostingpartij om PHP Zip in te schakelen.</p></div>';
        }
    }

    public function admin_menu() {
        add_menu_page(
            'Bonusan POS-bestelling',
            'Bonusan bestelling',
            'manage_woocommerce',
            'schreuder-bonusan-pos',
            array( $this, 'render_page' ),
            'dashicons-clipboard',
            56
        );
        add_submenu_page(
            'schreuder-bonusan-pos',
            'Locatievoorraad',
            'Locatievoorraad',
            'manage_woocommerce',
            'schreuder-bonusan-stock',
            array( $this, 'render_stock_page' )
        );
    }

    public function register_settings() {
        register_setting( 'sbp_group', self::OPTION, array( $this, 'sanitize_settings' ) );
    }

    public function sanitize_settings( $input ) {
        $defaults = $this->defaults();
        $out = $defaults;
        $out['recipient'] = sanitize_email( $input['recipient'] ?? $defaults['recipient'] );
        $out['subject'] = sanitize_text_field( $input['subject'] ?? $defaults['subject'] );
        $out['message'] = wp_kses_post( $input['message'] ?? $defaults['message'] );
        $out['reply_to'] = sanitize_email( $input['reply_to'] ?? $defaults['reply_to'] );
        $bcc_raw = isset( $input['bcc'] ) ? (string) $input['bcc'] : $defaults['bcc'];
        $bcc_parts = preg_split( '/[\s,;]+/', $bcc_raw, -1, PREG_SPLIT_NO_EMPTY );
        $bcc_clean = array();
        foreach ( $bcc_parts as $address ) {
            $address = sanitize_email( $address );
            if ( $address && ! in_array( $address, $bcc_clean, true ) ) { $bcc_clean[] = $address; }
        }
        $out['bcc'] = implode( "\n", $bcc_clean );
        foreach ( array( 'baarn', 'haarlem', 'zwolle' ) as $loc ) {
            $out['location_meta'][$loc] = sanitize_text_field( $input['location_meta'][$loc] ?? '' );
        }
        $out['extra_register_meta'] = sanitize_text_field( $input['extra_register_meta'] ?? '' );
        return $out;
    }

    private function defaults() {
        return array(
            'recipient' => 'bestellen@bonusan.nl',
            'subject'   => 'Bestelling Schreuder Natuurgeneeswijzen - {locatie} - {datum}',
            'reply_to'  => 'info@schreuder-natuurgeneeswijzen.nl',
            'bcc'       => "info@schreuder-natuurgeneeswijzen.nl\nnick@schreuder-natuurgeneeswijzen.nl",
            'message'   => "Dag lieve mensen,\n\nIn de bijlage treffen jullie de bestelling voor Schreuder\nIn het document vinden jullie het adres voor de verzending.\nAls jullie mij na het verwerken van de bestelling de track & trace willen mailen heel graag.\n\nAls er nog vragen of opmerkingen zijn omtrent de bestelling horen wij dit graag.\n\nAlvast bedankt!\n\nMet vriendelijke groet,\n\nSchreuder Natuurgeneeswijzen\nNieuw Baarnstraat 45\n3743 BP Baarn\n035 5416321\nKvk 32131617\nBtw nr NL001900599B50\ninfo@schreuder-natuurgeneeswijzen.nl\nwww.schreuder-natuurgeneeswijzen.nl\nwww.schreuder-natuurgeneesmiddelen.nl",
            'location_meta' => array( 'baarn' => '', 'haarlem' => '', 'zwolle' => '' ),
            // Optioneel kenmerk van de extra/mobiele kassa. Orders van deze kassa
            // worden niet automatisch afgeboekt totdat Baarn/Haarlem/Zwolle is gekozen.
            'extra_register_meta' => '',
        );
    }

    private function settings() {
        return wp_parse_args( get_option( self::OPTION, array() ), $this->defaults() );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
        $s = $this->settings();
        $today = wp_date( 'Y-m-d' );
        $week_ago = wp_date( 'Y-m-d', strtotime( '-7 days', current_time( 'timestamp' ) ) );
        ?>
        <div class="wrap">
            <h1>Bonusan POS-bestelling</h1>
            <p>Bekijk op ieder moment de actuele tussenstand. De bestelling wordt pas verzonden nadat je het ingevulde Excel-bestand hebt gecontroleerd en zelf bevestigt. <a href="<?php echo esc_url( admin_url('admin.php?page=schreuder-bonusan-stock') ); ?>"><strong>Locatievoorraad beheren →</strong></a></p>
            <div class="sbp-card">
                <table class="form-table"><tbody>
                    <tr><th>Locatie</th><td><select id="sbp-location"><option value="baarn">Baarn</option><option value="haarlem">Haarlem</option><option value="zwolle">Zwolle</option></select></td></tr>
                    <tr><th>Van datum</th><td><input type="date" id="sbp-from" value="<?php echo esc_attr( $week_ago ); ?>"></td></tr>
                    <tr><th>Tot en met</th><td><input type="date" id="sbp-to" value="<?php echo esc_attr( $today ); ?>"></td></tr>
                    <tr><th>Alleen YITH POS-orders</th><td><label><input type="checkbox" id="sbp-pos-only" checked> Webshoporders uitsluiten</label></td></tr>
                    <tr><th>Eerder verzonden orders</th><td><label><input type="checkbox" id="sbp-include-sent"> Ook reeds definitief verzonden kassaverkopen opnieuw laden</label><p class="description">Gebruik dit alleen om een eerdere periode opnieuw te controleren of opnieuw te versturen. Deze orders kunnen dan dubbel worden besteld.</p></td></tr>
                </tbody></table>
                <p><button class="button button-primary" id="sbp-preview">Tussenstand en Excel controleren</button> <span class="spinner" id="sbp-spinner"></span></p>
            </div>
            <div id="sbp-result"></div>

            <hr>
            <h2>Instellingen</h2>
            <form method="post" action="options.php">
                <?php settings_fields( 'sbp_group' ); ?>
                <table class="form-table"><tbody>
                    <tr><th>Ontvanger</th><td><input class="regular-text" type="email" name="<?php echo esc_attr(self::OPTION); ?>[recipient]" value="<?php echo esc_attr($s['recipient']); ?>"></td></tr>
                    <tr><th>BCC</th><td><textarea class="large-text code" rows="3" name="<?php echo esc_attr(self::OPTION); ?>[bcc]"><?php echo esc_textarea($s['bcc']); ?></textarea><p class="description">Eén e-mailadres per regel. Deze ontvangers zijn niet zichtbaar voor Bonusan of voor elkaar.</p></td></tr>
                    <tr><th>Reply-To</th><td><input class="regular-text" type="email" name="<?php echo esc_attr(self::OPTION); ?>[reply_to]" value="<?php echo esc_attr($s['reply_to']); ?>"><p class="description">Antwoorden van Bonusan worden naar dit adres gestuurd.</p></td></tr>
                    <tr><th>Onderwerp</th><td><input class="large-text" type="text" name="<?php echo esc_attr(self::OPTION); ?>[subject]" value="<?php echo esc_attr($s['subject']); ?>"><p class="description">Beschikbaar: {locatie} en {datum}</p></td></tr>
                    <tr><th>E-mailbericht</th><td><textarea class="large-text code" rows="18" name="<?php echo esc_attr(self::OPTION); ?>[message]"><?php echo esc_textarea($s['message']); ?></textarea></td></tr>
                    <tr><th>YITH locatiekenmerk Baarn</th><td><input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION); ?>[location_meta][baarn]" value="<?php echo esc_attr($s['location_meta']['baarn']); ?>"><p class="description">Register-ID van de kassa van deze locatie (uit het ordergegeven <code>_yith_pos_register</code>). Meerdere ID's scheid je met een komma. Er wordt exact vergeleken. Gebruik <strong>niet</strong> het store-ID: dat is voor alle kassa's gelijk.</p></td></tr>
                    <tr><th>YITH locatiekenmerk Haarlem</th><td><input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION); ?>[location_meta][haarlem]" value="<?php echo esc_attr($s['location_meta']['haarlem']); ?>"></td></tr>
                    <tr><th>YITH locatiekenmerk Zwolle</th><td><input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION); ?>[location_meta][zwolle]" value="<?php echo esc_attr($s['location_meta']['zwolle']); ?>"></td></tr>
                    <tr><th>Kenmerk extra kassa</th><td><input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION); ?>[extra_register_meta]" value="<?php echo esc_attr($s['extra_register_meta'] ?? ''); ?>"><p class="description">Register-ID('s) van de extra kassa (komma-gescheiden). Verkopen van deze kassa horen bij geen enkele locatie totdat je in het controlescherm (of bij Locatievoorraad) Baarn, Haarlem of Zwolle hebt gekozen. Ook onbekende kassa's, zoals een testkassa, wachten op een keuze.</p></td></tr>
                </tbody></table>
                <?php submit_button(); ?>
            </form>

            <hr>
            <h2>Recente verzendingen</h2>
            <?php $this->render_send_log(); ?>
        </div>
        <style>
            .sbp-card{background:#fff;border:1px solid #ccd0d4;padding:8px 20px;max-width:900px}.sbp-error{background:#fff1f0;border-left:4px solid #d63638;padding:12px}.sbp-notice{background:#fff8e5;border-left:4px solid #dba617;padding:12px;margin:12px 0}.sbp-ok{background:#edfaef;border-left:4px solid #00a32a;padding:12px}.sbp-table{border-collapse:collapse;width:100%;max-width:900px;background:#fff}.sbp-table th,.sbp-table td{border:1px solid #ccd0d4;padding:8px;text-align:left}.sbp-actions{margin-top:14px}.sbp-qty{width:90px}.sbp-reset{margin-left:6px;vertical-align:middle}.sbp-dirty{color:#b26200;font-weight:600;margin-left:8px}.spinner.is-active{float:none;margin:0 5px}.sbp-manual{display:inline-block;background:#e8f2ff;color:#135e96;border-radius:10px;padding:2px 7px;font-size:11px;margin-left:6px}.sbp-addbox{background:#f6f7f7;border:1px solid #ccd0d4;padding:12px;margin:14px 0;max-width:876px}.sbp-search-wrap{position:relative;max-width:650px}.sbp-search-results{position:absolute;z-index:1000;left:0;right:0;background:#fff;border:1px solid #8c8f94;max-height:260px;overflow:auto;box-shadow:0 2px 6px rgba(0,0,0,.12)}.sbp-search-item{display:block;width:100%;text-align:left;border:0;border-bottom:1px solid #eee;background:#fff;padding:9px 10px;cursor:pointer}.sbp-search-item:hover{background:#f0f6fc}.sbp-search-empty{padding:9px 10px;color:#646970}.sbp-draft-note{background:#eaf6ea;border-left:4px solid #00a32a;padding:10px 12px;margin:12px 0}
        </style>
        <script>
        jQuery(function($){
            const nonce = <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?>;
            let autosaveTimer=null, searchTimer=null;

            function payload(action){
                return {
                    action:action,_ajax_nonce:nonce,
                    location:$('#sbp-location').val(),
                    from:$('#sbp-from').val(),to:$('#sbp-to').val(),
                    pos_only:$('#sbp-pos-only').is(':checked')?1:0,
                    include_sent:$('#sbp-include-sent').is(':checked')?1:0
                };
            }
            function esc(s){return $('<div>').text(s==null?'':s).html();}
            function overrides(){let values={}; $('.sbp-qty').each(function(){values[$(this).data('sku')]=$(this).val();}); return values;}
            function itemRow(i){
                return '<tr data-sku="'+esc(i.sku)+'"><td>'+esc(i.sku)+(i.manual?' <span class="sbp-manual">Handmatig</span>':'')+(i.template_missing?' <span class="sbp-manual" style="background:#fff3cd;color:#7a4b00">Niet in sjabloon</span>':'')+'</td><td>'+esc(i.name)+'</td><td>'+esc(i.pos_qty)+'</td><td>'+esc(i.webshop_qty)+'</td><td>'+esc(i.original_qty)+'</td><td><input class="sbp-qty" type="number" min="0" step="1" data-sku="'+esc(i.sku)+'" data-default="'+esc(i.pos_qty)+'" value="'+esc(i.qty)+'"><button type="button" class="button button-small sbp-reset" title="Herstel naar echte kassaverkoop">Herstel</button>'+(i.manual?' <button type="button" class="button button-small sbp-remove-manual" data-sku="'+esc(i.sku)+'" title="Verwijder handmatig toegevoegd product">Verwijderen</button>':'')+'</td></tr>';
            }
            function saveOverrides(done, silent){
                let token=$('#sbp-result').data('token'); if(!token){if(done)done();return;}
                let p=payload('sbp_update'); p.token=token; p.quantities=overrides();
                if(!silent) $('#sbp-edit-status').text('Wijzigingen opslaan…');
                $.post(ajaxurl,p).done(function(r){
                    if(!r.success){$('#sbp-result').prepend('<div class="sbp-error">'+esc(r.data&&r.data.message?r.data.message:'Wijzigingen konden niet worden opgeslagen.')+'</div>');return;}
                    $('#sbp-edit-status').text('Opgeslagen – 24 uur bewaard');
                    if(done) done();
                }).fail(function(){$('#sbp-result').prepend('<div class="sbp-error">De wijzigingen konden niet worden opgeslagen.</div>');});
            }
            function loadPreview(after){
                $('#sbp-spinner').addClass('is-active'); $('#sbp-result').html('');
                $.post(ajaxurl,payload('sbp_preview')).done(function(r){
                    if(!r.success){$('#sbp-result').html('<div class="sbp-error">'+esc(r.data&&r.data.message?r.data.message:'Onbekende fout')+'</div>');return;}
                    let d=r.data, html='<h2>Controle '+esc(d.location_label)+'</h2>';
                    if(d.draft_restored){html+='<div class="sbp-draft-note"><strong>Opgeslagen wijzigingen hersteld.</strong> Handmatige aanpassingen en toegevoegde producten worden 24 uur bewaard.</div>';}
                    if(d.draft_warnings && d.draft_warnings.length){
                        html+='<div class="sbp-notice"><strong>Let op: de verkoop is veranderd sinds je een aantal hebt aangepast.</strong> Het door jou ingevulde aantal is behouden. Controleer of het nog klopt:<ul style="margin:6px 0 0 18px;list-style:disc">'+d.draft_warnings.map(function(w){return '<li>'+esc(w.sku)+' – '+esc(w.name)+': jouw aantal '+esc(w.saved_qty)+'; kassaverkoop was '+esc(w.base_qty)+' en is nu '+esc(w.now_qty)+'</li>';}).join('')+'</ul></div>';
                    }
                    if(d.unassigned && d.unassigned.length){
                        html+='<div class="sbp-notice"><strong>Kassaverkopen zonder locatie (nog niet besteld)</strong><p class="description">Deze verkopen komen van een extra of onbekende kassa. Kies per verkoop bij welke locatie ze horen. Daarna worden ze meegenomen in de bestelling van die locatie.</p><table class="sbp-table"><thead><tr><th>Order</th><th>Datum</th><th>Producten</th><th>Locatie kiezen</th></tr></thead><tbody>';
                        d.unassigned.forEach(function(o){html+='<tr data-order-id="'+esc(o.order_id)+'"><td>#'+esc(o.order_id)+'</td><td>'+esc(o.date)+'</td><td>'+esc(o.lines.join(', '))+'</td><td><button type="button" class="button sbp-assign" data-location="baarn">Baarn</button> <button type="button" class="button sbp-assign" data-location="haarlem">Haarlem</button> <button type="button" class="button sbp-assign" data-location="zwolle">Zwolle</button></td></tr>';});
                        html+='</tbody></table></div>';
                    }
                    if(d.include_sent){html+='<div class="sbp-notice"><strong>Let op:</strong> deze controle bevat ook kassaverkopen die eerder definitief zijn verzonden. Verzend alleen opnieuw wanneer dat bewust de bedoeling is.</div>';}
                    if(d.stock_advice && d.stock_advice.length){
                        html+='<div class="sbp-addbox"><strong>Locatievoorraad – aanvuladvies</strong><p class="description">Baarn is de hoofdvoorraad voor de producten die je onder Locatievoorraad volgt. Haarlem en Zwolle worden eerst vanuit Baarn aangevuld. Extern aanvullen is alleen een advies; jij bepaalt het uiteindelijke bestelaantal.</p>';
                        let locName=d.location_label, anyAdopt=d.stock_advice.some(function(x){return x.adopt_target;});
                        html+='<table class="sbp-table" id="sbp-advice-table"><thead><tr>'+(anyAdopt?'<th><input type="checkbox" id="sbp-adv-all" title="Alles selecteren"></th>':'')+'<th>Product</th><th>Baarn</th><th>Haarlem</th><th>Zwolle</th><th>Intern aanvullen</th><th>Extern advies</th><th>Route</th>'+(anyAdopt?'<th>Overnemen in bestellijst '+esc(locName)+'</th>':'')+'</tr></thead><tbody>';
                        d.stock_advice.forEach(function(x){
                            let internal=[];if(x.mode==='direct'&&Number(x.own_need_here)>0)internal.push((x.route==='bonusan'?'Zelf bestellen via Bonusan: ':'Zelf bestellen (planner): ')+x.own_need_here);if(Number(x.to_haarlem)>0)internal.push(x.to_haarlem+' → Haarlem');if(Number(x.to_zwolle)>0)internal.push(x.to_zwolle+' → Zwolle');
                            let cb='',ad='';
                            if(anyAdopt){
                                if(x.adopt_target){cb='<input type="checkbox" class="sbp-adv-row">';ad='<input type="number" class="sbp-adv-qty" min="0" step="any" value="'+esc(x.adopt_qty)+'" style="width:80px"> <small>→ '+(x.adopt_target==='bonusan'?'Bonusan-bestellijst '+esc(locName):'Planner')+'</small>';}
                                else{ad='<small>'+(x.adopt_note?esc(x.adopt_note):'Interne aanvulling vanuit Baarn: geen bestelling')+'</small>';}
                            }
                            html+='<tr data-product-id="'+esc(x.product_id)+'">'+(anyAdopt?'<td>'+cb+'</td>':'')+'<td>'+esc(x.name)+'<br><small>SKU '+esc(x.sku||'—')+'</small></td><td>'+esc(x.baarn)+'</td><td>'+esc(x.haarlem)+'</td><td>'+esc(x.zwolle)+'</td><td>'+esc(internal.join(', ')||'—')+'</td><td><strong>'+esc(x.external_need||0)+'</strong></td><td>'+esc(x.route_label)+'</td>'+(anyAdopt?'<td>'+ad+'</td>':'')+'</tr>';
                        });
                        html+='</tbody></table>';
                        if(anyAdopt){html+='<p><button type="button" class="button button-primary" id="sbp-adv-adopt">Geselecteerd advies overnemen in de bestellijst '+esc(locName)+'</button> <span class="description">Het aantal bij "Te bestellen" van die producten wordt het geadviseerde aantal (aan te passen). Met "Herstel" zet je het terug.</span></p>';}
                        html+='<p><a class="button" href="<?php echo esc_js( admin_url('admin.php?page=schreuder-bonusan-stock') ); ?>">Locatievoorraad openen</a></p></div>';
                    }
                    // Planner-overzicht altijd tonen, ook wanneer er op dit moment geen regels zijn.
                    // Zo is direct zichtbaar dat de module actief is. POS-regels zonder SKU worden
                    // ook meegenomen wanneer het om fysiek meegegeven kassaverkopen gaat.
                    // De server levert één samengevoegde plannerlijst. Deze lijst staat
                    // volledig los van de handmatig toegevoegde Bonusan-turflijst.
                    let plannerItems=(d.planner_items||[]).slice();
                    html+='<div class="sbp-addbox sbp-planner-box"><strong>Niet via Bonusan te bestellen – voor planner</strong><p class="description">Hier staan fysieke kassaverkopen die niet via het Bonusan-bestelsjabloon worden besteld. Deze regels kun je direct kopiëren naar de planner.</p>';
                    if(plannerItems.length){
                        let plannerLines=plannerItems.map(function(x){return String(x.qty)+'x '+String(x.name)+(x.sku&&x.sku!=='—'?' (SKU '+String(x.sku)+')':'');});
                        html+='<table class="sbp-table"><thead><tr><th>SKU</th><th>Product</th><th>Aantal verkocht</th></tr></thead><tbody>';
                        plannerItems.forEach(function(x){html+='<tr><td>'+esc(x.sku||'—')+'</td><td>'+esc(x.name)+(x.no_sku?' <span class="sbp-manual" style="background:#fff3cd;color:#7a4b00">Geen SKU</span>':'')+(x.manual_planner?' <span class="sbp-manual">Handmatig planner</span>':'')+'</td><td>'+esc(x.qty)+(x.manual_planner?' <button type="button" class="button button-small sbp-planner-remove" data-sku="'+esc(x.sku)+'">Verwijderen</button>':'')+'</td></tr>';});
                        html+='</tbody></table>';
                        html+='<p><button type="button" class="button" id="sbp-copy-planner">Kopiëren voor planner</button> <span id="sbp-copy-status"></span></p>';
                        html+='<textarea id="sbp-planner-text" readonly style="width:100%;max-width:900px;min-height:120px">'+esc(plannerLines.join('\n'))+'</textarea>';
                    }else{
                        html+='<p><em>Geen producten gevonden die buiten de Bonusan-bestelling vallen in deze periode.</em></p>';
                    }
                    html+='</div>';
                    html+='<div class="sbp-addbox"><strong>Product handmatig aan planner toevoegen</strong><p class="description">Alleen voor overige producten. Bonusan- en Schreuder-producten die via de webshop/turflijst worden besteld kunnen hier niet worden toegevoegd.</p><div class="sbp-search-wrap"><input type="search" id="sbp-planner-search" class="regular-text" placeholder="Zoek plannerproduct op naam of SKU…" autocomplete="off"><div id="sbp-planner-results" class="sbp-search-results" style="display:none"></div></div></div>';
                    if(d.unresolved && d.unresolved.length){html+='<div class="sbp-error"><strong>Verkochte regels zonder herkenbare SKU:</strong><br>'+d.unresolved.map(function(x){return 'Order #'+esc(x.order_id)+': '+esc(x.name);}).join('<br>')+'<p class="description">Deze regels konden niet aan een SKU worden gekoppeld. Als het fysieke kassaverkopen zijn, staan ze hierboven ook in het planner-overzicht.</p></div>';}
                    if(!d.items.length){html+='<div class="sbp-error">Geen bestelbare producten gevonden.</div>';}
                    html+='<div class="sbp-addbox"><strong>Product handmatig toevoegen</strong><p class="description">Alleen voor Bonusan- en Schreuder-producten die via de webshop/turflijst worden besteld. Deze producten komen nooit in de planner. Toegevoegde producten en aangepaste aantallen blijven 24 uur bewaard.</p><div class="sbp-search-wrap"><input type="search" id="sbp-product-search" class="regular-text" placeholder="Zoek op productnaam of SKU…" autocomplete="off"><div id="sbp-search-results" class="sbp-search-results" style="display:none"></div></div></div>';
                    if(d.items.length){
                        html+='<p><strong>‘Te bestellen’ wordt standaard gevuld met alleen Echte kassa – Afgerond.</strong> Webshopverkopen zijn uitsluitend ter informatie. Je kunt het bestelaantal aanpassen of hieronder handmatig een product toevoegen.</p><table class="sbp-table" id="sbp-items-table"><thead><tr><th>SKU</th><th>Product</th><th>Echte kassa<br><small>Afgerond</small></th><th>Webshop via kassa<br><small>In behandeling</small></th><th>Totaal verkocht</th><th>Te bestellen</th></tr></thead><tbody>';
                        d.items.forEach(i=>html+=itemRow(i));
                        html+='</tbody></table><p>'+d.order_count+' orders verwerkt. <span id="sbp-edit-status"></span></p>';
                    }
                    if(d.can_send){html+='<div class="sbp-actions"><button class="button" id="sbp-reload">Opnieuw laden</button> <button class="button" id="sbp-save">Wijzigingen toepassen</button> <button class="button" id="sbp-download">Ingevulde Excel downloaden</button> <button class="button button-primary" id="sbp-send">Definitief verzenden</button></div><p class="description">Handmatige wijzigingen blijven 24 uur bewaard. Na een succesvolle definitieve verzending wordt deze tijdelijke bestellijst gewist.</p>';}
                    $('#sbp-result').html(html).data('token',d.token);
                    if(typeof after==='function'){after();}
                }).fail(function(){ $('#sbp-result').html('<div class="sbp-error">De server gaf geen geldig antwoord.</div>'); }).always(function(){$('#sbp-spinner').removeClass('is-active');});
            }

            $('#sbp-preview').on('click',loadPreview);
            $(document).on('click','#sbp-reload',function(){ saveOverrides(loadPreview,true); });
            $(document).on('input change','.sbp-qty',function(){
                $('#sbp-edit-status').html('<span class="sbp-dirty">Wordt automatisch opgeslagen…</span>');
                clearTimeout(autosaveTimer); autosaveTimer=setTimeout(function(){saveOverrides(null,true);},800);
            });
            $(document).on('click','.sbp-reset',function(){let input=$(this).siblings('.sbp-qty'); input.val(input.data('default')).trigger('change');});
            $(document).on('click','#sbp-save',function(){saveOverrides();});

            $(document).on('input','#sbp-product-search',function(){
                let term=$(this).val().trim(), box=$('#sbp-search-results');
                clearTimeout(searchTimer);
                if(term.length<2){box.hide().empty();return;}
                searchTimer=setTimeout(function(){
                    let p=payload('sbp_product_search'); p.token=$('#sbp-result').data('token'); p.term=term;
                    $.post(ajaxurl,p).done(function(r){
                        if(!r.success){box.html('<div class="sbp-search-empty">'+esc(r.data&&r.data.message?r.data.message:'Zoeken mislukt.')+'</div>').show();return;}
                        if(!r.data.results.length){box.html('<div class="sbp-search-empty">Geen geschikt Bonusan-product gevonden.</div>').show();return;}
                        let h=''; r.data.results.forEach(function(x){h+='<button type="button" class="sbp-search-item" data-product-id="'+esc(x.id)+'"><strong>'+esc(x.name)+'</strong><br><small>SKU '+esc(x.sku)+'</small></button>';}); box.html(h).show();
                    });
                },250);
            });
            $(document).on('click','.sbp-search-item',function(){
                let btn=$(this), p=payload('sbp_add_product'); p.token=$('#sbp-result').data('token'); p.product_id=btn.data('product-id');
                btn.prop('disabled',true);
                $.post(ajaxurl,p).done(function(r){
                    if(!r.success){$('#sbp-result').prepend('<div class="sbp-error">'+esc(r.data&&r.data.message?r.data.message:'Product kon niet worden toegevoegd.')+'</div>');return;}
                    let item=r.data.item, row=$('#sbp-items-table tbody tr').filter(function(){return String($(this).data('sku'))===String(item.sku);});
                    if(row.length){row.replaceWith(itemRow(item));}else{
                        if(!$('#sbp-items-table').length){loadPreview();return;}
                        $('#sbp-items-table tbody').append(itemRow(item));
                    }
                    $('#sbp-product-search').val(''); $('#sbp-search-results').hide().empty();
                    $('#sbp-edit-status').text('Product toegevoegd – 24 uur bewaard');
                }).always(function(){btn.prop('disabled',false);});
            });
            $(document).on('input','#sbp-planner-search',function(){
                let term=$(this).val().trim(), box=$('#sbp-planner-results'); clearTimeout(searchTimer);
                if(term.length<2){box.hide().empty();return;}
                searchTimer=setTimeout(function(){let p=payload('sbp_planner_search');p.token=$('#sbp-result').data('token');p.term=term;
                    $.post(ajaxurl,p).done(function(r){if(!r.success){box.html('<div class="sbp-search-empty">Zoeken mislukt.</div>').show();return;}
                    if(!r.data.results.length){box.html('<div class="sbp-search-empty">Geen product gevonden.</div>').show();return;}
                    let h='';r.data.results.forEach(function(x){h+='<button type="button" class="sbp-search-item sbp-planner-add" data-product-id="'+esc(x.id)+'"><strong>'+esc(x.name)+'</strong><br><small>SKU '+esc(x.sku||'—')+'</small></button>';});box.html(h).show();});
                },250);
            });
            $(document).on('click','.sbp-planner-add',function(){let btn=$(this),p=payload('sbp_planner_add');p.token=$('#sbp-result').data('token');p.product_id=btn.data('product-id');btn.prop('disabled',true);
                $.post(ajaxurl,p).done(function(r){if(!r.success){alert(r.data&&r.data.message?r.data.message:'Kon niet toevoegen.');return;}loadPreview();}).always(function(){btn.prop('disabled',false);});
            });
            $(document).on('click','.sbp-assign',function(){
                let btn=$(this), row=btn.closest('tr'), p=payload('sbp_assign_order_location');
                p.location=btn.data('location'); p.order_id=row.data('order-id');
                if(!confirm('Verkoop #'+p.order_id+' toewijzen aan '+btn.text()+'? Dit kan niet meer worden gewijzigd.')) return;
                row.find('.sbp-assign').prop('disabled',true);
                $.post(ajaxurl,p).done(function(r){
                    if(!r.success){alert(r.data&&r.data.message?r.data.message:'Locatie kon niet worden opgeslagen.');row.find('.sbp-assign').prop('disabled',false);return;}
                    saveOverrides(loadPreview,true);
                }).fail(function(){row.find('.sbp-assign').prop('disabled',false);});
            });
            $(document).on('change','#sbp-adv-all',function(){$('.sbp-adv-row').prop('checked',$(this).is(':checked'));});
            $(document).on('click','#sbp-adv-adopt',function(){
                let items=[];$('#sbp-advice-table tbody tr').each(function(){let tr=$(this);if(tr.find('.sbp-adv-row').is(':checked')){items.push({product_id:tr.data('product-id'),qty:tr.find('.sbp-adv-qty').val()});}});
                if(!items.length){alert('Vink eerst de regels aan die je wilt overnemen.');return;}
                let btn=$(this).prop('disabled',true);
                clearTimeout(autosaveTimer);
                saveOverrides(function(){
                    let p=payload('sbp_adopt_advice');p.token=$('#sbp-result').data('token');p.items=JSON.stringify(items);
                    $.post(ajaxurl,p).done(function(r){
                        if(!r.success){alert(r.data&&r.data.message?r.data.message:'Overnemen mislukt.');btn.prop('disabled',false);return;}
                        let note='<div class="sbp-ok">'+esc(r.data.message)+'</div>';
                        if(r.data.skipped&&r.data.skipped.length){note+='<div class="sbp-notice"><strong>Niet overgenomen:</strong><br>'+r.data.skipped.map(esc).join('<br>')+'</div>';}
                        loadPreview(function(){$('#sbp-result').prepend(note);});
                    }).fail(function(x){alert(x.responseJSON&&x.responseJSON.data&&x.responseJSON.data.message?x.responseJSON.data.message:'Overnemen mislukt.');btn.prop('disabled',false);});
                },true);
            });
            $(document).on('click','.sbp-planner-remove',function(){let p=payload('sbp_planner_remove');p.token=$('#sbp-result').data('token');p.sku=$(this).data('sku');$.post(ajaxurl,p).done(function(r){if(r.success)loadPreview();});});

            $(document).on('click','.sbp-remove-manual',function(){
                let btn=$(this), sku=String(btn.data('sku')||'');
                if(!sku || !confirm('Dit handmatig toegevoegde product uit de bestellijst verwijderen?')) return;
                let p=payload('sbp_remove_product'); p.token=$('#sbp-result').data('token'); p.sku=sku;
                btn.prop('disabled',true);
                $.post(ajaxurl,p).done(function(r){
                    if(!r.success){$('#sbp-result').prepend('<div class="sbp-error">'+esc(r.data&&r.data.message?r.data.message:'Product kon niet worden verwijderd.')+'</div>');return;}
                    if(r.data && r.data.item){
                        $('tr[data-sku="'+sku.replace(/"/g,'\\"')+'"]').replaceWith(itemRow(r.data.item));
                    }else{
                        $('tr[data-sku="'+sku.replace(/"/g,'\\"')+'"]').remove();
                    }
                    $('#sbp-edit-status').text('Handmatig product verwijderd – opgeslagen');
                }).always(function(){btn.prop('disabled',false);});
            });

            $(document).on('click','#sbp-copy-planner',function(){
                let text=$('#sbp-planner-text').val()||'';
                let done=function(){ $('#sbp-copy-status').text('Gekopieerd'); setTimeout(function(){$('#sbp-copy-status').text('');},2000); };
                if(navigator.clipboard && window.isSecureContext){
                    navigator.clipboard.writeText(text).then(done).catch(function(){
                        let ta=document.getElementById('sbp-planner-text'); ta.focus(); ta.select(); document.execCommand('copy'); done();
                    });
                }else{
                    let ta=document.getElementById('sbp-planner-text'); ta.focus(); ta.select(); document.execCommand('copy'); done();
                }
            });

            $(document).on('click',function(e){if(!$(e.target).closest('.sbp-search-wrap').length){$('#sbp-search-results').hide();}});

            $(document).on('click','#sbp-send',function(){
                let repeat=$('#sbp-include-sent').is(':checked'); let question=repeat?'Deze bestelling bevat mogelijk reeds verzonden kassaverkopen. Weet je zeker dat je deze opnieuw wilt verzenden naar <?php echo esc_js($s['recipient']); ?>?':'De gecontroleerde en eventueel aangepaste bestelling nu verzenden naar <?php echo esc_js($s['recipient']); ?>?'; if(!confirm(question)) return;
                let button=$(this).prop('disabled',true); saveOverrides(function(){let p=payload('sbp_send'); p.token=$('#sbp-result').data('token'); $.post(ajaxurl,p).done(function(r){$('#sbp-result').prepend(r.success?'<div class="sbp-ok">'+esc(r.data.message)+'</div>':'<div class="sbp-error">'+esc(r.data.message)+'</div>');}).always(function(){button.prop('disabled',false);});});
            });
            $(document).on('click','#sbp-download',function(){
                saveOverrides(function(){let p=payload('sbp_download'); p.token=$('#sbp-result').data('token'); window.location=ajaxurl+'?'+$.param(p);});
            });
        });
        </script>
        <?php
    }

    public function ajax_preview() {
        $this->guard();
        try {
            $data = $this->build_preview_from_request();
            $draft_restored = $this->restore_draft( $data );
            $token = wp_generate_password( 24, false, false );
            $data['user_id'] = get_current_user_id();
            set_transient( 'sbp_' . $token, $data, DAY_IN_SECONDS );
            wp_send_json_success( array(
                'token' => $token,
                'location_label' => ucfirst( $data['location'] ),
                'items' => array_values( $data['items'] ),
                // Plannerregels worden server-side samengevoegd en expliciet gescheiden
                // van de Bonusan-turflijst. Zo kan een handmatige turflijstregel nooit
                // per ongeluk ook als plannerregel verschijnen.
                'planner_items' => $this->build_planner_items( $data ),
                'unknown' => array_values( $data['unknown'] ),
                'planner_manual' => array_values( $data['planner_manual'] ?? array() ),
                'unresolved' => array_values( $data['unresolved'] ?? array() ),
                'order_count' => count( $data['order_ids'] ),
                'can_send' => ! empty( $data['items'] ),
                'include_sent' => ! empty( $data['include_sent'] ),
                'draft_restored' => $draft_restored,
                'draft_warnings' => array_values( $data['draft_warnings'] ?? array() ),
                'unassigned' => array_values( $data['unassigned'] ?? array() ),
                'stock_advice' => $this->get_stock_preview_advice( $data['location'] ),
            ) );
        } catch ( Throwable $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ), 400 );
        }
    }

    public function ajax_update() {
        $this->guard();
        $token = sanitize_key( $_POST['token'] ?? '' );
        $data = get_transient( 'sbp_' . $token );
        if ( ! $data || (int)($data['user_id'] ?? 0) !== get_current_user_id() ) {
            wp_send_json_error( array( 'message' => 'De controle is verlopen of hoort bij een andere gebruiker.' ), 400 );
        }
        $quantities = isset($_POST['quantities']) && is_array($_POST['quantities']) ? wp_unslash($_POST['quantities']) : array();
        foreach ( $data['items'] as $sku => &$item ) {
            // De browser stuurt de weergave-SKU; de server bewaart de genormaliseerde sleutel.
            $posted_key = null;
            if ( isset( $item['sku'] ) && array_key_exists( (string)$item['sku'], $quantities ) ) { $posted_key = (string)$item['sku']; }
            elseif ( array_key_exists( $sku, $quantities ) ) { $posted_key = $sku; }
            if ( null === $posted_key ) { continue; }
            $raw = str_replace(',', '.', sanitize_text_field((string)$quantities[$posted_key]));
            if ( ! is_numeric($raw) ) { wp_send_json_error( array( 'message' => 'Vul uitsluitend geldige aantallen in.' ), 400 ); }
            $qty = (float)$raw;
            if ( $qty < 0 || $qty > 100000 ) { wp_send_json_error( array( 'message' => 'Een aantal ligt buiten het toegestane bereik.' ), 400 ); }
            $item['qty'] = $qty;
            $item['manual_override'] = ! empty($item['manual']) || abs( $qty - (float)($item['pos_qty'] ?? 0) ) > 0.00001;
        }
        unset($item);
        set_transient( 'sbp_' . $token, $data, DAY_IN_SECONDS );
        $this->save_draft( $data );
        wp_send_json_success( array( 'message' => 'De aangepaste aantallen zijn opgeslagen en blijven 24 uur bewaard.' ) );
    }


    public function ajax_product_search() {
        $this->guard();
        $token = sanitize_key( $_POST['token'] ?? '' );
        $data = get_transient( 'sbp_' . $token );
        if ( ! $data || (int)($data['user_id'] ?? 0) !== get_current_user_id() ) {
            wp_send_json_error( array( 'message' => 'Open eerst een geldige bestellijst.' ), 400 );
        }
        $term = trim( sanitize_text_field( wp_unslash( $_POST['term'] ?? '' ) ) );
        if ( strlen($term) < 2 ) { wp_send_json_success( array( 'results' => array() ) ); }

        global $wpdb;
        $like = '%' . $wpdb->esc_like( $term ) . '%';
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON (pm.post_id=p.ID AND pm.meta_key='_sku')
             WHERE p.post_type IN ('product','product_variation')
               AND p.post_status NOT IN ('trash','auto-draft')
               AND (p.post_title LIKE %s OR pm.meta_value LIKE %s)
             ORDER BY CASE WHEN pm.meta_value=%s THEN 0 ELSE 1 END, p.post_title ASC
             LIMIT 20",
            $like, $like, $term
        ) );

        $template_map = $this->read_template_map( $data['location'] );
        $results = array();
        foreach ( $ids as $id ) {
            $product = wc_get_product( (int)$id );
            if ( ! $product ) { continue; }
            $sku = trim( (string)$product->get_sku() );
            $sku_key=$this->normalize_sku($sku); if ( '' === $sku_key || ( ! isset($template_map[$sku_key]) && ! $this->is_assistent_bonusan_product( $product ) ) ) { continue; } if ( isset($template_map[$sku_key]) ) { $sku=(string)$template_map[$sku_key]['sku']; }
            $results[] = array( 'id'=>(int)$product->get_id(), 'sku'=>$sku, 'name'=>$product->get_name() );
        }
        wp_send_json_success( array( 'results' => $results ) );
    }

    public function ajax_add_product() {
        $this->guard();
        $token = sanitize_key( $_POST['token'] ?? '' );
        $data = get_transient( 'sbp_' . $token );
        if ( ! $data || (int)($data['user_id'] ?? 0) !== get_current_user_id() ) {
            wp_send_json_error( array( 'message' => 'De controle is verlopen. Laad de bestellijst opnieuw.' ), 400 );
        }
        $product_id = absint( $_POST['product_id'] ?? 0 );
        $product = wc_get_product( $product_id );
        if ( ! $product ) { wp_send_json_error( array( 'message' => 'Product niet gevonden.' ), 404 ); }
        $sku = trim( (string)$product->get_sku() );
        if ( '' === $sku ) { wp_send_json_error( array( 'message' => 'Dit product heeft geen SKU.' ), 400 ); }
        $template_map = $this->read_template_map( $data['location'] );
        if ( ! isset($template_map[$this->normalize_sku($sku)]) && ! $this->is_assistent_bonusan_product( $product ) ) {
            wp_send_json_error( array( 'message' => 'SKU ' . $sku . ' staat niet in het Bonusan-bestelsjabloon en is ook niet door Schreuder Assistent als Bonusan-product herkend.' ), 400 );
        }

        // Strikte scheiding: een turflijstproduct mag nooit tegelijk als handmatig
        // plannerproduct blijven bestaan (ook niet uit een concept van v1.6.0/1.6.1).
        $planner_key = $this->normalize_sku( $sku );
        if ( isset($data['planner_manual'][$planner_key]) ) { unset($data['planner_manual'][$planner_key]); }
        if ( isset($data['planner_manual'][$sku]) ) { unset($data['planner_manual'][$sku]); }

        // Altijd dezelfde genormaliseerde sleutel gebruiken als bij het opbouwen van de lijst.
        $item_key = $planner_key;
        $display_sku = isset($template_map[$item_key]) ? (string)$template_map[$item_key]['sku'] : $sku;
        if ( isset($data['items'][$item_key]) ) {
            $data['items'][$item_key]['manual'] = true;
            $data['items'][$item_key]['manual_override'] = true;
            if ( (float)$data['items'][$item_key]['qty'] <= 0 ) { $data['items'][$item_key]['qty'] = 1; }
        } else {
            $data['items'][$item_key] = array(
                'sku' => $display_sku,
                'sku_key' => $item_key,
                'name' => $product->get_name(),
                'qty' => 1,
                'pos_qty' => 0,
                'webshop_qty' => 0,
                'original_qty' => 0,
                'manual' => true,
                'manual_override' => true,
            );
        }

        uasort( $data['items'], function($a,$b) use ($template_map){
            return ($template_map[$this->normalize_sku($a['sku'])]['row'] ?? 999999) <=> ($template_map[$this->normalize_sku($b['sku'])]['row'] ?? 999999);
        } );
        set_transient( 'sbp_' . $token, $data, DAY_IN_SECONDS );
        $this->save_draft( $data );
        wp_send_json_success( array( 'item' => $data['items'][$item_key] ) );
    }

    public function ajax_planner_search() {
        $this->guard();
        $token = sanitize_key( $_POST['token'] ?? '' );
        $data = get_transient( 'sbp_' . $token );
        if ( ! $data || (int)($data['user_id'] ?? 0) !== get_current_user_id() ) { wp_send_json_error( array('message'=>'Open eerst een geldige bestellijst.'), 400 ); }
        $term = trim( sanitize_text_field( wp_unslash( $_POST['term'] ?? '' ) ) );
        if ( strlen($term) < 2 ) { wp_send_json_success( array('results'=>array()) ); }
        global $wpdb; $like='%'.$wpdb->esc_like($term).'%';
        $ids=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON (pm.post_id=p.ID AND pm.meta_key='_sku') WHERE p.post_type IN ('product','product_variation') AND p.post_status NOT IN ('trash','auto-draft') AND (p.post_title LIKE %s OR pm.meta_value LIKE %s) ORDER BY p.post_title ASC LIMIT 20",$like,$like));
        // Plannerzoeker toont uitsluitend producten die NIET op de Bonusan-turflijst horen.
        // Alles wat in het locatiesjabloon staat (Bonusan + Schreuder) of door
        // Schreuder Assistent als Bonusan-product is herkend, hoort uitsluitend
        // bij de turflijst en wordt hier dus niet aangeboden.
        $template_map = $this->read_template_map( $data['location'] );
        $results=array();
        foreach($ids as $id){
            $product=wc_get_product((int)$id); if(!$product)continue;
            $sku=trim((string)$product->get_sku());
            $sku_key=$this->normalize_sku($sku);
            if ( ( '' !== $sku_key && isset($template_map[$sku_key]) ) || $this->is_assistent_bonusan_product($product) ) { continue; }
            $results[]=array('id'=>(int)$product->get_id(),'sku'=>$sku,'name'=>$product->get_name());
        }
        wp_send_json_success(array('results'=>$results));
    }

    public function ajax_planner_add() {
        $this->guard(); $token=sanitize_key($_POST['token']??''); $data=get_transient('sbp_'.$token);
        if(!$data || (int)($data['user_id']??0)!==get_current_user_id()){wp_send_json_error(array('message'=>'De controle is verlopen.'),400);}
        $product=wc_get_product(absint($_POST['product_id']??0)); if(!$product){wp_send_json_error(array('message'=>'Product niet gevonden.'),404);}
        $real_sku=trim((string)$product->get_sku());
        $template_map=$this->read_template_map($data['location']);
        $real_key=$this->normalize_sku($real_sku);
        if ( ( '' !== $real_key && isset($template_map[$real_key]) ) || $this->is_assistent_bonusan_product($product) ) {
            wp_send_json_error(array('message'=>'Dit is een Bonusan/Schreuder-product en hoort uitsluitend op de Bonusan-turflijst. Voeg het daar handmatig toe.'),400);
        }
        $sku=$real_sku; if($sku===''){$sku='product-'.$product->get_id();}
        $key=$this->normalize_sku($sku); if($key===''){$key='PRODUCT-'.$product->get_id();}
        if(!isset($data['planner_manual'])||!is_array($data['planner_manual'])){$data['planner_manual']=array();}
        if(isset($data['planner_manual'][$key])){$data['planner_manual'][$key]['qty']=(float)($data['planner_manual'][$key]['qty']??0)+1;}else{$data['planner_manual'][$key]=array('sku'=>$sku,'name'=>$product->get_name(),'qty'=>1,'manual_planner'=>true);}
        set_transient('sbp_'.$token,$data,DAY_IN_SECONDS); $this->save_draft($data);
        wp_send_json_success(array('item'=>$data['planner_manual'][$key]));
    }

    public function ajax_planner_remove() {
        $this->guard(); $token=sanitize_key($_POST['token']??''); $data=get_transient('sbp_'.$token);
        if(!$data || (int)($data['user_id']??0)!==get_current_user_id()){wp_send_json_error(array('message'=>'De controle is verlopen.'),400);}
        $sku=sanitize_text_field(wp_unslash($_POST['sku']??'')); $key=$this->normalize_sku($sku);
        if(isset($data['planner_manual'][$key]))unset($data['planner_manual'][$key]); elseif(isset($data['planner_manual'][$sku]))unset($data['planner_manual'][$sku]);
        set_transient('sbp_'.$token,$data,DAY_IN_SECONDS); $this->save_draft($data); wp_send_json_success(array('message'=>'Plannerproduct verwijderd.'));
    }

    public function ajax_remove_product() {
        $this->guard();
        $token = sanitize_key( $_POST['token'] ?? '' );
        $data = get_transient( 'sbp_' . $token );
        if ( ! $data || (int)($data['user_id'] ?? 0) !== get_current_user_id() ) {
            wp_send_json_error( array( 'message' => 'De controle is verlopen. Laad de bestellijst opnieuw.' ), 400 );
        }
        $sku = trim( sanitize_text_field( wp_unslash( $_POST['sku'] ?? '' ) ) );
        $key = $this->normalize_sku( $sku );
        if ( '' === $key || ! isset( $data['items'][$key] ) ) {
            // Oudere transients kunnen de ruwe SKU als sleutel bevatten.
            if ( isset( $data['items'][$sku] ) ) { $key = $sku; }
            else { wp_send_json_error( array( 'message' => 'Handmatig product niet gevonden.' ), 404 ); }
        }
        $item = $data['items'][$key];
        if ( empty( $item['manual'] ) ) {
            wp_send_json_error( array( 'message' => 'Alleen handmatig toegevoegde producten kunnen met deze knop worden verwijderd.' ), 400 );
        }

        // Als hetzelfde product inmiddels ook echt via de kassa is verkocht, verwijder alleen
        // de handmatige toevoeging/override en herstel het echte kassaaantal.
        if ( (float)($item['pos_qty'] ?? 0) > 0 || (float)($item['webshop_qty'] ?? 0) > 0 ) {
            $data['items'][$key]['manual'] = false;
            $data['items'][$key]['manual_override'] = false;
            $data['items'][$key]['qty'] = (float)($item['pos_qty'] ?? 0);
            $response_item = $data['items'][$key];
        } else {
            unset( $data['items'][$key] );
            $response_item = null;
        }
        set_transient( 'sbp_' . $token, $data, DAY_IN_SECONDS );
        $this->save_draft( $data );
        wp_send_json_success( array( 'message' => 'Handmatige productregel verwijderd.', 'item' => $response_item ) );
    }

    public function ajax_send() {
        $this->guard();
        $token = sanitize_key( $_POST['token'] ?? '' );
        $data = get_transient( 'sbp_' . $token );
        if ( ! $data || empty( $data['items'] ) || (int)($data['user_id'] ?? 0) !== get_current_user_id() ) {
            wp_send_json_error( array( 'message' => 'De controle is verlopen of bevat fouten. Maak de controle opnieuw.' ), 400 );
        }
        if ( ! $this->has_positive_quantity( $data['items'] ) ) { wp_send_json_error( array( 'message' => 'Er staat geen enkel product met een aantal groter dan 0 in de bestelling.' ), 400 ); }

        // Eén verzending per locatie tegelijk: voorkomt dubbele mails bij dubbelklik,
        // twee tabbladen of een herhaalde aanvraag na een time-out.
        $lock_name = 'send_' . sanitize_key( $data['location'] );
        if ( ! $this->acquire_lock( $lock_name, 300 ) ) {
            wp_send_json_error( array( 'message' => 'Er wordt op dit moment al een bestelling voor deze locatie verzonden. Controleer het verzendlogboek voordat je het opnieuw probeert.' ), 409 );
        }
        $result = $this->do_send( $data, $token );
        $this->release_lock( $lock_name );
        if ( ! empty( $result['error'] ) ) { wp_send_json_error( array( 'message' => $result['message'] ), 500 ); }
        wp_send_json_success( array( 'message' => $result['message'] ) );
    }

    /**
     * Voert de verzending uit en geeft altijd een resultaat terug. Zodra de mail
     * is verzonden wordt nooit meer een fout gemeld, want de gebruiker zou dan
     * opnieuw kunnen verzenden en Bonusan dubbel laten bestellen.
     */
    private function do_send( $data, $token ) {
        $file = '';

        // Twee controles (bijv. in twee tabbladen) hebben elk een eigen token. Controleer binnen
        // de lock opnieuw of een van deze orders intussen al is verzonden.
        if ( empty( $data['include_sent'] ) ) {
            $already = array();
            foreach ( (array)( $data['processed_order_ids'] ?? $data['order_ids'] ?? array() ) as $oid ) {
                $check = wc_get_order( (int)$oid );
                if ( $check && $check->get_meta( '_sbp_bonusan_sent_' . $data['location'] ) ) { $already[] = (int)$oid; }
            }
            if ( $already ) {
                return array( 'error' => true, 'message' => 'Een of meer orders in deze controle zijn intussen al verzonden (#' . implode( ', #', $already ) . '). Maak de controle opnieuw om dubbel bestellen te voorkomen.' );
            }
        }
        try {
            $file = $this->create_xlsx( $data );
            $s = $this->settings();
            $subject = strtr( $s['subject'], array( '{locatie}' => ucfirst($data['location']), '{datum}' => wp_date('d-m-Y') ) );
            $headers = array( 'Content-Type: text/plain; charset=UTF-8' );
            $reply_to = sanitize_email( $s['reply_to'] ?? '' );
            if ( $reply_to ) { $headers[] = 'Reply-To: ' . $reply_to; }
            $bcc_list = preg_split( '/[\s,;]+/', (string)($s['bcc'] ?? ''), -1, PREG_SPLIT_NO_EMPTY );
            $bcc_clean = array();
            foreach ( $bcc_list as $bcc_address ) {
                $bcc_address = sanitize_email( $bcc_address );
                if ( $bcc_address && ! in_array( $bcc_address, $bcc_clean, true ) ) {
                    $bcc_clean[] = $bcc_address;
                    $headers[] = 'Bcc: ' . $bcc_address;
                }
            }
            $sent = wp_mail( $s['recipient'], $subject, wp_strip_all_tags($s['message']), $headers, array( $file ) );
            if ( ! $sent ) { throw new RuntimeException( 'WordPress kon de e-mail niet verzenden. Controleer de SMTP-instellingen.' ); }
        } catch ( Throwable $e ) {
            if ( $file && file_exists( $file ) ) { @unlink( $file ); }
            return array( 'error' => true, 'message' => $e->getMessage() );
        }

        // Vanaf hier is de mail onderweg. Maak de controle meteen ongeldig en leg de
        // verzending vast, vóórdat de orders worden gemarkeerd.
        @unlink( $file );
        delete_transient( 'sbp_' . $token );
        delete_transient( $this->draft_key($data) );
        $order_ids = array_values( array_unique( array_map( 'intval', ( $data['processed_order_ids'] ?? $data['order_ids'] ) ) ) );
        $this->add_send_log( array(
            'sent_at' => current_time('mysql'),
            'location' => ucfirst($data['location']),
            'recipient' => $s['recipient'],
            'bcc' => $bcc_clean,
            'subject' => $subject,
            'user_id' => get_current_user_id(),
            'order_count' => count($order_ids),
            'article_count' => $this->sum_order_quantity($data['items']),
            'repeat_load' => ! empty( $data['include_sent'] ),
            'order_ids' => $order_ids,
        ) );

        $failed = array();
        $stamp = current_time('mysql');
        foreach ( $order_ids as $id ) {
            try {
                $order = wc_get_order( $id );
                if ( ! $order ) { $failed[] = $id; continue; }
                $order->update_meta_data( '_sbp_bonusan_sent_' . $data['location'], $stamp );
                $order->save_meta_data();
            } catch ( Throwable $e ) {
                $failed[] = $id;
            }
        }
        $message = 'De bestelling is verzonden naar ' . $s['recipient'] . '.';
        // Gevolgde producten uit deze bestelling worden "onderweg" naar de locatie. De voorraad stijgt pas
        // nadat je bij Locatievoorraad de levering hebt geaccordeerd.
        try {
            if ( $this->create_inbound_for_send( $data, $subject ) ) {
                $message .= ' De gevolgde producten uit deze bestelling staan nu als "onderweg" bij Locatievoorraad.';
            }
        } catch ( Throwable $e ) {
            error_log( 'Schreuder Bonusan POS: onderweg vastleggen mislukt: ' . $e->getMessage() );
            $message .= ' LET OP: de bestelling kon niet als "onderweg" bij Locatievoorraad worden vastgelegd (' . $e->getMessage() . ').';
        }
        if ( $failed ) {
            $message .= ' LET OP: de volgende orders konden niet als verzonden worden gemarkeerd en kunnen bij de volgende controle opnieuw verschijnen: #' . implode( ', #', $failed ) . '.';
        }
        return array( 'error' => false, 'message' => $message );
    }

    /**
     * Eenvoudige, atomaire lock via een unieke optierij (zelfde techniek als WordPress
     * zelf bij core-updates). Een verlopen lock (ouder dan $ttl seconden) wordt overgenomen.
     */
    private function acquire_lock( $name, $ttl = 300 ) {
        global $wpdb;
        $key = 'sbp_lock_' . sanitize_key( $name );
        $now = time();
        $ok = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, (string)$now ) );
        if ( $ok ) { return true; }
        $held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
        if ( $held && ( $now - $held ) > (int)$ttl ) {
            $took = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string)$now, $key, (string)$held ) );
            return (bool) $took;
        }
        return false;
    }

    private function release_lock( $name ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", 'sbp_lock_' . sanitize_key( $name ) ) );
    }

    public function ajax_download() {
        $this->guard();
        $token = sanitize_key( $_GET['token'] ?? '' );
        $data = get_transient( 'sbp_' . $token );
        if ( ! $data || empty( $data['items'] ) || (int)($data['user_id'] ?? 0) !== get_current_user_id() ) { wp_die( 'De controle is verlopen of bevat fouten.' ); }
        if ( ! $this->has_positive_quantity( $data['items'] ) ) { wp_die( 'Er staat geen enkel product met een aantal groter dan 0 in de bestelling.' ); }
        $file = '';
        try {
            $file = $this->create_xlsx( $data );
            nocache_headers();
            header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
            header( 'Content-Disposition: attachment; filename="' . basename($file) . '"' );
            header( 'Content-Length: ' . filesize($file) );
            readfile( $file ); @unlink( $file ); exit;
        } catch ( Throwable $e ) {
            if ( $file && file_exists( $file ) ) { @unlink( $file ); }
            wp_die( esc_html( $e->getMessage() ) );
        }
    }


    private function draft_key( $data ) {
        $parts = array(
            get_current_user_id(),
            sanitize_key( $data['location'] ?? '' ),
            sanitize_text_field( $data['from'] ?? '' ),
            sanitize_text_field( $data['to'] ?? '' ),
            ! empty($data['pos_only']) ? '1' : '0',
            ! empty($data['include_sent']) ? '1' : '0',
        );
        return 'sbp_draft_' . md5( implode('|',$parts) );
    }

    private function save_draft( $data ) {
        $draft_items = array();
        foreach ( (array)($data['items'] ?? array()) as $sku => $item ) {
            if ( empty($item['manual']) && empty($item['manual_override']) ) { continue; }
            $draft_items[$sku] = array(
                'sku' => (string)$item['sku'],
                'name' => (string)$item['name'],
                'qty' => (float)$item['qty'],
                'manual' => ! empty($item['manual']),
                'manual_override' => true,
                // Kassaverkoop op het moment van opslaan, om later een wijziging te kunnen melden.
                'base_pos_qty' => (float)($item['pos_qty'] ?? 0),
            );
        }
        $draft = array( 'items'=>$draft_items, 'planner_manual'=>(array)($data['planner_manual'] ?? array()), 'saved_at'=>time() );
        set_transient( $this->draft_key($data), $draft, DAY_IN_SECONDS );
    }

    /**
     * Staat deze SKU op de Bonusan/Schreuder-turflijst? Dat is zo wanneer hij in het
     * sjabloon van de gekozen locatie staat of door Schreuder Assistent als
     * Bonusan-product is herkend. Dit is dé bron voor de scheiding turflijst/Planner.
     */
    private function is_turflist_sku( $sku, $template_map ) {
        $key = $this->normalize_sku( $sku );
        if ( '' === $key ) { return false; }
        return isset( $template_map[$key] ) || $this->is_assistent_bonusan_sku( $sku );
    }

    private function restore_draft( &$data ) {
        $draft = get_transient( $this->draft_key($data) );
        if ( ! is_array($draft) ) { return false; }
        $template_map = $this->read_template_map( $data['location'] );
        $restored = false;
        $data['draft_warnings'] = array();

        // Handmatige plannerregels uit een concept (ook uit v1.6.x) mogen nooit een
        // turflijstproduct bevatten, ook niet wanneer dat product in deze periode niet is verkocht.
        $planner = array();
        foreach ( (array)( $draft['planner_manual'] ?? array() ) as $pkey => $pitem ) {
            $psku = (string)( $pitem['sku'] ?? '' );
            if ( $this->is_turflist_sku( $psku, $template_map ) ) { continue; }
            $planner[$pkey] = $pitem;
        }
        if ( $planner ) { $data['planner_manual'] = $planner; $restored = true; }

        foreach ( (array)( $draft['items'] ?? array() ) as $sku => $saved ) {
            $saved_sku = ( isset($saved['sku']) && '' !== trim((string)$saved['sku']) ) ? trim((string)$saved['sku']) : (string)$sku;
            $sku_key = $this->normalize_sku( $saved_sku );
            if ( '' === $sku_key || ! $this->is_turflist_sku( $saved_sku, $template_map ) ) { continue; }
            if ( isset($data['items'][$sku_key]) ) {
                $now_pos = (float)( $data['items'][$sku_key]['pos_qty'] ?? 0 );
                $saved_qty = (float)( $saved['qty'] ?? $data['items'][$sku_key]['qty'] );
                if ( isset($saved['base_pos_qty']) && abs( $now_pos - (float)$saved['base_pos_qty'] ) > 0.00001 ) {
                    $data['draft_warnings'][] = array(
                        'sku' => (string)$data['items'][$sku_key]['sku'], 'name' => (string)$data['items'][$sku_key]['name'],
                        'saved_qty' => $saved_qty, 'base_qty' => (float)$saved['base_pos_qty'], 'now_qty' => $now_pos,
                    );
                }
                $data['items'][$sku_key]['qty'] = $saved_qty;
                $data['items'][$sku_key]['manual_override'] = true;
                if ( ! empty($saved['manual']) ) { $data['items'][$sku_key]['manual'] = true; }
            } else {
                $product_id = wc_get_product_id_by_sku( $saved_sku );
                $product = $product_id ? wc_get_product($product_id) : false;
                if ( ! $product ) { continue; }
                $data['items'][$sku_key] = array(
                    'sku'=> isset($template_map[$sku_key]) ? (string)$template_map[$sku_key]['sku'] : $saved_sku,
                    'sku_key'=>$sku_key,
                    'name'=>$product->get_name(),
                    'qty'=>(float)($saved['qty'] ?? 1),
                    'pos_qty'=>0,
                    'webshop_qty'=>0,
                    'original_qty'=>0,
                    'manual'=>true,
                    'manual_override'=>true,
                );
            }
            $restored = true;
        }
        if ( $restored ) {
            uasort( $data['items'], function($a,$b) use ($template_map){
                return ($template_map[$this->normalize_sku($a['sku'])]['row'] ?? 999999) <=> ($template_map[$this->normalize_sku($b['sku'])]['row'] ?? 999999);
            } );
        }
        return $restored;
    }

    private function guard() {
        check_ajax_referer( self::NONCE );
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( array( 'message' => 'Geen toegang.' ), 403 ); }
        if ( ! function_exists( 'wc_get_orders' ) ) { wp_send_json_error( array( 'message' => 'WooCommerce is niet actief.' ), 400 ); }
    }

    private function build_preview_from_request() {
        $location = sanitize_key( $_REQUEST['location'] ?? '' );
        if ( ! in_array( $location, array('baarn','haarlem','zwolle'), true ) ) { throw new InvalidArgumentException( 'Kies een geldige locatie.' ); }
        $from = sanitize_text_field( $_REQUEST['from'] ?? '' );
        $to   = sanitize_text_field( $_REQUEST['to'] ?? '' );
        if ( ! preg_match('/^\d{4}-\d{2}-\d{2}$/',$from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/',$to) ) { throw new InvalidArgumentException( 'Kies een geldige periode.' ); }
        // Gebruik alle geregistreerde WooCommerce-orderstatussen. YITH/andere plugins kunnen
        // eigen statussen toevoegen, zoals 'Openstaand factuur'. Met een vaste statuslijst zou
        // zo'n fysieke kassaverkoop al vóór onze POS-logica uit de query verdwijnen.
        $registered_statuses = function_exists( 'wc_get_order_statuses' ) ? array_keys( wc_get_order_statuses() ) : array();
        if ( empty( $registered_statuses ) ) {
            $registered_statuses = array( 'wc-pending','wc-processing','wc-completed','wc-on-hold' );
        }
        $orders = wc_get_orders( array(
            'limit' => -1,
            'status' => $registered_statuses,
            'date_created' => $from . ' 00:00:00...' . $to . ' 23:59:59',
            'orderby' => 'date', 'order' => 'ASC',
        ) );
        $pos_only = ! empty( $_REQUEST['pos_only'] );
        $include_sent = ! empty( $_REQUEST['include_sent'] );
        $items = array(); $order_ids = array(); $processed_order_ids = array(); $unresolved = array(); $planner_manual = array(); $unassigned = array();
        foreach ( $orders as $order ) {
            if ( ! $include_sent && $order->get_meta( '_sbp_bonusan_sent_' . $location ) ) { continue; }
            $is_pos = $this->is_yith_pos_order( $order );
            if ( $pos_only && ! $is_pos ) { continue; }

            // Fysieke kassaverkoop: afgerond, of een POS-verkoop via bankoverschrijving/Openstaand
            // factuur. De turflijst volgt wat fysiek is meegegeven, niet alleen wat betaald is.
            // Een gewone webshoporder is nooit een fysieke kassaverkoop, ook niet als hij is afgerond.
            $physical = $is_pos && ( 'completed' === $order->get_status() || $this->is_pos_bank_transfer_sale( $order ) || $this->is_pos_open_invoice_sale( $order ) );

            // Locatie van de order: expliciet gekozen, anders uit het register-ID van de kassa.
            // '' = onbekende/extra kassa: nog niet besteld totdat de gebruiker een locatie kiest.
            $order_location = $this->resolve_order_location( $order );
            if ( '' === $order_location ) {
                if ( $physical ) {
                    $summary = $this->summarize_unassigned_order( $order );
                    if ( $summary ) { $unassigned[] = $summary; }
                }
                continue;
            }
            if ( $order_location !== $location ) { continue; }

            $used = false; $has_completed_bonusan_item = false;
            foreach ( $order->get_items('line_item') as $item ) {
                // Consulten/diensten horen nooit in een productbestel- of plannerlijst.
                // Filter op zowel productnaam als productcategorie zodat ook consulten zonder
                // SKU of met een afwijkende naam worden uitgesloten.
                if ( $this->is_consult_order_item( $item ) ) {
                    continue;
                }
                // Gedeeltelijk geretourneerde aantallen tellen niet als verkoop.
                $line_qty = max( 0.0, (float) $item->get_quantity() + (float) $order->get_qty_refunded_for_item( $item->get_id() ) );
                // YITH POS kan oudere orderregels bewaren waarvan get_product() geen actueel
                // productobject meer teruggeeft. Resolving gebeurt daarom via meerdere bronnen.
                $sku = $this->resolve_order_item_sku( $item );
                if ( '' === $sku ) {
                    $unresolved[] = array(
                        'order_id'    => (int) $order->get_id(),
                        'name'        => (string) $item->get_name(),
                        'planner_qty' => $physical ? $line_qty : 0,
                        'reason'      => 'Geen SKU gevonden in product, variatie, orderregel-meta of productnaam.',
                    );
                    if ( $physical ) {
                        $used = true;
                        $has_completed_bonusan_item = true;
                    }
                    continue;
                }
                $sku_key = $this->normalize_sku( $sku );
                if ( '' === $sku_key ) { continue; }
                if ( ! isset( $items[$sku_key] ) ) {
                    $items[$sku_key] = array( 'sku'=>$sku, 'sku_key'=>$sku_key, 'name'=>$item->get_name(), 'qty'=>0, 'pos_qty'=>0, 'webshop_qty'=>0, 'original_qty'=>0 );
                }
                if ( $physical ) {
                    $items[$sku_key]['pos_qty'] += $line_qty;
                    $has_completed_bonusan_item = true;
                } elseif ( in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
                    // Webshoporders en nog niet fysiek meegegeven kassa-orders: alleen ter informatie.
                    $items[$sku_key]['webshop_qty'] += $line_qty;
                }
                $items[$sku_key]['original_qty'] = $items[$sku_key]['pos_qty'] + $items[$sku_key]['webshop_qty'];
                $items[$sku_key]['qty'] = $items[$sku_key]['pos_qty'];
                $used = true;
            }
            if ( $used ) { $order_ids[] = $order->get_id(); }
            if ( $has_completed_bonusan_item ) { $processed_order_ids[] = $order->get_id(); }
        }
        $template_map = $this->read_template_map( $location );
        $unknown = array();
        foreach ( array_keys( $items ) as $key ) {
            if ( isset( $template_map[ $key ] ) ) {
                // Gebruik de SKU exact zoals hij in het Bonusan-sjabloon staat.
                $items[$key]['sku'] = (string) $template_map[ $key ]['sku'];
                $items[$key]['template_missing'] = false;
            } elseif ( $this->is_assistent_bonusan_sku( $items[$key]['sku'] ) ) {
                // Nieuwe Bonusan-producten kunnen al door Schreuder Assistent herkend zijn,
                // terwijl het oudere locatie-sjabloon de SKU nog niet bevat.
                $items[$key]['template_missing'] = true;
            } else {
                // Een niet-Bonusan/verkeerd gekoppelde verkoop hoort niet in de bestelregels.
                // Wel bewaren als diagnose, zodat niets stilletjes verdwijnt.
                // Niet-Bonusan verkopen zijn bedoeld voor de interne planner. Alleen
                // fysiek meegegeven kassaverkopen tellen mee; webshop/in-behandeling niet.
                $planner_qty = (float) $items[$key]['pos_qty'];
                if ( $planner_qty > 0 ) {
                    $unknown[] = array(
                        'sku'  => (string) $items[$key]['sku'],
                        'name' => (string) $items[$key]['name'],
                        'qty'  => $planner_qty,
                    );
                }
                unset( $items[$key] );
            }
        }
        uasort( $items, function($a,$b) use ($template_map){
            $ak=$this->normalize_sku($a['sku']); $bk=$this->normalize_sku($b['sku']);
            return ($template_map[$ak]['row'] ?? 999999) <=> ($template_map[$bk]['row'] ?? 999999);
        } );
        return compact( 'location','from','to','items','unknown','planner_manual','unresolved','unassigned','order_ids','processed_order_ids','include_sent','pos_only' );
    }

    /**
     * Bouw één volledig gescheiden plannerlijst.
     * - Producten die al op de Bonusan-turflijst staan worden nooit automatisch
     *   ook als plannerproduct getoond.
     * - Dezelfde SKU wordt over meerdere orders opgeteld.
     * - Regels zonder SKU worden op genormaliseerde productnaam samengevoegd.
     * - Handmatige plannerregels blijven onafhankelijk van handmatige turflijstregels.
     */
    private function build_planner_items( $data ) {
        $groups = array();
        $turflist_skus = array();
        $template_map = $this->read_template_map( $data['location'] );

        foreach ( (array)($data['items'] ?? array()) as $item ) {
            $k = $this->normalize_sku( (string)($item['sku'] ?? '') );
            if ( '' !== $k ) { $turflist_skus[$k] = true; }
        }

        $add = function( $sku, $name, $qty, $manual = false, $no_sku = false ) use ( &$groups, $turflist_skus, $template_map ) {
            $qty = (float)$qty;
            if ( $qty <= 0 ) { return; }
            $sku = trim( (string)$sku );
            $name = trim( (string)$name );
            $sku_key = $this->normalize_sku( $sku );

            // Strikte scheiding: staat een SKU op de Bonusan-turflijst, dan mag
            // dezelfde SKU nooit in de planner verschijnen, ook niet vanuit een oud
            // handmatig plannerconcept.
            if ( '' !== $sku_key && isset( $turflist_skus[$sku_key] ) ) { return; }
            // Ook wanneer het product in deze periode niet is verkocht: een turflijstproduct
            // (sjabloon of Schreuder Assistent) komt nooit uit een handmatige plannerregel.
            if ( $manual && '' !== $sku_key && $this->is_turflist_sku( $sku, $template_map ) ) { return; }

            if ( '' !== $sku_key && '—' !== $sku ) {
                $key = 'sku:' . $sku_key;
            } else {
                $name_key = strtolower( remove_accents( wp_strip_all_tags( $name ) ) );
                $name_key = preg_replace( '/\s+/', ' ', trim( $name_key ) );
                $key = 'name:' . $name_key;
            }
            if ( '' === $key || 'name:' === $key ) { return; }

            if ( ! isset( $groups[$key] ) ) {
                $groups[$key] = array(
                    'sku' => ( '' !== $sku && '—' !== $sku ) ? $sku : '—',
                    'name' => $name,
                    'qty' => 0,
                    'manual_qty' => 0,
                    'automatic_qty' => 0,
                    'manual_planner' => false,
                    'no_sku' => $no_sku || '' === $sku || '—' === $sku,
                );
            }
            $groups[$key]['qty'] += $qty;
            if ( $manual ) {
                $groups[$key]['manual_qty'] += $qty;
                $groups[$key]['manual_planner'] = true;
            } else {
                $groups[$key]['automatic_qty'] += $qty;
            }
        };

        foreach ( (array)($data['unknown'] ?? array()) as $item ) {
            $add( $item['sku'] ?? '', $item['name'] ?? '', $item['qty'] ?? 0, false, false );
        }
        foreach ( (array)($data['unresolved'] ?? array()) as $item ) {
            $add( '', $item['name'] ?? '', $item['planner_qty'] ?? 0, false, true );
        }
        foreach ( (array)($data['planner_manual'] ?? array()) as $item ) {
            $add( $item['sku'] ?? '', $item['name'] ?? '', $item['qty'] ?? 0, true, empty($item['sku']) );
        }

        uasort( $groups, function( $a, $b ) {
            return strcasecmp( (string)$a['name'], (string)$b['name'] );
        } );
        return array_values( $groups );
    }

    private function is_consult_order_item( $item ) {
        $name = strtolower( remove_accents( wp_strip_all_tags( (string) $item->get_name() ) ) );
        // Vangt o.a. consult, consulten, consultatie en consulttijd op.
        if ( false !== strpos( $name, 'consult' ) ) {
            return true;
        }

        $product = $item->get_product();
        if ( ! $product ) {
            $pid = (int) $item->get_product_id();
            if ( $pid ) { $product = wc_get_product( $pid ); }
        }
        if ( $product ) {
            $product_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
            $terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'all' ) );
            if ( ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term ) {
                    $term_text = strtolower( remove_accents( (string) $term->name . ' ' . (string) $term->slug ) );
                    if ( false !== strpos( $term_text, 'consult' ) ) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    private function resolve_order_item_sku( $item ) {
        // 1. Actueel product/variatie.
        $product = $item->get_product();
        if ( $product ) {
            $sku = trim( (string) $product->get_sku() );
            if ( '' !== $sku ) { return $sku; }
            if ( $product->is_type('variation') ) {
                $parent = wc_get_product( $product->get_parent_id() );
                if ( $parent ) {
                    $sku = trim( (string) $parent->get_sku() );
                    if ( '' !== $sku ) { return $sku; }
                }
            }
        }

        // 2. Variation-ID / product-ID rechtstreeks uit de orderregel.
        foreach ( array( (int)$item->get_variation_id(), (int)$item->get_product_id() ) as $pid ) {
            if ( $pid <= 0 ) { continue; }
            $candidate = wc_get_product( $pid );
            if ( ! $candidate ) { continue; }
            $sku = trim( (string) $candidate->get_sku() );
            if ( '' !== $sku ) { return $sku; }
            if ( $candidate->is_type('variation') ) {
                $parent = wc_get_product( $candidate->get_parent_id() );
                if ( $parent ) {
                    $sku = trim( (string) $parent->get_sku() );
                    if ( '' !== $sku ) { return $sku; }
                }
            }
        }

        // 3. Sommige POS-versies bewaren een SKU-snapshot als orderregel-meta.
        foreach ( $item->get_meta_data() as $meta ) {
            $key = strtolower( (string) $meta->key );
            if ( false === strpos( $key, 'sku' ) ) { continue; }
            $value = is_scalar( $meta->value ) ? trim( (string) $meta->value ) : '';
            if ( '' !== $value ) { return $value; }
        }

        // 4. Laatste veilige fallback: exact dezelfde productnaam in WooCommerce.
        // Alleen gebruiken wanneer er precies één product met die titel en SKU bestaat.
        global $wpdb;
        $name = trim( (string) $item->get_name() );
        if ( '' !== $name ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT p.ID, pm.meta_value AS sku
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_sku'
                 WHERE p.post_title = %s
                   AND p.post_type IN ('product','product_variation')
                   AND p.post_status NOT IN ('trash','auto-draft')
                   AND pm.meta_value <> ''
                 LIMIT 2",
                $name
            ) );
            if ( 1 === count( $rows ) ) {
                return trim( (string) $rows[0]->sku );
            }
        }
        return '';
    }

    private function is_pos_open_invoice_sale( $order ) {
        if ( ! $order || ! $this->is_yith_pos_order( $order ) ) { return false; }
        if ( in_array( $order->get_status(), array( 'cancelled','refunded','failed','trash' ), true ) ) { return false; }

        $status_slug = strtolower( trim( (string) $order->get_status() ) );
        $status_name = function_exists( 'wc_get_order_status_name' )
            ? strtolower( trim( wp_strip_all_tags( (string) wc_get_order_status_name( $status_slug ) ) ) )
            : '';
        $method = strtolower( trim( (string) $order->get_payment_method() ) );
        $title  = strtolower( trim( (string) $order->get_payment_method_title() ) );

        // Ondersteun zowel Nederlandse als Engelstalige/custom slugs en labels.
        $haystacks = array( $status_slug, $status_name, $method, $title );
        foreach ( $haystacks as $haystack ) {
            $flat = str_replace( array( '-', '_', ' ' ), '', $haystack );
            if ( false !== strpos( $haystack, 'openstaand factuur' ) ||
                 false !== strpos( $haystack, 'openstaande factuur' ) ||
                 false !== strpos( $haystack, 'open invoice' ) ||
                 false !== strpos( $flat, 'openstaandfactuur' ) ||
                 false !== strpos( $flat, 'openstaandefactuur' ) ||
                 false !== strpos( $flat, 'openinvoice' ) ) {
                return true;
            }
        }
        return false;
    }

    private function is_pos_bank_transfer_sale( $order ) {
        if ( ! $order || ! $this->is_yith_pos_order( $order ) ) { return false; }

        // Een fysieke POS-verkoop via bankoverschrijving kan afhankelijk van WooCommerce/YITH
        // als pending, on-hold of processing blijven staan. Alleen echt negatieve statussen uitsluiten.
        if ( in_array( $order->get_status(), array( 'cancelled','refunded','failed','trash' ), true ) ) { return false; }

        $method = strtolower( trim( (string) $order->get_payment_method() ) );
        $title  = strtolower( trim( (string) $order->get_payment_method_title() ) );
        if ( 'bacs' === $method ) { return true; }
        foreach ( array( 'bankoverschrijving', 'bank transfer', 'direct bank', 'directe bankoverschrijving', 'overschrijving' ) as $needle ) {
            if ( false !== strpos( $title, $needle ) || false !== strpos( $method, str_replace(' ', '_', $needle) ) ) { return true; }
        }
        return false;
    }

    private function is_yith_pos_order( $order ) {
        if ( ! $order ) { return false; }

        // Bevestigd aan de hand van echte kassa-orders: YITH POS zet _yith_pos_order = 1 en
        // _yith_pos_register = kassa-ID. created_via is bij deze orders 'rest-api'.
        $flag = strtolower( trim( (string) $order->get_meta( '_yith_pos_order' ) ) );
        if ( '' !== $flag && ! in_array( $flag, array( '0', 'no', 'false' ), true ) ) { return true; }
        if ( '' !== trim( (string) $order->get_meta( '_yith_pos_register' ) ) ) { return true; }

        // Oudere orders: created_via met een los woord 'pos' of 'yith', of POS-meta van YITH POS.
        if ( is_callable( array( $order, 'get_created_via' ) ) ) {
            $created_via = strtolower( trim( (string) $order->get_created_via() ) );
            if ( preg_match( '/(^|[^a-z])pos([^a-z]|$)/', $created_via ) || false !== strpos( $created_via, 'yith' ) ) { return true; }
        }
        foreach ( $order->get_meta_data() as $meta ) {
            if ( preg_match( '/^_?yith_pos_/i', (string) $meta->key ) ) { return true; }
        }
        return false;
    }

    /** Splitst een instelling met één of meer kassa-ID's (komma, spatie of nieuwe regel). */
    private function parse_id_list( $raw ) {
        $parts = preg_split( '/[\s,;]+/', strtolower( trim( (string) $raw ) ), -1, PREG_SPLIT_NO_EMPTY );
        return array_values( array_unique( $parts ) );
    }

    /**
     * Bepaalt bij welke locatie een order hoort.
     * - Een door de gebruiker gekozen locatie (_sbp_location_stock_location) gaat altijd voor.
     * - Webshoporders horen bij Baarn (hoofdvoorraad).
     * - Kassa-orders: exacte vergelijking van het register-ID (of store-ID) met de instellingen.
     * - Extra kassa of onbekende kassa: '' = nog geen locatie, de gebruiker moet kiezen.
     * Er wordt nooit op losse tekst of delen van een waarde gegokt.
     */
    private function resolve_order_location( $order ) {
        $locations = $this->stock_locations();
        $explicit = sanitize_key( (string) $order->get_meta( '_sbp_location_stock_location' ) );
        if ( isset( $locations[$explicit] ) ) { return $explicit; }
        if ( ! $this->is_yith_pos_order( $order ) ) { return 'baarn'; }

        $s = $this->settings();
        $register = strtolower( trim( (string) $order->get_meta( '_yith_pos_register' ) ) );
        $store = strtolower( trim( (string) $order->get_meta( '_yith_pos_store' ) ) );
        if ( '' !== $register && in_array( $register, $this->parse_id_list( $s['extra_register_meta'] ?? '' ), true ) ) { return ''; }
        foreach ( $locations as $loc => $label ) {
            $ids = $this->parse_id_list( $s['location_meta'][$loc] ?? '' );
            if ( ! $ids ) { continue; }
            if ( '' !== $register && in_array( $register, $ids, true ) ) { return $loc; }
            if ( '' !== $store && in_array( $store, $ids, true ) ) { return $loc; }
        }
        return '';
    }

    private function summarize_unassigned_order( $order ) {
        $lines = array();
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( $this->is_consult_order_item( $item ) ) { continue; }
            $qty = max( 0.0, (float) $item->get_quantity() + (float) $order->get_qty_refunded_for_item( $item->get_id() ) );
            if ( $qty <= 0 ) { continue; }
            $lines[] = rtrim( rtrim( number_format( $qty, 2, '.', '' ), '0' ), '.' ) . '× ' . $item->get_name();
        }
        if ( ! $lines ) { return null; }
        $created = $order->get_date_created();
        return array( 'order_id' => (int) $order->get_id(), 'date' => $created ? $created->date_i18n( 'd-m-Y H:i' ) : '', 'lines' => $lines );
    }

    private function normalize_sku( $sku ) {
        $sku = html_entity_decode( (string) $sku, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $sku = preg_replace('/[\x{00A0}\s]+/u', '', $sku);
        return strtoupper( trim( (string) $sku ) );
    }

    private function template_path( $location ) { return plugin_dir_path(__FILE__) . 'templates/' . $location . '.xlsx'; }

    private function read_template_map( $location ) {
        static $cache = array();
        if ( isset($cache[$location]) ) { return $cache[$location]; }
        $path = $this->template_path($location);
        if ( ! file_exists($path) ) { throw new RuntimeException('Het sjabloon voor deze locatie ontbreekt.'); }
        $zip = new ZipArchive(); if ( true !== $zip->open($path) ) { throw new RuntimeException('Het Excel-sjabloon kan niet worden geopend.'); }
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml'); $zip->close();
        if ( false === $xml ) { throw new RuntimeException('Het eerste werkblad ontbreekt in het sjabloon.'); }
        $dom = new DOMDocument(); $dom->preserveWhiteSpace = true; $dom->loadXML($xml);
        $xp = new DOMXPath($dom); $xp->registerNamespace('x','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $shared = $this->read_shared_strings($path);
        $map = array();
        foreach ( $xp->query('//x:sheetData/x:row[@r >= 10]') as $row ) {
            $r=(int)$row->getAttribute('r'); $sku=$this->cell_value($xp,$row,'B'.$r,$shared); $name=$this->cell_value($xp,$row,'C'.$r,$shared);
            $sku=trim((string)$sku); $key=$this->normalize_sku($sku); if($key!==''){$map[$key]=array('row'=>$r,'name'=>$name,'sku'=>$sku);}
        }
        $cache[$location] = $map;
        return $map;
    }

    private function read_shared_strings( $path ) {
        $zip=new ZipArchive(); $strings=array(); if(true!==$zip->open($path)){return $strings;} $xml=$zip->getFromName('xl/sharedStrings.xml'); $zip->close(); if(false===$xml){return $strings;}
        $dom=new DOMDocument(); $dom->loadXML($xml); $xp=new DOMXPath($dom); $xp->registerNamespace('x','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        foreach($xp->query('//x:si') as $si){$txt='';foreach($xp->query('.//x:t',$si) as $t){$txt.=$t->textContent;}$strings[]=$txt;} return $strings;
    }

    private function cell_value( $xp, $row, $ref, $shared ) {
        $nodes=$xp->query('./x:c[@r="'.$ref.'"]',$row); if(!$nodes->length){return '';} $c=$nodes->item(0); $v=$xp->query('./x:v',$c); if(!$v->length){return '';} $val=$v->item(0)->textContent; return ('s'===$c->getAttribute('t'))?($shared[(int)$val]??''):$val;
    }


    private function is_assistent_bonusan_sku( $sku ) {
        if ( ! function_exists('wc_get_product_id_by_sku') ) { return false; }
        $id = wc_get_product_id_by_sku( trim((string)$sku) );
        if ( ! $id ) { return false; }
        $product = wc_get_product( $id );
        return $product ? $this->is_assistent_bonusan_product( $product ) : false;
    }

    private function is_assistent_bonusan_product( $product ) {
        if ( ! $product ) { return false; }
        $ids = array( (int)$product->get_id() );
        if ( $product->is_type('variation') && $product->get_parent_id() ) { $ids[] = (int)$product->get_parent_id(); }
        foreach ( array_unique($ids) as $id ) {
            // Deze velden worden door Schreuder Assistent 2.1.0 tijdens een succesvolle
            // Bonusan-voorraadsynchronisatie op herkende producten opgeslagen.
            if ( metadata_exists('post',$id,'_sbc_source_sku') || metadata_exists('post',$id,'_sbc_canonical_sku') || metadata_exists('post',$id,'_sbc_factory_stock') ) {
                return true;
            }
        }
        return false;
    }

    private function sum_order_quantity( $items ) {
        $total = 0;
        foreach ( $items as $item ) { $total += max( 0, (float)($item['qty'] ?? 0) ); }
        return $total;
    }

    private function add_send_log( $entry ) {
        $log = get_option( 'sbp_send_log', array() );
        if ( ! is_array( $log ) ) { $log = array(); }
        array_unshift( $log, $entry );
        update_option( 'sbp_send_log', array_slice( $log, 0, 50 ), false );
    }

    private function render_send_log() {
        $log = get_option( 'sbp_send_log', array() );
        if ( empty( $log ) || ! is_array( $log ) ) {
            echo '<p>Er zijn nog geen verzendingen geregistreerd.</p>';
            return;
        }
        echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Datum en tijd</th><th>Locatie</th><th>Ontvanger</th><th>BCC</th><th>Onderwerp</th><th>Orders</th><th>Artikelen</th><th>Verzonden door</th></tr></thead><tbody>';
        foreach ( array_slice( $log, 0, 20 ) as $entry ) {
            $user = get_userdata( (int)($entry['user_id'] ?? 0) );
            $user_name = $user ? $user->display_name : 'Onbekend';
            echo '<tr>';
            echo '<td>' . esc_html( $entry['sent_at'] ?? '' ) . '</td>';
            echo '<td>' . esc_html( $entry['location'] ?? '' ) . '</td>';
            echo '<td>' . esc_html( $entry['recipient'] ?? '' ) . '</td>';
            echo '<td>' . esc_html( implode( ', ', (array)($entry['bcc'] ?? array()) ) ) . '</td>';
            echo '<td>' . esc_html( $entry['subject'] ?? '' ) . '</td>';
            echo '<td>' . esc_html( (string)($entry['order_count'] ?? 0) ) . '</td>';
            echo '<td>' . esc_html( (string)($entry['article_count'] ?? 0) ) . '</td>';
            echo '<td>' . esc_html( $user_name ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table><p class="description">De laatste 50 verzendingen worden bewaard; maximaal 20 worden hier getoond.</p>';
    }

    private function has_positive_quantity( $items ) {
        foreach ( $items as $item ) { if ( (float)($item['qty'] ?? 0) > 0 ) { return true; } }
        return false;
    }

    private function create_xlsx( $data ) {
        $source=$this->template_path($data['location']);
        $uploads=wp_upload_dir(); $dir=trailingslashit($uploads['basedir']).'schreuder-bonusan-orders'; wp_mkdir_p($dir);
        if ( ! file_exists(trailingslashit($dir).'index.php') ) { @file_put_contents(trailingslashit($dir).'index.php', "<?php\n// Silence is golden.\n"); }
        if ( ! file_exists(trailingslashit($dir).'.htaccess') ) { @file_put_contents(trailingslashit($dir).'.htaccess', "Require all denied\nDeny from all\n"); }
        $filename='Bonusan-bestelling-'.sanitize_file_name($data['location']).'-'.wp_date('Y-m-d-His').'-'.wp_generate_password(8,false,false).'.xlsx'; $dest=trailingslashit($dir).$filename;
        if(!copy($source,$dest)){throw new RuntimeException('Kon geen tijdelijk Excel-bestand maken.');}
        $zip=new ZipArchive(); if(true!==$zip->open($dest)){throw new RuntimeException('Kon het Excel-bestand niet bewerken.');}
        $xml=$zip->getFromName('xl/worksheets/sheet1.xml');
        $dom=new DOMDocument(); $dom->preserveWhiteSpace=true; $dom->formatOutput=false; $dom->loadXML($xml);
        $xp=new DOMXPath($dom); $xp->registerNamespace('x','http://schemas.openxmlformats.org/spreadsheetml/2006/main'); $sheetData=$xp->query('//x:sheetData')->item(0);
        $rows=array(); foreach($xp->query('./x:row[@r >= 10]',$sheetData) as $row){$rows[(int)$row->getAttribute('r')]=$row->cloneNode(true);} 
        foreach(iterator_to_array($xp->query('./x:row[@r >= 10]',$sheetData)) as $row){$sheetData->removeChild($row);} 
        $map=$this->read_template_map($data['location']); $newRow=10; $missing=array();
        foreach($data['items'] as $item){
            if((float)$item['qty']<=0){continue;}
            $map_key=$this->normalize_sku($item['sku']);
            if(isset($map[$map_key])){
                $srcRow=$map[$map_key]['row']; if(!isset($rows[$srcRow])){$missing[]=(string)$item['sku'];continue;} $row=$rows[$srcRow]->cloneNode(true);
            } else {
                // Door Schreuder Assistent herkende Bonusan-SKU die nog niet in het oude
                // sjabloon staat: gebruik een bestaande productregel als opmaakbasis.
                if ( empty($rows) || ! $this->is_assistent_bonusan_sku($item['sku']) ) { $missing[]=(string)$item['sku']; continue; }
                $row=reset($rows)->cloneNode(true);
            }
            $row->setAttribute('r',(string)$newRow);
            foreach($xp->query('./x:c',$row) as $cell){$old=$cell->getAttribute('r'); $col=preg_replace('/\d+/','',$old); $cell->setAttribute('r',$col.$newRow);}
            if(!isset($map[$map_key])){
                // Een puur numerieke SKU als getal, zoals in de bestaande sjabloonregels.
                if ( preg_match( '/^[1-9]\\d{0,14}$/', (string)$item['sku'] ) ) { $this->set_numeric_cell($dom,$xp,$row,'B'.$newRow,(float)$item['sku']); }
                else { $this->set_inline_string_cell($dom,$xp,$row,'B'.$newRow,(string)$item['sku']); }
                $this->set_inline_string_cell($dom,$xp,$row,'C'.$newRow,(string)$item['name']);
            }
            $this->set_numeric_cell($dom,$xp,$row,'D'.$newRow,$item['qty']); $sheetData->appendChild($row); $newRow++; }
        $last=max(9,$newRow-1); $dim=$xp->query('//x:dimension')->item(0); if($dim){$dim->setAttribute('ref','A1:H'.$last);}
        // Het sjabloon stond gescrold op de laatste regels; zonder reset lijkt het resultaat leeg.
        foreach($xp->query('//x:sheetView') as $view){ $view->removeAttribute('topLeftCell'); }
        foreach($xp->query('//x:sheetView/x:selection') as $sel){ $sel->setAttribute('activeCell','A1'); $sel->setAttribute('sqref','A1'); }
        if ( $missing ) {
            $zip->close(); @unlink($dest);
            throw new RuntimeException( 'Deze producten kunnen niet in het Excel-bestand worden gezet (staan niet in het sjabloon van deze locatie en zijn niet als Bonusan-product herkend): ' . implode( ', ', array_unique( $missing ) ) . '. Zet het aantal op 0 of voeg ze toe aan het sjabloon.' );
        }

        $zip->addFromString('xl/worksheets/sheet1.xml',$dom->saveXML());
        $this->add_distribution_sheet( $zip, $data );
        $zip->close(); return $dest;
    }

    private function add_distribution_sheet( $zip, $data ) {
        $workbook = new DOMDocument();
        $workbook->preserveWhiteSpace = true;
        $workbook->loadXML( $zip->getFromName('xl/workbook.xml') );
        $xp = new DOMXPath($workbook);
        $xp->registerNamespace('x','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $sheets = $xp->query('//x:sheets')->item(0);

        $max_sheet_id = 0;
        $sheet_names = array();
        foreach ( $xp->query('./x:sheet', $sheets) as $existing_sheet ) {
            $max_sheet_id = max( $max_sheet_id, (int)$existing_sheet->getAttribute('sheetId') );
            $sheet_names[] = $existing_sheet->getAttribute('name');
        }

        $rels = new DOMDocument();
        $rels->preserveWhiteSpace = true;
        $rels->loadXML( $zip->getFromName('xl/_rels/workbook.xml.rels') );
        $rels_xp = new DOMXPath($rels);
        $rels_xp->registerNamespace('r','http://schemas.openxmlformats.org/package/2006/relationships');
        $used_rel_ids = array();
        $used_sheet_numbers = array();
        foreach ( $rels_xp->query('//r:Relationship') as $existing_rel ) {
            $used_rel_ids[] = $existing_rel->getAttribute('Id');
            if ( preg_match('#worksheets/sheet(\d+)\.xml$#', $existing_rel->getAttribute('Target'), $m) ) {
                $used_sheet_numbers[] = (int)$m[1];
            }
        }
        $sheet_number = empty($used_sheet_numbers) ? 1 : max($used_sheet_numbers) + 1;
        while ( $zip->locateName('xl/worksheets/sheet' . $sheet_number . '.xml') !== false ) { $sheet_number++; }
        $rel_id = 'rIdDistribution';
        $suffix = 2;
        while ( in_array($rel_id, $used_rel_ids, true) ) { $rel_id = 'rIdDistribution' . $suffix; $suffix++; }
        $sheet_name = in_array('Verdeling', $sheet_names, true) ? 'Verdeling ' . ($max_sheet_id + 1) : 'Verdeling';

        $sheet_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="A1:F' . max( 1, count( (array)$data['items'] ) + 1 ) . '"/><sheetData>';
        $headers = array( 'SKU', 'Product', 'Echte kassaverkoop (afgerond)', 'Webshop via kassa (in behandeling)', 'Totaal verkocht', 'Te bestellen' );
        $sheet_xml .= '<row r="1">';
        foreach ( $headers as $index => $header ) {
            $sheet_xml .= $this->inline_string_cell_xml( $this->column_letter($index + 1) . '1', $header );
        }
        $sheet_xml .= '</row>';
        $row_num = 2;
        foreach ( $data['items'] as $item ) {
            $sheet_xml .= '<row r="' . $row_num . '">';
            $sheet_xml .= $this->inline_string_cell_xml( 'A' . $row_num, (string)$item['sku'] );
            $sheet_xml .= $this->inline_string_cell_xml( 'B' . $row_num, (string)$item['name'] );
            $sheet_xml .= $this->numeric_cell_xml( 'C' . $row_num, (float)($item['pos_qty'] ?? 0) );
            $sheet_xml .= $this->numeric_cell_xml( 'D' . $row_num, (float)($item['webshop_qty'] ?? 0) );
            $sheet_xml .= $this->numeric_cell_xml( 'E' . $row_num, (float)($item['original_qty'] ?? $item['qty']) );
            $sheet_xml .= $this->numeric_cell_xml( 'F' . $row_num, (float)$item['qty'] );
            $sheet_xml .= '</row>';
            $row_num++;
        }
        $sheet_xml .= '</sheetData></worksheet>';
        $sheet_path = 'xl/worksheets/sheet' . $sheet_number . '.xml';
        $zip->addFromString( $sheet_path, $sheet_xml );

        $sheet = $workbook->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main','sheet');
        $sheet->setAttribute('name',$sheet_name);
        $sheet->setAttribute('sheetId',(string)($max_sheet_id + 1));
        $sheet->setAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships','r:id',$rel_id);
        $sheets->appendChild($sheet);
        $zip->addFromString('xl/workbook.xml',$workbook->saveXML());

        $root = $rels->documentElement;
        $rel = $rels->createElementNS('http://schemas.openxmlformats.org/package/2006/relationships','Relationship');
        $rel->setAttribute('Id',$rel_id);
        $rel->setAttribute('Type','http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet');
        $rel->setAttribute('Target','worksheets/sheet' . $sheet_number . '.xml');
        $root->appendChild($rel);
        $zip->addFromString('xl/_rels/workbook.xml.rels',$rels->saveXML());

        $types = new DOMDocument();
        $types->preserveWhiteSpace = true;
        $types->loadXML( $zip->getFromName('[Content_Types].xml') );
        $override = $types->createElementNS('http://schemas.openxmlformats.org/package/2006/content-types','Override');
        $override->setAttribute('PartName','/xl/worksheets/sheet' . $sheet_number . '.xml');
        $override->setAttribute('ContentType','application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml');
        $types->documentElement->appendChild($override);
        $zip->addFromString('[Content_Types].xml',$types->saveXML());
    }

    private function inline_string_cell_xml( $ref, $value ) {
        return '<c r="' . esc_attr($ref) . '" t="inlineStr"><is><t>' . htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</t></is></c>';
    }

    private function numeric_cell_xml( $ref, $value ) {
        $number = rtrim(rtrim(number_format((float)$value,4,'.',''),'0'),'.');
        if ( '' === $number ) { $number = '0'; }
        return '<c r="' . esc_attr($ref) . '"><v>' . $number . '</v></c>';
    }

    private function column_letter( $number ) {
        $result = '';
        while ( $number > 0 ) { $number--; $result = chr(65 + ($number % 26)) . $result; $number = intdiv($number,26); }
        return $result;
    }

    private function set_inline_string_cell($dom,$xp,$row,$ref,$value){
        $nodes=$xp->query('./x:c[@r="'.$ref.'"]',$row);
        if($nodes->length){$c=$nodes->item(0);}else{$c=$dom->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main','c');$c->setAttribute('r',$ref);$row->appendChild($c);}
        $c->setAttribute('t','inlineStr');
        foreach(iterator_to_array($xp->query('./x:v|./x:is',$c)) as $old){$c->removeChild($old);}
        $is=$dom->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main','is');
        $t=$dom->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main','t');
        $t->appendChild($dom->createTextNode((string)$value)); $is->appendChild($t); $c->appendChild($is);
    }

    private function set_numeric_cell($dom,$xp,$row,$ref,$value){
        $nodes=$xp->query('./x:c[@r="'.$ref.'"]',$row); if($nodes->length){$c=$nodes->item(0);}else{$c=$dom->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main','c');$c->setAttribute('r',$ref);$row->appendChild($c);} $c->removeAttribute('t');
        foreach(iterator_to_array($xp->query('./x:v|./x:is',$c)) as $old){$c->removeChild($old);} $v=$dom->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main','v',rtrim(rtrim(number_format((float)$value,4,'.',''),'0'),'.')); $c->appendChild($v);
    }


    /* ============================================================
     * Locatievoorraad v1.7.0
     * ============================================================ */

    private function order_edit_url( $order_id ) {
        if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && method_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil', 'get_order_admin_edit_url' ) ) {
            return \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url( (int)$order_id );
        }
        $order = function_exists( 'wc_get_order' ) ? wc_get_order( (int)$order_id ) : false;
        if ( $order && is_callable( array( $order, 'get_edit_order_url' ) ) ) { return $order->get_edit_order_url(); }
        return admin_url( 'post.php?post=' . (int)$order_id . '&action=edit' );
    }

    private function stock_locations() {
        return array( 'baarn' => 'Baarn', 'haarlem' => 'Haarlem', 'zwolle' => 'Zwolle' );
    }

    private function stock_meta_key( $type, $location ) {
        return '_sbp_loc_' . sanitize_key( $type ) . '_' . sanitize_key( $location );
    }

    private $stock_cache = array();
    private $transit_cache = array();
    private $velocity_cache = array();
    private $last_reconcile_sig = array();

    private function stock_number( $product_id, $type, $location ) {
        if ( 'stock' === $type ) {
            $pid = (int)$product_id;
            if ( ! isset( $this->stock_cache[$pid] ) ) { $this->preload_stock( array( $pid ) ); }
            return (float)( $this->stock_cache[$pid][$location] ?? 0.0 );
        }
        $value = get_post_meta( (int)$product_id, $this->stock_meta_key( $type, $location ), true );
        return is_numeric( $value ) ? (float)$value : 0.0;
    }

    private function preload_stock( array $ids ) {
        $need = array();
        foreach ( $ids as $id ) { $id = (int)$id; if ( $id && ! isset( $this->stock_cache[$id] ) ) { $need[] = $id; } }
        if ( ! $need ) { return; }
        foreach ( SBP_Ledger::quantities( $need, array_keys( $this->stock_locations() ) ) as $pid => $locs ) { $this->stock_cache[(int)$pid] = $locs; }
    }

    private function forget_stock_cache() {
        $this->stock_cache = array();
        delete_transient( 'sbp_stock_alert_count' );
        delete_transient( 'sbp_stock_pending_count' );
    }

    /** Alleen minimum en gewenste voorraad zijn nog meta; het saldo staat in het grootboek (SBP_Ledger). */
    private function set_stock_number( $product_id, $type, $location, $value ) {
        if ( ! in_array( $type, array( 'min', 'target' ), true ) ) { return; }
        update_post_meta( (int)$product_id, $this->stock_meta_key( $type, $location ), max( 0.0, (float)$value ) );
    }

    /** Het ID waarop dit product wordt gevolgd (variatie als die zelf wordt gevolgd, anders het hoofdproduct), of 0. */
    private function tracked_id_for_product( $product ) {
        $id = (int)$product->get_id();
        if ( 'yes' === get_post_meta( $id, '_sbp_location_stock_enabled', true ) ) { return $id; }
        if ( $product->is_type( 'variation' ) && $product->get_parent_id() ) {
            $parent = (int)$product->get_parent_id();
            if ( 'yes' === get_post_meta( $parent, '_sbp_location_stock_enabled', true ) ) { return $parent; }
        }
        return 0;
    }

    /** Legt de verzonden bestelling vast als "onderweg" (alleen gevolgde producten met aantal > 0). Geeft het zending-ID of ''. */
    private function create_inbound_for_send( $data, $subject ) {
        $lines = array();
        foreach ( (array)( $data['items'] ?? array() ) as $item ) {
            $qty = (float)( $item['qty'] ?? 0 );
            if ( $qty <= 0 ) { continue; }
            $found = wc_get_product_id_by_sku( (string)( $item['sku'] ?? '' ) );
            $product = $found ? wc_get_product( $found ) : false;
            $tid = $product ? $this->tracked_id_for_product( $product ) : 0;
            if ( ! $tid ) { continue; }
            $info = $this->stock_info( $tid );
            $lines[] = array( 'product_id' => $tid, 'sku' => $info['sku'], 'name' => $info['name'], 'qty' => $qty );
        }
        if ( ! $lines ) { return ''; }
        $shipment = 'z' . bin2hex( random_bytes( 6 ) );
        SBP_Ledger::create_shipment( $shipment, (string)$data['location'], $lines, (string)$subject, get_current_user_id(), ! empty( $data['include_sent'] ) ? 'Herverzending van reeds verzonden orders' : '' );
        $this->forget_stock_cache();
        return $shipment;
    }

    /**
     * Verwerkt het akkoord op een zending. Per regel: all = alles ontvangen; partial_wait = deels, rest volgt;
     * partial_cancel = deels, rest niet leverbaar; cancel = niet leverbaar; wait = niets doen.
     * @return array( aantal verwerkt, array met meldingen )
     */
    private function apply_receipt( array $changes ) {
        $applied = 0; $problems = array();
        foreach ( $changes as $c ) {
            if ( ! is_array( $c ) ) { continue; }
            $id = absint( $c['id'] ?? 0 );
            $mode = sanitize_key( (string)( $c['mode'] ?? '' ) );
            $expected = str_replace( ',', '.', sanitize_text_field( (string)( $c['expected'] ?? '' ) ) );
            if ( ! $id || ! is_numeric( $expected ) ) { continue; }
            $open = (float)$expected;
            $qty_raw = str_replace( ',', '.', sanitize_text_field( (string)( $c['qty'] ?? '' ) ) );
            $qty = is_numeric( $qty_raw ) ? max( 0.0, (float)$qty_raw ) : 0.0;
            switch ( $mode ) {
                case 'all':            $recv = $open; $cancel = 0.0; break;
                case 'partial_wait':   $recv = min( $qty, $open ); $cancel = 0.0; break;
                case 'partial_cancel': $recv = min( $qty, $open ); $cancel = $open - $recv; break;
                case 'cancel':         $recv = 0.0; $cancel = $open; break;
                default: continue 2;
            }
            try {
                $res = SBP_Ledger::receive_line( $id, $recv, $cancel, $open, get_current_user_id() );
                if ( $recv > 0 && ! empty( $res['product_id'] ) ) { $this->start_tracking( (int)$res['product_id'] ); }
                $applied++;
            } catch ( SBP_Ledger_Exception $e ) {
                $problems[] = 'regel #' . $id . ': ' . $e->getMessage();
            }
        }
        return array( $applied, $problems );
    }

    public function ajax_stock_receive() {
        $this->guard();
        $changes = json_decode( (string) wp_unslash( $_POST['changes'] ?? '[]' ), true );
        if ( ! is_array( $changes ) || ! $changes ) { wp_send_json_error( array( 'message' => 'Niets om te verwerken.' ), 400 ); }
        list( $applied, $problems ) = $this->apply_receipt( $changes );
        $this->forget_stock_cache();
        if ( $problems ) {
            wp_send_json_error( array( 'message' => 'Niet alles is verwerkt (' . $applied . ' wel). Er is niets dubbel geboekt:' . "\n- " . implode( "\n- ", $problems ) . "\nDe pagina wordt opnieuw geladen." ), 409 );
        }
        wp_send_json_success( array( 'message' => 'Levering verwerkt (' . $applied . ' regel(s)).' ) );
    }

    private function stock_info( $product_id ) {
        $p = wc_get_product( (int)$product_id );
        return array( 'sku' => $p ? (string)$p->get_sku() : '', 'name' => $p ? (string)$p->get_name() : '' );
    }

    /**
     * Moment (UTC) waarop de voorraadadministratie van dit product is gestart, of 0 als het product nog wacht
     * op de getelde beginvoorraad. Verkopen van vóór dat moment worden nooit afgeboekt; zolang een product
     * wacht, worden er helemaal geen verkopen van afgeboekt.
     */
    private function tracked_since( $product_id ) {
        $v = get_post_meta( (int)$product_id, '_sbp_loc_tracked_since', true );
        return is_numeric( $v ) ? max( 0, (int)$v ) : 0;
    }

    /** Start de administratie van dit product (bij het eerste invoeren van een getelde voorraad of levering). */
    private function start_tracking( $product_id ) {
        if ( ! $this->tracked_since( $product_id ) ) { update_post_meta( (int)$product_id, '_sbp_loc_tracked_since', time() ); }
    }

    private function is_stock_tracked_product_id( $product_id ) {
        $product_id = (int)$product_id;
        if ( $product_id && 'yes' === get_post_meta( $product_id, '_sbp_location_stock_enabled', true ) ) { return true; }
        $product = $product_id ? wc_get_product( $product_id ) : false;
        if ( $product && $product->is_type('variation') && $product->get_parent_id() ) {
            return 'yes' === get_post_meta( (int)$product->get_parent_id(), '_sbp_location_stock_enabled', true );
        }
        return false;
    }

    private function tracked_product_id_for_item( $item ) {
        $variation_id = (int)$item->get_variation_id();
        $product_id   = (int)$item->get_product_id();
        if ( $variation_id && 'yes' === get_post_meta( $variation_id, '_sbp_location_stock_enabled', true ) ) { return $variation_id; }
        if ( $product_id && 'yes' === get_post_meta( $product_id, '_sbp_location_stock_enabled', true ) ) { return $product_id; }
        return 0;
    }

    private function get_tracked_products() {
        $ids = get_posts( array(
            'post_type'      => array( 'product', 'product_variation' ),
            'post_status'    => array( 'publish', 'private', 'draft' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_key'       => '_sbp_location_stock_enabled',
            'meta_value'     => 'yes',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );
        $products = array();
        foreach ( $ids as $id ) {
            $product = wc_get_product( (int)$id );
            if ( $product ) { $products[(int)$id] = $product; }
        }
        uasort( $products, function( $a, $b ) { return strcasecmp( $a->get_name(), $b->get_name() ); } );
        return $products;
    }

    private function get_stock_state( $product_id ) {
        $row = array( 'product_id' => (int)$product_id );
        foreach ( $this->stock_locations() as $loc => $label ) {
            $row[$loc] = $this->stock_number( $product_id, 'stock', $loc );
            $row[$loc . '_min'] = $this->stock_number( $product_id, 'min', $loc );
            $row[$loc . '_target'] = $this->stock_number( $product_id, 'target', $loc );
        }
        return $row;
    }

    private function detect_stock_order_location( $order ) {
        // Dezelfde exacte logica als voor de Bonusan-turflijst, zodat voorraad en bestelling
        // nooit een andere locatie kiezen. '' = extra/onbekende kassa: eerst een keuze nodig.
        return $this->resolve_order_location( $order );
    }

    private function is_stock_sale_order( $order ) {
        if ( ! $order || in_array( $order->get_status(), array('cancelled','refunded','failed','trash'), true ) ) { return false; }
        if ( $this->is_yith_pos_order( $order ) ) {
            return 'completed' === $order->get_status() || $this->is_pos_bank_transfer_sale( $order ) || $this->is_pos_open_invoice_sale( $order );
        }
        // Webshopvoorraad wordt bij verwerking/afronding uit Baarn gehaald.
        return in_array( $order->get_status(), array('processing','completed'), true );
    }

    /**
     * Brengt het grootboek in overeenstemming met deze order. Het is veilig om dit zo vaak
     * aan te roepen als je wilt: alleen het verschil tussen "gewenst" en "al geboekt" wordt
     * verwerkt (en dat gebeurt per product in één database-transactie).
     */
    public function reconcile_order( $order_id, $order = null, $force_zero = false ) {
        if ( ! class_exists( 'WooCommerce' ) ) { return; }
        $order = $order instanceof WC_Order ? $order : wc_get_order( (int)$order_id );
        if ( ! $order instanceof WC_Order ) { return; }
        $order_id = (int)$order->get_id();
        $sale = ! $force_zero && $this->is_stock_sale_order( $order );

        $desired = array();
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $pid = $this->tracked_product_id_for_item( $item );
            if ( ! $pid || $this->is_consult_order_item( $item ) ) { continue; }
            // Gedeeltelijk geretourneerde aantallen tellen niet mee.
            $qty = max( 0.0, (float)$item->get_quantity() + (float)$order->get_qty_refunded_for_item( $item->get_id() ) );
            $desired[$pid] = ( $desired[$pid] ?? 0.0 ) + $qty;
        }
        $marker = $order->get_meta( '_sbp_location_stock_processed' );
        if ( ! $desired && empty( $marker ) ) { return; } // geen gevolgde producten en nooit iets geboekt

        $sig = md5( wp_json_encode( array( $order_id, $sale, $desired, $order->get_meta( '_sbp_location_stock_location' ) ) ) );
        if ( ( $this->last_reconcile_sig[$order_id] ?? '' ) === $sig ) { return; }
        $this->last_reconcile_sig[$order_id] = $sig;

        $legacy = $this->legacy_booking( $order );
        $bookings = SBP_Ledger::order_bookings( $order_id );
        $pids = array_keys( $desired );
        foreach ( array_keys( $bookings ) as $p ) { $pids[] = $p; }
        if ( $legacy ) { foreach ( array_keys( $legacy['lines'] ) as $p ) { $pids[] = $p; } }
        $pids = array_values( array_unique( array_map( 'intval', $pids ) ) );

        if ( $bookings ) { $first = reset( $bookings ); $location = (string)$first['location']; }
        elseif ( $legacy ) { $location = $legacy['location']; }
        else { $location = $this->detect_stock_order_location( $order ); }

        $created = $order->get_date_created();
        $created_ts = $created ? (int)$created->getTimestamp() : 0;
        $failed = false; $pending = false;
        foreach ( $pids as $pid ) {
            if ( ! $this->is_stock_tracked_product_id( $pid ) ) { continue; }
            $want = $sale ? (float)( $desired[$pid] ?? 0.0 ) : 0.0;
            $booked_here = isset( $bookings[$pid] ) || isset( $legacy['lines'][$pid] );
            $loc = $booked_here ? ( $bookings[$pid]['location'] ?? ( $legacy['location'] ?? '' ) ) : $location;
            if ( '' === $loc ) { if ( $want > 0 ) { $pending = true; } continue; } // extra/onbekende kassa: wacht op keuze
            $since = $this->tracked_since( $pid );
            if ( $since <= 0 && ! $booked_here ) { continue; } // wacht op beginvoorraad: nog niets afboeken
            try {
                SBP_Ledger::reconcile_line( $order_id, $pid, $loc, $want, (float)( $legacy['lines'][$pid] ?? 0 ), $since > 0 && $created_ts >= $since, array_merge( $this->stock_info( $pid ), array( 'user_id' => get_current_user_id() ) ) );
            } catch ( Throwable $e ) {
                $failed = true;
                error_log( 'Schreuder Bonusan POS: voorraadboeking order #' . $order_id . ' product ' . $pid . ' mislukt: ' . $e->getMessage() );
            }
        }
        $this->forget_stock_cache();
        $this->sync_order_markers( $order, $pending, $legacy );
        if ( $failed && ! wp_next_scheduled( 'sbp_reconcile_order', array( $order_id ) ) ) {
            wp_schedule_single_event( time() + 60, 'sbp_reconcile_order', array( $order_id ) );
        }
    }

    /** Boeking die v1.7.0 voor deze order had vastgelegd (alleen zolang er nog geen grootboekregels zijn). */
    private function legacy_booking( $order ) {
        $p = $order->get_meta( '_sbp_location_stock_processed' );
        if ( ! is_array( $p ) || empty( $p['lines'] ) || (int)( $p['v'] ?? 1 ) >= 2 ) { return null; }
        if ( $order->get_meta( '_sbp_location_stock_restored' ) ) { return null; }
        $loc = sanitize_key( (string)( $p['location'] ?? '' ) );
        if ( ! isset( $this->stock_locations()[$loc] ) ) { return null; }
        $lines = array();
        foreach ( (array)$p['lines'] as $l ) {
            $pid = (int)( $l['product_id'] ?? 0 ); $q = (float)( $l['qty'] ?? 0 );
            if ( $pid && $q > 0 ) { $lines[$pid] = ( $lines[$pid] ?? 0.0 ) + $q; }
        }
        return $lines ? array( 'location' => $loc, 'lines' => $lines ) : null;
    }

    /** Houdt de leesbare ordermeta (_sbp_location_stock_*) gelijk aan het grootboek. Het grootboek is leidend. */
    private function sync_order_markers( $order, $pending, $legacy ) {
        $bookings = SBP_Ledger::order_bookings( (int)$order->get_id() );
        if ( $legacy ) {
            foreach ( $legacy['lines'] as $pid => $q ) { if ( ! isset( $bookings[$pid] ) ) { $bookings[$pid] = array( 'qty' => $q, 'location' => $legacy['location'] ); } }
        }
        $lines = array(); $location = '';
        foreach ( $bookings as $pid => $b ) {
            if ( $b['qty'] > 0.0005 ) {
                $info = $this->stock_info( $pid );
                $lines[] = array( 'product_id' => (int)$pid, 'qty' => (float)$b['qty'], 'sku' => $info['sku'], 'name' => $info['name'] );
                $location = $b['location'];
            }
        }
        $dirty = false;
        $current = $order->get_meta( '_sbp_location_stock_processed' );
        if ( $lines ) {
            $new = array( 'v' => 2, 'location' => $location, 'processed_at' => ( is_array( $current ) && ! empty( $current['processed_at'] ) ) ? $current['processed_at'] : current_time( 'mysql' ), 'lines' => $lines );
            if ( $current != $new ) { $order->update_meta_data( '_sbp_location_stock_processed', $new ); $dirty = true; }
            if ( $order->get_meta( '_sbp_location_stock_restored' ) ) { $order->delete_meta_data( '_sbp_location_stock_restored' ); $dirty = true; }
        } elseif ( ! empty( $current ) ) {
            $order->delete_meta_data( '_sbp_location_stock_processed' );
            $order->update_meta_data( '_sbp_location_stock_restored', current_time( 'mysql' ) );
            $dirty = true;
        }
        $has_pending = 'yes' === $order->get_meta( '_sbp_location_stock_pending' );
        if ( $pending && ! $has_pending ) { $order->update_meta_data( '_sbp_location_stock_pending', 'yes' ); $dirty = true; }
        if ( ! $pending && $has_pending ) { $order->delete_meta_data( '_sbp_location_stock_pending' ); $dirty = true; }
        if ( $dirty ) { $order->save_meta_data(); }
    }

    public function on_order_status_changed( $order_id, $old_status = '', $new_status = '', $order = null ) { $this->reconcile_order( $order_id, $order ); }
    public function on_order_refunded( $order_id, $refund_id = 0 ) { $this->reconcile_order( $order_id ); }
    public function on_refund_deleted( $refund_id, $order_id ) { $this->reconcile_order( $order_id ); }
    public function on_order_trashed( $order_id, $order = null ) { $this->reconcile_order( $order_id, $order, true ); }
    public function on_post_trashed( $post_id ) { if ( in_array( get_post_type( $post_id ), array( 'shop_order', 'shop_order_placehold' ), true ) ) { $this->reconcile_order( $post_id, null, true ); } }
    public function on_post_untrashed( $post_id ) { if ( in_array( get_post_type( $post_id ), array( 'shop_order', 'shop_order_placehold' ), true ) ) { $this->reconcile_order( $post_id ); } }

    private function pending_stock_orders() {
        if ( ! function_exists('wc_get_orders') ) { return array(); }
        return wc_get_orders( array(
            'limit' => 50,
            'status' => array_keys( wc_get_order_statuses() ),
            'meta_key' => '_sbp_location_stock_pending',
            'meta_value' => 'yes',
            'orderby' => 'date', 'order' => 'DESC',
        ) );
    }

    private function pending_stock_count() {
        $c = get_transient( 'sbp_stock_pending_count' );
        if ( false !== $c ) { return (int)$c; }
        $ids = function_exists( 'wc_get_orders' ) ? wc_get_orders( array(
            'limit' => 50, 'status' => array_keys( wc_get_order_statuses() ),
            'meta_key' => '_sbp_location_stock_pending', 'meta_value' => 'yes', 'return' => 'ids',
        ) ) : array();
        $c = count( (array)$ids );
        set_transient( 'sbp_stock_pending_count', $c, 5 * MINUTE_IN_SECONDS );
        return $c;
    }

    public function pending_stock_location_notice() {
        if ( ! current_user_can('manage_woocommerce') || ! class_exists('WooCommerce') ) { return; }
        $n = $this->pending_stock_count();
        if ( ! $n ) { return; }
        $url = admin_url('admin.php?page=schreuder-bonusan-stock#sbp-pending-orders');
        echo '<div class="notice notice-warning"><p><strong>Schreuder locatievoorraad:</strong> ' . esc_html( $n ) . ' kassaverkoop/verkoop heeft nog geen locatie. <a href="' . esc_url($url) . '">Kies Baarn, Haarlem of Zwolle</a> zodat de voorraad correct wordt afgeboekt.</p></div>';
    }

    public function stock_level_notice() {
        if ( ! current_user_can('manage_woocommerce') || ! class_exists('WooCommerce') ) { return; }
        $count = get_transient( 'sbp_stock_alert_count' );
        if ( false === $count ) {
            $count = 0;
            $products = $this->get_tracked_products();
            $this->preload_stock( array_keys( $products ) );
            foreach ( $products as $id => $product ) {
                if ( ! $this->tracked_since( $id ) ) { continue; }
                $s = $this->get_stock_state($id);
                if ( ($s['baarn_target'] > 0 && $s['baarn'] <= $s['baarn_min']) ||
                     ($s['haarlem_target'] > 0 && $s['haarlem'] <= $s['haarlem_min']) ||
                     ($s['zwolle_target'] > 0 && $s['zwolle'] <= $s['zwolle_min']) ) { $count++; }
            }
            set_transient( 'sbp_stock_alert_count', $count, 5 * MINUTE_IN_SECONDS );
        }
        if ( !$count ) { return; }
        $url = admin_url('admin.php?page=schreuder-bonusan-stock');
        echo '<div class="notice notice-warning"><p><strong>Schreuder voorraad:</strong> bij ' . esc_html($count) . ' product(en) is een ingestelde minimumvoorraad bereikt. <a href="' . esc_url($url) . '">Bekijk het aanvuladvies</a>.</p></div>';
    }

    private function is_any_turflist_product( $product ) {
        if ( ! $product ) { return false; }
        if ( $this->is_assistent_bonusan_product( $product ) ) { return true; }
        $sku_key = $this->normalize_sku( (string)$product->get_sku() );
        if ( '' === $sku_key ) { return false; }
        foreach ( array('baarn','haarlem','zwolle') as $loc ) {
            try { $map = $this->read_template_map($loc); } catch ( Throwable $e ) { $map = array(); }
            if ( isset($map[$sku_key]) ) { return true; }
        }
        return false;
    }

    /** Is dit product in de webshop verborgen (zichtbaarheid "Verborgen")? Bij een variatie telt het hoofdproduct. */
    private function is_hidden_product( $product ) {
        if ( ! $product ) { return false; }
        $p = $product;
        if ( $product->is_type( 'variation' ) && $product->get_parent_id() ) {
            $parent = wc_get_product( $product->get_parent_id() );
            if ( $parent ) { $p = $parent; }
        }
        return 'hidden' === $p->get_catalog_visibility();
    }

    /**
     * Hoe wordt dit product aangevuld?
     * - 'transfer': Baarn is de hoofdvoorraad; Haarlem en Zwolle worden vanuit Baarn aangevuld en
     *   Baarn zelf extern (planner/leverancier). Geldt voor producten die Bonusan niet kan leveren:
     *   verborgen in de webshop en niet op de turflijst.
     * - 'direct': iedere locatie bestelt zelf bij Bonusan via de turflijst; er is geen transfer van Baarn.
     * Standaard automatisch; per product te overschrijven (meta _sbp_loc_mode).
     */
    private function stock_mode( $product ) {
        $v = get_post_meta( (int)$product->get_id(), '_sbp_loc_mode', true );
        if ( in_array( $v, array( 'transfer', 'direct' ), true ) ) { return $v; }
        return ( $this->is_hidden_product( $product ) && ! $this->is_any_turflist_product( $product ) ) ? 'transfer' : 'direct';
    }

    private function stock_advice_for_product( $product_id ) {
        $product = wc_get_product( (int)$product_id );
        if ( ! $product ) { return null; }
        $s = $this->get_stock_state( $product_id );
        $mode = $this->stock_mode( $product );
        // Wat al onderweg is (bij Bonusan besteld, nog niet ontvangen) telt mee, zodat je niet dubbel bestelt.
        $transit = $this->transit_cache[(int)$product_id] ?? array();
        $eff = array();
        foreach ( $this->stock_locations() as $loc => $label ) { $eff[$loc] = $s[$loc] + (float)( $transit[$loc] ?? 0 ); }
        $need = array();
        foreach ( $this->stock_locations() as $loc => $label ) {
            $need[$loc] = ( $s[$loc.'_target'] > 0 && $eff[$loc] <= $s[$loc.'_min'] ) ? max( 0, $s[$loc.'_target'] - $eff[$loc] ) : 0;
        }
        if ( 'transfer' === $mode ) {
            $h_need = $need['haarlem']; $z_need = $need['zwolle'];
            $baarn_after_transfers = $eff['baarn'] - $h_need - $z_need;
            $external_need = ( $s['baarn_target'] > 0 && $baarn_after_transfers <= $s['baarn_min'] ) ? max( 0, $s['baarn_target'] - $baarn_after_transfers ) : 0;
            $own_need = array( 'baarn' => 0, 'haarlem' => 0, 'zwolle' => 0 );
        } else {
            // Iedere locatie bestelt zelf bij Bonusan: geen transfer vanuit Baarn.
            $h_need = 0; $z_need = 0;
            $baarn_after_transfers = $eff['baarn'];
            $external_need = 0;
            $own_need = $need;
        }

        // Verkooptempo komt uit het eigen grootboek en bestaat dus pas vanaf het moment dat dit
        // product wordt gevolgd. Het maandvolume wordt gecorrigeerd voor de periode waarover echt
        // gegevens zijn; onder 14 dagen is het te onbetrouwbaar en blijft de slimme doelvoorraad gelijk aan je eigen instelling.
        $since = (int) get_post_meta( (int)$product_id, '_sbp_loc_tracked_since', true );
        $data_days = $since ? max( 0, (int) floor( ( time() - $since ) / DAY_IN_SECONDS ) ) : 0;
        $low_data = $data_days < 14;
        $window = max( 1, min( 90, $data_days ) );
        $vel_all = $this->velocity_cache[(int)$product_id] ?? array();
        $velocity = array(); $smart = array();
        foreach ( $this->stock_locations() as $loc => $label ) {
            $raw = $vel_all[$loc] ?? array( 'd30' => 0.0, 'd90' => 0.0 );
            $m30 = $raw['d30'] * 30 / max( 1, min( 30, $window ) );
            $m90 = $raw['d90'] * 30 / $window;
            $velocity[$loc] = array( 'd30' => $raw['d30'], 'm90' => $m90 );
            $tempo = $low_data ? 0.0 : ceil( max( $m30, $m90 ) * 1.25 );
            $smart[$loc] = max( (float)$s[$loc.'_target'], $tempo, (float)$s[$loc.'_min'] );
        }
        $turf = $this->is_any_turflist_product( $product );
        return array_merge( $s, array(
            'name' => $product->get_name(), 'sku' => $product->get_sku(),
            'to_haarlem' => $h_need, 'to_zwolle' => $z_need,
            'baarn_after_transfers' => $baarn_after_transfers,
            'external_need' => $external_need,
            'route' => $turf ? 'bonusan' : 'planner',
            'route_label' => $turf ? 'Bonusan-turflijst' : 'Planner/overige leverancier',
            'velocity' => $velocity, 'smart_target' => $smart,
            'data_days' => $data_days, 'low_data' => $low_data,
            'mode' => $mode, 'own_need' => $own_need,
            'in_transit' => array( 'baarn' => (float)( $transit['baarn'] ?? 0 ), 'haarlem' => (float)( $transit['haarlem'] ?? 0 ), 'zwolle' => (float)( $transit['zwolle'] ?? 0 ) ),
            'mode_label' => 'transfer' === $mode ? 'Vanuit Baarn (Bonusan levert niet)' : 'Eigen bestelling per locatie',
        ) );
    }

    private function get_stock_advice_rows( $alerts_only = false ) {
        $products = $this->get_tracked_products();
        $this->preload_stock( array_keys( $products ) );
        $this->velocity_cache = SBP_Ledger::sales_velocity( array_keys( $products ) );
        $this->transit_cache = SBP_Ledger::in_transit( array_keys( $products ) );
        $rows = array();
        foreach ( $products as $id => $product ) {
            if ( ! $this->tracked_since( $id ) ) { continue; } // nog geen beginvoorraad: geen advies
            $row = $this->stock_advice_for_product( $id );
            if ( ! $row ) { continue; }
            if ( $alerts_only && $row['to_haarlem'] <= 0 && $row['to_zwolle'] <= 0 && $row['external_need'] <= 0 && array_sum( $row['own_need'] ) <= 0 ) { continue; }
            $rows[] = $row;
        }
        return $rows;
    }

    private function get_stock_preview_advice( $location ) {
        $rows = array();
        $tmap = $this->read_template_map( $location );
        foreach ( $this->get_stock_advice_rows(true) as $row ) {
            if ( 'direct' === $row['mode'] ) {
                // Eigen bestelling: alleen tonen als deze locatie zelf iets nodig heeft.
                $here = (float)( $row['own_need'][$location] ?? 0 );
                if ( $here <= 0 ) { continue; }
                $adopt_qty = $here;
            } else {
                // Aanvulling vanuit Baarn: Baarn ziet wat naar Haarlem/Zwolle moet en het externe advies;
                // Haarlem en Zwolle zien alleen hun eigen ontvangst (intern, geen bestelling).
                $here = 0.0; $adopt_qty = 0.0;
                if ( 'baarn' === $location ) {
                    if ( $row['to_haarlem'] <= 0 && $row['to_zwolle'] <= 0 && $row['external_need'] <= 0 ) { continue; }
                    $adopt_qty = (float)$row['external_need'];
                } elseif ( (float)$row[ 'to_' . $location ] <= 0 ) { continue; }
            }
            // Waar kan het advies naartoe? Bonusan-turflijst (alleen als de SKU in het sjabloon van deze locatie
            // staat of als Bonusan-product is herkend) of de planner (alle overige producten).
            $target = ''; $note = '';
            if ( $adopt_qty > 0 ) {
                $sku = trim( (string)$row['sku'] );
                if ( 'bonusan' === $row['route'] && '' !== $sku ) {
                    if ( $this->is_turflist_sku( $sku, $tmap ) ) { $target = 'bonusan'; }
                    else { $note = 'Staat niet in het sjabloon van ' . ucfirst( $location ) . ' en is niet als Bonusan-product herkend: kan niet in de Bonusan-bestellijst.'; }
                } else { $target = 'planner'; }
            }
            $rows[] = array(
                'product_id' => $row['product_id'], 'name' => $row['name'], 'sku' => $row['sku'],
                'baarn' => $row['baarn'], 'haarlem' => $row['haarlem'], 'zwolle' => $row['zwolle'],
                'to_haarlem' => $row['to_haarlem'], 'to_zwolle' => $row['to_zwolle'],
                'external_need' => $row['external_need'], 'route' => $row['route'], 'route_label' => $row['route_label'],
                'mode' => $row['mode'], 'own_need_here' => $here,
                'adopt_qty' => $adopt_qty, 'adopt_target' => $target, 'adopt_note' => $note,
            );
        }
        return $rows;
    }

    /** Naam als samenvoeg-sleutel voor plannerregels (zelfde regels als build_planner_items). */
    private function planner_name_key( $name ) {
        $k = strtolower( remove_accents( wp_strip_all_tags( (string)$name ) ) );
        return preg_replace( '/\s+/', ' ', trim( $k ) );
    }

    /** Hoeveel van dit product staat al automatisch (uit kassaverkopen) op de plannerlijst? */
    private function planner_auto_qty( $data, $sku, $name ) {
        $sum = 0.0; $sk = $this->normalize_sku( $sku ); $nk = $this->planner_name_key( $name );
        foreach ( (array)( $data['unknown'] ?? array() ) as $u ) {
            $uk = $this->normalize_sku( (string)( $u['sku'] ?? '' ) );
            if ( '' !== $sk ) { if ( $uk === $sk ) { $sum += (float)( $u['qty'] ?? 0 ); } }
            elseif ( '' === $uk && $this->planner_name_key( $u['name'] ?? '' ) === $nk ) { $sum += (float)( $u['qty'] ?? 0 ); }
        }
        if ( '' === $sk ) {
            foreach ( (array)( $data['unresolved'] ?? array() ) as $u ) { if ( $this->planner_name_key( $u['name'] ?? '' ) === $nk ) { $sum += (float)( $u['planner_qty'] ?? 0 ); } }
        }
        return $sum;
    }

    /** Zet het aantal van een Bonusan-product in de bestellijst (vervangt het huidige aantal; "Herstel" zet het terug). */
    private function adopt_into_items( &$data, $product, $sku, $qty, $tmap ) {
        $key = $this->normalize_sku( $sku );
        if ( isset( $data['planner_manual'][$key] ) ) { unset( $data['planner_manual'][$key] ); }
        if ( isset( $data['items'][$key] ) ) {
            $data['items'][$key]['qty'] = $qty;
            $data['items'][$key]['manual_override'] = true;
        } else {
            $data['items'][$key] = array(
                'sku' => isset( $tmap[$key] ) ? (string)$tmap[$key]['sku'] : $sku, 'sku_key' => $key, 'name' => $product->get_name(),
                'qty' => $qty, 'pos_qty' => 0, 'webshop_qty' => 0, 'original_qty' => 0, 'manual' => true, 'manual_override' => true,
            );
        }
        uasort( $data['items'], function ( $a, $b ) use ( $tmap ) {
            return ( $tmap[$this->normalize_sku( $a['sku'] )]['row'] ?? 999999 ) <=> ( $tmap[$this->normalize_sku( $b['sku'] )]['row'] ?? 999999 );
        } );
    }

    /** Zet het advies op de plannerlijst, zonder te dubbelen met wat al automatisch uit de kassaverkopen op de planner staat. */
    private function adopt_into_planner( &$data, $product, $sku, $qty ) {
        $sku_for = '' !== $sku ? $sku : 'product-' . $product->get_id();
        $key = $this->normalize_sku( $sku_for );
        if ( '' === $key ) { $key = 'PRODUCT-' . $product->get_id(); }
        $manual = max( 0.0, $qty - $this->planner_auto_qty( $data, $sku, $product->get_name() ) );
        if ( ! isset( $data['planner_manual'] ) || ! is_array( $data['planner_manual'] ) ) { $data['planner_manual'] = array(); }
        if ( $manual <= 0 ) { unset( $data['planner_manual'][$key] ); return false; }
        $data['planner_manual'][$key] = array( 'sku' => $sku_for, 'name' => $product->get_name(), 'qty' => $manual, 'manual_planner' => true );
        return true;
    }

    public function ajax_adopt_advice() {
        $this->guard();
        $token = sanitize_key( $_POST['token'] ?? '' );
        $data = get_transient( 'sbp_' . $token );
        if ( ! $data || (int)( $data['user_id'] ?? 0 ) !== get_current_user_id() ) {
            wp_send_json_error( array( 'message' => 'De controle is verlopen. Laad de bestellijst opnieuw.' ), 400 );
        }
        $items = json_decode( (string) wp_unslash( $_POST['items'] ?? '[]' ), true );
        if ( ! is_array( $items ) || ! $items ) { wp_send_json_error( array( 'message' => 'Selecteer eerst regels uit het advies.' ), 400 ); }
        $location = (string)$data['location'];
        $tmap = $this->read_template_map( $location );
        $done = array( 'bonusan' => 0, 'planner' => 0, 'planner_covered' => 0 ); $skipped = array();
        foreach ( array_slice( $items, 0, 500 ) as $it ) {
            if ( ! is_array( $it ) ) { continue; }
            $pid = absint( $it['product_id'] ?? 0 );
            $product = $pid ? wc_get_product( $pid ) : false;
            $raw = str_replace( ',', '.', sanitize_text_field( (string)( $it['qty'] ?? '' ) ) );
            $label = $product ? $product->get_name() : ( '#' . $pid );
            if ( ! $product || ! $this->is_stock_tracked_product_id( $pid ) ) { $skipped[] = $label . ': wordt niet gevolgd bij Locatievoorraad.'; continue; }
            if ( ! is_numeric( $raw ) || (float)$raw <= 0 || (float)$raw > 100000 ) { $skipped[] = $label . ': ongeldig aantal.'; continue; }
            $qty = (float)$raw;
            $sku = trim( (string)$product->get_sku() );
            if ( $this->is_any_turflist_product( $product ) && '' !== $sku ) {
                if ( ! $this->is_turflist_sku( $sku, $tmap ) ) { $skipped[] = $label . ': staat niet in het sjabloon van ' . ucfirst( $location ) . ' (kan niet in de Bonusan-bestellijst).'; continue; }
                $this->adopt_into_items( $data, $product, $sku, $qty, $tmap );
                $done['bonusan']++;
            } else {
                if ( $this->adopt_into_planner( $data, $product, $sku, $qty ) ) { $done['planner']++; } else { $done['planner_covered']++; }
            }
        }
        set_transient( 'sbp_' . $token, $data, DAY_IN_SECONDS );
        $this->save_draft( $data );
        $msg = array();
        if ( $done['bonusan'] ) { $msg[] = $done['bonusan'] . ' product(en) in de Bonusan-bestellijst ' . ucfirst( $location ); }
        if ( $done['planner'] ) { $msg[] = $done['planner'] . ' product(en) in de planner'; }
        if ( $done['planner_covered'] ) { $msg[] = $done['planner_covered'] . ' product(en) stonden al voldoende op de planner (uit de kassaverkopen)'; }
        wp_send_json_success( array( 'message' => ( $msg ? 'Overgenomen: ' . implode( ', ', $msg ) . '.' : 'Er is niets overgenomen.' ), 'done' => $done, 'skipped' => $skipped ) );
    }

    /** Eén rij van de voorraadtabel (ook gebruikt om een zojuist toegevoegd product zonder herladen te tonen). */
    private function stock_row_html( $id, $product ) {
        $st = $this->get_stock_state( $id );
        ob_start();
        ?>
                    <tr data-product-id="<?php echo esc_attr($id); ?>"><td><strong><?php echo esc_html($product->get_name()); ?></strong><br><small>Aanvulling: <select class="sbp-stock-mode" data-id="<?php echo esc_attr($id); ?>"><?php $mv = get_post_meta($id,'_sbp_loc_mode',true); foreach(array(''=>'Automatisch ('.('transfer'===$this->stock_mode($product)?'vanuit Baarn':'eigen bestelling').')','transfer'=>'Vanuit Baarn naar Haarlem/Zwolle','direct'=>'Eigen bestelling per locatie') as $k=>$lbl){ echo '<option value="'.esc_attr($k).'"'.selected($mv,$k,false).'>'.esc_html($lbl).'</option>'; } ?></select></small><?php if ( ! $this->tracked_since( $id ) ) : ?><br><span class="sbp-wait">⏳ Wacht op beginvoorraad: verkopen worden pas afgeboekt nadat je de getelde voorraad hebt ingevoerd.</span><?php endif; ?><br><small>SKU <?php echo esc_html($product->get_sku() ?: '—'); ?></small></td>
                    <?php foreach(array('baarn','haarlem','zwolle') as $loc): ?>
                        <td><input class="small-text sbp-stock-number<?php echo $st[$loc] < 0 ? ' sbp-neg' : ''; ?>" type="number" step="any" data-id="<?php echo esc_attr($id); ?>" data-loc="<?php echo esc_attr($loc); ?>" data-type="stock" title="<?php echo esc_attr(ucfirst($loc)); ?> – Voorraad nu" aria-label="<?php echo esc_attr(ucfirst($loc)); ?> – Voorraad nu" data-orig="<?php echo esc_attr($st[$loc]); ?>" value="<?php echo esc_attr($st[$loc]); ?>"></td>
                        <td><input class="small-text sbp-stock-number" type="number" min="0" step="1" data-id="<?php echo esc_attr($id); ?>" data-loc="<?php echo esc_attr($loc); ?>" data-type="min" title="<?php echo esc_attr(ucfirst($loc)); ?> – Minimumvoorraad" aria-label="<?php echo esc_attr(ucfirst($loc)); ?> – Minimumvoorraad" data-orig="<?php echo esc_attr($st[$loc.'_min']); ?>" value="<?php echo esc_attr($st[$loc.'_min']); ?>"></td>
                        <td><input class="small-text sbp-stock-number" type="number" min="0" step="1" data-id="<?php echo esc_attr($id); ?>" data-loc="<?php echo esc_attr($loc); ?>" data-type="target" title="<?php echo esc_attr(ucfirst($loc)); ?> – Gewenste voorraad" aria-label="<?php echo esc_attr(ucfirst($loc)); ?> – Gewenste voorraad" data-orig="<?php echo esc_attr($st[$loc.'_target']); ?>" value="<?php echo esc_attr($st[$loc.'_target']); ?>"></td>
                    <?php endforeach; ?>
                    <td><button type="button" class="button-link-delete sbp-stock-remove">Niet meer volgen</button></td></tr>
        <?php
        return ob_get_clean();
    }

    public function render_stock_page() {
        if ( ! current_user_can('manage_woocommerce') ) { return; }
        $products = $this->get_tracked_products();
        $advice = $this->get_stock_advice_rows(false);
        $pending = $this->pending_stock_orders();
        $log_filter = absint( $_GET['log_product'] ?? 0 );
        $log = SBP_Ledger::log_rows( $log_filter, 200 );
        $nonce = wp_create_nonce(self::NONCE);
        ?>
        <div class="wrap sbp-stock-wrap">
            <h1>Locatievoorraad</h1>
            <p><strong>Baarn is de hoofdvoorraad</strong> voor de producten die je hier volgt. Haarlem en Zwolle hebben een kleinere werkvoorraad. <strong>Alleen producten die Bonusan niet kan leveren (verborgen in de webshop) worden vanuit Baarn naar Haarlem en Zwolle aangevuld.</strong> Alle andere gevolgde producten bestellen Haarlem en Zwolle zelf bij Bonusan via de turflijst; daarvoor geeft het advies alleen aan hoeveel die locatie zelf moet bestellen. Je kunt de wijze per product aanpassen. Gewone webshopverkopen worden standaard van Baarn afgeboekt. Vaste POS-locaties worden automatisch herkend wanneer het YITH locatiekenmerk is ingesteld; een onbekende/extra kassa komt hieronder te staan totdat je de locatie kiest.</p>
            <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=schreuder-bonusan-pos')); ?>">← Bonusan bestelling</a></p>

            <?php if ( $pending ) : ?>
            <div class="sbp-stock-card" id="sbp-pending-orders">
                <h2>⚠ Locatie kiezen voor kassaverkoop</h2>
                <p>Deze verkopen zijn nog <strong>niet</strong> van de locatievoorraad afgeboekt.</p>
                <table class="widefat striped"><thead><tr><th>Order</th><th>Datum</th><th>Producten</th><th>Vanuit welke locatie?</th></tr></thead><tbody>
                <?php foreach ( $pending as $order ) :
                    $names=array(); foreach($order->get_items('line_item') as $item){ if($this->tracked_product_id_for_item($item)){ $names[]=(float)$item->get_quantity().'× '.$item->get_name(); } }
                ?>
                    <tr data-order-id="<?php echo esc_attr($order->get_id()); ?>"><td>#<?php echo esc_html($order->get_id()); ?></td><td><?php echo esc_html($order->get_date_created() ? $order->get_date_created()->date_i18n('d-m-Y H:i') : ''); ?></td><td><?php echo esc_html(implode(', ',$names)); ?></td><td><button class="button sbp-assign-location" data-location="baarn">Baarn</button> <button class="button sbp-assign-location" data-location="haarlem">Haarlem</button> <button class="button sbp-assign-location" data-location="zwolle">Zwolle</button></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            </div>
            <?php endif; ?>

            <div class="sbp-stock-card" id="sbp-import">
                <h2>Producten importeren uit Excel (turflijst)</h2>
                <p class="description">Upload een Bonusan-turflijst (.xlsx, zoals Bestelling-Bonusan-turflijst Baarn/Haarlem/Zwolle). De producten worden op SKU (kolom Artikel/EAN/GTIN) gekoppeld aan je webshop en aan Locatievoorraad toegevoegd. Je ziet eerst een controle en kiest zelf wat wordt overgenomen. De voorraad zelf voer je daarna in (tellen); tot die tijd worden er geen verkopen van deze producten afgeboekt.</p>
                <p><input type="file" id="sbp-imp-file" accept=".xlsx"> <button type="button" class="button" id="sbp-imp-check">Bestand controleren</button></p>
                <div id="sbp-imp-result"></div>
            </div>

            <div class="sbp-stock-card">
                <h2>Product toevoegen en voorraad invullen</h2>
                <p class="description">Zoek een product, vul de voorraad, het minimum en de gewenste voorraad per locatie in en druk op <strong>Enter</strong>. Het product wordt toegevoegd en alles wordt in één keer opgeslagen. Met de pijltjestoetsen kies je in de zoekresultaten, met Tab ga je naar het volgende veld. Staat het product al in de lijst, dan spring je daar naartoe.</p>
                <div class="sbp-stock-search-wrap"><input type="search" id="sbp-stock-search" class="regular-text" placeholder="Zoek product op naam of SKU…" autocomplete="off"><div id="sbp-stock-search-results" class="sbp-stock-search-results" style="display:none"></div></div>
                <div id="sbp-quick" style="display:none">
                    <h3 id="sbp-quick-title" style="margin:14px 0 6px"></h3>
                    <table class="sbp-quick-table"><thead><tr><th></th><th>Voorraad nu</th><th>Minimum</th><th>Gewenst</th></tr></thead><tbody>
                    <?php foreach ( $this->stock_locations() as $qloc => $qlabel ) : ?>
                        <tr><th scope="row"><?php echo esc_html( 'baarn' === $qloc ? 'Baarn – hoofdvoorraad' : $qlabel ); ?></th>
                        <?php foreach ( array( 'stock' => 'Voorraad nu', 'min' => 'Minimumvoorraad', 'target' => 'Gewenste voorraad' ) as $qt => $qtl ) : ?>
                            <td><input type="number" min="0" step="any" class="small-text sbp-q" data-loc="<?php echo esc_attr( $qloc ); ?>" data-type="<?php echo esc_attr( $qt ); ?>" title="<?php echo esc_attr( $qlabel . ' – ' . $qtl ); ?>" aria-label="<?php echo esc_attr( $qlabel . ' – ' . $qtl ); ?>"></td>
                        <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table>
                    <p><button type="button" class="button button-primary" id="sbp-quick-save">Toevoegen en opslaan</button> <button type="button" class="button" id="sbp-quick-cancel">Annuleren</button></p>
                    <p class="description">Laat <em>Voorraad nu</em> leeg als je nog niet hebt geteld: het product wacht dan op beginvoorraad. Een leeg minimum of gewenst wordt 0.</p>
                </div>
                <p id="sbp-quick-msg" class="sbp-quick-msg" role="status" aria-live="polite"></p>
            </div>

            <div class="sbp-stock-card">
                <h2>Voorraad en grenswaarden</h2>
                <?php if ( empty($products) ) : ?>
                    <p>Nog geen producten geselecteerd voor locatievoorraad.</p>
                <?php else : ?>
                <p class="sbp-find">
                    <input type="search" id="sbp-find" class="regular-text" placeholder="Zoek in deze lijst op productnaam of SKU…" autocomplete="off" aria-label="Zoek een product in de voorraadlijst">
                    <button type="button" class="button" id="sbp-find-prev" title="Vorige treffer (Shift+Enter)">↑</button>
                    <button type="button" class="button" id="sbp-find-next" title="Volgende treffer (Enter)">↓</button>
                    <span id="sbp-find-count" class="description" aria-live="polite"></span>
                    <label style="margin-left:12px"><input type="checkbox" id="sbp-find-only"> Alleen gevonden producten tonen</label>
                </p>
                <form id="sbp-stock-form">
                    <div class="sbp-stock-scroll sbp-stock-main"><table class="widefat striped sbp-stock-table"><thead><tr><th rowspan="2">Product</th><th colspan="3">Baarn – hoofdvoorraad</th><th colspan="3">Haarlem</th><th colspan="3">Zwolle</th><th rowspan="2"></th></tr><tr><th>Nu</th><th>Min.</th><th>Gewenst</th><th>Nu</th><th>Min.</th><th>Gewenst</th><th>Nu</th><th>Min.</th><th>Gewenst</th></tr></thead><tbody>
                    <?php foreach ( $products as $id => $product ) : ?>
                    <?php echo $this->stock_row_html( $id, $product ); ?>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <p><button type="submit" class="button button-primary">Alles opslaan</button> <span id="sbp-stock-save-status"></span></p>
                    <p class="description">Wijzigingen worden automatisch opgeslagen zodra je een veld verlaat (Tab of Enter); een groen kader bevestigt het. De knop Alles opslaan is alleen nodig als iets niet automatisch is opgeslagen. Alleen velden die je hebt gewijzigd worden opgeslagen. Een gewijzigd voorraadgetal wordt als telling/correctie gelogd en alleen verwerkt als de voorraad sinds het laden van deze pagina niet is veranderd (bijvoorbeeld door een verkoop). Anders krijg je een melding en wordt er niets overschreven.</p>
                </form>
                <?php endif; ?>
            </div>

            <?php if ( $products ) : ?>
            <div class="sbp-stock-grid">
                <div class="sbp-stock-card">
                    <h2>Interne voorraad overboeken</h2>
                    <p class="description">Bijvoorbeeld Baarn → Haarlem. De ene voorraad gaat omlaag en de andere tegelijk omhoog.</p>
                    <p><select id="sbp-transfer-product"><?php foreach($products as $id=>$product){echo '<option value="'.esc_attr($id).'">'.esc_html($product->get_name()).'</option>';} ?></select></p>
                    <p><select id="sbp-transfer-from"><option value="baarn">Baarn</option><option value="haarlem">Haarlem</option><option value="zwolle">Zwolle</option></select> → <select id="sbp-transfer-to"><option value="haarlem">Haarlem</option><option value="zwolle">Zwolle</option><option value="baarn">Baarn</option></select></p>
                    <p>Aantal <input type="number" id="sbp-transfer-qty" min="1" step="1" value="1" class="small-text"> <button class="button" id="sbp-transfer-do">Overboeken</button></p>
                    <div id="sbp-transfer-status"></div>
                </div>
                <div class="sbp-stock-card">
                    <h2>Handmatige correctie / levering</h2>
                    <p><select id="sbp-adjust-product"><?php foreach($products as $id=>$product){echo '<option value="'.esc_attr($id).'">'.esc_html($product->get_name()).'</option>';} ?></select></p>
                    <p><select id="sbp-adjust-location"><option value="baarn">Baarn</option><option value="haarlem">Haarlem</option><option value="zwolle">Zwolle</option></select> Aantal <input type="number" id="sbp-adjust-delta" step="1" value="1" class="small-text"></p>
                    <p><input type="text" id="sbp-adjust-note" class="regular-text" placeholder="Reden, bv. levering / telling / beschadigd"></p>
                    <p><button class="button" id="sbp-adjust-do">Voorraad corrigeren</button></p><div id="sbp-adjust-status"></div>
                </div>
            </div>
            <?php endif; ?>

            <?php $open_lines = SBP_Ledger::open_lines(); if ( $open_lines ) : $by_ship = array(); foreach ( $open_lines as $ol ) { $by_ship[$ol['shipment']][] = $ol; } ?>
            <div class="sbp-stock-card" id="sbp-inbound">
                <h2>Onderweg – bestellingen bij Bonusan</h2>
                <p class="description">Hier staan de gevolgde producten uit verzonden Bonusan-bestellingen. De voorraad stijgt pas nadat je de levering accordeert. Klopt alles, klik dan op Akkoord. Is er iets afwijkend (deels, nalevering of niet leverbaar), geef dat bij die regel aan; de overige regels worden als ontvangen zoals besteld verwerkt. Wat nog onderweg is telt mee in het aanvuladvies.</p>
                <?php foreach ( $by_ship as $ship => $lines ) : $first = $lines[0]; ?>
                <div class="sbp-shipment" data-shipment="<?php echo esc_attr($ship); ?>" style="margin:14px 0">
                    <h3>Naar <?php echo esc_html(ucfirst($first['location'])); ?> · verzonden <?php echo esc_html(get_date_from_gmt($first['created_gmt'],'d-m-Y H:i')); ?> <small><?php echo esc_html($first['subject']); ?></small><?php echo $first['note'] ? ' <em>('.esc_html($first['note']).')</em>' : ''; ?></h3>
                    <div class="sbp-stock-scroll"><table class="widefat striped"><thead><tr><th>Product</th><th>Besteld</th><th>Al ontvangen</th><th>Niet leverbaar</th><th>Nog onderweg</th><th>Hoe is het geleverd?</th><th>Aantal ontvangen</th></tr></thead><tbody>
                    <?php foreach ( $lines as $l ) : $open = round((float)$l['qty_ordered']-(float)$l['qty_received']-(float)$l['qty_cancelled'],3); ?>
                        <tr data-line="<?php echo esc_attr($l['id']); ?>" data-open="<?php echo esc_attr($open); ?>">
                            <td><strong><?php echo esc_html($l['product_name']); ?></strong><br><small><?php echo esc_html($l['sku'] ?: '—'); ?></small></td>
                            <td><?php echo esc_html((float)$l['qty_ordered']); ?></td><td><?php echo esc_html((float)$l['qty_received']); ?></td><td><?php echo esc_html((float)$l['qty_cancelled']); ?></td><td><strong><?php echo esc_html($open); ?></strong></td>
                            <td><select class="sbp-recv-mode"><option value="all">Ontvangen zoals besteld</option><option value="partial_wait">Deels ontvangen, rest volgt (nalevering)</option><option value="partial_cancel">Deels ontvangen, rest niet leverbaar</option><option value="wait">Nog niets ontvangen (blijft onderweg)</option><option value="cancel">Niet leverbaar</option></select></td>
                            <td><input type="number" class="small-text sbp-recv-qty" min="0" step="any" value="<?php echo esc_attr($open); ?>" style="display:none"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <p><button type="button" class="button button-primary sbp-receive-shipment">Akkoord: verwerk deze zending</button></p>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="sbp-stock-card">
                <h2>Aanvuladvies</h2>
                <p class="description">Haarlem/Zwolle worden eerst tot hun gewenste voorraad vanuit Baarn aangevuld zodra ze op/onder minimum komen. Daarna controleert de plugin of Baarn zelf onder het minimum komt. De slimme doelvoorraad gebruikt het netto verkooptempo (verkopen min retouren) uit het voorraadlogboek en bestaat dus pas vanaf het moment dat een product wordt gevolgd. Bij minder dan 14 dagen gegevens is het te onbetrouwbaar en wordt het niet gebruikt. Het verandert niets automatisch.</p>
                <?php if(empty($advice)): ?><p>Nog geen producten.</p><?php else: ?>
                <div class="sbp-stock-scroll"><table class="widefat striped"><thead><tr><th>Product</th><th>Baarn</th><th>Haarlem</th><th>Zwolle</th><th>Onderweg B/H/Z</th><th>Aanvulling</th><th>Naar Haarlem</th><th>Naar Zwolle</th><th>Extern aanvullen</th><th>Zelf bestellen B/H/Z</th><th>Route</th><th>Verkoop 30d B/H/Z</th><th>Slim gewenst B/H/Z</th></tr></thead><tbody>
                <?php foreach($advice as $a): ?>
                    <tr class="<?php echo ($a['external_need']>0?'sbp-stock-danger':(($a['to_haarlem']>0||$a['to_zwolle']>0||array_sum($a['own_need'])>0)?'sbp-stock-warn':'')); ?>"><td><strong><?php echo esc_html($a['name']); ?></strong><br><small><?php echo esc_html($a['sku'] ?: '—'); ?></small></td><td><?php echo esc_html($a['baarn']); ?></td><td><?php echo esc_html($a['haarlem']); ?></td><td><?php echo esc_html($a['zwolle']); ?></td><td><?php echo esc_html($a['in_transit']['baarn'].'/'.$a['in_transit']['haarlem'].'/'.$a['in_transit']['zwolle']); ?></td><td><?php echo esc_html($a['mode_label']); ?></td><td><?php echo 'transfer'===$a['mode'] ? esc_html($a['to_haarlem']) : '—'; ?></td><td><?php echo 'transfer'===$a['mode'] ? esc_html($a['to_zwolle']) : '—'; ?></td><td><strong><?php echo 'transfer'===$a['mode'] ? esc_html($a['external_need']) : '—'; ?></strong></td><td><?php echo 'direct'===$a['mode'] ? esc_html($a['own_need']['baarn'].'/'.$a['own_need']['haarlem'].'/'.$a['own_need']['zwolle']) : '—'; ?></td><td><?php echo esc_html($a['route_label']); ?></td><td><?php echo esc_html(round($a['velocity']['baarn']['d30'],1).'/'.round($a['velocity']['haarlem']['d30'],1).'/'.round($a['velocity']['zwolle']['d30'],1)); ?></td><td><?php echo esc_html($a['smart_target']['baarn'].'/'.$a['smart_target']['haarlem'].'/'.$a['smart_target']['zwolle']); ?><?php echo $a['low_data'] ? ' <small>(weinig data: '.(int)$a['data_days'].' d)</small>' : ''; ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div><?php endif; ?>
            </div>

            <div class="sbp-stock-card"><h2>Voorraadlogboek</h2>
                <form method="get" style="margin-bottom:10px"><input type="hidden" name="page" value="schreuder-bonusan-stock">
                    <select name="log_product"><option value="0">Alle producten</option><?php foreach($products as $pid=>$pr){ echo '<option value="'.esc_attr($pid).'"'.selected($log_filter,$pid,false).'>'.esc_html($pr->get_name()).'</option>'; } ?></select>
                    <button class="button">Filter</button>
                    <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url('admin-post.php?action=sbp_stock_log_csv&log_product='.(int)$log_filter), 'sbp_stock_log_csv' ) ); ?>">Exporteer volledige historie (CSV)</a>
                </form>
                <?php if(empty($log)): ?><p>Nog geen voorraadmutaties.</p><?php else: ?>
                <table class="widefat striped"><thead><tr><th>Datum</th><th>Product</th><th>Locatie</th><th>Mutatie</th><th>Voor → na</th><th>Reden</th><th>Order</th><th>Notitie</th><th>Gebruiker</th></tr></thead><tbody>
                <?php foreach($log as $e): $u = (int)$e['user_id'] ? get_userdata((int)$e['user_id']) : false; $d=(float)$e['delta']; ?>
                    <tr><td><?php echo esc_html( get_date_from_gmt( $e['created_gmt'], 'd-m-Y H:i' ) ); ?></td>
                    <td><?php echo esc_html($e['product_name']); ?><br><small>#<?php echo esc_html($e['product_id']); ?> · <?php echo esc_html($e['sku']); ?></small></td>
                    <td><?php echo esc_html(ucfirst($e['location'])); ?></td>
                    <td><?php echo esc_html(($d>0?'+':'').$d); ?></td>
                    <td><?php echo esc_html((float)$e['before_qty'].' → '.(float)$e['after_qty']); ?></td>
                    <td><?php echo esc_html($this->reason_label($e['reason'])); ?></td>
                    <td><?php echo !empty($e['order_id']) ? '<a href="'.esc_url($this->order_edit_url((int)$e['order_id'])).'">#'.esc_html($e['order_id']).'</a>' : '—'; ?></td>
                    <td><?php echo esc_html($e['note']); ?></td>
                    <td><?php echo esc_html($u ? $u->display_name : ( $e['user_id'] ? '#'.$e['user_id'] : 'systeem' )); ?></td></tr>
                <?php endforeach; ?></tbody></table><p class="description">De laatste 200 regels. Gebruik de CSV-export voor de volledige historie.</p><?php endif; ?>
            </div>
        </div>
        <style>
        .sbp-stock-card{background:#fff;border:1px solid #c3c4c7;padding:16px 18px;margin:16px 0;max-width:1450px}.sbp-stock-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:16px;max-width:1450px}.sbp-stock-grid .sbp-stock-card{margin:0}.sbp-stock-scroll{overflow-x:auto}.sbp-stock-table th{text-align:center}.sbp-stock-table td:first-child{min-width:220px}.sbp-stock-number{width:70px}.sbp-stock-search-wrap{position:relative;max-width:650px}.sbp-stock-search-results{position:absolute;z-index:50;background:#fff;border:1px solid #8c8f94;left:0;right:0;max-height:280px;overflow:auto}.sbp-stock-search-item{width:100%;display:block;text-align:left;border:0;border-bottom:1px solid #eee;background:#fff;padding:9px;cursor:pointer}.sbp-stock-search-item:hover{background:#f0f6fc}.sbp-stock-danger td{background:#fff1f0}.sbp-stock-warn td{background:#fff8e5}.sbp-neg{color:#b32d2e;font-weight:600}.sbp-wait{color:#996800;font-size:12px}.sbp-quick-table{border-collapse:collapse}.sbp-quick-table th,.sbp-quick-table td{padding:4px 8px;text-align:left}.sbp-quick-table thead th{font-weight:600;font-size:12px}.sbp-quick-msg{min-height:1.4em;color:#00700a;font-weight:600}.sbp-stock-search-item.sbp-active{background:#dbeafe}.sbp-find{margin:0 0 10px}.sbp-find #sbp-find{width:360px;max-width:100%}.sbp-stock-table tbody tr.sbp-hit td{background:#fff8c5 !important}.sbp-stock-table tbody tr.sbp-hit-current td{background:#ffe27a !important}.sbp-stock-table tbody tr.sbp-hit-current td:first-child{box-shadow:inset 4px 0 #dba617}
        .sbp-stock-main{max-height:calc(100vh - 150px);min-height:240px;overflow:auto;position:relative}
        .sbp-stock-table{border-collapse:separate;border-spacing:0}
        .sbp-stock-table thead th{position:sticky;top:0;z-index:4;background:#f0f0f1;box-shadow:inset 0 -1px 0 #c3c4c7;vertical-align:middle}
        .sbp-stock-table thead tr:first-child th:nth-child(2){background:#e5f0fb}.sbp-stock-table thead tr:first-child th:nth-child(3){background:#e6f4e8}.sbp-stock-table thead tr:first-child th:nth-child(4){background:#fdf0dc}
        .sbp-stock-table thead tr:nth-child(2) th:nth-child(-n+3){background:#eef5fc}.sbp-stock-table thead tr:nth-child(2) th:nth-child(n+4):nth-child(-n+6){background:#eff8f1}.sbp-stock-table thead tr:nth-child(2) th:nth-child(n+7){background:#fef6e8}
        .sbp-stock-table thead tr:first-child th:nth-child(n+2):nth-child(-n+4){border-left:2px solid #8c8f94}.sbp-stock-table thead tr:nth-child(2) th:nth-child(3n+1){border-left:2px solid #8c8f94}
        .sbp-stock-table tbody td:nth-child(2),.sbp-stock-table tbody td:nth-child(5),.sbp-stock-table tbody td:nth-child(8){border-left:2px solid #8c8f94}
        .sbp-stock-table thead tr:first-child th:first-child{left:0;z-index:6}
        .sbp-stock-table tbody td:first-child{position:sticky;left:0;z-index:2;box-shadow:inset -1px 0 #c3c4c7}
        .sbp-stock-table tbody tr:nth-child(odd) td:first-child{background:#f6f7f7}.sbp-stock-table tbody tr:nth-child(even) td:first-child{background:#fff}
        </style>
        <script>
        jQuery(function($){
            const nonce=<?php echo wp_json_encode($nonce); ?>; let timer=null;
            function post(action,data){data=data||{};data.action=action;data._ajax_nonce=nonce;return $.post(ajaxurl,data).fail(function(x){alert(x.responseJSON&&x.responseJSON.data&&x.responseJSON.data.message?x.responseJSON.data.message:'De actie is mislukt.');});}
            function esc(s){return $('<div>').text(s==null?'':s).html();}
            let quickProduct=null;
            function jumpToRow(id){
                let tr=$('.sbp-stock-table tbody tr[data-product-id="'+id+'"]'); if(!tr.length)return;
                $('#sbp-find').val(''); $('.sbp-stock-table tbody tr').removeClass('sbp-hit sbp-hit-current').show();
                tr.addClass('sbp-hit'); findHits=[tr]; findIdx=0; goHit();
                tr.find('input[data-type="stock"]').first().trigger('focus');
            }
            function startQuick(p){
                quickProduct=p; $('#sbp-quick-msg').text('');
                $('#sbp-quick .sbp-q').val('');
                $('#sbp-quick-title').text(p.name+(p.sku?' (SKU '+p.sku+')':'')+(p.hidden?' · verborgen in webshop':''));
                $('#sbp-quick').show(); $('#sbp-quick .sbp-q').first().trigger('focus');
            }
            function endQuick(){quickProduct=null;$('#sbp-quick').hide();$('#sbp-stock-search').val('').trigger('focus');}
            function pickResult(b){
                $('#sbp-stock-search-results').hide().empty();
                if(b.data('tracked')){$('#sbp-stock-search').val('');$('#sbp-quick-msg').text('Dit product staat al in de lijst: hier is het.');$('#sbp-quick').hide();jumpToRow(b.data('id'));return;}
                startQuick({id:b.data('id'),name:String(b.data('name')),sku:String(b.data('sku')||''),hidden:!!b.data('hidden')});
            }
            function saveQuick(){
                if(!quickProduct)return;
                let values={haarlem:{},baarn:{},zwolle:{}};
                $('#sbp-quick .sbp-q').each(function(){let i=$(this);values[i.data('loc')][i.data('type')]=i.val();});
                let btn=$('#sbp-quick-save').prop('disabled',true),prod=quickProduct;
                post('sbp_stock_quick_add',{product_id:prod.id,values:JSON.stringify(values)}).done(function(r){
                    if(!r.success){alert(r.data&&r.data.message?r.data.message:'Toevoegen mislukt.');return;}
                    let tb=$('.sbp-stock-table tbody');
                    if(!tb.length){location.reload();return;}
                    let row=$(r.data.row_html); tb.prepend(row);
                    $('#sbp-transfer-product,#sbp-adjust-product').append($('<option>').val(prod.id).text(prod.name));
                    $('#sbp-quick-msg').text('✓ '+r.data.message);
                    endQuick(); jumpToRow(prod.id); $('#sbp-stock-search').trigger('focus');
                }).fail(function(){setTimeout(function(){location.reload();},300);}).always(function(){btn.prop('disabled',false);});
            }
            $('#sbp-quick-save').on('click',saveQuick);
            $('#sbp-quick-cancel').on('click',endQuick);
            $('#sbp-quick').on('keydown','.sbp-q',function(e){if(e.key==='Enter'){e.preventDefault();saveQuick();}else if(e.key==='Escape'){endQuick();}});
            $('#sbp-stock-search').on('input',function(){let term=$(this).val().trim(),box=$('#sbp-stock-search-results');clearTimeout(timer);if(term.length<2){box.hide().empty();return;}timer=setTimeout(function(){post('sbp_stock_product_search',{term:term}).done(function(r){if(!r.success||!r.data.results.length){box.html('<div style="padding:9px">Geen product gevonden.</div>').show();return;}let h='';r.data.results.forEach(function(x){h+='<button type="button" class="sbp-stock-search-item" data-id="'+esc(x.id)+'" data-name="'+esc(x.name).replace(/"/g,'&quot;')+'" data-sku="'+esc(x.sku||'').replace(/"/g,'&quot;')+'" data-tracked="'+(x.tracked?'1':'')+'" data-hidden="'+(x.hidden?'1':'')+'"><strong>'+esc(x.name)+'</strong><br><small>SKU '+esc(x.sku||'—')+(x.hidden?' · verborgen in webshop':' · zichtbaar in webshop')+(x.tracked?' · staat al in de lijst':'')+'</small></button>';});box.html(h).show();box.find('.sbp-stock-search-item').first().addClass('sbp-active');});},250);});
            $('#sbp-stock-search').on('keydown',function(e){
                let items=$('#sbp-stock-search-results .sbp-stock-search-item'); if(!items.length)return;
                let cur=items.index(items.filter('.sbp-active')); if(cur<0)cur=0;
                if(e.key==='ArrowDown'||e.key==='ArrowUp'){e.preventDefault();items.removeClass('sbp-active');cur=(cur+(e.key==='ArrowDown'?1:-1)+items.length)%items.length;let it=items.eq(cur).addClass('sbp-active');it[0].scrollIntoView({block:'nearest'});}
                else if(e.key==='Enter'){e.preventDefault();pickResult(items.eq(cur));}
                else if(e.key==='Escape'){$('#sbp-stock-search-results').hide();}
            });
            $(document).on('click','.sbp-stock-search-item',function(){pickResult($(this));});
            function saveCell(i){
                let orig=String(i.data('orig')),val=i.val();
                if(val===''||parseFloat(val)===parseFloat(orig)||i.data('saving'))return;
                i.data('saving',true).css('outline','2px solid #dba617');
                let c={id:i.data('id'),loc:i.data('loc'),type:i.data('type'),value:val};if(c.type==='stock')c.expected=orig;
                post('sbp_stock_save',{changes:JSON.stringify([c])}).done(function(r){
                    if(r.success){i.data('orig',val).css('outline','2px solid #00a32a');setTimeout(function(){i.css('outline','');},900);if(c.type==='stock'){i.closest('tr').find('.sbp-wait').remove();}}
                }).fail(function(){setTimeout(function(){location.reload();},300);}).always(function(){i.data('saving',false);});
            }
            $(document).on('change','#sbp-stock-form .sbp-stock-number',function(){saveCell($(this));});
            $('#sbp-stock-form').on('submit',function(e){e.preventDefault();let changes=[];$('.sbp-stock-number').each(function(){let i=$(this),orig=String(i.data('orig')),val=i.val();if(val===''||parseFloat(val)===parseFloat(orig)||i.data('saving'))return;let c={id:i.data('id'),loc:i.data('loc'),type:i.data('type'),value:val};if(c.type==='stock')c.expected=orig;changes.push(c);});if(!changes.length){$('#sbp-stock-save-status').text('Alles is al opgeslagen.');return;}let btn=$(this).find('button[type=submit]').prop('disabled',true);post('sbp_stock_save',{changes:JSON.stringify(changes)}).done(function(r){if(r.success){$('#sbp-stock-save-status').text('Opgeslagen');setTimeout(function(){location.reload();},500);}else{alert(r.data.message||'Opslaan mislukt.');}}).fail(function(){setTimeout(function(){location.reload();},300);}).always(function(){btn.prop('disabled',false);});});
            $(document).on('change','.sbp-stock-mode',function(){post('sbp_stock_mode',{product_id:$(this).data('id'),mode:$(this).val()}).done(function(){location.reload();});});
            function stickyOffsets(){let h=$('.sbp-stock-table thead tr:first-child th').eq(1).outerHeight();if(h){$('.sbp-stock-table thead tr:nth-child(2) th').css('top',h+'px');}}
            stickyOffsets();$(window).on('resize',stickyOffsets);
            // Zoeken in de voorraadlijst: springt naar het product, markeert het en laat doorbladeren.
            let findHits=[],findIdx=-1,findTimer=null;
            function normTxt(t){return String(t||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'');}
            function goHit(){
                $('.sbp-stock-table tbody tr.sbp-hit-current').removeClass('sbp-hit-current');
                if(findIdx<0||!findHits[findIdx])return;
                let tr=findHits[findIdx].addClass('sbp-hit-current'),box=$('.sbp-stock-main')[0],head=$('.sbp-stock-table thead').outerHeight()||0;
                box.scrollTop+=tr[0].getBoundingClientRect().top-box.getBoundingClientRect().top-head-6;
                box.scrollIntoView({block:'nearest'});
                $('#sbp-find-count').text((findIdx+1)+' van '+findHits.length+' gevonden');
            }
            function runFind(){
                let q=normTxt($('#sbp-find').val()).trim(),only=$('#sbp-find-only').is(':checked'),rows=$('.sbp-stock-table tbody tr');
                rows.removeClass('sbp-hit sbp-hit-current').show(); findHits=[]; findIdx=-1;
                if(!q){$('#sbp-find-count').text('');return;}
                rows.each(function(){let tr=$(this);if(normTxt(tr.find('td:first-child').text()).indexOf(q)!==-1){tr.addClass('sbp-hit');findHits.push(tr);}else if(only){tr.hide();}});
                if(!findHits.length){$('#sbp-find-count').text('Niets gevonden');return;}
                findIdx=0; goHit();
            }
            function stepHit(d){if(!findHits.length){return;}findIdx=(findIdx+d+findHits.length)%findHits.length;goHit();}
            $('#sbp-find').on('input',function(){clearTimeout(findTimer);findTimer=setTimeout(runFind,150);});
            $('#sbp-find').on('keydown',function(e){if(e.key==='Enter'){e.preventDefault();stepHit(e.shiftKey?-1:1);}});
            $('#sbp-find-only').on('change',runFind);
            $('#sbp-find-next').on('click',function(){stepHit(1);});
            $('#sbp-find-prev').on('click',function(){stepHit(-1);});
            let impToken=null;
            $('#sbp-imp-check').on('click',function(){
                let f=$('#sbp-imp-file')[0].files[0]; if(!f){alert('Kies eerst een Excel-bestand (.xlsx).');return;}
                let fd=new FormData(); fd.append('action','sbp_stock_import_preview'); fd.append('_ajax_nonce',nonce); fd.append('file',f);
                let b=$(this).prop('disabled',true); $('#sbp-imp-result').html('<p>Bestand controleren…</p>');
                $.ajax({url:ajaxurl,type:'POST',data:fd,processData:false,contentType:false}).done(function(r){
                    if(!r.success){$('#sbp-imp-result').html('<div class="notice notice-error inline"><p>'+esc(r.data&&r.data.message?r.data.message:'Controle mislukt.')+'</p></div>');return;}
                    let d=r.data,sm=d.summary; impToken=d.token;
                    let lab={new:'Nieuw',tracked:'Al gevolgd',missing:'Niet in webshop gevonden',consult:'Consult (overgeslagen)'};
                    let h='<p><strong>'+esc(sm.rows)+' regels gelezen:</strong> '+esc(sm['new'])+' nieuw, '+esc(sm.tracked)+' al gevolgd, '+esc(sm.missing)+' niet in de webshop gevonden'+(sm.consult?', '+esc(sm.consult)+' consult (overgeslagen)':'')+(sm.dup?', '+esc(sm.dup)+' dubbel in het bestand':'')+'.</p>';
                    if(d.warnings&&d.warnings.length){h+='<div class="notice notice-warning inline"><p>'+d.warnings.map(esc).join('<br>')+'</p></div>';}
                    if(sm.qty_rows>0){h+='<p><label><input type="checkbox" id="sbp-imp-useqty"> De kolom <em>Aantal</em> overnemen als getelde beginvoorraad voor</label> <select id="sbp-imp-loc"><option value="baarn">Baarn</option><option value="haarlem">Haarlem</option><option value="zwolle">Zwolle</option></select><br><small>Let op: in een turflijst is Aantal normaal het <strong>bestelaantal</strong>, geen voorraad. Er staan '+esc(sm.qty_rows)+' regel(s) met een aantal in dit bestand. Alleen producten die nog wachten op beginvoorraad worden gevuld; bestaande voorraad wordt nooit overschreven.</small></p>';}
                    h+='<div class="sbp-stock-scroll" style="max-height:420px;overflow:auto"><table class="widefat striped"><thead><tr><th><input type="checkbox" id="sbp-imp-all" checked></th><th>SKU</th><th>Naam in bestand</th><th>Naam in webshop</th><th>Status</th><th>Aanvulling</th><th>Aantal</th></tr></thead><tbody>';
                    d.rows.forEach(function(x){let ok=(x.status==='new'||x.status==='tracked');h+='<tr><td>'+(ok?'<input type="checkbox" class="sbp-imp-row" data-key="'+esc(x.key)+'"'+(x.status==='new'?' checked':'')+'>':'')+'</td><td>'+esc(x.sku)+'</td><td>'+esc(x.name)+(x.dup?' <small>(komt vaker voor in het bestand)</small>':'')+'</td><td>'+esc(x.shop_name||'—')+(x.hidden?' <small>(verborgen)</small>':'')+'</td><td>'+esc(lab[x.status]||x.status)+'</td><td>'+esc(x.mode_label||'')+'</td><td>'+esc(x.qty===null?'':x.qty)+'</td></tr>';});
                    h+='</tbody></table></div><p><button type="button" class="button button-primary" id="sbp-imp-apply">Geselecteerde producten importeren</button> <span id="sbp-imp-status"></span></p>';
                    $('#sbp-imp-result').html(h);
                }).fail(function(x){alert(x.responseJSON&&x.responseJSON.data&&x.responseJSON.data.message?x.responseJSON.data.message:'Upload mislukt.');$('#sbp-imp-result').html('');}).always(function(){b.prop('disabled',false);});
            });
            $(document).on('change','#sbp-imp-all',function(){$('.sbp-imp-row').prop('checked',$(this).is(':checked'));});
            $(document).on('click','#sbp-imp-apply',function(){
                let keys=[];$('.sbp-imp-row:checked').each(function(){keys.push(String($(this).data('key')));});
                if(!keys.length){alert('Selecteer eerst producten.');return;}
                if(!confirm(keys.length+' product(en) toevoegen aan Locatievoorraad?'))return;
                let b=$(this).prop('disabled',true);
                post('sbp_stock_import_apply',{token:impToken,keys:JSON.stringify(keys),use_qty:$('#sbp-imp-useqty').is(':checked')?1:0,location:$('#sbp-imp-loc').val()||''}).done(function(r){if(r.success){$('#sbp-imp-status').text(r.data.message);setTimeout(function(){location.reload();},1800);}else{alert(r.data.message);}}).always(function(){b.prop('disabled',false);});
            });
            $(document).on('change','.sbp-recv-mode',function(){let tr=$(this).closest('tr'),m=$(this).val();tr.find('.sbp-recv-qty').toggle(m==='partial_wait'||m==='partial_cancel');});
            $(document).on('click','.sbp-receive-shipment',function(){let box=$(this).closest('.sbp-shipment'),changes=[];box.find('tbody tr').each(function(){let tr=$(this),mode=tr.find('.sbp-recv-mode').val();if(mode==='wait')return;changes.push({id:tr.data('line'),mode:mode,qty:tr.find('.sbp-recv-qty').val(),expected:String(tr.data('open'))});});if(!changes.length){alert('Er is niets om te verwerken.');return;}if(!confirm('Deze zending verwerken? De ontvangen aantallen worden bij de voorraad opgeteld.'))return;let b=$(this).prop('disabled',true);post('sbp_stock_receive',{changes:JSON.stringify(changes)}).done(function(r){if(r.success){location.reload();}else{alert(r.data.message);location.reload();}}).fail(function(){setTimeout(function(){location.reload();},300);}).always(function(){b.prop('disabled',false);});});
            $(document).on('click','.sbp-stock-remove',function(){if(!confirm('Dit product niet meer volgen in locatievoorraad? De voorraadhistorie blijft bewaard.'))return;let id=$(this).closest('tr').data('product-id');post('sbp_stock_remove_product',{product_id:id}).done(function(r){if(r.success)location.reload();});});
            $('.sbp-assign-location').on('click',function(){let b=$(this),row=b.closest('tr');post('sbp_assign_order_location',{order_id:row.data('order-id'),location:b.data('location')}).done(function(r){if(r.success){row.fadeOut(200,function(){location.reload();});}else alert(r.data.message||'Locatie kon niet worden opgeslagen.');});});
            $('#sbp-transfer-do').on('click',function(){let data={product_id:$('#sbp-transfer-product').val(),from:$('#sbp-transfer-from').val(),to:$('#sbp-transfer-to').val(),qty:$('#sbp-transfer-qty').val()};post('sbp_stock_transfer',data).done(function(r){if(r.success){$('#sbp-transfer-status').text(r.data.message);setTimeout(function(){location.reload();},500);}else alert(r.data.message||'Overboeken mislukt.');});});
            $('#sbp-adjust-do').on('click',function(){let data={product_id:$('#sbp-adjust-product').val(),location:$('#sbp-adjust-location').val(),delta:$('#sbp-adjust-delta').val(),note:$('#sbp-adjust-note').val()};post('sbp_stock_adjust',data).done(function(r){if(r.success){$('#sbp-adjust-status').text(r.data.message);setTimeout(function(){location.reload();},500);}else alert(r.data.message||'Correctie mislukt.');});});
        });
        </script>
        <?php
    }

    public function ajax_stock_product_search() {
        $this->guard();
        $term = trim( sanitize_text_field( wp_unslash($_POST['term'] ?? '') ) );
        if ( strlen($term) < 2 ) { wp_send_json_success(array('results'=>array())); }
        global $wpdb; $like='%'.$wpdb->esc_like($term).'%';
        $ids=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON(pm.post_id=p.ID AND pm.meta_key='_sku') WHERE p.post_type IN('product','product_variation') AND p.post_status NOT IN('trash','auto-draft') AND(p.post_title LIKE %s OR pm.meta_value LIKE %s) ORDER BY p.post_title ASC LIMIT 30",$like,$like));
        $results=array(); foreach($ids as $id){$product=wc_get_product((int)$id);if(!$product)continue;$results[]=array('id'=>(int)$id,'name'=>$product->get_name(),'sku'=>$product->get_sku(),'tracked'=>$this->is_stock_tracked_product_id($id),'hidden'=>$this->is_hidden_product($product));}
        wp_send_json_success(array('results'=>$results));
    }

    public function ajax_stock_add_product() {
        $this->guard();
        $id = absint( $_POST['product_id'] ?? 0 );
        $product = wc_get_product( $id );
        if ( ! $product ) { wp_send_json_error( array( 'message' => 'Product niet gevonden.' ), 404 ); }
        if ( 'yes' !== get_post_meta( $id, '_sbp_location_stock_enabled', true ) ) {
            // Het product wacht op de getelde beginvoorraad; pas daarna worden verkopen afgeboekt.
            update_post_meta( $id, '_sbp_location_stock_enabled', 'yes' );
            delete_post_meta( $id, '_sbp_loc_tracked_since' );
        }
        foreach ( $this->stock_locations() as $loc => $label ) {
            foreach ( array( 'min', 'target' ) as $type ) {
                $key = $this->stock_meta_key( $type, $loc );
                if ( ! metadata_exists( 'post', $id, $key ) ) { update_post_meta( $id, $key, 0 ); }
            }
        }
        $this->forget_stock_cache();
        wp_send_json_success( array( 'message' => 'Product toegevoegd aan locatievoorraad. Voer nu de getelde beginvoorraad in; verkopen worden pas afgeboekt nadat je dat hebt gedaan.' ) );
    }

    public function ajax_stock_save() {
        $this->guard();
        $changes = json_decode( (string) wp_unslash( $_POST['changes'] ?? '[]' ), true );
        if ( ! is_array( $changes ) ) { wp_send_json_error( array( 'message' => 'Ongeldige gegevens.' ), 400 ); }
        $locations = $this->stock_locations();
        $applied = 0; $conflicts = array();
        foreach ( $changes as $c ) {
            if ( ! is_array( $c ) ) { continue; }
            $id = absint( $c['id'] ?? 0 );
            $loc = sanitize_key( (string)( $c['loc'] ?? '' ) );
            $type = sanitize_key( (string)( $c['type'] ?? '' ) );
            if ( ! $id || ! isset( $locations[$loc] ) || ! in_array( $type, array( 'stock', 'min', 'target' ), true ) || ! $this->is_stock_tracked_product_id( $id ) ) { continue; }
            $raw = str_replace( ',', '.', sanitize_text_field( (string)( $c['value'] ?? '' ) ) );
            if ( ! is_numeric( $raw ) ) { continue; }
            $value = (float)$raw;
            if ( 'stock' !== $type ) { $this->set_stock_number( $id, $type, $loc, $value ); $applied++; continue; }
            $expected = str_replace( ',', '.', sanitize_text_field( (string)( $c['expected'] ?? '' ) ) );
            $info = $this->stock_info( $id );
            if ( ! is_numeric( $expected ) ) { $conflicts[] = $info['name'] . ' (' . ucfirst( $loc ) . '): geen verwachte waarde meegestuurd.'; continue; }
            try {
                SBP_Ledger::set_absolute( $id, $loc, $value, (float)$expected, 'Telling/correctie in locatieoverzicht', get_current_user_id(), $info );
                $this->start_tracking( $id );
                $applied++;
            } catch ( SBP_Ledger_Exception $e ) {
                $conflicts[] = $info['name'] . ' (' . ucfirst( $loc ) . '): ' . $e->getMessage();
            }
        }
        $this->forget_stock_cache();
        if ( $conflicts ) {
            wp_send_json_error( array( 'message' => 'Niet alles is opgeslagen (' . $applied . ' wel). Er is niets overschreven van wat sinds het laden van de pagina is gewijzigd:' . "\n- " . implode( "\n- ", $conflicts ) . "\nDe pagina wordt opnieuw geladen." ), 409 );
        }
        wp_send_json_success( array( 'message' => 'Opgeslagen (' . $applied . ' wijziging(en)).' ) );
    }

    public function ajax_stock_remove_product() {
        $this->guard(); $id=absint($_POST['product_id']??0); if(!$id){wp_send_json_error(array('message'=>'Ongeldig product.'),400);} delete_post_meta($id,'_sbp_location_stock_enabled'); wp_send_json_success(array('message'=>'Product wordt niet meer gevolgd.'));
    }

    private function is_consult_product( $product ) {
        $name = strtolower( remove_accents( wp_strip_all_tags( (string)$product->get_name() ) ) );
        if ( false !== strpos( $name, 'consult' ) ) { return true; }
        $pid = ( $product->is_type( 'variation' ) && $product->get_parent_id() ) ? $product->get_parent_id() : $product->get_id();
        $terms = wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'all' ) );
        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $t ) { if ( false !== strpos( strtolower( remove_accents( $t->name . ' ' . $t->slug ) ), 'consult' ) ) { return true; } }
        }
        return false;
    }

    /**
     * Leest een Excel-turflijst (eerste werkblad). Zoekt zelf de kolommen Artikel/EAN/GTIN, Naam, Aantal en
     * Product Lijn op de koprij. Er wordt niets uitgepakt of uitgevoerd; alleen de benodigde onderdelen worden gelezen.
     * @return array( 'rows' => array(...), 'warnings' => array(...) )
     */
    private function parse_stock_import_file( $path ) {
        if ( ! class_exists( 'ZipArchive' ) ) { throw new RuntimeException( 'De PHP-extensie ZipArchive ontbreekt.' ); }
        if ( ! is_readable( $path ) || filesize( $path ) > 5 * 1024 * 1024 ) { throw new RuntimeException( 'Het bestand is onleesbaar of groter dan 5 MB.' ); }
        $zip = new ZipArchive();
        if ( true !== $zip->open( $path ) ) { throw new RuntimeException( 'Dit is geen geldig .xlsx-bestand.' ); }
        libxml_use_internal_errors( true );
        try {
            $read = function ( $name ) use ( $zip ) {
                $st = $zip->statName( $name );
                if ( ! $st || $st['size'] > 20 * 1024 * 1024 ) { return false; }
                return $zip->getFromName( $name );
            };
            $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
            $rel_ns = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
            $wb_xml = $read( 'xl/workbook.xml' );
            if ( false === $wb_xml ) { throw new RuntimeException( 'Dit lijkt geen Excel-werkmap te zijn (workbook ontbreekt).' ); }
            $wb = new DOMDocument(); $wb->loadXML( $wb_xml, LIBXML_NONET );
            $wxp = new DOMXPath( $wb ); $wxp->registerNamespace( 'x', $ns );
            $first = $wxp->query( '//x:sheets/x:sheet' )->item( 0 );
            if ( ! $first ) { throw new RuntimeException( 'De werkmap bevat geen werkbladen.' ); }
            $rid = $first->getAttributeNS( $rel_ns, 'id' );
            $target = 'worksheets/sheet1.xml';
            $rels_xml = $read( 'xl/_rels/workbook.xml.rels' );
            if ( false !== $rels_xml ) {
                $rd = new DOMDocument(); $rd->loadXML( $rels_xml, LIBXML_NONET );
                foreach ( $rd->getElementsByTagName( 'Relationship' ) as $rel ) { if ( $rel->getAttribute( 'Id' ) === $rid ) { $target = $rel->getAttribute( 'Target' ); break; } }
            }
            $sheet_path = ( 0 === strpos( $target, '/' ) ) ? ltrim( $target, '/' ) : 'xl/' . $target;
            $sheet_xml = $read( $sheet_path );
            if ( false === $sheet_xml ) { throw new RuntimeException( 'Het eerste werkblad kon niet worden gelezen.' ); }

            $shared = array();
            $ss_xml = $read( 'xl/sharedStrings.xml' );
            if ( false !== $ss_xml ) {
                $sd = new DOMDocument(); $sd->loadXML( $ss_xml, LIBXML_NONET );
                $sxp = new DOMXPath( $sd ); $sxp->registerNamespace( 'x', $ns );
                foreach ( $sxp->query( '//x:si' ) as $si ) {
                    $txt = ''; foreach ( $sxp->query( './/x:t', $si ) as $t ) { $txt .= $t->textContent; }
                    $shared[] = $txt;
                }
            }
            $sh = new DOMDocument(); $sh->loadXML( $sheet_xml, LIBXML_NONET );
            $xp = new DOMXPath( $sh ); $xp->registerNamespace( 'x', $ns );
            $grid = array(); $count = 0;
            foreach ( $xp->query( '//x:sheetData/x:row' ) as $row ) {
                if ( ++$count > 5000 ) { break; }
                $r = (int)$row->getAttribute( 'r' ); $cells = array();
                foreach ( $xp->query( './x:c', $row ) as $c ) {
                    $col = preg_replace( '/\d+/', '', $c->getAttribute( 'r' ) ); $t = $c->getAttribute( 't' ); $val = '';
                    if ( 'inlineStr' === $t ) { $is = $xp->query( './x:is', $c )->item( 0 ); $val = $is ? $is->textContent : ''; }
                    else {
                        $v = $xp->query( './x:v', $c );
                        if ( $v->length ) {
                            $val = $v->item( 0 )->textContent;
                            if ( 's' === $t ) { $val = $shared[(int)$val] ?? ''; }
                            elseif ( '' === $t || 'n' === $t ) { if ( is_numeric( $val ) && abs( (float)$val ) < 1e15 && (float)$val == floor( (float)$val ) ) { $val = (string)(int)round( (float)$val ); } }
                        }
                    }
                    $cells[$col] = trim( (string)$val );
                }
                $grid[$r] = $cells;
            }
        } finally {
            $zip->close();
        }
        // Koprij zoeken
        $norm = function ( $v ) { return strtolower( preg_replace( '/\s+/', ' ', trim( (string)$v ) ) ); };
        $cols = null; $header_row = 0;
        for ( $r = 1; $r <= 60; $r++ ) {
            if ( empty( $grid[$r] ) ) { continue; }
            $found = array();
            foreach ( $grid[$r] as $col => $v ) {
                $n = $norm( $v );
                if ( in_array( $n, array( 'artikel/ean/gtin', 'artikelnummer', 'artikel', 'sku', 'ean', 'gtin' ), true ) ) { $found['sku'] = $col; }
                elseif ( in_array( $n, array( 'naam', 'productnaam', 'product', 'omschrijving' ), true ) ) { $found['name'] = $col; }
                elseif ( in_array( $n, array( 'aantal', 'bestelaantal', 'besteld' ), true ) ) { $found['qty'] = $col; }
                elseif ( in_array( $n, array( 'product lijn', 'productlijn', 'lijn' ), true ) ) { $found['line'] = $col; }
            }
            if ( isset( $found['sku'] ) ) { $cols = $found; $header_row = $r; break; }
        }
        if ( ! $cols ) { throw new RuntimeException( "Kon geen koprij met de kolom 'Artikel/EAN/GTIN' (of 'SKU') vinden in het eerste werkblad." ); }
        $rows = array(); $warnings = array();
        foreach ( $grid as $r => $cells ) {
            if ( $r <= $header_row ) { continue; }
            $sku = trim( (string)( $cells[$cols['sku']] ?? '' ) );
            if ( '' === $sku ) { continue; }
            if ( ! preg_match( '/^[A-Za-z0-9._\-\/ ]{1,64}$/', $sku ) ) { $warnings[] = 'Regel ' . $r . ': ongeldige SKU overgeslagen.'; continue; }
            $qty = null;
            if ( isset( $cols['qty'] ) ) { $q = str_replace( ',', '.', (string)( $cells[$cols['qty']] ?? '' ) ); if ( is_numeric( $q ) && (float)$q > 0 ) { $qty = (float)$q; } }
            $rows[] = array(
                'row' => $r, 'sku' => $sku,
                'name' => isset( $cols['name'] ) ? (string)( $cells[$cols['name']] ?? '' ) : '',
                'line' => isset( $cols['line'] ) ? (string)( $cells[$cols['line']] ?? '' ) : '',
                'qty' => $qty,
            );
            if ( count( $rows ) > 2000 ) { throw new RuntimeException( 'Meer dan 2000 producten in het bestand.' ); }
        }
        if ( ! $rows ) { throw new RuntimeException( 'Er staan geen producten (SKU\'s) onder de koprij.' ); }
        return array( 'rows' => $rows, 'warnings' => $warnings );
    }

    /** Controleert het bestand tegen de webshop. Bewaart de uitkomst (1 uur) onder een token; wijzigt niets. */
    private function build_import_preview( $path ) {
        $parsed = $this->parse_stock_import_file( $path );
        $rows = array(); $index = array(); $store = array();
        $sum = array( 'rows' => 0, 'new' => 0, 'tracked' => 0, 'missing' => 0, 'consult' => 0, 'dup' => 0, 'qty_rows' => 0 );
        foreach ( $parsed['rows'] as $pr ) {
            $key = $this->normalize_sku( $pr['sku'] );
            if ( isset( $index[$key] ) ) {
                if ( empty( $rows[$index[$key]]['dup'] ) ) { $rows[$index[$key]]['dup'] = true; $sum['dup']++; }
                continue;
            }
            $sum['rows']++;
            $pid = (int) wc_get_product_id_by_sku( $pr['sku'] );
            $product = $pid ? wc_get_product( $pid ) : false;
            $row = array( 'key' => $key, 'sku' => $pr['sku'], 'name' => $pr['name'], 'line' => $pr['line'], 'qty' => $pr['qty'], 'status' => 'missing', 'shop_name' => '', 'hidden' => false, 'mode_label' => '', 'dup' => false );
            if ( $pr['qty'] ) { $sum['qty_rows']++; }
            if ( $product ) {
                $row['shop_name'] = (string)$product->get_name();
                $row['hidden'] = $this->is_hidden_product( $product );
                if ( $this->is_consult_product( $product ) ) { $row['status'] = 'consult'; }
                else {
                    $tracked = 'yes' === get_post_meta( $pid, '_sbp_location_stock_enabled', true );
                    $row['status'] = $tracked ? 'tracked' : 'new';
                    $row['mode_label'] = 'transfer' === $this->stock_mode( $product ) ? 'Vanuit Baarn' : 'Eigen bestelling per locatie';
                    $store[$key] = array( 'product_id' => $pid, 'qty' => $pr['qty'], 'status' => $row['status'] );
                }
            }
            $sum[$row['status']]++;
            $index[$key] = count( $rows );
            $rows[] = $row;
        }
        $token = wp_generate_password( 24, false, false );
        set_transient( 'sbp_imp_' . $token, array( 'user_id' => get_current_user_id(), 'rows' => $store ), HOUR_IN_SECONDS );
        return array( 'token' => $token, 'summary' => $sum, 'rows' => $rows, 'warnings' => $parsed['warnings'] );
    }

    public function ajax_stock_import_preview() {
        $this->guard();
        $f = $_FILES['file'] ?? null;
        if ( ! is_array( $f ) || UPLOAD_ERR_OK !== (int)( $f['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( (string)$f['tmp_name'] ) ) {
            wp_send_json_error( array( 'message' => 'De upload is mislukt. Kies een .xlsx-bestand (maximaal 5 MB).' ), 400 );
        }
        if ( 'xlsx' !== strtolower( pathinfo( (string)$f['name'], PATHINFO_EXTENSION ) ) ) {
            wp_send_json_error( array( 'message' => 'Alleen .xlsx-bestanden worden ondersteund.' ), 400 );
        }
        try {
            wp_send_json_success( $this->build_import_preview( (string)$f['tmp_name'] ) );
        } catch ( Throwable $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ), 400 );
        }
    }

    /** Neemt de gekozen producten over. Opnieuw uitvoeren is veilig: wat al gevolgd wordt, blijft ongemoeid. */
    private function apply_import( $data, array $keys, $use_qty, $location ) {
        $added = 0; $already = 0; $stocked = 0; $skipped_qty = array();
        foreach ( array_slice( $keys, 0, 1000 ) as $k ) {
            $k = (string)$k;
            if ( ! isset( $data['rows'][$k] ) ) { continue; }
            $row = $data['rows'][$k];
            $id = (int)$row['product_id'];
            $product = wc_get_product( $id );
            if ( ! $product || $this->is_consult_product( $product ) ) { continue; }
            if ( 'yes' !== get_post_meta( $id, '_sbp_location_stock_enabled', true ) ) {
                update_post_meta( $id, '_sbp_location_stock_enabled', 'yes' );
                delete_post_meta( $id, '_sbp_loc_tracked_since' ); // wacht op getelde beginvoorraad
                foreach ( $this->stock_locations() as $loc => $label ) {
                    foreach ( array( 'min', 'target' ) as $type ) {
                        $mk = $this->stock_meta_key( $type, $loc );
                        if ( ! metadata_exists( 'post', $id, $mk ) ) { update_post_meta( $id, $mk, 0 ); }
                    }
                }
                $added++;
            } else { $already++; }
            if ( $use_qty && ! empty( $row['qty'] ) ) {
                if ( $this->tracked_since( $id ) > 0 ) { $skipped_qty[] = $product->get_name(); continue; } // bestaande voorraad nooit overschrijven
                try {
                    SBP_Ledger::set_absolute( $id, $location, (float)$row['qty'], SBP_Ledger::quantity( $id, $location ), 'Beginvoorraad uit Excel-import', get_current_user_id(), $this->stock_info( $id ) );
                    $this->start_tracking( $id );
                    $stocked++;
                } catch ( SBP_Ledger_Exception $e ) { $skipped_qty[] = $product->get_name(); }
            }
        }
        $this->forget_stock_cache();
        return array( 'added' => $added, 'already' => $already, 'stocked' => $stocked, 'skipped_qty' => $skipped_qty );
    }

    public function ajax_stock_import_apply() {
        $this->guard();
        $token = sanitize_key( $_POST['token'] ?? '' );
        $data = get_transient( 'sbp_imp_' . $token );
        if ( ! $data || (int)( $data['user_id'] ?? 0 ) !== get_current_user_id() ) {
            wp_send_json_error( array( 'message' => 'De controle is verlopen. Upload het bestand opnieuw.' ), 400 );
        }
        $keys = json_decode( (string) wp_unslash( $_POST['keys'] ?? '[]' ), true );
        if ( ! is_array( $keys ) || ! $keys ) { wp_send_json_error( array( 'message' => 'Selecteer eerst producten.' ), 400 ); }
        $use_qty = ! empty( $_POST['use_qty'] );
        $loc = sanitize_key( $_POST['location'] ?? '' );
        if ( $use_qty && ! isset( $this->stock_locations()[$loc] ) ) { wp_send_json_error( array( 'message' => 'Kies een locatie voor de beginvoorraad.' ), 400 ); }
        $r = $this->apply_import( $data, $keys, $use_qty, $loc );
        delete_transient( 'sbp_imp_' . $token );
        $msg = $r['added'] . ' product(en) toegevoegd aan Locatievoorraad' . ( $r['already'] ? ', ' . $r['already'] . ' werden al gevolgd' : '' ) . '.';
        if ( $use_qty ) { $msg .= ' Beginvoorraad gezet voor ' . $r['stocked'] . ' product(en).'; }
        if ( $r['skipped_qty'] ) { $msg .= ' Voorraad niet overschreven (al in gebruik): ' . implode( ', ', array_slice( $r['skipped_qty'], 0, 10 ) ) . ( count( $r['skipped_qty'] ) > 10 ? '…' : '' ) . '.'; }
        $msg .= ' Voer nu de getelde beginvoorraad in; tot die tijd worden er geen verkopen van deze producten afgeboekt.';
        wp_send_json_success( array( 'message' => $msg ) );
    }

    /** Voegt een product toe en slaat in dezelfde handeling voorraad, minimum en gewenst voor alle locaties op. */
    public function ajax_stock_quick_add() {
        $this->guard();
        $id = absint( $_POST['product_id'] ?? 0 );
        $product = wc_get_product( $id );
        if ( ! $product ) { wp_send_json_error( array( 'message' => 'Product niet gevonden.' ), 404 ); }
        if ( 'yes' === get_post_meta( $id, '_sbp_location_stock_enabled', true ) ) {
            wp_send_json_error( array( 'message' => 'Dit product wordt al gevolgd. Pas de waarden aan in de lijst.' ), 409 );
        }
        $values = json_decode( (string) wp_unslash( $_POST['values'] ?? '[]' ), true );
        if ( ! is_array( $values ) ) { $values = array(); }
        $parsed = array();
        foreach ( $this->stock_locations() as $loc => $label ) {
            $row = ( isset( $values[$loc] ) && is_array( $values[$loc] ) ) ? $values[$loc] : array();
            foreach ( array( 'stock', 'min', 'target' ) as $type ) {
                $raw = str_replace( ',', '.', sanitize_text_field( (string)( $row[$type] ?? '' ) ) );
                if ( '' === $raw ) { continue; }
                if ( ! is_numeric( $raw ) || (float)$raw < 0 ) {
                    wp_send_json_error( array( 'message' => 'Ongeldige waarde bij ' . $label . ': gebruik een getal van 0 of hoger.' ), 400 );
                }
                $parsed[$loc][$type] = (float)$raw;
            }
        }
        update_post_meta( $id, '_sbp_location_stock_enabled', 'yes' );
        delete_post_meta( $id, '_sbp_loc_tracked_since' ); // start pas bij de eerste ingevoerde voorraad
        foreach ( $this->stock_locations() as $loc => $label ) {
            foreach ( array( 'min', 'target' ) as $type ) { $this->set_stock_number( $id, $type, $loc, $parsed[$loc][$type] ?? 0 ); }
        }
        $stocked = 0; $problems = array(); $info = $this->stock_info( $id );
        foreach ( $this->stock_locations() as $loc => $label ) {
            if ( ! isset( $parsed[$loc]['stock'] ) ) { continue; }
            try {
                SBP_Ledger::set_absolute( $id, $loc, $parsed[$loc]['stock'], SBP_Ledger::quantity( $id, $loc ), 'Beginvoorraad ingevoerd bij toevoegen', get_current_user_id(), $info );
                $stocked++;
            } catch ( SBP_Ledger_Exception $e ) { $problems[] = $label . ': ' . $e->getMessage(); }
        }
        if ( $stocked ) { $this->start_tracking( $id ); }
        $this->forget_stock_cache();
        if ( $problems ) {
            wp_send_json_error( array( 'message' => 'Het product is toegevoegd, maar de voorraad is niet overal opgeslagen: ' . implode( '; ', $problems ) . '. De pagina wordt opnieuw geladen.' ), 500 );
        }
        wp_send_json_success( array(
            'message' => $info['name'] . ' toegevoegd en opgeslagen' . ( $stocked ? '.' : '; nog zonder voorraad (wacht op beginvoorraad).' ),
            'name' => $info['name'], 'started' => $stocked > 0, 'row_html' => $this->stock_row_html( $id, $product ),
        ) );
    }

    public function ajax_stock_mode() {
        $this->guard();
        $id = absint( $_POST['product_id'] ?? 0 );
        $mode = sanitize_key( $_POST['mode'] ?? '' );
        if ( ! $id || ! $this->is_stock_tracked_product_id( $id ) || ! in_array( $mode, array( '', 'transfer', 'direct' ), true ) ) {
            wp_send_json_error( array( 'message' => 'Ongeldig product of keuze.' ), 400 );
        }
        if ( '' === $mode ) { delete_post_meta( $id, '_sbp_loc_mode' ); } else { update_post_meta( $id, '_sbp_loc_mode', $mode ); }
        $this->forget_stock_cache();
        wp_send_json_success( array( 'message' => 'Aanvulwijze opgeslagen.' ) );
    }

    public function ajax_stock_adjust() {
        $this->guard();
        $id = absint( $_POST['product_id'] ?? 0 );
        $loc = sanitize_key( $_POST['location'] ?? '' );
        $raw = str_replace( ',', '.', sanitize_text_field( (string)( $_POST['delta'] ?? '' ) ) );
        $note = sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) );
        if ( ! $id || ! $this->is_stock_tracked_product_id( $id ) || ! isset( $this->stock_locations()[$loc] ) || ! is_numeric( $raw ) || (float)$raw == 0 ) {
            wp_send_json_error( array( 'message' => 'Controleer product, locatie en aantal.' ), 400 );
        }
        $delta = (float)$raw;
        try {
            SBP_Ledger::adjust( $id, $loc, $delta, $delta > 0 ? 'manual_in' : 'manual_out', $note ?: 'Handmatige voorraadcorrectie', get_current_user_id(), $this->stock_info( $id ) );
            $this->start_tracking( $id );
        } catch ( SBP_Ledger_Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ), 400 );
        }
        $this->forget_stock_cache();
        wp_send_json_success( array( 'message' => 'Voorraad aangepast.' ) );
    }

    public function ajax_stock_transfer() {
        $this->guard();
        $id = absint( $_POST['product_id'] ?? 0 );
        $from = sanitize_key( $_POST['from'] ?? '' );
        $to = sanitize_key( $_POST['to'] ?? '' );
        $raw = str_replace( ',', '.', sanitize_text_field( (string)( $_POST['qty'] ?? '' ) ) );
        $locs = $this->stock_locations();
        if ( ! $id || ! $this->is_stock_tracked_product_id( $id ) || $from === $to || ! isset( $locs[$from] ) || ! isset( $locs[$to] ) || ! is_numeric( $raw ) || (float)$raw <= 0 ) {
            wp_send_json_error( array( 'message' => 'Controleer product, locaties en aantal.' ), 400 );
        }
        $qty = (float)$raw;
        try {
            SBP_Ledger::transfer( $id, $from, $to, $qty, 'Interne transfer ' . ucfirst( $from ) . ' → ' . ucfirst( $to ), get_current_user_id(), $this->stock_info( $id ) );
        } catch ( SBP_Ledger_Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ), 400 );
        }
        $this->forget_stock_cache();
        wp_send_json_success( array( 'message' => $qty . ' stuks overgeboekt van ' . ucfirst( $from ) . ' naar ' . ucfirst( $to ) . '.' ) );
    }

    public function ajax_assign_order_location() {
        $this->guard();
        $order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) );
        $loc = sanitize_key( $_POST['location'] ?? '' );
        if ( ! $order || ! isset( $this->stock_locations()[$loc] ) ) { wp_send_json_error( array( 'message' => 'Ongeldige order of locatie.' ), 400 ); }
        if ( ! $this->is_yith_pos_order( $order ) ) { wp_send_json_error( array( 'message' => 'Alleen kassaverkopen kunnen een locatie krijgen.' ), 400 ); }
        $existing = sanitize_key( (string) $order->get_meta( '_sbp_location_stock_location' ) );
        if ( '' !== $existing ) { wp_send_json_error( array( 'message' => 'Voor deze order is al een locatie gekozen (' . ucfirst( $existing ) . ').' ), 400 ); }
        if ( $order->get_meta( '_sbp_location_stock_processed' ) ) { wp_send_json_error( array( 'message' => 'De voorraad van deze order is al verwerkt.' ), 400 ); }
        $order->update_meta_data( '_sbp_location_stock_location', $loc );
        $order->delete_meta_data( '_sbp_location_stock_pending' );
        $order->save_meta_data();
        $this->forget_stock_cache();
        $this->reconcile_order( $order->get_id(), $order );
        wp_send_json_success( array( 'message' => 'Locatie opgeslagen.' ) );
    }

    private function reason_label( $reason ) {
        $map = array(
            'sale' => 'Verkoop', 'sale_edit' => 'Verkoop (order gewijzigd)', 'return' => 'Terugboeking', 'return_partial' => 'Gedeeltelijke terugboeking',
            'legacy_import' => 'Boeking uit v1.7.0', 'manual_in' => 'Levering/correctie (+)', 'manual_out' => 'Uitboeking/correctie (−)',
            'transfer_in' => 'Transfer in', 'delivery_in' => 'Levering Bonusan ontvangen', 'transfer_out' => 'Transfer uit', 'count_correction' => 'Telling/correctie',
        );
        return $map[(string)$reason] ?? (string)$reason;
    }

    /** Eenmalig bij een update: tabellen maken, bestaande gevolgde producten een startmoment geven, oud logboek overnemen. */
    public function maybe_upgrade() {
        if ( ! class_exists( 'WooCommerce' ) ) { return; }
        if ( version_compare( (string) get_option( 'sbp_db_version', '' ), SBP_Ledger::DB_VERSION, '>=' ) ) { return; }
        $lock = 'upgrade_' . SBP_Ledger::DB_VERSION;
        if ( ! $this->acquire_lock( $lock, 300 ) ) { return; }
        try {
            SBP_Ledger::install();
            // Het oude startmoment was een lokale (verschoven) tijd; terugrekenen naar UTC.
            $offset = (int) round( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
            $old = (int) get_option( 'sbp_location_stock_started_at', 0 );
            $since = $old ? max( 1, $old - $offset ) : time();
            foreach ( $this->get_tracked_products() as $id => $product ) {
                if ( ! get_post_meta( $id, '_sbp_loc_tracked_since', true ) ) { update_post_meta( $id, '_sbp_loc_tracked_since', $since ); }
            }
            if ( ! get_option( 'sbp_ledger_legacy_imported' ) ) {
                SBP_Ledger::purge_legacy_import();
                $old_log = get_option( 'sbp_location_stock_log', array() );
                if ( is_array( $old_log ) ) { foreach ( array_reverse( $old_log ) as $entry ) { SBP_Ledger::import_legacy_entry( (array)$entry ); } }
                update_option( 'sbp_ledger_legacy_imported', time(), false );
            }
            update_option( 'sbp_db_version', SBP_Ledger::DB_VERSION, true );
        } catch ( Throwable $e ) {
            error_log( 'Schreuder Bonusan POS: upgrade naar ' . SBP_Ledger::DB_VERSION . ' mislukt: ' . $e->getMessage() );
        }
        $this->release_lock( $lock );
    }

    private function csv_safe( $value ) {
        $value = (string)$value;
        return ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) ? "'" . $value : $value;
    }

    public function download_stock_log_csv() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'Geen toegang.' ); }
        check_admin_referer( 'sbp_stock_log_csv' );
        $pid = absint( $_GET['log_product'] ?? 0 );
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="voorraadlogboek-' . wp_date( 'Y-m-d' ) . '.csv"' );
        $out = fopen( 'php://output', 'w' );
        fwrite( $out, "\xEF\xBB\xBF" );
        fputcsv( $out, array( 'Datum (lokaal)', 'Datum (UTC)', 'Product-ID', 'SKU', 'Product', 'Locatie', 'Mutatie', 'Voorraad voor', 'Voorraad na', 'Reden', 'Order', 'Transfer-ID', 'Notitie', 'Gebruiker-ID' ), ';' );
        for ( $offset = 0; ; $offset += 1000 ) {
            $rows = SBP_Ledger::log_rows( $pid, 1000, $offset );
            if ( ! $rows ) { break; }
            foreach ( $rows as $e ) {
                fputcsv( $out, array( get_date_from_gmt( $e['created_gmt'], 'Y-m-d H:i:s' ), $e['created_gmt'], $e['product_id'], $this->csv_safe( $e['sku'] ), $this->csv_safe( $e['product_name'] ), $e['location'], (float)$e['delta'], (float)$e['before_qty'], (float)$e['after_qty'], $e['reason'], $e['order_id'], $e['transfer_id'], $this->csv_safe( $e['note'] ), $e['user_id'] ), ';' );
            }
        }
        fclose( $out );
        exit;
    }

    public function maybe_prepare_after_close() {
        // YITH's internal close hooks differ by version. We deliberately do not auto-send without a visible final check.
        set_transient( 'sbp_register_recently_closed', current_time('mysql'), HOUR_IN_SECONDS );
    }
}

register_activation_hook( __FILE__, function () { SBP_Ledger::install(); } );
add_action( 'plugins_loaded', array( 'Schreuder_Bonusan_POS', 'instance' ) );
