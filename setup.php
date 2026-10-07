<?php
/**
 * Builds the Cleanverse Detailing store inside WordPress Playground.
 * Run by the blueprint after WooCommerce and the theme are installed.
 * Service photos are read from /wordpress/cv-images.
 */
require_once '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// The photos are already web-sized, so each one is copied straight into uploads and registered with its
// size. (media_handle_sideload opens every image, which made the demo slow to build.)
function cv_sideload( $tmp, $name, $parent, $title ) {
	$up   = wp_upload_dir();
	$file = wp_unique_filename( $up['path'], $name );
	$dest = trailingslashit( $up['path'] ) . $file;
	if ( ! @rename( $tmp, $dest ) && ! copy( $tmp, $dest ) ) {
		return new WP_Error( 'cv_copy', 'Could not copy ' . $name );
	}
	$type = wp_check_filetype( $file );
	$size = @getimagesize( $dest ) ?: [ 0, 0 ];
	$id   = wp_insert_attachment( [ 'post_mime_type' => $type['type'], 'post_title' => $title, 'post_status' => 'inherit', 'guid' => trailingslashit( $up['url'] ) . $file ], $dest, $parent, true, false );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	wp_update_attachment_metadata( $id, [ 'width' => $size[0], 'height' => $size[1], 'file' => _wp_relative_upload_path( $dest ), 'sizes' => [], 'image_meta' => [] ] );
	return $id;
}

// One transaction for the whole import: SQLite otherwise commits (and syncs to disk) after every query.
wp_defer_term_counting( true );
wp_suspend_cache_invalidation( true );
$wpdb->query( 'START TRANSACTION' );

// Fast demo build: the photos are already web-sized, so skip making thumbnails of each one.
if ( defined( 'CV_FAST' ) && CV_FAST ) {
	add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
	add_filter( 'big_image_size_threshold', '__return_false' );
	add_filter( 'woocommerce_background_image_regeneration', '__return_false' );
	add_filter( 'woocommerce_resize_images', '__return_false' );
}

// Store settings.
foreach ( [
	'blogname'                               => 'Cleanverse Detailing',
	'blogdescription'                        => 'Precision automotive detailing, protection & enhancement at your doorstep in Nairobi & Kiambu.',
	'timezone_string'                        => 'Africa/Nairobi',
	'woocommerce_currency'                   => 'KES',
	'woocommerce_currency_pos'               => 'left_space',
	'woocommerce_price_num_decimals'         => '0',
	'woocommerce_price_thousand_sep'         => ',',
	'woocommerce_default_country'            => 'KE:KE30',
	'woocommerce_store_address'              => 'Mobile service',
	'woocommerce_store_city'                 => 'Nairobi',
	'woocommerce_manage_stock'               => 'no',
	'woocommerce_allowed_countries'          => 'specific',
	'woocommerce_specific_allowed_countries' => [ 'KE' ],
	'woocommerce_ship_to_countries'          => 'disabled',
	'woocommerce_enable_reviews'             => 'yes',
	'woocommerce_review_rating_verification_label' => 'yes',
	'woocommerce_onboarding_profile'         => [ 'skipped' => true ],
	'woocommerce_task_list_hidden'           => 'yes',
	'woocommerce_coming_soon'                => 'no',
	'woocommerce_checkout_phone_field'       => 'required',
	'woocommerce_checkout_company_field'     => 'hidden',
	'woocommerce_enable_coupons'             => 'yes',
	'woocommerce_calc_discounts_sequentially' => 'no',
] as $k => $v ) {
	update_option( $k, $v );
}

// Remove sample content.
foreach ( get_posts( [ 'post_type' => [ 'post', 'page' ], 'name' => 'hello-world', 'numberposts' => 1 ] ) as $p ) wp_delete_post( $p->ID, true );
$sample = get_page_by_path( 'sample-page' );
if ( $sample ) wp_delete_post( $sample->ID, true );

