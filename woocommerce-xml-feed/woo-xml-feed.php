<?php
/**
 * Plugin Name: WooCommerce Custom XML Feed & Price Preview
 * Description: Custom XML product feed (endpoint ?xml=customexport) with price rules and admin preview table.
 * Version: 1.0
 * Author: Maja Zen Code
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ========== 1. POMOĆNE FUNKCIJE ZA XML ==========

/**
 * Clean raw text for XML: remove HTML tags, decode entities, trim whitespace.
 */
function woo_xml_clean_raw( $string ) {
    if ( $string === null ) return '';

    $string = wp_strip_all_tags( $string );
    $string = html_entity_decode( $string, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $string = str_replace( "\xc2\xa0", ' ', $string );
    $string = str_replace( '&nbsp;', ' ', $string );
    $string = preg_replace( '/\s+/', ' ', $string );
    return trim( $string );
}

/**
 * Escape string for XML 1.0 content.
 */
function woo_xml_escape( $string ) {
    $raw = woo_xml_clean_raw( $string );
    return htmlspecialchars( $raw, ENT_XML1 | ENT_COMPAT, 'UTF-8' );
}

/**
 * Parse dimensions from short description (universal).
 * Supports: "Dim:45x35x60cm", "45 x 35 x 22", "Š:90 cm D:18 cm V:35 cm"
 */
function woo_parse_dimensions_from_short_desc( $short ) {
    $short = trim( $short );

    // Format: Š:90 cm D:18 cm V:35 cm
    if ( preg_match( '/Š\s*:?\s*(\d+)[^\d]+D\s*:?\s*(\d+)[^\d]+V\s*:?\s*(\d+)/iu', $short, $m ) ) {
        return [
            'width'  => (float) $m[1],
            'length' => (float) $m[2],
            'height' => (float) $m[3],
        ];
    }

    // Format: 45x35x60, 45 x 35 x 60, Dim:45x35x60cm
    if ( preg_match( '/(\d+)\s*[xX]\s*(\d+)\s*[xX]\s*(\d+)/u', $short, $m ) ) {
        return [
            'width'  => (float) $m[1],
            'length' => (float) $m[2],
            'height' => (float) $m[3],
        ];
    }

    return false;
}

// ========== 2. XML FEED ENDPOINT ==========
add_action( 'init', function() {
    if ( ! isset( $_GET['xml'] ) || $_GET['xml'] !== 'customexport' ) {
        return;
    }

    // Clean buffers and suppress warnings
    while ( ob_get_level() ) ob_end_clean();
    error_reporting( E_ALL & ~E_NOTICE & ~E_WARNING );

    header( 'Content-Type: application/xml; charset=UTF-8' );

    // Optional: load external CSV with special prices (example - path should be replaced)
    $csv_special_prices = [];
    $csv_path = ''; // ← replace with your own CSV path or remove this feature
    if ( $csv_path && file_exists( $csv_path ) ) {
        if ( ( $handle = fopen( $csv_path, 'r' ) ) !== false ) {
            fgetcsv( $handle, 1000, ',' ); // skip header
            while ( ( $row = fgetcsv( $handle, 1000, ',' ) ) !== false ) {
                $sku = trim( $row[0] );
                $price = (float) $row[1];
                if ( $sku && $price > 0 ) {
                    $csv_special_prices[ $sku ] = $price;
                }
            }
            fclose( $handle );
        }
    }

    // Get all published product IDs
    $products = get_posts( [
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
    ] );

    // Start XML
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<Products>' . "\n";

    foreach ( $products as $id ) {
        $product = wc_get_product( $id );
        if ( ! $product ) continue;

        // Apply your own product filtering logic here
        // Example: if ( ! my_custom_filter( $product ) ) continue;

        $sku = $product->get_sku();
        if ( ! $sku ) continue; // skip products without SKU

        $title       = get_post_field( 'post_title', $id );
        $description = $product->get_description();

        // Brand (example: attribute pa_brand)
        $brand_terms = wp_get_post_terms( $id, 'pa_brand', [ 'fields' => 'names' ] );
        $brand       = $brand_terms ? $brand_terms[0] : '';

        // Categories as "A > B > C"
        $cats = wp_get_post_terms( $id, 'product_cat', [ 'fields' => 'names' ] );
        $category_path = $cats ? implode( ' > ', $cats ) : '';

        // Images (featured + gallery)
        $image_urls = [];
        $thumb_id = $product->get_image_id();
        if ( $thumb_id ) {
            $thumb = wp_get_attachment_image_src( $thumb_id, 'full' );
            if ( $thumb && ! empty( $thumb[0] ) ) $image_urls[] = $thumb[0];
        }
        $gallery_ids = $product->get_gallery_image_ids();
        foreach ( $gallery_ids as $gid ) {
            $img = wp_get_attachment_image_src( $gid, 'full' );
            if ( $img && ! empty( $img[0] ) ) $image_urls[] = $img[0];
        }
        $image_urls = array_unique( $image_urls );
        $images_str = implode( '|', $image_urls );

        // Dimensions (use product dimensions or fallback to short description)
        $width  = $product->get_width();
        $length = $product->get_length();
        $height = $product->get_height();
        if ( empty( $width ) && empty( $length ) && empty( $height ) ) {
            $short_desc = get_post_field( 'post_excerpt', $id );
            $dims = woo_parse_dimensions_from_short_desc( $short_desc );
            if ( $dims ) {
                $width  = $dims['width'];
                $length = $dims['length'];
                $height = $dims['height'];
            }
        }

        $weight = $product->get_weight();

        // Color and size attributes (example)
        $color = $product->get_attribute( 'pa_color' );
        $size  = $product->get_attribute( 'pa_size' );

        // Price logic: regular / sale / CSV override
        $regular_price = (float) $product->get_regular_price();
        $sale_price    = (float) $product->get_sale_price();

        $final_price = $regular_price;
        if ( isset( $csv_special_prices[ $sku ] ) && $csv_special_prices[ $sku ] > 0 ) {
            $final_price = $csv_special_prices[ $sku ];
        } elseif ( $sale_price > 0 ) {
            $final_price = $sale_price * 1.12;  // example multiplier
            $final_price = round( $final_price, 2 );
        }

        // Optional: extra discount for specific category/brand (example)
        // if ( has_term( 'example-category', 'product_cat', $id ) && $brand === 'EXAMPLE_BRAND' ) {
        //     $final_price = round( $final_price * 0.95, 2 );
        // }

        $group_id = $id;
        $stock    = 100; // example stock

        // Output product node
        echo '<Product>' . "\n";
        echo '  <EAN>' . woo_xml_escape( get_post_meta( $id, '_alg_ean', true ) ) . '</EAN>' . "\n";
        echo '  <SKU>' . woo_xml_escape( $sku ) . '</SKU>' . "\n";
        echo '  <Name>' . woo_xml_escape( $title ) . '</Name>' . "\n";
        echo '  <Description>' . woo_xml_escape( $description ) . '</Description>' . "\n";
        echo '  <Images>' . $images_str . '</Images>' . "\n";
        echo '  <Brand>' . woo_xml_escape( $brand ) . '</Brand>' . "\n";
        echo '  <BackendCategory>' . woo_xml_escape( $category_path ) . '</BackendCategory>' . "\n";
        echo '  <NabavnaCena></NabavnaCena>' . "\n";
        echo '  <ProdajnaCena>' . woo_xml_escape( $final_price ) . '</ProdajnaCena>' . "\n";
        echo '  <TaxClass>' . woo_xml_escape( $product->get_tax_class() ) . '</TaxClass>' . "\n";
        echo '  <Width>' . woo_xml_escape( $width ) . '</Width>' . "\n";
        echo '  <Length>' . woo_xml_escape( $length ) . '</Length>' . "\n";
        echo '  <Height>' . woo_xml_escape( $height ) . '</Height>' . "\n";
        echo '  <Weight>' . woo_xml_escape( $weight ) . '</Weight>' . "\n";
        echo '  <Color>' . woo_xml_escape( $color ) . '</Color>' . "\n";
        echo '  <Size>' . woo_xml_escape( $size ) . '</Size>' . "\n";
        echo '  <GroupID>' . woo_xml_escape( $group_id ) . '</GroupID>' . "\n";
        echo '  <Lager>' . woo_xml_escape( $stock ) . '</Lager>' . "\n";
        echo '</Product>' . "\n";
    }

    echo '</Products>';
    exit;
} );

// ========== 3. ADMIN PREGLED XML CIJENA ==========
add_action( 'admin_menu', function() {
    add_submenu_page(
        'woocommerce',
        'XML Cijene',
        'XML Cijene',
        'manage_woocommerce',
        'xml-price-preview',
        'woo_xml_price_preview_page'
    );
} );

function woo_xml_price_preview_page() {
    echo '<div class="wrap"><h1>Pregled XML cijena</h1>';
    echo '<p>Prikazane su cijene koje se šalju u XML feed (<a href="?xml=customexport" target="_blank">pogledaj XML</a>).</p>';

    // Load special prices from CSV (if any) - same logic as in feed
    $csv_special_prices = [];
    $csv_path = ''; // same as above
    if ( $csv_path && file_exists( $csv_path ) ) {
        if ( ( $handle = fopen( $csv_path, 'r' ) ) !== false ) {
            fgetcsv( $handle, 1000, ',' );
            while ( ( $row = fgetcsv( $handle, 1000, ',' ) ) !== false ) {
                $sku = trim( $row[0] );
                $price = (float) $row[1];
                if ( $sku && $price > 0 ) {
                    $csv_special_prices[ $sku ] = $price;
                }
            }
            fclose( $handle );
        }
    }

    $products = get_posts( [
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
    ] );

    echo '<table class="widefat fixed striped">';
    echo '<thead><tr><th>Naziv proizvoda</th><th>Šifra (SKU)</th><th>XML cijena (RSD)</th></tr></thead><tbody>';

    foreach ( $products as $id ) {
        $product = wc_get_product( $id );
        if ( ! $product ) continue;

        // Apply same product filtering as in XML feed
        // if ( ! my_custom_filter( $product ) ) continue;

        $sku = $product->get_sku();
        if ( ! $sku ) continue;

        $title = get_post_field( 'post_title', $id );

        $regular_price = (float) $product->get_regular_price();
        $sale_price    = (float) $product->get_sale_price();

        $final_price = $regular_price;
        if ( isset( $csv_special_prices[ $sku ] ) && $csv_special_prices[ $sku ] > 0 ) {
            $final_price = $csv_special_prices[ $sku ];
        } elseif ( $sale_price > 0 ) {
            $final_price = round( $sale_price * 1.12, 2 );
        }

        // Optional discount (example)
        // if ( has_term( 'example-category', 'product_cat', $id ) && $brand === 'EXAMPLE_BRAND' ) {
        //     $final_price = round( $final_price * 0.95, 2 );
        // }

        echo '<tr>';
        echo '<td>' . esc_html( $title ) . '</td>';
        echo '<td>' . esc_html( $sku ) . '</td>';
        echo '<td><strong>' . number_format( $final_price, 2, ',', '.' ) . ' RSD</strong></td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}
