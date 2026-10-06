<?php
/**
 * Locatievoorraad-grootboek (v1.8.0).
 *
 * Alle voorraadmutaties lopen via deze klasse. Iedere mutatie is één database-transactie
 * waarin de voorraadrij wordt vergrendeld (SELECT ... FOR UPDATE), het nieuwe saldo wordt
 * geschreven en de logregel wordt vastgelegd. Daardoor kunnen gelijktijdige kassaverkopen
 * elkaar niet overschrijven en kan het saldo nooit afwijken van het logboek.
 *
 * Orderboekingen zijn idempotent: per order en product wordt de gewenste hoeveelheid
 * vergeleken met wat al in het grootboek staat (kolom order_qty). Alleen het verschil wordt
 * geboekt. Dezelfde order meerdere keren verwerken geeft dus nooit een dubbele boeking.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SBP_Ledger_Exception extends Exception {
    public $code_name;
    public function __construct( $code_name, $message = '' ) {
        parent::__construct( '' !== $message ? $message : $code_name );
        $this->code_name = $code_name;
    }
}

class SBP_Ledger_Retry extends Exception {}

final class SBP_Ledger {
    const DB_VERSION = '1.9.0';
    /** Markering (kolom transfer_id) op regels die uit het oude optie-logboek zijn overgenomen. */
    const LEGACY_TAG = 'legacy-1.7.0';
    /** Redenen die meetellen voor "hoeveel van deze order is geboekt". */
    const ORDER_REASONS = array( 'sale', 'sale_edit', 'return', 'return_partial', 'legacy_import' );
    /** Redenen die meetellen voor het verkooptempo (netto verkocht). */
    const SALE_REASONS = array( 'sale', 'sale_edit', 'return', 'return_partial' );

    private static $seed = null;

    /** Callback( product_id, location ) => float: beginsaldo uit het oude meta-veld (v1.7.0). */
    public static function set_seed_provider( $callback ) { self::$seed = $callback; }

    public static function stock_table() { global $wpdb; return $wpdb->prefix . 'sbp_stock'; }
    public static function ledger_table() { global $wpdb; return $wpdb->prefix . 'sbp_stock_ledger'; }

    public static function inbound_table() { global $wpdb; return $wpdb->prefix . 'sbp_inbound'; }

    public static function schema_statements() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $stock = self::stock_table();
        $ledger = self::ledger_table();
        $inbound = self::inbound_table();
        return array(
            "CREATE TABLE $inbound (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  shipment varchar(40) NOT NULL,
  created_gmt datetime NOT NULL,
  closed_gmt datetime NULL,
  location varchar(20) NOT NULL,
  product_id bigint(20) unsigned NOT NULL,
  sku varchar(100) NOT NULL DEFAULT '',
  product_name varchar(255) NOT NULL DEFAULT '',
  qty_ordered decimal(14,3) NOT NULL,
  qty_received decimal(14,3) NOT NULL DEFAULT 0,
  qty_cancelled decimal(14,3) NOT NULL DEFAULT 0,
  subject varchar(255) NOT NULL DEFAULT '',
  note text NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY shipment (shipment),
  KEY open_lines (closed_gmt,location,product_id)
) $charset",
            "CREATE TABLE $stock (
  product_id bigint(20) unsigned NOT NULL,
  location varchar(20) NOT NULL,
  qty decimal(14,3) NOT NULL DEFAULT 0,
  updated_gmt datetime NOT NULL,
  PRIMARY KEY  (product_id,location)
) $charset",
            "CREATE TABLE $ledger (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_gmt datetime NOT NULL,
  product_id bigint(20) unsigned NOT NULL,
  sku varchar(100) NOT NULL DEFAULT '',
  product_name varchar(255) NOT NULL DEFAULT '',
  location varchar(20) NOT NULL,
  delta decimal(14,3) NOT NULL,
  before_qty decimal(14,3) NOT NULL,
  after_qty decimal(14,3) NOT NULL,
  order_qty decimal(14,3) NOT NULL DEFAULT 0,
  reason varchar(30) NOT NULL,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  transfer_id varchar(40) NOT NULL DEFAULT '',
  note text NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY product_loc_time (product_id,location,created_gmt),
  KEY order_product (order_id,product_id),
  KEY created_gmt (created_gmt)
) $charset",
        );
    }

    public static function install() {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach ( self::schema_statements() as $sql ) { dbDelta( $sql ); }
    }

    /* ---------- lage laag ---------- */

    private static function q( $sql ) {
        global $wpdb;
        $res = $wpdb->query( $sql );
        if ( false === $res ) {
            $err = (string) $wpdb->last_error;
            if ( false !== stripos( $err, 'deadlock' ) || false !== stripos( $err, 'lock wait timeout' ) ) {
                throw new SBP_Ledger_Retry( $err );
            }
            throw new SBP_Ledger_Exception( 'db', $err );
        }
        return $res;
    }

    /** Voert $fn uit binnen een transactie, met een paar pogingen bij deadlocks. */
    private static function tx( $fn ) {
        global $wpdb;
        $suppress = $wpdb->suppress_errors( true );
        try {
            for ( $attempt = 1; ; $attempt++ ) {
                $wpdb->query( 'START TRANSACTION' );
                try {
                    $out = $fn();
                    self::q( 'COMMIT' );
                    return $out;
                } catch ( SBP_Ledger_Retry $e ) {
                    $wpdb->query( 'ROLLBACK' );
                    if ( $attempt >= 8 ) { throw new SBP_Ledger_Exception( 'busy', $e->getMessage() ); }
                    usleep( random_int( 15000, 60000 ) * $attempt );
                } catch ( Throwable $e ) {
                    $wpdb->query( 'ROLLBACK' );
                    throw $e;
                }
            }
        } finally {
            $wpdb->suppress_errors( $suppress );
        }
    }

    /**
     * Vergrendelt de voorraadrij en geeft het huidige saldo. Bestaat de rij nog niet, dan wordt hij
     * aangemaakt met het oude beginsaldo (v1.7.0). Eerst lezen, pas daarna invoegen: dat voorkomt
     * de meeste deadlocks bij veel gelijktijdige boekingen.
     */
    private static function lock_row( $product_id, $location ) {
        global $wpdb;
        $t = self::stock_table();
        $sql = $wpdb->prepare( "SELECT qty FROM $t WHERE product_id = %d AND location = %s FOR UPDATE", $product_id, $location );
        $v = $wpdb->get_var( $sql );
        if ( null === $v && '' !== (string) $wpdb->last_error ) { throw new SBP_Ledger_Retry( (string) $wpdb->last_error ); }
        if ( null === $v ) {
            $seed = self::$seed ? (float) call_user_func( self::$seed, (int) $product_id, $location ) : 0.0;
            self::q( $wpdb->prepare( "INSERT IGNORE INTO $t (product_id, location, qty, updated_gmt) VALUES (%d, %s, %f, %s)", $product_id, $location, $seed, gmdate( 'Y-m-d H:i:s' ) ) );
            $v = $wpdb->get_var( $sql );
            if ( null === $v ) {
                if ( '' !== (string) $wpdb->last_error ) { throw new SBP_Ledger_Retry( (string) $wpdb->last_error ); }
                throw new SBP_Ledger_Exception( 'db', 'Voorraadrij niet gevonden.' );
            }
        }
        return round( (float) $v, 3 );
    }

    private static function write_qty( $product_id, $location, $qty ) {
        global $wpdb;
        $t = self::stock_table();
        self::q( $wpdb->prepare( "UPDATE $t SET qty = %f, updated_gmt = %s WHERE product_id = %d AND location = %s", round( $qty, 3 ), gmdate( 'Y-m-d H:i:s' ), $product_id, $location ) );
    }

    private static function insert_ledger( $r ) {
        global $wpdb;
        $t = self::ledger_table();
        $r = array_merge( array(
            'created_gmt' => gmdate( 'Y-m-d H:i:s' ), 'product_id' => 0, 'sku' => '', 'product_name' => '', 'location' => '',
            'delta' => 0, 'before_qty' => 0, 'after_qty' => 0, 'order_qty' => 0, 'reason' => '', 'order_id' => 0,
            'transfer_id' => '', 'note' => '', 'user_id' => 0,
        ), $r );
        self::q( $wpdb->prepare(
            "INSERT INTO $t (created_gmt, product_id, sku, product_name, location, delta, before_qty, after_qty, order_qty, reason, order_id, transfer_id, note, user_id)
             VALUES (%s, %d, %s, %s, %s, %f, %f, %f, %f, %s, %d, %s, %s, %d)",
            $r['created_gmt'], $r['product_id'], substr( (string) $r['sku'], 0, 100 ), substr( (string) $r['product_name'], 0, 255 ), $r['location'],
            round( (float) $r['delta'], 3 ), round( (float) $r['before_qty'], 3 ), round( (float) $r['after_qty'], 3 ), round( (float) $r['order_qty'], 3 ),
            $r['reason'], $r['order_id'], $r['transfer_id'], (string) $r['note'], $r['user_id']
        ) );
    }

    /* ---------- lezen ---------- */

    public static function quantity( $product_id, $location ) {
        global $wpdb;
        $t = self::stock_table();
        $v = $wpdb->get_var( $wpdb->prepare( "SELECT qty FROM $t WHERE product_id = %d AND location = %s", $product_id, $location ) );
        if ( null !== $v ) { return (float) $v; }
        return self::$seed ? (float) call_user_func( self::$seed, (int) $product_id, $location ) : 0.0;
    }

    /** @return array product_id => location => saldo (ontbrekende rijen vallen terug op het oude beginsaldo) */
    public static function quantities( array $product_ids, array $locations ) {
        global $wpdb;
        $t = self::stock_table();
        $ids = array_values( array_unique( array_map( 'intval', $product_ids ) ) );
        $out = array();
        if ( $ids ) {
            $rows = $wpdb->get_results( "SELECT product_id, location, qty FROM $t WHERE product_id IN (" . implode( ',', $ids ) . ')', ARRAY_A );
            foreach ( (array) $rows as $row ) { $out[(int)$row['product_id']][$row['location']] = (float) $row['qty']; }
        }
        foreach ( $ids as $id ) {
            foreach ( $locations as $loc ) {
                if ( ! isset( $out[$id][$loc] ) ) {
                    $out[$id][$loc] = self::$seed ? (float) call_user_func( self::$seed, $id, $loc ) : 0.0;
                }
            }
        }
        return $out;
    }

    /** Wat is er voor deze order geboekt? product_id => array( 'qty' => netto verkocht, 'location' => ... ) */
    public static function order_bookings( $order_id ) {
        global $wpdb;
        $t = self::ledger_table();
        $in = "'" . implode( "','", self::ORDER_REASONS ) . "'";
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT product_id, location, SUM(order_qty) AS q FROM $t WHERE order_id = %d AND reason IN ($in) GROUP BY product_id, location", $order_id ), ARRAY_A );
        $out = array();
        foreach ( (array) $rows as $row ) { $out[(int)$row['product_id']] = array( 'qty' => round( (float) $row['q'], 3 ), 'location' => (string) $row['location'] ); }
        return $out;
    }

    /** Netto verkocht (verkopen min retouren) over 30 en 90 dagen. product_id => location => array( d30, d90 ) */
    public static function sales_velocity( array $product_ids ) {
        global $wpdb;
        $t = self::ledger_table();
        $ids = array_values( array_unique( array_map( 'intval', $product_ids ) ) );
        $out = array();
        if ( ! $ids ) { return $out; }
        $in = "'" . implode( "','", self::SALE_REASONS ) . "'";
        $d30 = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
        $d90 = gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT product_id, location, SUM(CASE WHEN created_gmt >= %s THEN order_qty ELSE 0 END) AS d30, SUM(order_qty) AS d90
             FROM $t WHERE reason IN ($in) AND created_gmt >= %s AND product_id IN (" . implode( ',', $ids ) . ')
             GROUP BY product_id, location', $d30, $d90 ), ARRAY_A );
        foreach ( (array) $rows as $row ) {
            $out[(int)$row['product_id']][$row['location']] = array( 'd30' => max( 0.0, (float) $row['d30'] ), 'd90' => max( 0.0, (float) $row['d90'] ) );
        }
        return $out;
    }

    public static function log_rows( $product_id = 0, $limit = 200, $offset = 0 ) {
        global $wpdb;
        $t = self::ledger_table();
        $where = $product_id ? $wpdb->prepare( 'WHERE product_id = %d', $product_id ) : '';
        return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t $where ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A );
    }

    /* ---------- schrijven ---------- */

    /**
     * Brengt de boeking van één order/product in overeenstemming met de gewenste hoeveelheid.
     * $legacy_qty: wat v1.7.0 al voor deze order had afgeboekt (alleen relevant bij de eerste keer).
     * $allow_new: false = order is van vóór de start van de voorraadadministratie voor dit product;
     * er wordt dan niets nieuws geboekt (een bestaande boeking kan nog wel worden gecorrigeerd).
     */
    public static function reconcile_line( $order_id, $product_id, $location, $desired, $legacy_qty, $allow_new, $info ) {
        global $wpdb;
        return self::tx( function () use ( $wpdb, $order_id, $product_id, $location, $desired, $legacy_qty, $allow_new, $info ) {
            $t = self::ledger_table();
            $before = self::lock_row( $product_id, $location );
            $in = "'" . implode( "','", self::ORDER_REASONS ) . "'";
            // Gewone SELECT (geen FOR UPDATE): de zojuist vergrendelde voorraadrij zorgt dat boekingen voor
            // dit product/deze locatie na elkaar lopen, en deze lezing ziet dus alles wat eerder is vastgelegd.
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS c, COALESCE(SUM(order_qty),0) AS s FROM $t WHERE order_id = %d AND product_id = %d AND reason IN ($in)", $order_id, $product_id ), ARRAY_A );
            if ( null === $row ) { throw new SBP_Ledger_Retry( (string) $wpdb->last_error ); }
            $count = (int) $row['c'];
            $booked = round( (float) $row['s'], 3 );
            $base = array(
                'product_id' => $product_id, 'sku' => (string)( $info['sku'] ?? '' ), 'product_name' => (string)( $info['name'] ?? '' ),
                'location' => $location, 'order_id' => $order_id, 'user_id' => (int)( $info['user_id'] ?? 0 ),
            );
            if ( 0 === $count && $legacy_qty > 0 ) {
                // Verkoop die v1.7.0 al van de voorraad had afgeboekt: vastleggen zonder het saldo te wijzigen.
                self::insert_ledger( array_merge( $base, array( 'delta' => 0, 'before_qty' => $before, 'after_qty' => $before, 'order_qty' => $legacy_qty, 'reason' => 'legacy_import', 'note' => 'Boeking uit v1.7.0 overgenomen' ) ) );
                $booked = round( (float) $legacy_qty, 3 );
                $count = 1;
            }
            $desired = max( 0.0, round( (float) $desired, 3 ) );
            if ( 0 === $count && ! $allow_new ) { return array( 'status' => 'skipped', 'delta' => 0.0 ); }
            $diff = round( $desired - $booked, 3 );
            if ( abs( $diff ) < 0.0005 ) { return array( 'status' => 'unchanged', 'delta' => 0.0 ); }
            $after = round( $before - $diff, 3 );
            if ( $diff > 0 ) { $reason = $booked > 0.0005 ? 'sale_edit' : 'sale'; $note = $booked > 0.0005 ? 'Order gewijzigd (meer aantal)' : 'Automatische afboeking verkoop'; }
            else { $reason = $desired <= 0.0005 ? 'return' : 'return_partial'; $note = $desired <= 0.0005 ? 'Terugboeking: order geannuleerd/terugbetaald/verwijderd' : 'Terugboeking: order gewijzigd of gedeeltelijk terugbetaald'; }
            self::write_qty( $product_id, $location, $after );
            self::insert_ledger( array_merge( $base, array( 'delta' => -$diff, 'before_qty' => $before, 'after_qty' => $after, 'order_qty' => $diff, 'reason' => $reason, 'note' => $note ) ) );
            return array( 'status' => 'booked', 'delta' => -$diff, 'before' => $before, 'after' => $after );
        } );
    }

    /** Handmatige mutatie (levering, correctie, afschrijving). Mag standaard niet onder 0 komen. */
    public static function adjust( $product_id, $location, $delta, $reason, $note, $user_id, $info = array(), $allow_negative = false ) {
        return self::tx( function () use ( $product_id, $location, $delta, $reason, $note, $user_id, $info, $allow_negative ) {
            $before = self::lock_row( $product_id, $location );
            $after = round( $before + (float) $delta, 3 );
            if ( ! $allow_negative && $after < 0 ) { throw new SBP_Ledger_Exception( 'negative', 'De voorraad zou negatief worden (nu ' . $before . ').' ); }
            self::write_qty( $product_id, $location, $after );
            self::insert_ledger( array(
                'product_id' => $product_id, 'sku' => (string)( $info['sku'] ?? '' ), 'product_name' => (string)( $info['name'] ?? '' ), 'location' => $location,
                'delta' => (float) $delta, 'before_qty' => $before, 'after_qty' => $after, 'reason' => $reason, 'note' => $note, 'user_id' => $user_id,
            ) );
            return array( 'before' => $before, 'after' => $after );
        } );
    }

    /** Stelt de voorraad in op een geteld aantal, mits hij sinds het tonen niet is veranderd. */
    public static function set_absolute( $product_id, $location, $new_qty, $expected_qty, $note, $user_id, $info = array() ) {
        return self::tx( function () use ( $product_id, $location, $new_qty, $expected_qty, $note, $user_id, $info ) {
            $before = self::lock_row( $product_id, $location );
            if ( abs( $before - round( (float) $expected_qty, 3 ) ) > 0.0005 ) {
                throw new SBP_Ledger_Exception( 'conflict', 'De voorraad is intussen gewijzigd (nu ' . $before . ').' );
            }
            $new_qty = round( (float) $new_qty, 3 );
            $delta = round( $new_qty - $before, 3 );
            if ( abs( $delta ) < 0.0005 ) { return array( 'before' => $before, 'after' => $before, 'changed' => false ); }
            self::write_qty( $product_id, $location, $new_qty );
            self::insert_ledger( array(
                'product_id' => $product_id, 'sku' => (string)( $info['sku'] ?? '' ), 'product_name' => (string)( $info['name'] ?? '' ), 'location' => $location,
                'delta' => $delta, 'before_qty' => $before, 'after_qty' => $new_qty, 'reason' => 'count_correction', 'note' => $note, 'user_id' => $user_id,
            ) );
            return array( 'before' => $before, 'after' => $new_qty, 'changed' => true );
        } );
    }

    /** Interne transfer in één transactie; beide logregels delen een transfer_id. */
    public static function transfer( $product_id, $from, $to, $qty, $note, $user_id, $info = array() ) {
        return self::tx( function () use ( $product_id, $from, $to, $qty, $note, $user_id, $info ) {
            $qty = round( (float) $qty, 3 );
            if ( $qty <= 0 || $from === $to ) { throw new SBP_Ledger_Exception( 'invalid', 'Ongeldige transfer.' ); }
            // Altijd in dezelfde volgorde vergrendelen om onderlinge deadlocks te voorkomen.
            $order = array( $from, $to ); sort( $order );
            $before = array();
            foreach ( $order as $loc ) { $before[$loc] = self::lock_row( $product_id, $loc ); }
            if ( $before[$from] < $qty ) { throw new SBP_Ledger_Exception( 'insufficient', 'Niet genoeg voorraad in ' . ucfirst( $from ) . ' (nu ' . $before[$from] . ').' ); }
            $tid = 't' . bin2hex( random_bytes( 8 ) );
            $base = array( 'product_id' => $product_id, 'sku' => (string)( $info['sku'] ?? '' ), 'product_name' => (string)( $info['name'] ?? '' ), 'transfer_id' => $tid, 'note' => $note, 'user_id' => $user_id );
            $out_after = round( $before[$from] - $qty, 3 );
            $in_after = round( $before[$to] + $qty, 3 );
            self::write_qty( $product_id, $from, $out_after );
            self::write_qty( $product_id, $to, $in_after );
            self::insert_ledger( array_merge( $base, array( 'location' => $from, 'delta' => -$qty, 'before_qty' => $before[$from], 'after_qty' => $out_after, 'reason' => 'transfer_out' ) ) );
            self::insert_ledger( array_merge( $base, array( 'location' => $to, 'delta' => $qty, 'before_qty' => $before[$to], 'after_qty' => $in_after, 'reason' => 'transfer_in' ) ) );
            return array( 'transfer_id' => $tid, 'from_after' => $out_after, 'to_after' => $in_after );
        } );
    }

    /** Verwijdert eerder overgenomen regels, zodat een herhaalde import nooit dubbele regels geeft. */
    public static function purge_legacy_import() {
        global $wpdb;
        $t = self::ledger_table();
        $wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE transfer_id = %s", self::LEGACY_TAG ) );
    }

    /* ---------- onderweg (verzonden Bonusan-bestellingen) ---------- */

    /** Legt een verzonden bestelling vast als "onderweg" (alleen gevolgde producten). Geen voorraadmutatie. */
    public static function create_shipment( $shipment, $location, array $lines, $subject, $user_id, $note = '' ) {
        global $wpdb;
        return self::tx( function () use ( $wpdb, $shipment, $location, $lines, $subject, $user_id, $note ) {
            $t = self::inbound_table();
            foreach ( $lines as $l ) {
                self::q( $wpdb->prepare(
                    "INSERT INTO $t (shipment, created_gmt, location, product_id, sku, product_name, qty_ordered, subject, note, user_id) VALUES (%s, %s, %s, %d, %s, %s, %f, %s, %s, %d)",
                    $shipment, gmdate( 'Y-m-d H:i:s' ), $location, (int)$l['product_id'], substr( (string)$l['sku'], 0, 100 ), substr( (string)$l['name'], 0, 255 ),
                    round( (float)$l['qty'], 3 ), substr( (string)$subject, 0, 255 ), (string)$note, $user_id
                ) );
            }
            return count( $lines );
        } );
    }

    /** Openstaande (nog niet volledig afgehandelde) regels, oudste zending eerst. */
    public static function open_lines() {
        global $wpdb;
        $t = self::inbound_table();
        return (array) $wpdb->get_results( "SELECT * FROM $t WHERE closed_gmt IS NULL ORDER BY created_gmt ASC, id ASC", ARRAY_A );
    }

    /** Wat is er nog onderweg? product_id => location => aantal. */
    public static function in_transit( array $product_ids ) {
        global $wpdb;
        $t = self::inbound_table();
        $ids = array_values( array_unique( array_map( 'intval', $product_ids ) ) );
        $out = array();
        if ( ! $ids ) { return $out; }
        $rows = $wpdb->get_results( "SELECT product_id, location, SUM(qty_ordered - qty_received - qty_cancelled) AS open_qty FROM $t WHERE closed_gmt IS NULL AND product_id IN (" . implode( ',', $ids ) . ') GROUP BY product_id, location', ARRAY_A );
        foreach ( (array)$rows as $r ) {
            $q = round( (float)$r['open_qty'], 3 );
            if ( $q > 0 ) { $out[(int)$r['product_id']][$r['location']] = $q; }
        }
        return $out;
    }

    /**
     * Verwerkt een levering voor één regel: $receive stuks komen op de voorraad, $cancel stuks vervallen
     * (niet leverbaar). Wat overblijft blijft onderweg (nalevering). $expected_open moet overeenkomen met
     * wat nu openstaat, zodat dubbel klikken of een tweede gebruiker nooit dubbel boekt.
     */
    public static function receive_line( $line_id, $receive, $cancel, $expected_open, $user_id ) {
        global $wpdb;
        return self::tx( function () use ( $wpdb, $line_id, $receive, $cancel, $expected_open, $user_id ) {
            $t = self::inbound_table();
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d FOR UPDATE", $line_id ), ARRAY_A );
            if ( null === $row ) {
                if ( '' !== (string) $wpdb->last_error ) { throw new SBP_Ledger_Retry( (string) $wpdb->last_error ); }
                throw new SBP_Ledger_Exception( 'missing', 'Regel niet gevonden.' );
            }
            if ( null !== $row['closed_gmt'] ) { throw new SBP_Ledger_Exception( 'closed', 'Deze regel is al afgehandeld.' ); }
            $open = round( (float)$row['qty_ordered'] - (float)$row['qty_received'] - (float)$row['qty_cancelled'], 3 );
            if ( abs( $open - round( (float)$expected_open, 3 ) ) > 0.0005 ) { throw new SBP_Ledger_Exception( 'conflict', 'Er staat nu ' . $open . ' onderweg (was ' . $expected_open . ').' ); }
            $receive = round( (float)$receive, 3 ); $cancel = round( (float)$cancel, 3 );
            if ( $receive < 0 || $cancel < 0 || $receive + $cancel > $open + 0.0005 ) { throw new SBP_Ledger_Exception( 'invalid', 'Ontvangen plus niet leverbaar is meer dan er onderweg is (' . $open . ').' ); }
            $pid = (int)$row['product_id']; $loc = (string)$row['location'];
            if ( $receive > 0 ) {
                $before = self::lock_row( $pid, $loc );
                $after = round( $before + $receive, 3 );
                self::write_qty( $pid, $loc, $after );
                self::insert_ledger( array(
                    'product_id' => $pid, 'sku' => $row['sku'], 'product_name' => $row['product_name'], 'location' => $loc,
                    'delta' => $receive, 'before_qty' => $before, 'after_qty' => $after, 'reason' => 'delivery_in',
                    'transfer_id' => (string)$row['shipment'], 'note' => 'Levering Bonusan ontvangen', 'user_id' => $user_id,
                ) );
            }
            $new_open = round( $open - $receive - $cancel, 3 );
            self::q( $wpdb->prepare(
                "UPDATE $t SET qty_received = qty_received + %f, qty_cancelled = qty_cancelled + %f, closed_gmt = " . ( $new_open <= 0.0005 ? $wpdb->prepare( '%s', gmdate( 'Y-m-d H:i:s' ) ) : 'NULL' ) . ' WHERE id = %d',
                $receive, $cancel, $line_id
            ) );
            return array( 'received' => $receive, 'cancelled' => $cancel, 'open_after' => max( 0.0, $new_open ) );
        } );
    }

    /** Eenmalige import van een regel uit het oude optie-logboek (v1.7.0). Wijzigt het saldo niet. */
    public static function import_legacy_entry( $e ) {
        global $wpdb;
        $suppress = $wpdb->suppress_errors( true );
        try {
            $reason = (string)( $e['reason'] ?? '' );
            $delta = (float)( $e['delta'] ?? 0 );
            $order_qty = in_array( $reason, array( 'sale', 'return' ), true ) ? -$delta : 0;
            $time = (string)( $e['time'] ?? '' );
            $gmt = $time && function_exists( 'get_gmt_from_date' ) ? get_gmt_from_date( $time ) : gmdate( 'Y-m-d H:i:s' );
            self::insert_ledger( array(
                'created_gmt' => $gmt, 'product_id' => (int)( $e['product_id'] ?? 0 ), 'sku' => (string)( $e['sku'] ?? '' ), 'product_name' => (string)( $e['name'] ?? '' ),
                'location' => (string)( $e['location'] ?? '' ), 'delta' => $delta, 'before_qty' => (float)( $e['before'] ?? 0 ), 'after_qty' => (float)( $e['after'] ?? 0 ),
                'order_qty' => $order_qty, 'reason' => $reason, 'order_id' => (int)( $e['order_id'] ?? 0 ), 'transfer_id' => self::LEGACY_TAG,
                'note' => (string)( $e['note'] ?? '' ), 'user_id' => (int)( $e['user_id'] ?? 0 ),
            ) );
        } finally {
            $wpdb->suppress_errors( $suppress );
        }
    }
}
