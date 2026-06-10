# WooCommerce Custom XML Feed

A custom WooCommerce plugin that generates an XML product feed (endpoint `?xml=customexport`) with flexible price rules, category filtering, and an admin preview table.

## Features

- XML feed with product data: SKU, name, description, images, brand, dimensions, price, stock, etc.
- Optional CSV import for special prices (override regular/sale prices).
- Admin page to preview final XML prices for all products.
- Ready to extend with custom filtering logic.

## Installation

1. Upload the `woocommerce-xml-feed` folder to `/wp-content/plugins/`.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Visit `https://yoursite.com/?xml=customexport` to see the XML feed.
4. Go to WooCommerce → XML Cijene to preview prices.

## Requirements

- WordPress 5.0+
- WooCommerce active

## Customization

- To change price multiplier or discount rules, edit the `$final_price` logic in `woo-xml-feed.php`.
- To add product filtering, uncomment and modify the `my_custom_filter()` example.


## License

GPL-2.0+