// Service categories (order = order on the home page and in the filters).
$cat = [];
$i   = 0;
foreach ( [
	'full-detailing'    => [ 'Full Car Detailing', 'Foam bath, hand wash, interior deep clean, leather care and more, done at your home or office.' ],
	'ceramic-paint'     => [ 'Ceramic Coating & Paint Correction', 'Machine polishing that takes out swirls and light scratches, sealed under a 1–5 year ceramic coating.' ],
	'ppf-tint'          => [ 'PPF & Window Tint', 'Paint protection film for the areas stones hit first, and heat- and UV-blocking window tint, fitted on site.' ],
	'maintenance-plans' => [ 'Monthly Maintenance Plans', 'Regular doorstep details that keep your car spotless all year.' ],
	'add-ons'           => [ 'Add-ons & Extras', 'Engine bay, headlights, glass, leather and rims: add them to any detail.' ],
	'home-cleaning'     => [ 'Sofa & Carpet Cleaning', 'The same deep-clean kit, for the sofas and carpets at home.' ],
] as $slug => [ $name, $desc ] ) {
	$t = term_exists( $slug, 'product_cat' ) ?: wp_insert_term( $name, 'product_cat', [ 'slug' => $slug, 'description' => $desc ] );
	$cat[ $slug ] = (int) $t['term_id'];
	update_term_meta( $cat[ $slug ], 'order', $i++ );
}

// Vehicle size: a global attribute with its own pages (/vehicle/compact-suv/). The four sizes and the
// full-detail prices are from Cleanverse's own price flyer (Instagram, May 2026).
if ( ! wc_attribute_taxonomy_id_by_name( 'vehicle' ) ) {
	wc_create_attribute( [ 'name' => 'Vehicle size', 'slug' => 'vehicle', 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => true ] );
}
if ( ! taxonomy_exists( 'pa_vehicle' ) ) {
	register_taxonomy( 'pa_vehicle', [ 'product' ], [ 'hierarchical' => false, 'show_ui' => false, 'query_var' => true, 'rewrite' => false ] );
}
$sizes = [
	'sedan'       => [ 'Sedan / hatchback', 'Saloons and hatchbacks, from a Toyota Axio to a Mercedes C-Class or an SLK convertible.' ],
	'compact-suv' => [ 'Compact SUV', 'Small SUVs and crossovers like the RAV4, X-Trail and Mazda CX-5.' ],
	'midsize-suv' => [ 'Midsize SUV', 'Mid-size SUVs like the Harrier, Discovery Sport and Mercedes GLC.' ],
	'suv-pickup'  => [ 'Full-size SUV / pickup', 'Big 4x4s and pickups: Prado, Land Cruiser, Defender, Hilux, Silverado.' ],
];
$size = [];
foreach ( $sizes as $slug => [ $name, $desc ] ) {
	$t = term_exists( $slug, 'pa_vehicle' ) ?: wp_insert_term( $name, 'pa_vehicle', [ 'slug' => $slug, 'description' => $desc ] );
	$size[ $slug ] = (int) $t['term_id'];
	update_term_meta( $size[ $slug ], 'order', count( $size ) );
}
delete_transient( 'wc_attribute_taxonomies' );

// Payment: nothing is paid online. The till / paybill isn't public yet, so M-Pesa details are sent on confirmation (PLACEHOLDER).
update_option( 'woocommerce_cod_settings', [
	'enabled'            => 'yes',
	'title'              => 'Pay after the job (M-Pesa or cash)',
	'description'        => 'Inspect your car when we finish, then pay the detailer by M-Pesa or cash.',
	'instructions'       => 'We will call to confirm your slot. Pay by M-Pesa or cash once the job is done and you are happy with it.',
	'enable_for_methods' => [],
	'enable_for_virtual' => 'yes',
] );
update_option( 'woocommerce_bacs_settings', [
	'enabled'         => 'yes',
	'title'           => 'M-Pesa deposit to hold my slot',
	'description'     => 'For ceramic, PPF and Sunday bookings. We send our M-Pesa details when we call to confirm, and the deposit comes off your total.',
	'instructions'    => 'We will call to confirm your booking and send our M-Pesa details for the deposit. The balance is paid after the job.',
	'account_details' => '',
] );
update_option( 'woocommerce_bacs_accounts', [] );
update_option( 'woocommerce_cheque_settings', [ 'enabled' => 'no' ] );
update_option( 'woocommerce_gateway_order', [ 'cod' => 0, 'bacs' => 1 ] );

