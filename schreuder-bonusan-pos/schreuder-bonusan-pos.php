<?php
/**
 * Plugin Name: Schreuder Bonusan POS Bestellingen
 * Description: Maakt per locatie een aaneengesloten Bonusan-bestellijst vanuit WooCommerce/YITH POS-orders, toont eerst een controle en verzendt daarna het Excel-bestand.
 * Version: 1.7.0
 * Author: Schreuder Natuurgeneeswijzen
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Schreuder_Bonusan_POS {
    const OPTION = 'sbp_settings';
    const NONCE  = 'sbp_nonce';
    const VERSION = '1.7.0';

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
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

        // Locatievoorraad v1.7.0.
        add_action( 'wp_ajax_sbp_stock_product_search', array( $this, 'ajax_stock_product_search' ) );
        add_action( 'wp_ajax_sbp_stock_add_product', array( $this, 'ajax_stock_add_product' ) );
        add_action( 'wp_ajax_sbp_stock_save', array( $this, 'ajax_stock_save' ) );
        add_action( 'wp_ajax_sbp_stock_remove_product', array( $this, 'ajax_stock_remove_product' ) );
        add_action( 'wp_ajax_sbp_stock_adjust', array( $this, 'ajax_stock_adjust' ) );
        add_action( 'wp_ajax_sbp_stock_transfer', array( $this, 'ajax_stock_transfer' ) );
        add_action( 'wp_ajax_sbp_assign_order_location', array( $this, 'ajax_assign_order_location' ) );
        add_action( 'woocommerce_order_status_changed', array( $this, 'handle_stock_order_status_change' ), 20, 4 );
        add_action( 'woocommerce_update_order', array( $this, 'maybe_process_stock_order' ), 50, 2 );
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
                    <tr><th>YITH locatiekenmerk Baarn</th><td><input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION); ?>[location_meta][baarn]" value="<?php echo esc_attr($s['location_meta']['baarn']); ?>"><p class="description">Optioneel: store-ID, register-ID of exacte meta-waarde die YITH bij deze locatie opslaat.</p></td></tr>
                    <tr><th>YITH locatiekenmerk Haarlem</th><td><input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION); ?>[location_meta][haarlem]" value="<?php echo esc_attr($s['location_meta']['haarlem']); ?>"></td></tr>
                    <tr><th>YITH locatiekenmerk Zwolle</th><td><input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION); ?>[location_meta][zwolle]" value="<?php echo esc_attr($s['location_meta']['zwolle']); ?>"></td></tr>
                    <tr><th>Kenmerk extra kassa</th><td><input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION); ?>[extra_register_meta]" value="<?php echo esc_attr($s['extra_register_meta'] ?? ''); ?>"><p class="description">Optioneel: store-/register-ID of meta-waarde van de extra kassa. Voor deze kassa wordt de verkoop pas van de locatievoorraad afgeboekt nadat je Baarn, Haarlem of Zwolle hebt gekozen.</p></td></tr>
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
            function loadPreview(){
                $('#sbp-spinner').addClass('is-active'); $('#sbp-result').html('');
                $.post(ajaxurl,payload('sbp_preview')).done(function(r){
                    if(!r.success){$('#sbp-result').html('<div class="sbp-error">'+esc(r.data&&r.data.message?r.data.message:'Onbekende fout')+'</div>');return;}
                    let d=r.data, html='<h2>Controle '+esc(d.location_label)+'</h2>';
                    if(d.draft_restored){html+='<div class="sbp-draft-note"><strong>Opgeslagen wijzigingen hersteld.</strong> Handmatige aanpassingen en toegevoegde producten worden 24 uur bewaard.</div>';}
                    if(d.include_sent){html+='<div class="sbp-notice"><strong>Let op:</strong> deze controle bevat ook kassaverkopen die eerder definitief zijn verzonden. Verzend alleen opnieuw wanneer dat bewust de bedoeling is.</div>';}
                    if(d.stock_advice && d.stock_advice.length){
                        html+='<div class="sbp-addbox"><strong>Locatievoorraad – aanvuladvies</strong><p class="description">Baarn is de hoofdvoorraad voor de producten die je onder Locatievoorraad volgt. Haarlem en Zwolle worden eerst vanuit Baarn aangevuld. Extern aanvullen is alleen een advies; jij bepaalt het uiteindelijke bestelaantal.</p>';
                        html+='<table class="sbp-table"><thead><tr><th>Product</th><th>Baarn</th><th>Haarlem</th><th>Zwolle</th><th>Intern aanvullen</th><th>Extern advies</th><th>Route</th></tr></thead><tbody>';
                        d.stock_advice.forEach(function(x){let internal=[];if(Number(x.to_haarlem)>0)internal.push(x.to_haarlem+' → Haarlem');if(Number(x.to_zwolle)>0)internal.push(x.to_zwolle+' → Zwolle');html+='<tr><td>'+esc(x.name)+'<br><small>SKU '+esc(x.sku||'—')+'</small></td><td>'+esc(x.baarn)+'</td><td>'+esc(x.haarlem)+'</td><td>'+esc(x.zwolle)+'</td><td>'+esc(internal.join(', ')||'—')+'</td><td><strong>'+esc(x.external_need||0)+'</strong></td><td>'+esc(x.route_label)+'</td></tr>';});
                        html+='</tbody></table><p><a class="button" href="<?php echo esc_js( admin_url('admin.php?page=schreuder-bonusan-stock') ); ?>">Locatievoorraad openen</a></p></div>';
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
                        html+='<p><strong>‘Te bestellen’ wordt standaard gevuld met alleen Echte kassa – Afgerond.</strong> Webshopverkopen zijn uitsluitend ter informatie. Je kunt het bestelaantal aanpassen of hieronder handmatig een product toevoegen.</p><table class="sbp-table"><thead><tr><th>SKU</th><th>Product</th><th>Echte kassa<br><small>Afgerond</small></th><th>Webshop via kassa<br><small>In behandeling</small></th><th>Totaal verkocht</th><th>Te bestellen</th></tr></thead><tbody>';
                        d.items.forEach(i=>html+=itemRow(i));
                        html+='</tbody></table><p>'+d.order_count+' orders verwerkt. <span id="sbp-edit-status"></span></p>';
                    }
                    if(d.can_send){html+='<div class="sbp-actions"><button class="button" id="sbp-reload">Opnieuw laden</button> <button class="button" id="sbp-save">Wijzigingen toepassen</button> <button class="button" id="sbp-download">Ingevulde Excel downloaden</button> <button class="button button-primary" id="sbp-send">Definitief verzenden</button></div><p class="description">Handmatige wijzigingen blijven 24 uur bewaard. Na een succesvolle definitieve verzending wordt deze tijdelijke bestellijst gewist.</p>';}
                    $('#sbp-result').html(html).data('token',d.token);
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
                    let item=r.data.item, row=$('.sbp-table tbody tr').filter(function(){return String($(this).data('sku'))===String(item.sku);});
                    if(row.length){row.replaceWith(itemRow(item));}else{
                        if(!$('.sbp-table').length){loadPreview();return;}
                        $('.sbp-table tbody').append(itemRow(item));
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
            if ( ! array_key_exists( $sku, $quantities ) ) { continue; }
            $raw = str_replace(',', '.', sanitize_text_field((string)$quantities[$sku]));
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

        if ( isset($data['items'][$sku]) ) {
            $data['items'][$sku]['manual'] = true;
            $data['items'][$sku]['manual_override'] = true;
            if ( (float)$data['items'][$sku]['qty'] <= 0 ) { $data['items'][$sku]['qty'] = 1; }
        } else {
            $data['items'][$sku] = array(
                'sku' => $sku,
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
        wp_send_json_success( array( 'item' => $data['items'][$sku] ) );
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
            foreach ( ($data['processed_order_ids'] ?? $data['order_ids']) as $id ) {
                $order = wc_get_order( $id );
                if ( $order ) { $order->update_meta_data( '_sbp_bonusan_sent_' . $data['location'], current_time('mysql') ); $order->save(); }
            }
            $this->add_send_log( array(
                'sent_at' => current_time('mysql'),
                'location' => ucfirst($data['location']),
                'recipient' => $s['recipient'],
                'bcc' => $bcc_clean,
                'subject' => $subject,
                'user_id' => get_current_user_id(),
                'order_count' => count($data['order_ids']),
                'article_count' => $this->sum_order_quantity($data['items']),
                'repeat_load' => ! empty( $data['include_sent'] ),
            ) );
            @unlink( $file );
            delete_transient( $this->draft_key($data) );
            delete_transient( 'sbp_' . $token );
            wp_send_json_success( array( 'message' => 'De bestelling is verzonden naar ' . $s['recipient'] . '.' ) );
        } catch ( Throwable $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
        }
    }

    public function ajax_download() {
        $this->guard();
        $token = sanitize_key( $_GET['token'] ?? '' );
        $data = get_transient( 'sbp_' . $token );
        if ( ! $data || empty( $data['items'] ) || (int)($data['user_id'] ?? 0) !== get_current_user_id() ) { wp_die( 'De controle is verlopen of bevat fouten.' ); }
        if ( ! $this->has_positive_quantity( $data['items'] ) ) { wp_die( 'Er staat geen enkel product met een aantal groter dan 0 in de bestelling.' ); }
        try {
            $file = $this->create_xlsx( $data );
            nocache_headers();
            header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
            header( 'Content-Disposition: attachment; filename="' . basename($file) . '"' );
            header( 'Content-Length: ' . filesize($file) );
            readfile( $file ); @unlink( $file ); exit;
        } catch ( Throwable $e ) { wp_die( esc_html( $e->getMessage() ) ); }
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
            );
        }
        $draft = array( 'items'=>$draft_items, 'planner_manual'=>(array)($data['planner_manual'] ?? array()), 'saved_at'=>time() );
        set_transient( $this->draft_key($data), $draft, DAY_IN_SECONDS );
    }

    private function restore_draft( &$data ) {
        $draft = get_transient( $this->draft_key($data) );
        if ( ! is_array($draft) ) { return false; }
        if ( ! empty($draft['planner_manual']) ) { $data['planner_manual'] = (array)$draft['planner_manual']; }
        $template_map = $this->read_template_map( $data['location'] );
        $restored = ! empty($draft['planner_manual']);
        foreach ( $draft['items'] as $sku => $saved ) {
            $sku = (string)$sku;
            $sku_key = $this->normalize_sku( $sku );
            $draft_product_id = wc_get_product_id_by_sku( $sku );
            $draft_product = $draft_product_id ? wc_get_product( $draft_product_id ) : false;
            if ( ! isset($template_map[$sku_key]) && ! ( $draft_product && $this->is_assistent_bonusan_product( $draft_product ) ) ) { continue; }
            $sku = $sku_key;
            if ( isset($data['items'][$sku]) ) {
                $data['items'][$sku]['qty'] = (float)($saved['qty'] ?? $data['items'][$sku]['qty']);
                $data['items'][$sku]['manual_override'] = true;
                if ( ! empty($saved['manual']) ) { $data['items'][$sku]['manual'] = true; }
            } else {
                $product_id = wc_get_product_id_by_sku( $sku );
                $product = $product_id ? wc_get_product($product_id) : false;
                if ( ! $product ) { continue; }
                $data['items'][$sku] = array(
                    'sku'=>$sku,
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
        $items = array(); $order_ids = array(); $processed_order_ids = array(); $unresolved = array(); $planner_manual = array();
        foreach ( $orders as $order ) {
            if ( ! $include_sent && $order->get_meta( '_sbp_bonusan_sent_' . $location ) ) { continue; }
            if ( $pos_only && ! $this->is_yith_pos_order( $order ) ) { continue; }
            if ( ! $this->order_matches_location( $order, $location ) ) { continue; }
            $used = false; $has_completed_bonusan_item = false;
            foreach ( $order->get_items('line_item') as $item ) {
                // Consulten/diensten horen nooit in een productbestel- of plannerlijst.
                // Filter op zowel productnaam als productcategorie zodat ook consulten zonder
                // SKU of met een afwijkende naam worden uitgesloten.
                if ( $this->is_consult_order_item( $item ) ) {
                    continue;
                }
                // YITH POS kan oudere orderregels bewaren waarvan get_product() geen actueel
                // productobject meer teruggeeft. Resolving gebeurt daarom via meerdere bronnen.
                $sku = $this->resolve_order_item_sku( $item );
                if ( '' === $sku ) {
                    $is_physical_pos = ( 'completed' === $order->get_status() || $this->is_pos_bank_transfer_sale( $order ) || $this->is_pos_open_invoice_sale( $order ) );
                    $unresolved[] = array(
                        'order_id'    => (int) $order->get_id(),
                        'name'        => (string) $item->get_name(),
                        'planner_qty' => $is_physical_pos ? (float) $item->get_quantity() : 0,
                        'reason'      => 'Geen SKU gevonden in product, variatie, orderregel-meta of productnaam.',
                    );
                    if ( $is_physical_pos ) {
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
                $line_qty = (float) $item->get_quantity();
                if ( 'completed' === $order->get_status() || $this->is_pos_bank_transfer_sale( $order ) || $this->is_pos_open_invoice_sale( $order ) ) {
                    // Fysieke POS-verkoop: ook meenemen wanneer betaling later volgt via
                    // bankoverschrijving of de orderstatus 'Openstaand factuur' is. De turflijst
                    // volgt wat fysiek is meegegeven, niet alleen wat al betaald is.
                    $items[$sku_key]['pos_qty'] += $line_qty;
                    $has_completed_bonusan_item = true;
                } elseif ( 'processing' === $order->get_status() ) {
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
        return compact( 'location','from','to','items','unknown','planner_manual','unresolved','order_ids','processed_order_ids','include_sent','pos_only' );
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

        foreach ( (array)($data['items'] ?? array()) as $item ) {
            $k = $this->normalize_sku( (string)($item['sku'] ?? '') );
            if ( '' !== $k ) { $turflist_skus[$k] = true; }
        }

        $add = function( $sku, $name, $qty, $manual = false, $no_sku = false ) use ( &$groups, $turflist_skus ) {
            $qty = (float)$qty;
            if ( $qty <= 0 ) { return; }
            $sku = trim( (string)$sku );
            $name = trim( (string)$name );
            $sku_key = $this->normalize_sku( $sku );

            // Strikte scheiding: staat een SKU op de Bonusan-turflijst, dan mag
            // dezelfde SKU nooit in de planner verschijnen, ook niet vanuit een oud
            // handmatig plannerconcept.
            if ( '' !== $sku_key && isset( $turflist_skus[$sku_key] ) ) { return; }

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

        // WooCommerce bewaart created_via als orderprop; die verschijnt niet altijd tussen meta_data.
        if ( is_callable( array( $order, 'get_created_via' ) ) ) {
            $created_via = strtolower( trim( (string) $order->get_created_via() ) );
            if ( false !== strpos( $created_via, 'pos' ) || false !== strpos( $created_via, 'yith' ) ) { return true; }
        }

        foreach ( $order->get_meta_data() as $meta ) {
            $key = strtolower( (string) $meta->key );
            $value = strtolower( is_scalar($meta->value) ? (string)$meta->value : wp_json_encode($meta->value) );
            if ( false !== strpos($key,'yith') && false !== strpos($key,'pos') ) { return true; }
            if ( in_array($key,array('_created_via','created_via'),true) && false !== strpos($value,'pos') ) { return true; }
            if ( preg_match('/(register|store|pos)/', $key) && ( false !== strpos($value,'pos') || false !== strpos($value,'yith') ) ) { return true; }
        }
        return false;
    }

    private function order_matches_location( $order, $location ) {
        $s = $this->settings();
        $needle = strtolower( trim( $s['location_meta'][$location] ?? '' ) );
        $haystacks = array();
        foreach ( $order->get_meta_data() as $meta ) {
            $key = strtolower((string)$meta->key);
            if ( preg_match('/(yith|pos|store|register|location)/',$key) ) {
                $haystacks[] = strtolower( is_scalar($meta->value) ? (string)$meta->value : wp_json_encode($meta->value) );
            }
        }
        if ( $needle !== '' ) {
            foreach ( $haystacks as $value ) { if ( $value === $needle || false !== strpos($value,$needle) ) { return true; } }
            return false;
        }
        foreach ( $haystacks as $value ) { if ( false !== strpos($value,$location) ) { return true; } }
        // If no identifiers are configured or discoverable, allow manual location selection.
        return true;
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
        $map=$this->read_template_map($data['location']); $newRow=10;
        foreach($data['items'] as $item){
            if((float)$item['qty']<=0){continue;}
            $map_key=$this->normalize_sku($item['sku']);
            if(isset($map[$map_key])){
                $srcRow=$map[$map_key]['row']; if(!isset($rows[$srcRow])){continue;} $row=$rows[$srcRow]->cloneNode(true);
            } else {
                // Door Schreuder Assistent herkende Bonusan-SKU die nog niet in het oude
                // sjabloon staat: gebruik een bestaande productregel als opmaakbasis.
                if ( empty($rows) || ! $this->is_assistent_bonusan_sku($item['sku']) ) { continue; }
                $row=reset($rows)->cloneNode(true);
            }
            $row->setAttribute('r',(string)$newRow);
            foreach($xp->query('./x:c',$row) as $cell){$old=$cell->getAttribute('r'); $col=preg_replace('/\d+/','',$old); $cell->setAttribute('r',$col.$newRow);}
            if(!isset($map[$map_key])){
                $this->set_inline_string_cell($dom,$xp,$row,'B'.$newRow,(string)$item['sku']);
                $this->set_inline_string_cell($dom,$xp,$row,'C'.$newRow,(string)$item['name']);
            }
            $this->set_numeric_cell($dom,$xp,$row,'D'.$newRow,$item['qty']); $sheetData->appendChild($row); $newRow++; }
        $last=max(9,$newRow-1); $dim=$xp->query('//x:dimension')->item(0); if($dim){$dim->setAttribute('ref','A1:H'.$last);} 
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
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
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
        $sheet_xml .= '</sheetData><dimension ref="A1:F' . max(1,$row_num-1) . '"/></worksheet>';
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

    private function stock_locations() {
        return array( 'baarn' => 'Baarn', 'haarlem' => 'Haarlem', 'zwolle' => 'Zwolle' );
    }

    private function stock_meta_key( $type, $location ) {
        return '_sbp_loc_' . sanitize_key( $type ) . '_' . sanitize_key( $location );
    }

    private function stock_number( $product_id, $type, $location ) {
        $value = get_post_meta( (int)$product_id, $this->stock_meta_key( $type, $location ), true );
        return is_numeric( $value ) ? (float)$value : 0.0;
    }

    private function set_stock_number( $product_id, $type, $location, $value ) {
        update_post_meta( (int)$product_id, $this->stock_meta_key( $type, $location ), (float)$value );
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

    private function stock_log_entries() {
        $log = get_option( 'sbp_location_stock_log', array() );
        return is_array( $log ) ? $log : array();
    }

    private function add_stock_log( $entry ) {
        $log = $this->stock_log_entries();
        $entry = wp_parse_args( $entry, array(
            'time' => current_time('mysql'), 'product_id' => 0, 'sku' => '', 'name' => '',
            'location' => '', 'delta' => 0, 'before' => 0, 'after' => 0,
            'reason' => '', 'order_id' => 0, 'note' => '', 'user_id' => get_current_user_id(),
        ) );
        array_unshift( $log, $entry );
        update_option( 'sbp_location_stock_log', array_slice( $log, 0, 2000 ), false );
    }

    private function adjust_location_stock( $product_id, $location, $delta, $reason, $order_id = 0, $note = '' ) {
        if ( ! isset( $this->stock_locations()[$location] ) ) { return false; }
        $product = wc_get_product( (int)$product_id );
        if ( ! $product ) { return false; }
        $before = $this->stock_number( $product_id, 'stock', $location );
        $after = $before + (float)$delta;
        $this->set_stock_number( $product_id, 'stock', $location, $after );
        $this->add_stock_log( array(
            'product_id' => (int)$product_id,
            'sku' => (string)$product->get_sku(),
            'name' => (string)$product->get_name(),
            'location' => $location,
            'delta' => (float)$delta,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'order_id' => (int)$order_id,
            'note' => (string)$note,
        ) );
        return true;
    }

    private function order_location_haystacks( $order ) {
        $values = array();
        foreach ( $order->get_meta_data() as $meta ) {
            $key = strtolower( (string)$meta->key );
            if ( preg_match('/(yith|pos|store|register|location|shop)/', $key) ) {
                $values[] = strtolower( is_scalar($meta->value) ? (string)$meta->value : wp_json_encode($meta->value) );
            }
        }
        return $values;
    }

    private function detect_stock_order_location( $order ) {
        $explicit = sanitize_key( (string)$order->get_meta('_sbp_location_stock_location') );
        if ( isset( $this->stock_locations()[$explicit] ) ) { return $explicit; }

        // Gewone webshopbestellingen gaan standaard uit de hoofdvoorraad Baarn.
        if ( ! $this->is_yith_pos_order( $order ) ) { return 'baarn'; }

        $s = $this->settings();
        $values = $this->order_location_haystacks( $order );
        $extra = strtolower( trim( (string)($s['extra_register_meta'] ?? '') ) );
        if ( '' !== $extra ) {
            foreach ( $values as $value ) {
                if ( $value === $extra || false !== strpos( $value, $extra ) ) { return ''; }
            }
        }

        foreach ( $this->stock_locations() as $loc => $label ) {
            $needle = strtolower( trim( (string)($s['location_meta'][$loc] ?? '') ) );
            if ( '' === $needle ) { continue; }
            foreach ( $values as $value ) {
                if ( $value === $needle || false !== strpos( $value, $needle ) ) { return $loc; }
            }
        }
        // Als YITH de plaatsnaam zelf opslaat kunnen we die zonder extra configuratie herkennen.
        foreach ( $this->stock_locations() as $loc => $label ) {
            foreach ( $values as $value ) {
                if ( false !== strpos( $value, $loc ) ) { return $loc; }
            }
        }
        // Onbekende/extra kassa: eerst locatie laten kiezen, dus nog niets afboeken.
        return '';
    }

    private function is_stock_sale_order( $order ) {
        if ( ! $order || in_array( $order->get_status(), array('cancelled','refunded','failed','trash'), true ) ) { return false; }
        if ( $this->is_yith_pos_order( $order ) ) {
            return 'completed' === $order->get_status() || $this->is_pos_bank_transfer_sale( $order ) || $this->is_pos_open_invoice_sale( $order );
        }
        // Webshopvoorraad wordt bij verwerking/afronding uit Baarn gehaald.
        return in_array( $order->get_status(), array('processing','completed'), true );
    }

    public function maybe_process_stock_order( $order_id, $order = null ) {
        if ( ! class_exists('WooCommerce') ) { return; }
        $order = $order instanceof WC_Order ? $order : wc_get_order( (int)$order_id );
        if ( ! $order || ! $this->is_stock_sale_order( $order ) ) { return; }

        // v1.7.0 start bewust met de door de gebruiker ingevoerde beginvoorraad.
        // Oude orders mogen bij een latere bewerking dus nooit ineens worden afgeboekt.
        $started_at = (int) get_option( 'sbp_location_stock_started_at', 0 );
        if ( ! $started_at ) {
            $started_at = current_time('timestamp');
            update_option( 'sbp_location_stock_started_at', $started_at, false );
        }
        $created = $order->get_date_created();
        if ( $created && $created->getTimestamp() < ( $started_at - 300 ) ) { return; }

        if ( $order->get_meta('_sbp_location_stock_processed') ) { return; }

        $lines = array();
        foreach ( $order->get_items('line_item') as $item ) {
            if ( $this->is_consult_order_item( $item ) ) { continue; }
            $pid = $this->tracked_product_id_for_item( $item );
            if ( ! $pid ) { continue; }
            $qty = max( 0, (float)$item->get_quantity() );
            if ( $qty <= 0 ) { continue; }
            if ( ! isset($lines[$pid]) ) { $lines[$pid] = 0; }
            $lines[$pid] += $qty;
        }
        if ( empty($lines) ) { return; }

        $location = $this->detect_stock_order_location( $order );
        if ( '' === $location ) {
            $order->update_meta_data( '_sbp_location_stock_pending', 'yes' );
            $order->save_meta_data();
            return;
        }

        $processed_lines = array();
        foreach ( $lines as $pid => $qty ) {
            if ( $this->adjust_location_stock( $pid, $location, -$qty, 'sale', $order->get_id(), 'Automatische afboeking verkoop' ) ) {
                $product = wc_get_product( $pid );
                $processed_lines[] = array(
                    'product_id' => (int)$pid, 'qty' => (float)$qty,
                    'sku' => $product ? (string)$product->get_sku() : '',
                    'name' => $product ? (string)$product->get_name() : '',
                );
            }
        }
        if ( $processed_lines ) {
            $order->update_meta_data( '_sbp_location_stock_processed', array(
                'location' => $location,
                'processed_at' => current_time('mysql'),
                'lines' => $processed_lines,
            ) );
            $order->delete_meta_data( '_sbp_location_stock_pending' );
            $order->delete_meta_data( '_sbp_location_stock_restored' );
            $order->save_meta_data();
        }
    }

    public function handle_stock_order_status_change( $order_id, $old_status, $new_status, $order ) {
        $order = $order instanceof WC_Order ? $order : wc_get_order( (int)$order_id );
        if ( ! $order ) { return; }
        if ( in_array( $new_status, array('cancelled','refunded','failed'), true ) ) {
            $this->restore_stock_order( $order );
            return;
        }
        // Wordt een eerder geannuleerde/teruggeboekte order alsnog actief, dan moet
        // dezelfde voorraad opnieuw worden afgeboekt. Wis daarvoor de oude verwerking.
        if ( $order->get_meta('_sbp_location_stock_restored') ) {
            $order->delete_meta_data('_sbp_location_stock_processed');
            $order->delete_meta_data('_sbp_location_stock_restored');
            $order->save_meta_data();
        }
        $this->maybe_process_stock_order( $order_id, $order );
    }

    private function restore_stock_order( $order ) {
        $processed = $order->get_meta('_sbp_location_stock_processed');
        if ( ! is_array($processed) || empty($processed['lines']) || $order->get_meta('_sbp_location_stock_restored') ) { return; }
        $location = sanitize_key( (string)($processed['location'] ?? '') );
        if ( ! isset($this->stock_locations()[$location]) ) { return; }
        foreach ( (array)$processed['lines'] as $line ) {
            $pid = (int)($line['product_id'] ?? 0); $qty = (float)($line['qty'] ?? 0);
            if ( $pid && $qty > 0 ) { $this->adjust_location_stock( $pid, $location, $qty, 'return', $order->get_id(), 'Terugboeking na annulering/refund' ); }
        }
        $order->update_meta_data( '_sbp_location_stock_restored', current_time('mysql') );
        $order->save_meta_data();
    }

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

    public function pending_stock_location_notice() {
        if ( ! current_user_can('manage_woocommerce') || ! class_exists('WooCommerce') ) { return; }
        $pending = $this->pending_stock_orders();
        if ( empty($pending) ) { return; }
        $url = admin_url('admin.php?page=schreuder-bonusan-stock#sbp-pending-orders');
        echo '<div class="notice notice-warning"><p><strong>Schreuder locatievoorraad:</strong> ' . esc_html( count($pending) ) . ' kassaverkoop/verkoop heeft nog geen locatie. <a href="' . esc_url($url) . '">Kies Baarn, Haarlem of Zwolle</a> zodat de voorraad correct wordt afgeboekt.</p></div>';
    }

    public function stock_level_notice() {
        if ( ! current_user_can('manage_woocommerce') || ! class_exists('WooCommerce') ) { return; }
        $count = 0;
        foreach ( $this->get_tracked_products() as $id => $product ) {
            $s = $this->get_stock_state($id);
            if ( ($s['baarn_target'] > 0 && $s['baarn'] <= $s['baarn_min']) ||
                 ($s['haarlem_target'] > 0 && $s['haarlem'] <= $s['haarlem_min']) ||
                 ($s['zwolle_target'] > 0 && $s['zwolle'] <= $s['zwolle_min']) ) { $count++; }
        }
        if ( !$count ) { return; }
        $url = admin_url('admin.php?page=schreuder-bonusan-stock');
        echo '<div class="notice notice-warning"><p><strong>Schreuder voorraad:</strong> bij ' . esc_html($count) . ' product(en) is een ingestelde minimumvoorraad bereikt. <a href="' . esc_url($url) . '">Bekijk het aanvuladvies</a>.</p></div>';
    }

    private function stock_sales_velocity( $product_id, $location, $days ) {
        $cutoff = current_time('timestamp') - ( max(1,(int)$days) * DAY_IN_SECONDS );
        $total = 0.0;
        foreach ( $this->stock_log_entries() as $entry ) {
            if ( (int)($entry['product_id'] ?? 0) !== (int)$product_id ) { continue; }
            if ( ($entry['location'] ?? '') !== $location || ($entry['reason'] ?? '') !== 'sale' ) { continue; }
            $ts = strtotime( (string)($entry['time'] ?? '') );
            if ( $ts && $ts >= $cutoff ) { $total += abs( min(0,(float)($entry['delta'] ?? 0)) ); }
        }
        return $total;
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

    private function stock_advice_for_product( $product_id ) {
        $product = wc_get_product( (int)$product_id );
        if ( ! $product ) { return null; }
        $s = $this->get_stock_state( $product_id );
        $h_need = ( $s['haarlem_target'] > 0 && $s['haarlem'] <= $s['haarlem_min'] ) ? max( 0, $s['haarlem_target'] - $s['haarlem'] ) : 0;
        $z_need = ( $s['zwolle_target'] > 0 && $s['zwolle'] <= $s['zwolle_min'] ) ? max( 0, $s['zwolle_target'] - $s['zwolle'] ) : 0;
        $baarn_after_transfers = $s['baarn'] - $h_need - $z_need;
        $external_need = ( $s['baarn_target'] > 0 && $baarn_after_transfers <= $s['baarn_min'] ) ? max( 0, $s['baarn_target'] - $baarn_after_transfers ) : 0;
        $velocity = array(); $smart = array();
        foreach ( $this->stock_locations() as $loc => $label ) {
            $v30 = $this->stock_sales_velocity( $product_id, $loc, 30 );
            $v90 = $this->stock_sales_velocity( $product_id, $loc, 90 ) / 3;
            $velocity[$loc] = array( 'd30'=>$v30, 'm90'=>$v90 );
            $smart[$loc] = max( (float)$s[$loc.'_target'], ceil( max($v30,$v90) * 1.25 ), (float)$s[$loc.'_min'] );
        }
        return array_merge( $s, array(
            'name' => $product->get_name(), 'sku' => $product->get_sku(),
            'to_haarlem' => $h_need, 'to_zwolle' => $z_need,
            'baarn_after_transfers' => $baarn_after_transfers,
            'external_need' => $external_need,
            'route' => $this->is_any_turflist_product($product) ? 'bonusan' : 'planner',
            'route_label' => $this->is_any_turflist_product($product) ? 'Bonusan-turflijst' : 'Planner/overige leverancier',
            'velocity' => $velocity, 'smart_target' => $smart,
        ) );
    }

    private function get_stock_advice_rows( $alerts_only = false ) {
        $rows = array();
        foreach ( $this->get_tracked_products() as $id => $product ) {
            $row = $this->stock_advice_for_product( $id );
            if ( ! $row ) { continue; }
            if ( $alerts_only && $row['to_haarlem'] <= 0 && $row['to_zwolle'] <= 0 && $row['external_need'] <= 0 ) { continue; }
            $rows[] = $row;
        }
        return $rows;
    }

    private function get_stock_preview_advice( $location ) {
        $rows = array();
        foreach ( $this->get_stock_advice_rows(true) as $row ) {
            $rows[] = array(
                'product_id' => $row['product_id'], 'name' => $row['name'], 'sku' => $row['sku'],
                'baarn' => $row['baarn'], 'haarlem' => $row['haarlem'], 'zwolle' => $row['zwolle'],
                'to_haarlem' => $row['to_haarlem'], 'to_zwolle' => $row['to_zwolle'],
                'external_need' => $row['external_need'], 'route' => $row['route'], 'route_label' => $row['route_label'],
            );
        }
        return $rows;
    }

    public function render_stock_page() {
        if ( ! current_user_can('manage_woocommerce') ) { return; }
        $products = $this->get_tracked_products();
        $advice = $this->get_stock_advice_rows(false);
        $pending = $this->pending_stock_orders();
        $log = $this->stock_log_entries();
        $nonce = wp_create_nonce(self::NONCE);
        ?>
        <div class="wrap sbp-stock-wrap">
            <h1>Locatievoorraad</h1>
            <p><strong>Baarn is de hoofdvoorraad</strong> voor de producten die je hier volgt. Haarlem en Zwolle hebben een kleinere werkvoorraad. Gewone webshopverkopen worden standaard van Baarn afgeboekt. Vaste POS-locaties worden automatisch herkend wanneer het YITH locatiekenmerk is ingesteld; een onbekende/extra kassa komt hieronder te staan totdat je de locatie kiest.</p>
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

            <div class="sbp-stock-card">
                <h2>Product aan locatievoorraad toevoegen</h2>
                <p class="description">Gebruik dit voor kassaproducten / producten waarvoor Baarn de hoofdvoorraad is. Je kunt ieder WooCommerce-product selecteren; de gewone Bonusan-producten hoeven niet per se gevolgd te worden.</p>
                <div class="sbp-stock-search-wrap"><input type="search" id="sbp-stock-search" class="regular-text" placeholder="Zoek product op naam of SKU…" autocomplete="off"><div id="sbp-stock-search-results" class="sbp-stock-search-results" style="display:none"></div></div>
            </div>

            <div class="sbp-stock-card">
                <h2>Voorraad en grenswaarden</h2>
                <?php if ( empty($products) ) : ?>
                    <p>Nog geen producten geselecteerd voor locatievoorraad.</p>
                <?php else : ?>
                <form id="sbp-stock-form">
                    <div class="sbp-stock-scroll"><table class="widefat striped sbp-stock-table"><thead><tr><th rowspan="2">Product</th><th colspan="3">Baarn – hoofdvoorraad</th><th colspan="3">Haarlem</th><th colspan="3">Zwolle</th><th rowspan="2"></th></tr><tr><th>Nu</th><th>Min.</th><th>Gewenst</th><th>Nu</th><th>Min.</th><th>Gewenst</th><th>Nu</th><th>Min.</th><th>Gewenst</th></tr></thead><tbody>
                    <?php foreach ( $products as $id => $product ) : $st=$this->get_stock_state($id); ?>
                    <tr data-product-id="<?php echo esc_attr($id); ?>"><td><strong><?php echo esc_html($product->get_name()); ?></strong><br><small>SKU <?php echo esc_html($product->get_sku() ?: '—'); ?></small></td>
                    <?php foreach(array('baarn','haarlem','zwolle') as $loc): ?>
                        <td><input class="small-text sbp-stock-number" type="number" step="1" name="rows[<?php echo esc_attr($id); ?>][<?php echo esc_attr($loc); ?>][stock]" value="<?php echo esc_attr($st[$loc]); ?>"></td>
                        <td><input class="small-text sbp-stock-number" type="number" min="0" step="1" name="rows[<?php echo esc_attr($id); ?>][<?php echo esc_attr($loc); ?>][min]" value="<?php echo esc_attr($st[$loc.'_min']); ?>"></td>
                        <td><input class="small-text sbp-stock-number" type="number" min="0" step="1" name="rows[<?php echo esc_attr($id); ?>][<?php echo esc_attr($loc); ?>][target]" value="<?php echo esc_attr($st[$loc.'_target']); ?>"></td>
                    <?php endforeach; ?>
                    <td><button type="button" class="button-link-delete sbp-stock-remove">Niet meer volgen</button></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <p><button type="submit" class="button button-primary">Voorraadinstellingen opslaan</button> <span id="sbp-stock-save-status"></span></p>
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

            <div class="sbp-stock-card">
                <h2>Aanvuladvies</h2>
                <p class="description">Haarlem/Zwolle worden eerst tot hun gewenste voorraad vanuit Baarn aangevuld zodra ze op/onder minimum komen. Daarna controleert de plugin of Baarn zelf onder het minimum komt. De slimme doelvoorraad gebruikt de geregistreerde verkopen van 30 en 90 dagen als signaal, maar verandert niets automatisch.</p>
                <?php if(empty($advice)): ?><p>Nog geen producten.</p><?php else: ?>
                <div class="sbp-stock-scroll"><table class="widefat striped"><thead><tr><th>Product</th><th>Baarn</th><th>Haarlem</th><th>Zwolle</th><th>Naar Haarlem</th><th>Naar Zwolle</th><th>Extern aanvullen</th><th>Route</th><th>Verkoop 30d B/H/Z</th><th>Slim gewenst B/H/Z</th></tr></thead><tbody>
                <?php foreach($advice as $a): ?>
                    <tr class="<?php echo ($a['external_need']>0?'sbp-stock-danger':(($a['to_haarlem']>0||$a['to_zwolle']>0)?'sbp-stock-warn':'')); ?>"><td><strong><?php echo esc_html($a['name']); ?></strong><br><small><?php echo esc_html($a['sku'] ?: '—'); ?></small></td><td><?php echo esc_html($a['baarn']); ?></td><td><?php echo esc_html($a['haarlem']); ?></td><td><?php echo esc_html($a['zwolle']); ?></td><td><?php echo esc_html($a['to_haarlem']); ?></td><td><?php echo esc_html($a['to_zwolle']); ?></td><td><strong><?php echo esc_html($a['external_need']); ?></strong></td><td><?php echo esc_html($a['route_label']); ?></td><td><?php echo esc_html(round($a['velocity']['baarn']['d30'],1).'/'.round($a['velocity']['haarlem']['d30'],1).'/'.round($a['velocity']['zwolle']['d30'],1)); ?></td><td><?php echo esc_html($a['smart_target']['baarn'].'/'.$a['smart_target']['haarlem'].'/'.$a['smart_target']['zwolle']); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div><?php endif; ?>
            </div>

            <div class="sbp-stock-card"><h2>Voorraadlogboek</h2>
                <?php if(empty($log)): ?><p>Nog geen voorraadmutaties.</p><?php else: ?><table class="widefat striped"><thead><tr><th>Datum</th><th>Product</th><th>Locatie</th><th>Mutatie</th><th>Voor → na</th><th>Reden</th><th>Order</th><th>Notitie</th></tr></thead><tbody>
                <?php foreach(array_slice($log,0,100) as $e): ?><tr><td><?php echo esc_html($e['time']??''); ?></td><td><?php echo esc_html($e['name']??''); ?><br><small><?php echo esc_html($e['sku']??''); ?></small></td><td><?php echo esc_html(ucfirst($e['location']??'')); ?></td><td><?php echo esc_html(((float)($e['delta']??0)>0?'+':'').($e['delta']??0)); ?></td><td><?php echo esc_html(($e['before']??0).' → '.($e['after']??0)); ?></td><td><?php echo esc_html($e['reason']??''); ?></td><td><?php echo !empty($e['order_id']) ? '<a href="'.esc_url(admin_url('post.php?post='.(int)$e['order_id'].'&action=edit')).'">#'.esc_html($e['order_id']).'</a>' : '—'; ?></td><td><?php echo esc_html($e['note']??''); ?></td></tr><?php endforeach; ?>
                </tbody></table><?php endif; ?>
            </div>
        </div>
        <style>
        .sbp-stock-card{background:#fff;border:1px solid #c3c4c7;padding:16px 18px;margin:16px 0;max-width:1450px}.sbp-stock-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:16px;max-width:1450px}.sbp-stock-grid .sbp-stock-card{margin:0}.sbp-stock-scroll{overflow-x:auto}.sbp-stock-table th{text-align:center}.sbp-stock-table td:first-child{min-width:220px}.sbp-stock-number{width:70px}.sbp-stock-search-wrap{position:relative;max-width:650px}.sbp-stock-search-results{position:absolute;z-index:50;background:#fff;border:1px solid #8c8f94;left:0;right:0;max-height:280px;overflow:auto}.sbp-stock-search-item{width:100%;display:block;text-align:left;border:0;border-bottom:1px solid #eee;background:#fff;padding:9px;cursor:pointer}.sbp-stock-search-item:hover{background:#f0f6fc}.sbp-stock-danger td{background:#fff1f0}.sbp-stock-warn td{background:#fff8e5}
        </style>
        <script>
        jQuery(function($){
            const nonce=<?php echo wp_json_encode($nonce); ?>; let timer=null;
            function post(action,data){data=data||{};data.action=action;data._ajax_nonce=nonce;return $.post(ajaxurl,data);}
            function esc(s){return $('<div>').text(s==null?'':s).html();}
            $('#sbp-stock-search').on('input',function(){let term=$(this).val().trim(),box=$('#sbp-stock-search-results');clearTimeout(timer);if(term.length<2){box.hide().empty();return;}timer=setTimeout(function(){post('sbp_stock_product_search',{term:term}).done(function(r){if(!r.success||!r.data.results.length){box.html('<div style="padding:9px">Geen product gevonden.</div>').show();return;}let h='';r.data.results.forEach(function(x){h+='<button type="button" class="sbp-stock-search-item" data-id="'+x.id+'"><strong>'+esc(x.name)+'</strong><br><small>SKU '+esc(x.sku||'—')+(x.tracked?' · al toegevoegd':'')+'</small></button>';});box.html(h).show();});},250);});
            $(document).on('click','.sbp-stock-search-item',function(){let b=$(this);post('sbp_stock_add_product',{product_id:b.data('id')}).done(function(r){if(r.success)location.reload();else alert(r.data.message||'Toevoegen mislukt.');});});
            $('#sbp-stock-form').on('submit',function(e){e.preventDefault();let data=$(this).serializeArray(),payload={};data.forEach(function(x){payload[x.name]=x.value;});let btn=$(this).find('button[type=submit]').prop('disabled',true);post('sbp_stock_save',payload).done(function(r){if(r.success){$('#sbp-stock-save-status').text('Opgeslagen');setTimeout(function(){location.reload();},500);}else alert(r.data.message||'Opslaan mislukt.');}).always(function(){btn.prop('disabled',false);});});
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
        $results=array(); foreach($ids as $id){$product=wc_get_product((int)$id);if(!$product)continue;$results[]=array('id'=>(int)$id,'name'=>$product->get_name(),'sku'=>$product->get_sku(),'tracked'=>$this->is_stock_tracked_product_id($id));}
        wp_send_json_success(array('results'=>$results));
    }

    public function ajax_stock_add_product() {
        $this->guard(); $id=absint($_POST['product_id']??0); $product=wc_get_product($id);
        if(!$product){wp_send_json_error(array('message'=>'Product niet gevonden.'),404);}
        update_post_meta($id,'_sbp_location_stock_enabled','yes');
        foreach($this->stock_locations() as $loc=>$label){foreach(array('stock','min','target') as $type){$key=$this->stock_meta_key($type,$loc);if(!metadata_exists('post',$id,$key)){update_post_meta($id,$key,0);}}}
        wp_send_json_success(array('message'=>'Product toegevoegd aan locatievoorraad.'));
    }

    public function ajax_stock_save() {
        $this->guard(); $rows=isset($_POST['rows'])&&is_array($_POST['rows'])?wp_unslash($_POST['rows']):array();
        foreach($rows as $id=>$locs){$id=absint($id);if(!$id||!$this->is_stock_tracked_product_id($id)||!is_array($locs))continue;foreach($this->stock_locations() as $loc=>$label){$vals=$locs[$loc]??array();foreach(array('stock','min','target') as $type){$raw=str_replace(',','.',sanitize_text_field((string)($vals[$type]??0)));if(!is_numeric($raw))continue;$value=(float)$raw;if('stock'!==$type)$value=max(0,$value);if('stock'===$type){$old=$this->stock_number($id,'stock',$loc);$delta=$value-$old;if(abs($delta)>0.00001){$this->adjust_location_stock($id,$loc,$delta,'count_correction',0,'Voorraad handmatig aangepast in locatieoverzicht');}}else{$this->set_stock_number($id,$type,$loc,$value);}}}}
        wp_send_json_success(array('message'=>'Voorraadinstellingen opgeslagen.'));
    }

    public function ajax_stock_remove_product() {
        $this->guard(); $id=absint($_POST['product_id']??0); if(!$id){wp_send_json_error(array('message'=>'Ongeldig product.'),400);} delete_post_meta($id,'_sbp_location_stock_enabled'); wp_send_json_success(array('message'=>'Product wordt niet meer gevolgd.'));
    }

    public function ajax_stock_adjust() {
        $this->guard(); $id=absint($_POST['product_id']??0);$loc=sanitize_key($_POST['location']??'');$raw=str_replace(',','.',sanitize_text_field((string)($_POST['delta']??'')));$note=sanitize_text_field(wp_unslash($_POST['note']??''));
        if(!$id||!$this->is_stock_tracked_product_id($id)||!isset($this->stock_locations()[$loc])||!is_numeric($raw)||(float)$raw==0){wp_send_json_error(array('message'=>'Controleer product, locatie en aantal.'),400);} $delta=(float)$raw;
        $this->adjust_location_stock($id,$loc,$delta,$delta>0?'manual_in':'manual_out',0,$note?:'Handmatige voorraadcorrectie'); wp_send_json_success(array('message'=>'Voorraad aangepast.'));
    }

    public function ajax_stock_transfer() {
        $this->guard();$id=absint($_POST['product_id']??0);$from=sanitize_key($_POST['from']??'');$to=sanitize_key($_POST['to']??'');$raw=str_replace(',','.',sanitize_text_field((string)($_POST['qty']??'')));
        if(!$id||!$this->is_stock_tracked_product_id($id)||$from===$to||!isset($this->stock_locations()[$from])||!isset($this->stock_locations()[$to])||!is_numeric($raw)||(float)$raw<=0){wp_send_json_error(array('message'=>'Controleer product, locaties en aantal.'),400);} $qty=(float)$raw;$available=$this->stock_number($id,'stock',$from);if($qty>$available){wp_send_json_error(array('message'=>'Niet genoeg voorraad in '.ucfirst($from).'. Huidig: '.$available),400);}
        $note='Interne transfer '.ucfirst($from).' → '.ucfirst($to);$this->adjust_location_stock($id,$from,-$qty,'transfer_out',0,$note);$this->adjust_location_stock($id,$to,$qty,'transfer_in',0,$note);wp_send_json_success(array('message'=>$qty.' stuks overgeboekt van '.ucfirst($from).' naar '.ucfirst($to).'.'));
    }

    public function ajax_assign_order_location() {
        $this->guard();$order=wc_get_order(absint($_POST['order_id']??0));$loc=sanitize_key($_POST['location']??'');if(!$order||!isset($this->stock_locations()[$loc])){wp_send_json_error(array('message'=>'Ongeldige order of locatie.'),400);}
        $order->update_meta_data('_sbp_location_stock_location',$loc);$order->delete_meta_data('_sbp_location_stock_pending');$order->save_meta_data();$this->maybe_process_stock_order($order->get_id(),$order);wp_send_json_success(array('message'=>'Locatie opgeslagen en voorraad verwerkt.'));
    }

    public function maybe_prepare_after_close() {
        // YITH's internal close hooks differ by version. We deliberately do not auto-send without a visible final check.
        set_transient( 'sbp_register_recently_closed', current_time('mysql'), HOUR_IN_SECONDS );
    }
}

add_action( 'plugins_loaded', array( 'Schreuder_Bonusan_POS', 'instance' ) );
