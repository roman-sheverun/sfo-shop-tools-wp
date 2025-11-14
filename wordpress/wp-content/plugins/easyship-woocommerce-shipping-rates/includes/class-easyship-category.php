<?php
/**
 * Easyship Categories.
 *
 * @package WooCommerce_Easyship/Classes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// NOTE: Actually not used. Pending to implement on class-easyship-shipping-method.php!

/**
 * Easyship Category class.
 */
class Easyship_Category {

	/**
	 * Easyship categories.
	 *
	 * @var array
	 */
	private static $easyship_categories = array(
		'mobile_phones'          => 'Mobile Phones',
		'tablets'                => 'Tablets',
		'computers_laptops'      => 'Computers & Laptops',
		'cameras'                => 'Cameras',
		'accessory_no_battery'   => 'Accessory (no-battery)',
		'accessory_with_battery' => 'Accessory (with battery)',
		'health_beauty'          => 'Health & Beauty',
		'fashion'                => 'Fashion',
		'watches'                => 'Watches',
		'home_appliances'        => 'Home Appliances',
		'home_decor'             => 'Home Decor',
		'toys'                   => 'Toys',
		'sport_leisure'          => 'Sport & Leisure',
		'bags_luggages'          => 'Bags & Luggages',
		'audio_video'            => 'Audio Video',
		'documents'              => 'Documents',
		'jewelry'                => 'Jewellery',
		'dry_food_supplements'   => 'Dry Food & Supplements',
		'books_collectibles'     => 'Books & Collectibles',
		'pet_accessory'          => 'Pet Accessory',
		'gaming'                 => 'Gaming',
	);

	/**
	 * Get Category by Key.
	 *
	 * @param mixed $key Key to search the category.
	 *
	 * @return string|null
	 */
	public static function get_category_by_key( $key ) {
		return isset( self::$easyship_categories[ $key ] ) ? self::$easyship_categories[ $key ] : null;
	}

	/**
	 * Get all categories.
	 *
	 * @return array
	 */
	public static function get_categories() {
		return self::$easyship_categories;
	}
}