// Services. Full detail prices and the inside/outside checklists are Cleanverse's own (price flyer, May 2026);
// every other price is a PLACEHOLDER (Nairobi market estimate) to confirm with the client.
// 'by_size' => [ sedan, compact SUV, midsize SUV, SUV/pickup ] prices; 'attr2' + 'options2' => [ label => multiplier ]
// adds a second choice. 'options' => [ label => price ] is a choice that doesn't depend on the vehicle.
$full_inside  = [ 'Thorough vacuuming', 'Cloth seat treatment and extraction if needed', 'Leather seat care and conditioning', 'Console and cup holder cleaning', 'Dashboard detailing and UV protection', 'Interior trim and plastic care', 'Door panel and pocket cleaning', 'Glass cleaning inside and out, with watermark removal', 'Headliner (roof lining) cleaning', 'Floor mat and carpet shampoo', 'Steam cleaning' ];
$full_outside = [ 'Pre-wash and foam bath', 'Hand wash: body, fuel cap area and water channels', 'Wheels, rims and wheel wells deep cleaned', 'Trim and plastic restoration', 'Watermark removal', 'Wax sealant' ];
$on_site      = 'At your home or office (Nairobi & Kiambu)';
$products = [
	[
		'slug' => 'full-car-detail', 'name' => 'Full Car Detail', 'cats' => [ 'full-detailing' ],
		'badge' => 'Most booked', 'perk' => 'Inside & out', 'perks' => 'Interior deep clean | Foam bath & wax sealant | Leather care', 'time' => '4–6 hours on site',
		'reel' => 'https://www.instagram.com/reel/DPTZZBriPxY/',
		'short' => 'Our signature detail, inside and out, at your doorstep: foam bath, hand wash, rims, interior deep clean, leather care and a wax sealant. The Overfinch Defender in the photos had exactly this.',
		'desc' => 'Everything on our full detailing checklist, done on site by our detailers with their own water, power and products. Inside, we vacuum, shampoo and steam-clean, treat cloth or leather seats, clean the headliner and protect the dashboard from UV. Outside, the car gets a pre-wash and foam bath, a hand wash down to the fuel cap and water channels, deep-cleaned rims and wheel wells, restored trims, watermark removal and a wax sealant. Selling the car? A full detail is the cheapest way to raise what buyers offer.',
		'by_size' => [ 13000, 15000, 16000, 17000 ],
		'included' => array_merge( array_map( fn( $s ) => 'Inside: ' . $s, $full_inside ), array_map( fn( $s ) => 'Outside: ' . $s, $full_outside ) ),
		'specs' => [ 'Time on site' => 'About 4–6 hours, depending on the car', 'Where' => $on_site, 'Water & power' => 'We bring our own: generator and water tanks', 'Payment' => 'After the job, M-Pesa or cash' ],
		'upsells' => [ 'engine-bay-detail', 'headlight-restoration', 'glass-rain-repellent' ],
	],
	[
		'slug' => 'exterior-foam-bath-wax', 'name' => 'Exterior Foam Bath & Wax', 'cats' => [ 'full-detailing' ],
		'badge' => 'Exterior', 'perk' => 'Hydrophobic finish', 'perks' => 'Snow-foam pre-wash | Hand wash | Wax sealant', 'time' => '1.5–2 hours on site',
		'reel' => 'https://www.instagram.com/reel/DX8wbzKMgO7/',
		'short' => 'The outside of our full detail on its own: snow-foam bath, two-bucket hand wash, rims and wheel wells, trims, watermark removal and a wax sealant.',
		'desc' => 'The quick way to a showroom shine. A thick foam bath lifts road film and dust before we touch the paint, then a careful hand wash, rims and arches, trims and glass. A wax sealant leaves water beading off the paint for weeks.',
		'by_size' => [ 5000, 6000, 6500, 7000 ],
		'included' => $full_outside,
		'specs' => [ 'Time on site' => 'About 1.5–2 hours', 'Where' => $on_site, 'Water & power' => 'We bring our own', 'Payment' => 'After the job, M-Pesa or cash' ],
		'upsells' => [ 'rim-restoration', 'glass-rain-repellent', 'headlight-restoration' ],
	],
	[
		'slug' => 'interior-deep-clean', 'name' => 'Interior Deep Clean', 'cats' => [ 'full-detailing' ],
		'badge' => 'Interior', 'perk' => 'Steam cleaned', 'perks' => 'Shampoo & extraction | Steam cleaning | UV dashboard protection', 'time' => '3–4 hours on site',
		'short' => 'The inside of our full detail on its own: vacuum, seat and carpet shampoo with extraction, steam cleaning, headliner, dashboard and leather care.',
		'desc' => 'For cabins that need more than a vacuum: kids, pets, spills, long road trips. We take the seats, carpets, mats and headliner back to clean, steam the vents and controls, and finish with UV protection on the dash and conditioner on leather.',
		'by_size' => [ 8000, 9000, 10000, 11000 ],
		'included' => $full_inside,
		'specs' => [ 'Time on site' => 'About 3–4 hours', 'Where' => $on_site, 'Drying' => 'Seats are dry to sit on the same day', 'Payment' => 'After the job, M-Pesa or cash' ],
		'upsells' => [ 'leather-conditioning', 'water-extraction' ],
	],
	[
		'slug' => 'water-extraction', 'name' => 'Water Extraction & Cabin Drying', 'cats' => [ 'full-detailing' ],
		'badge' => 'Interior rescue', 'perk' => 'Stops mould & smells', 'perks' => 'Extraction | Drying | Odour treatment', 'time' => '3–5 hours on site', 'illustration' => true,
		'short' => 'Rain through an open window, a flooded footwell or a big spill: we extract the water, dry the carpets and seats and treat the smell before mould sets in.',
		'desc' => 'Water trapped under carpets turns into mould and a musty smell within days. We extract it from carpets, underlay and seats, dry the cabin and treat it against odour. Call us as soon as it happens.',
		'by_size' => [ 6000, 7000, 8000, 9000 ],
		'included' => [ 'Water extraction from carpets, underlay and seats', 'Shampoo of the affected areas', 'Drying', 'Odour treatment' ],
		'specs' => [ 'Time on site' => 'About 3–5 hours', 'Where' => $on_site, 'Best' => 'As soon as possible after the water gets in', 'Payment' => 'After the job, M-Pesa or cash' ],
		'upsells' => [ 'interior-deep-clean' ],
	],
	[
		'slug' => 'paint-correction', 'name' => 'Paint Correction', 'cats' => [ 'ceramic-paint' ],
		'badge' => 'Paint', 'perk' => 'Swirl & scratch removal', 'perks' => 'Machine polish | Swirl & scratch removal | Deep gloss', 'time' => '1 day',
		'short' => 'Machine polishing that removes swirl marks, light scratches and dullness from the paint. One stage refreshes the gloss; two stages take out deeper marks.',
		'desc' => 'Years of car washes and dusty roads leave the clear coat full of fine swirls that dull the colour. We decontaminate the paint, then machine-polish it panel by panel to take out swirls and light scratches. Deep scratches that catch a fingernail may only improve. Seal the result with a ceramic coating to keep it.',
		'by_size' => [ 15000, 18000, 20000, 22000 ], 'attr2' => 'Correction', 'options2' => [ '1-stage enhancement' => 1, '2-stage correction' => 1.6 ],
		'included' => [ 'Foam bath and hand wash', 'Clay bar and iron decontamination', 'Machine polishing, panel by panel', 'Paint wipe-down and inspection', 'Wax sealant (or upgrade to ceramic)' ],
		'specs' => [ 'Time on site' => 'About a day (two stages may take longer)', 'Where' => 'Under cover at your home or office, or ask us', 'Best with' => 'Ceramic coating', 'Payment' => 'Deposit to book, balance after' ],
		'upsells' => [ 'ceramic-coating', 'headlight-restoration' ],
	],
	[
		'slug' => 'ceramic-coating', 'name' => 'Ceramic Coating', 'cats' => [ 'ceramic-paint' ],
		'badge' => 'Protection', 'perk' => '9H ceramic layer', 'perks' => '9H ceramic layer | Hydrophobic finish | UV & bird-dropping protection', 'time' => '1–2 days',
		'short' => 'A hard, glossy ceramic layer bonded to your paint: water and dirt slide off, washing is quicker, and the paint is protected from sun and bird droppings for 1, 3 or 5 years.',
		'desc' => 'We wash and decontaminate the car, polish the paint to take out light swirls, then apply the coating panel by panel and let it cure. Choose how long you want it to last; longer protection means more layers. Rims and glass can be coated too: ask us.',
		'by_size' => [ 25000, 28000, 32000, 35000 ], 'attr2' => 'Protection', 'options2' => [ '1 year' => 1, '3 years' => 1.8, '5 years' => 2.6 ],
		'included' => [ 'Foam bath and hand wash', 'Clay bar and iron decontamination', 'One-stage machine polish', 'Panel wipe to remove polishing oils', 'Ceramic coating, panel by panel', 'Curing time and aftercare guide' ],
		'specs' => [ 'Time on site' => '1–2 days, including curing', 'Hardness' => '9H', 'Aftercare' => 'No washing for 7 days; hand wash only after that', 'Payment' => 'Deposit to book, balance after' ],
		'upsells' => [ 'paint-correction', 'glass-rain-repellent', 'rim-restoration' ],
	],
	[
		'slug' => 'paint-protection-film', 'name' => 'Paint Protection Film (PPF)', 'cats' => [ 'ppf-tint' ],
		'badge' => 'PPF', 'perk' => 'Self-healing film', 'perks' => 'Self-healing film | Stone-chip protection | Invisible finish', 'time' => 'Half a day to 2 days', 'illustration' => true,
		'short' => 'Clear, self-healing film on the parts of the car that take the most stone chips and scratches. Start with headlights or door edges, or cover the whole front.',
		'desc' => 'Paint protection film is a tough, nearly invisible layer that takes the hits instead of your paint. Light scratches in the film heal in the sun. We cut it to your car and fit it on site, wrapping the edges where we can so it stays out of sight.',
		'options' => [ 'Headlights (pair)' => 8000, 'Door edges & handle cups' => 6000, 'Partial front (bumper, bonnet edge, mirrors)' => 60000, 'Full front (bumper, bonnet, wings, mirrors)' => 120000 ], 'attr' => 'Coverage',
		'included' => [ 'Wash and decontamination of the panels', 'Film cut to your car', 'Fitting with wrapped edges where possible', 'Aftercare guide' ],
		'specs' => [ 'Time on site' => 'Half a day (headlights) to 2 days (full front)', 'Film' => 'Clear, self-healing', 'Bigger SUVs' => 'Full-front prices may vary: we confirm first', 'Payment' => 'Deposit to book, balance after' ],
		'upsells' => [ 'ceramic-coating', 'headlight-restoration' ],
	],
	[
		'slug' => 'window-tint', 'name' => 'Mobile Window Tint', 'cats' => [ 'ppf-tint' ],
		'badge' => 'Tint', 'perk' => 'Heat & UV protection', 'perks' => 'Enhanced privacy | UV & heat protection | Fitted at your location', 'time' => '2–4 hours on site',
		'reel' => 'https://www.instagram.com/reel/DXo_59NDJkQ/',
		'short' => 'Privacy, UV and heat protection, fitted at your home or office. Drives cooler, looks sharper. Choose standard film or ceramic film for more heat rejection.',
		'desc' => 'Our mobile tint team comes to you and tints the car on site, like the Volvo XC90 in our reel. We follow the NTSA rules on how dark front windows and the windscreen may be; tell us what you want and we advise.',
		'by_size' => [ 8000, 9000, 10000, 12000 ], 'attr2' => 'Film', 'options2' => [ 'Standard film' => 1, 'Ceramic heat-rejection film' => 1.75 ],
		'included' => [ 'Glass cleaned and prepared', 'Film cut to each window', 'Clean professional installation', 'Aftercare advice' ],
		'specs' => [ 'Time on site' => 'About 2–4 hours', 'Where' => $on_site, 'Rules' => 'NTSA limits apply to front glass', 'Aftercare' => 'Keep windows up for 3 days' ],
		'upsells' => [ 'glass-rain-repellent' ],
	],
	[
		'slug' => 'monthly-maintenance-plan', 'name' => 'Monthly Maintenance Plan', 'cats' => [ 'maintenance-plans' ],
		'badge' => 'Subscription', 'perk' => 'Spotless all year', 'perks' => 'Regular doorstep visits | Same detailer | Priority booking', 'time' => 'About 2 hours a visit',
		'short' => 'Keep the car looking freshly detailed: we come twice or four times a month for a foam bath, hand wash, quick interior clean and a top-up of protection.',
		'desc' => 'The easiest way to never have a dirty car. We agree a regular day and time, and our detailer comes to your home or office for a maintenance detail each visit. Plans are paid monthly; pause or cancel any time with a week\'s notice. Best started after a full detail.',
		'by_size' => [ 7000, 8000, 9000, 10000 ], 'attr2' => 'Visits', 'options2' => [ '2 visits a month' => 1, '4 visits a month' => 1.75 ],
		'included' => [ 'Foam bath and hand wash', 'Rims and tyres cleaned and dressed', 'Interior vacuum and wipe-down', 'Glass inside and out', 'Wax or ceramic top-up', 'Same day and time every visit' ],
		'specs' => [ 'Visits' => 'Twice or four times a month', 'Where' => $on_site, 'Billing' => 'Monthly, after the first visit', 'Cancel' => 'Any time, with a week\'s notice' ],
		'upsells' => [ 'full-car-detail' ],
	],
	[
		'slug' => 'engine-bay-detail', 'name' => 'Engine Bay Detail', 'cats' => [ 'add-ons' ],
		'badge' => 'Add-on', 'perk' => 'Safe degrease', 'perks' => 'Degrease | Dress plastics', 'time' => '+45 minutes',
		'short' => 'A careful degrease and clean of the engine bay, with electrics covered, then the plastics and hoses dressed so it looks new.',
		'desc' => 'Clean engines are easier to check for leaks and look great when you sell. We cover the sensitive parts, degrease, rinse with low pressure and dress the plastics and rubber.',
		'price' => 2500,
		'included' => [ 'Sensitive parts covered', 'Degrease and agitate', 'Low-pressure rinse', 'Plastics and hoses dressed' ],
		'specs' => [ 'Added time' => 'About 45 minutes', 'Best with' => 'Any detail' ],
	],
	[
		'slug' => 'headlight-restoration', 'name' => 'Headlight Restoration & Ceramic Sealant', 'cats' => [ 'add-ons' ],
		'badge' => 'Add-on', 'perk' => 'Clear lenses', 'perks' => 'Removes yellowing | Brighter at night | Ceramic sealed', 'time' => '+1 hour',
		'short' => 'Yellow, cloudy headlights sanded and polished clear, then sealed with ceramic so they stay clear longer and light the road properly again.',
		'desc' => 'Sun-faded headlights cut how far you can see at night and age the whole car. We wet-sand the oxidised layer away, polish the lenses clear and seal them with a ceramic coating.',
		'price' => 4000,
		'included' => [ 'Masking around the lights', 'Wet-sanding of the oxidised layer', 'Machine polish to clear', 'Ceramic sealant' ],
		'specs' => [ 'Added time' => 'About 1 hour', 'Covers' => 'Both headlights' ],
	],
	[
		'slug' => 'glass-rain-repellent', 'name' => 'Glass Rain-Repellent Coating', 'cats' => [ 'add-ons' ],
		'badge' => 'Add-on', 'perk' => 'Hydrophobic glass', 'perks' => 'Water beads off | Clearer in rain', 'time' => '+30 minutes',
		'short' => 'A hydrophobic coating for the windscreen and windows: rain beads and blows off as you drive, so you see more and use the wipers less.',
		'desc' => 'We polish the glass to remove watermarks and film, then apply a hydrophobic coating to the windscreen and side windows.',
		'price' => 3500,
		'included' => [ 'Watermark removal', 'Glass polish', 'Hydrophobic coating on windscreen and windows' ],
		'specs' => [ 'Added time' => 'About 30 minutes', 'Lasts' => 'Several months with normal washing' ],
	],
	[
		'slug' => 'leather-conditioning', 'name' => 'Leather Conditioning & Fabric Stain Guard', 'cats' => [ 'add-ons' ],
		'badge' => 'Add-on', 'perk' => 'Protects seats', 'perks' => 'Leather cleaned & fed | Fabric stain guard', 'time' => '+45 minutes',
		'short' => 'Leather seats cleaned and fed with conditioner so they stay soft and don\'t crack; fabric seats get a stain guard so spills wipe off.',
		'desc' => 'Nairobi sun dries leather out fast. We clean it properly and work in a conditioner (we use Meguiar\'s Gold Class Rich Leather, as in our reel), or protect fabric seats with a stain guard.',
		'price' => 3000,
		'included' => [ 'Leather cleaned', 'Conditioner worked in and buffed', 'Or: fabric stain guard on cloth seats' ],
		'specs' => [ 'Added time' => 'About 45 minutes', 'Seats' => 'Leather or fabric' ],
	],
	[
		'slug' => 'rim-restoration', 'name' => 'Rim Restoration', 'cats' => [ 'add-ons' ],
		'badge' => 'Add-on', 'perk' => 'Factory finish', 'perks' => 'Brake dust & tar removed | Polished | Protected', 'time' => '+1 hour',
		'short' => 'Rims brought back towards a factory finish: brake dust, tar and old dressing removed, polished and protected. The Silverado\'s owner asked for exactly this.',
		'desc' => 'Fallout and tar bake onto rims and dull them. We decontaminate each wheel, polish the faces and protect them so they stay clean longer. Kerb damage needs a wheel shop; we tell you honestly what will improve.',
		'price' => 4000,
		'included' => [ 'Iron fallout and tar removal', 'Rim faces and barrels cleaned', 'Polish', 'Protective sealant', 'Tyres dressed' ],
		'specs' => [ 'Added time' => 'About 1 hour', 'Covers' => 'All four wheels' ],
	],
	[
		'slug' => 'sofa-carpet-cleaning', 'name' => 'Sofa & Carpet Cleaning', 'cats' => [ 'home-cleaning' ],
		'badge' => 'At home', 'perk' => 'Deep extraction', 'perks' => 'Shampoo & extraction | Stain treatment | Dry the same day', 'time' => '1–3 hours', 'illustration' => true,
		'short' => 'The same shampoo-and-extraction kit we use on car seats, for your sofas and carpets at home.',
		'desc' => 'We pre-treat stains, shampoo and extract the dirt from the fabric, and leave sofas and carpets fresh and dry the same day. Book it with your car\'s detail and we do both in one visit.',
		'options' => [ '5-seater sofa' => 3500, '7-seater sofa' => 5000, 'Carpet (up to 3 × 4 m)' => 2500 ], 'attr' => 'Job',
		'included' => [ 'Vacuum', 'Stain pre-treatment', 'Shampoo and extraction', 'Deodorising' ],
		'specs' => [ 'Time' => 'About 1–3 hours', 'Where' => 'Your home in Nairobi & Kiambu', 'Drying' => 'Same day' ],
	],
];

function cv_attach_images( $pid, $slug, $name ) {
	$num   = fn( $f ) => preg_match( '#^' . preg_quote( $slug, '#' ) . '(?:-(\d+))?\.webp$#', basename( $f ), $m ) ? (int) ( $m[1] ?? 1 ) : 0;
	$files = array_values( array_filter( glob( "/wordpress/cv-images/$slug*.webp" ) ?: [], fn( $f ) => $num( $f ) > 0 ) );
	usort( $files, fn( $a, $b ) => $num( $a ) <=> $num( $b ) );
	$ids = [];
	foreach ( $files as $file ) {
		$base = basename( $file );
		$tmp  = wp_tempnam( $base );
		copy( $file, $tmp );
		$n   = $num( $file );
		$att = cv_sideload( $tmp, $base, $pid, $name . ( $n > 1 ? ' – photo ' . $n : '' ) );
		if ( ! is_wp_error( $att ) ) $ids[ $n ] = $att;
	}
	return $ids; // photo number => attachment id
}

function cv_local_attr( $name, array $options, $position ) {
	$a = new WC_Product_Attribute();
	$a->set_name( $name );
	$a->set_options( $options );
	$a->set_position( $position );
	$a->set_visible( true );
	$a->set_variation( true );
	return $a;
}

$ids = [];
foreach ( $products as $order => $d ) {
	$by_size  = $d['by_size'] ?? null;
	$variable = $by_size || ! empty( $d['options'] );
	$p = $variable ? new WC_Product_Variable() : new WC_Product_Simple();
	$p->set_name( $d['name'] );
	$p->set_slug( $d['slug'] );
	$p->set_status( 'publish' );
	$p->set_menu_order( $order );
	$p->set_virtual( true );
	$p->set_description( $d['desc'] );
	$p->set_short_description( $d['short'] );
	$p->set_category_ids( array_map( fn( $c ) => $cat[ $c ], $d['cats'] ) );
	// The home page's packages: the car services with real photos (not add-ons or the illustrated extras).
	$p->set_featured( ! array_intersect( [ 'add-ons', 'home-cleaning' ], $d['cats'] ) && $d['slug'] !== 'water-extraction' );
	foreach ( [ 'badge', 'perk', 'perks', 'time', 'reel' ] as $k ) {
		if ( ! empty( $d[ $k ] ) ) $p->update_meta_data( '_cv_' . $k, $d[ $k ] );
	}
	$p->update_meta_data( '_cv_specs', implode( "\n", array_map( fn( $k, $v ) => "$k: $v", array_keys( $d['specs'] ), $d['specs'] ) ) );
	$p->update_meta_data( '_cv_included', implode( "\n", $d['included'] ) );
	if ( ! empty( $d['illustration'] ) ) $p->update_meta_data( '_cv_illustration', 'yes' );

	$attrs = [];
	if ( $by_size ) {
		$a = new WC_Product_Attribute();
		$a->set_id( wc_attribute_taxonomy_id_by_name( 'vehicle' ) );
		$a->set_name( 'pa_vehicle' );
		$a->set_options( array_values( $size ) );
		$a->set_position( 0 );
		$a->set_visible( true );
		$a->set_variation( true );
		$attrs[] = $a;
		if ( ! empty( $d['options2'] ) ) $attrs[] = cv_local_attr( $d['attr2'], array_keys( $d['options2'] ), 1 );
	} elseif ( $variable ) {
		$attrs[] = cv_local_attr( $d['attr'], array_keys( $d['options'] ), 0 );
	}
	$p->set_attributes( $attrs );
	if ( $by_size ) {
		$defaults = [ 'pa_vehicle' => 'sedan' ];
		if ( ! empty( $d['options2'] ) ) $defaults[ sanitize_title( $d['attr2'] ) ] = array_key_first( $d['options2'] );
		$p->set_default_attributes( $defaults );
	} elseif ( $variable ) {
		$p->set_default_attributes( [ sanitize_title( $d['attr'] ) => array_key_first( $d['options'] ) ] );
	} else {
		$p->set_regular_price( $d['price'] );
	}
	$pid = $p->save();
	$ids[ $d['slug'] ] = $pid;

	$imgs = cv_attach_images( $pid, $d['slug'], $d['name'] );
	if ( $imgs ) {
		// Written as meta: a second full product save here slowed the import.
		set_post_thumbnail( $pid, $imgs[1] ?? reset( $imgs ) );
		update_post_meta( $pid, '_product_image_gallery', implode( ',', array_values( array_diff_key( $imgs, [ 1 => true ] ) ) ) );
	}

	if ( $variable ) {
		// One variation per combination: vehicle size × second choice, or the plain choices.
		$combos = [];
		if ( $by_size ) {
			foreach ( array_keys( $size ) as $n => $s ) {
				foreach ( $d['options2'] ?? [ null => 1 ] as $o2 => $mult ) {
					$va = [ 'pa_vehicle' => $s ];
					if ( $o2 !== '' && $o2 !== null ) $va[ sanitize_title( $d['attr2'] ) ] = $o2;
					$combos[] = [ $va, round( $by_size[ $n ] * $mult / 500 ) * 500 ];
				}
			}
		} else {
			foreach ( $d['options'] as $label => $price ) {
				$combos[] = [ [ sanitize_title( $d['attr'] ) => $label ], $price ];
			}
		}
		foreach ( $combos as $n => [ $va, $price ] ) {
			$v = new WC_Product_Variation();
			$v->set_parent_id( $pid );
			$v->set_attributes( $va );
			$v->set_virtual( true );
			$v->set_regular_price( (string) $price );
			$v->set_menu_order( $n );
			$v->save();
		}
		WC_Product_Variable::sync( $pid );
	}
}

// "Popular add-ons" on each service page.
foreach ( $products as $d ) {
	if ( ! empty( $d['upsells'] ) ) {
		update_post_meta( $ids[ $d['slug'] ], '_upsell_ids', array_values( array_filter( array_map( fn( $s ) => $ids[ $s ] ?? 0, $d['upsells'] ) ) ) );
	}
}

// Customer Service Week: 5% off all detailing (their own offer, Instagram 6 Oct 2026). The code name is a PLACEHOLDER.
if ( ! wc_get_coupon_id_by_code( 'CSWEEK5' ) ) {
	$c = new WC_Coupon();
	$c->set_code( 'CSWEEK5' );
	$c->set_description( 'Customer Service Week 2026: 5% off all detailing services.' );
	$c->set_discount_type( 'percent' );
	$c->set_amount( 5 );
	$c->set_date_expires( '2026-10-11 23:59:59' );
	$c->save();
}

// Pages.
function cv_page( $slug, $title, $content ) {
	$existing = get_page_by_path( $slug );
	if ( $existing ) return $existing->ID;
	return wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content ] );
}
cv_page( 'estimator', 'Price your detail', "<!-- wp:paragraph -->\n<p>Choose your vehicle, a package and any add-ons to see your price straight away. Book it in a few taps, or send it to us on WhatsApp.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:shortcode -->\n[cv_estimator]\n<!-- /wp:shortcode -->" );
cv_page( 'fleet-commercial', 'Fleet & commercial detailing', "<!-- wp:paragraph -->\n<p>Pool cars, executive vehicles, vans and pickups, detailed where they park, on a schedule that suits your business. Our vans carry their own water, power and products, so there's nothing to arrange on your side. Tell us about your fleet and we reply on WhatsApp with a per-vehicle price.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:shortcode -->\n[cv_fleet_form]\n<!-- /wp:shortcode -->" );

// Cart and checkout read as a booking.
foreach ( [ 'shop' => 'All services', 'cart' => 'Your booking', 'checkout' => 'Book your detail' ] as $page => $title ) {
	$id = wc_get_page_id( $page );
	if ( $id > 0 ) wp_update_post( [ 'ID' => $id, 'post_title' => $title ] );
}

update_option( 'permalink_structure', '/%postname%/' );
flush_rewrite_rules();

// Skip WooCommerce's first-run redirect and setup checklist so the admin opens on the store itself.
delete_transient( '_wc_activation_redirect' );
update_option( 'woocommerce_task_list_hidden_lists', [ 'setup', 'extended' ] );
update_option( 'woocommerce_task_list_complete', 'yes' );
update_option( 'woocommerce_show_marketplace_suggestions', 'no' );
update_option( 'woocommerce_admin_install_timestamp', time() - WEEK_IN_SECONDS );

$wpdb->query( 'COMMIT' );
wp_suspend_cache_invalidation( false );
wp_defer_term_counting( false );
