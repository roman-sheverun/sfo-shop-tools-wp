<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AfterShip Protection
 */
class AfterShip_Protection {


	/**
	 * Instance of this class.
	 *
	 * @var object Class Instance
	 */
	private static $instance;

	/**
	 * Get the class instance
	 *
	 * @return AfterShip_Protection
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {

			self::$instance = new self;
		}

		return self::$instance;
	}

	private function __construct()
	{
		// ===========================================
		// V2 API端点注册
		// ===========================================
		
		// 1. 版本检测API（无需security验证）
		add_action( 'wp_ajax_nopriv_aftership_get_protection_version', array($this, 'get_protection_version_ajax_handler') );
		add_action( 'wp_ajax_aftership_get_protection_version', array($this, 'get_protection_version_ajax_handler') );
		
		// 2. 设置保费API
		add_action( 'wp_ajax_nopriv_aftership_set_insurance_fee', array($this, 'set_insurance_fee_ajax_handler') );
		add_action( 'wp_ajax_aftership_set_insurance_fee', array($this, 'set_insurance_fee_ajax_handler') );
		
		// 3. 清除保费API  
		add_action( 'wp_ajax_nopriv_aftership_remove_insurance_fee', array($this, 'remove_insurance_fee_ajax_handler') );
		add_action( 'wp_ajax_aftership_remove_insurance_fee', array($this, 'remove_insurance_fee_ajax_handler') );
		
		// 4. 获取购物车API
		add_action( 'wp_ajax_nopriv_aftership_get_cart_details', array($this, 'get_cart_details_ajax_handler') );
		add_action( 'wp_ajax_aftership_get_cart_details', array($this, 'get_cart_details_ajax_handler') );

		// ===========================================
		// WooCommerce集成hooks
		// ===========================================
		
		// 应用保险费到购物车（包含session完整性验证）
		add_action('woocommerce_cart_calculate_fees', array($this, 'apply_aftership_shipping_insurance_fee'), 20, 1);
		add_action( 'woocommerce_cart_emptied', array( $this, 'handle_woocommerce_cart_emptied' ) );
		
		// 注入Security令牌到checkout页面
		add_action( 'wp_footer', array($this, 'inject_security_tokens'), 1 );
	}

	// ===========================================
	// V2 统一响应格式和Security验证
	// ===========================================

	/**
	 * 统一API响应格式
	 */
	private function wp_send_json_unified($data, $meta_code = 20000, $meta_type = 'OK', $meta_message = 'Success') {
		wp_send_json(array(
			'meta' => array(
				'code' => $meta_code,
				'type' => $meta_type,
				'message' => $meta_message
			),
			'data' => $data
		));
	}

	/**
	 * Security验证（使用security字段，遵循WooCommerce约定）
	 */
	private function verify_security($action_name) {
		if (!isset($_POST['security'])) {
			$this->wp_send_json_unified(
				array(),
				40300,
				'SECURITY_VERIFICATION_FAILED',
				'Security verification failed'
			);
			wp_die();
		}
		
		if (!wp_verify_nonce($_POST['security'], $action_name)) {
			$this->wp_send_json_unified(
				array(),
				40300,
				'SECURITY_VERIFICATION_FAILED',
				'Security verification failed'
			);
			wp_die();
		}
		
		return true;
	}

	// ===========================================
	// V2 API端点实现
	// ===========================================

	/**
	 * 版本检测API（无需security验证）
	 */
	function get_protection_version_ajax_handler() {
		$this->wp_send_json_unified(array(
			'version' => 'v2'
		));
	}


	/**
	 * 清除AfterShip保险费相关的session数据
	 * 触发时机：WooCommerce购物车被清空时（如用户点击"清空购物车"或所有商品被移除）
	 */
	function handle_woocommerce_cart_emptied() {
		// 清除保险费金额
		WC()->session->set('aftership_shipping_insurance_fee', null);
		// 清除验证哈希（防篡改数据）
		WC()->session->set('aftership_fee_verification', null);
	}

	/**
	 * V2版本：应用保险费到WooCommerce购物车（包含session完整性验证）
	 * 关键：在应用保费前必须验证session是否被篡改
	 */
	function apply_aftership_shipping_insurance_fee() {
		// 验证session数据是否被篡改
		if (!$this->verify_session_insurance_fee()) {
			error_log('AfterShip Protection: Session data verification failed, clearing fee');
			WC()->session->set('aftership_shipping_insurance_fee', '-');
			WC()->session->set('aftership_fee_verification', '');
			return;
		}
		
		$amount = WC()->session->get('aftership_shipping_insurance_fee', '-');
		
		if ($amount !== '-' && is_numeric($amount) && floatval($amount) > 0) {
			WC()->cart->add_fee(AFTERSHIP_PROTECTION_LABEL, floatval($amount), false, "");
		}
	}

	/**
	 * 验证session保险费用是否被篡改
	 */
	private function verify_session_insurance_fee() {
		$amount = WC()->session->get('aftership_shipping_insurance_fee', '-');
		$stored_hash = WC()->session->get('aftership_fee_verification', '');
		
		if ($amount === '-' || empty($stored_hash)) {
			return true; // 未设置费用，验证通过
		}
		
		$expected_hash = $this->generate_verification_hash($amount);
		return hash_equals($expected_hash, $stored_hash);
	}

	/**
	 * V2版本：设置保费API
	 * 重新计算保费（不信任前端），安全设置session，返回购物车数据
	 */
	function set_insurance_fee_ajax_handler() {
		// Security验证
		$this->verify_security('aftership_set_insurance_fee');
		
		// 重新计算保费（不信任前端，完全由后端决定）
		$calculation = $this->calculate_insurance_fee();
		$insurance_fee = $calculation['insurance_fee'];
		
		// 根据计算结果设置或清除保费
		if ($insurance_fee > 0) {
			// 有保费：安全设置session
			$success = $this->set_session_insurance_fee_securely($insurance_fee);
			if (!$success) {
				$this->wp_send_json_unified(
					array(),
					50001,
					'INTERNAL_ERROR',
					'Failed to set insurance fee'
				);
				return;
			}
		} else {
			// 无保费：清除session数据
			WC()->session->set('aftership_shipping_insurance_fee', '-');
			WC()->session->set('aftership_fee_verification', '');
		}
		
		// 返回更新后的购物车数据
		WC()->cart->calculate_totals();
		$cart_data = $this->get_cart_data();
		
		$this->wp_send_json_unified(array(
			'cart' => $cart_data['cart'],
			'fees' => $cart_data['fees'],
			'totals' => $cart_data['totals'],
			'currency' => $cart_data['currency']
		));
	}

	/**
	 * V2版本：清除保费API
	 * 清除session数据和验证信息，返回购物车数据
	 */
	function remove_insurance_fee_ajax_handler() {
		// Security验证
		$this->verify_security('aftership_remove_insurance_fee');
		
		// 清除session中的保险费和验证哈希
		WC()->session->set('aftership_shipping_insurance_fee', '-');
		WC()->session->set('aftership_fee_verification', '');
		
		// 确保购物车状态最新并获取购物车数据
		WC()->cart->calculate_totals();
		$cart_data = $this->get_cart_data();
		
		$this->wp_send_json_unified(array(
			'cart' => $cart_data['cart'],
			'fees' => $cart_data['fees'],
			'totals' => $cart_data['totals'],
			'currency' => $cart_data['currency']
		));
	}

	/**
	 * 获取购物车API（增加security校验）
	 * 返回完整WooCommerce购物车数据，用于前端状态同步
	 */
	function get_cart_details_ajax_handler() {
		// Security验证
		$this->verify_security('aftership_get_cart_details');
		
		// 确保购物车状态最新
		WC()->cart->calculate_totals();
		
		$wc_cart = WC()->cart;
		$cart = $wc_cart->get_cart();
		
		// 丰富购物车数据，包含产品和变体完整信息
		foreach ($cart as $cart_item_key => $cart_item) {
			if (isset($cart_item['variation_id']) && isset($cart_item['variation'])) {
				$variation = new WC_Product_Variation($cart_item['variation_id']);
				$cart[$cart_item_key]['variation'] = array_merge($cart_item['variation'], $variation->get_data());
			}
			$product = wc_get_product($cart_item['product_id']);
			if ($product) {
				$cart[$cart_item_key]['product'] = $product->get_data();
			}
		}
		
		// 使用V2统一响应格式
		$this->wp_send_json_unified(array(
			'cart' => $cart,
			'fees' => $wc_cart->fees_api()->get_fees(),
			'totals' => $wc_cart->get_totals(),
			'currency' => get_woocommerce_currency(),
		));
	}

	// ===========================================
	// V2 核心业务逻辑 - 完整复制V1前端逻辑
	// ===========================================

	/**
	 * 核心保费计算逻辑（完整复制V1前端逻辑到后端）
	 * 对应V1前端 checkout.ts 的 handleUpdate() 方法
	 */
	private function calculate_insurance_fee() {
		// 初始化返回结果
		$result = array(
			'insurance_fee' => 0,
			'reason' => 'unknown'
		);

		// 1. 检查WooCommerce可用性
		if (!function_exists('WC') || !WC() || !WC()->cart) {
			$result['reason'] = 'woocommerce_unavailable';
			return $result;
		}

		// 2. 获取保护配置
		$config_data = $this->fetch_protection_config();
		if (!$config_data['has_valid_config']) {
			$result['reason'] = 'no_config';
			return $result;
		}

		$protection_config = $config_data['protectionConfig'];
		$variants = $config_data['variants'];
		$protection_rules = $config_data['protectionConnectionRules'];

		// 3. 检查保护功能是否启用（新增的严格检查）
		if (!$protection_config || 
			$protection_config['status'] !== 'active' || 
			!isset($protection_config['insuranceOptions']['shippingProtection']['enabled']) ||
			$protection_config['insuranceOptions']['shippingProtection']['enabled'] !== true) {
			$result['reason'] = 'config_disabled';
			return $result;
		}

		// 4. 计算物理商品总价（复制V1前端逻辑）
		$physical_total = $this->calculate_physical_items_total();
		
		if ($physical_total <= 0) {
			$result['reason'] = 'no_physical_items';
			return $result;
		}

		// 5. 价格范围检查（复制V1前端逻辑）
		$min_price = $protection_config['minPrice'];
		$max_price = $protection_config['maxPrice'];
		
		if ($physical_total < $min_price || $physical_total > $max_price) {
			$result['reason'] = 'out_of_range';
			return $result;
		}

		// 6. 查找匹配的保费变体（复制V1前端逻辑）
		$match_premium = $this->find_matching_premium_variant($variants, $physical_total);
		
		if ($match_premium === null || $match_premium <= 0) {
			$result['reason'] = 'no_matching_variant';
			return $result;
		}

		// 7. 处理购买规则逻辑（复制V1前端逻辑）
		$active_rule = $this->get_highest_priority_active_rule($protection_rules, $protection_config);
		
		if ($active_rule) {
			// 检查免费保护逻辑
			$free_protection_amount = $active_rule['conditions']['orderAmount']['value'] ?? null;
			
			if ($free_protection_amount && $physical_total >= $free_protection_amount) {
				// 免费保护情况
				$result['insurance_fee'] = 0;
				$result['reason'] = 'free_protection';
				return $result;
			}
			
			// 检查隐藏规则
			if ($protection_config['displayRuleWidgetOption'] === 'hide_purchase_widget_when_not_match_rules') {
				$result['reason'] = 'widget_hidden_by_rule';
				return $result;
			}
		}

		// 8. 返回计算结果
		$result['insurance_fee'] = $match_premium;
		$result['reason'] = 'calculated';
		
		return $result;
	}

	/**
	 * 计算物理商品总价（复制V1前端逻辑）
	 */
	private function calculate_physical_items_total() {
		$total = 0;
		$cart = WC()->cart->get_cart();
		
		foreach ($cart as $cart_item) {
			$product = wc_get_product($cart_item['product_id']);
			
			if (!$product) {
				continue;
			}
			
			// 检查是否为物理商品（复制V1前端逻辑）
			$is_virtual = false;
			
			if (isset($cart_item['variation_id']) && $cart_item['variation_id'] > 0) {
				// 变体产品：检查变体的virtual属性
				$variation = wc_get_product($cart_item['variation_id']);
				if ($variation) {
					$is_virtual = $variation->is_virtual();
				}
			} else {
				// 简单产品：检查产品的virtual属性
				$is_virtual = $product->is_virtual();
			}
			
			// 只计算物理商品
			if (!$is_virtual) {
				$total += $cart_item['line_total'];
			}
		}
		
		return $total;
	}

	/**
	 * 查找匹配的保费变体（复制V1前端逻辑）
	 */
	private function find_matching_premium_variant($variants, $total_price) {
		if (empty($variants)) {
			return null;
		}
		
		foreach ($variants as $variant) {
			$min_price = $variant['minPrice'] ?? 0;
			$max_price = $variant['maxPrice'] ?? 0;
			$premium_fee = $variant['premiumFee'] ?? 0;
			
			if ($total_price >= $min_price && $total_price <= $max_price) {
				return $premium_fee;
			}
		}
		
		return null;
	}

	/**
	 * 获取最高优先级的激活规则（复制V1前端逻辑）
	 */
	private function get_highest_priority_active_rule($rules, $protection_config) {
		if (empty($rules)) {
			return null;
		}
		
		// 只有当购买方式选项为1时才处理规则
		if ($protection_config['purchaseMethodOption'] !== 1) {
			return null;
		}
		
		// 过滤启用的规则并按优先级排序
		$active_rules = array();
		foreach ($rules as $rule) {
			if ($rule['ruleEnable']) {
				$active_rules[] = $rule;
			}
		}
		
		if (empty($active_rules)) {
			return null;
		}
		
		// 按优先级排序（假设有rulePriority字段，如果没有则使用id作为优先级）
		usort($active_rules, function($a, $b) {
			$priority_a = $a['rulePriority'] ?? $a['id'] ?? 0;
			$priority_b = $b['rulePriority'] ?? $b['id'] ?? 0;
			return $priority_b - $priority_a; // 降序排列
		});
		
		return $active_rules[0];
	}

	// ===========================================
	// V2 Session安全机制
	// ===========================================

	/**
	 * 安全设置session保险费用（防止前端篡改）
	 */
	private function set_session_insurance_fee_securely($amount) {
		// 验证金额范围（防止异常值）
		if (!is_numeric($amount) || $amount < 0) {
			error_log('AfterShip Protection: Invalid insurance fee amount: ' . $amount);
			return false;
		}
		
		// 设置session值
		WC()->session->set('aftership_shipping_insurance_fee', floatval($amount));
		
		// 生成验证哈希，防止篡改
		$verification_hash = $this->generate_verification_hash($amount);
		WC()->session->set('aftership_fee_verification', $verification_hash);
		
		return true;
	}

	/**
	 * 生成验证哈希（防止session篡改）
	 */
	private function generate_verification_hash($amount) {
		$salt = wp_salt('auth'); // 使用WordPress内置的安全盐
		return hash_hmac('sha256', $amount, $salt);
	}
	
	/**
	 * 获取购物车数据（内部方法，复用购物车获取逻辑）
	 */
	private function get_cart_data() {
		$wc_cart = WC()->cart;
		$cart = $wc_cart->get_cart();
		
		// 丰富购物车数据，包含产品和变体完整信息
		foreach ($cart as $cart_item_key => $cart_item) {
			if (isset($cart_item['variation_id']) && isset($cart_item['variation'])) {
				$variation = new WC_Product_Variation($cart_item['variation_id']);
				$cart[$cart_item_key]['variation'] = array_merge($cart_item['variation'], $variation->get_data());
			}
			$product = wc_get_product($cart_item['product_id']);
			if ($product) {
				$cart[$cart_item_key]['product'] = $product->get_data();
			}
		}
		
		return array(
			'cart' => $cart,
			'fees' => $wc_cart->fees_api()->get_fees(),
			'totals' => $wc_cart->get_totals(),
			'currency' => get_woocommerce_currency(),
		);
	}

		/**
	 * 获取保护配置（GraphQL API调用，带5分钟缓存）
	 */
	private function fetch_protection_config() {
		// 默认错误结构 - 符合WooCommerce一致性原则
		$error_result = array(
			'has_valid_config' => false,
			'protectionConfig' => null,
			'variants' => array(),
			'protectionConnectionRules' => array(),
			'error_reason' => null
		);
		
		$app_key = $this->get_current_app_key();
		if (empty($app_key)) {
			$error_result['error_reason'] = 'invalid_app_key';
			return $error_result;
		}

		// 检查缓存（5分钟有效期）
		$cache_key = 'aftership_protection_config_' . md5($app_key);
		$cached_data = get_transient($cache_key);
		
		if ($cached_data !== false) {
			// 缓存数据始终是标准数组结构，直接返回
			return $cached_data;
		}

		// 使用正确的GraphQL查询格式，参考需求文档中的curl示例
		$query = 'query WG_INS_queryConfig($appKey: String!, $platform: String!) {
			protectionProductConnection(appKey: $appKey, platform: $platform) {
				protectionConfig {
					minPrice
					maxPrice
					protectionToggleDefault
					currency
					protectionPlatform
					status
					displayRuleWidgetOption
					purchaseMethodOption
					businessModel
					insuranceOptions {
						returnCare {
							enabled
							pricing {
								mode
								fixed
							}
						}
						shippingProtection {
							enabled
						}
					}
				}
				product {
					externalId
					handle
				}
				variants {
					minPrice
					maxPrice
					premiumFee
					externalId
				}
				returnCareProduct {
					product {
						externalId
						handle
					}
					variants {
						minPrice
						maxPrice
						premiumFee
						externalId
					}
				}
			}
			protectionConnectionRules(appKey: $appKey, platform: $platform) {
				id
				createdAt
				updatedAt
				ruleEnable
				filterType
				conditions {
					orderAmount {
						operator
						value
					}
				}
			}
		}';

		$variables = array(
			'appKey' => $app_key,
			'platform' => 'woocommerce'
		);

		$body = array(
			'operationName' => 'WG_INS_queryConfig',
			'query' => $query,
			'variables' => $variables
		);

		$response = wp_remote_post('https://api.automizely.com/aftership/public/graphql', array(
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body' => json_encode($body),
			'timeout' => 30
		));

		if (is_wp_error($response)) {
			$error_result['error_reason'] = 'api_request_failed';
			return $error_result;
		}

		$status_code = wp_remote_retrieve_response_code($response);
		$response_body = wp_remote_retrieve_body($response);
		
		// 只有真正的服务器错误不缓存，避免缓存穿透
		if ($status_code >= 500) {
			$error_result['error_reason'] = 'server_error';
			return $error_result;
		}

		$data = json_decode($response_body, true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			$error_result['error_reason'] = 'invalid_json';
			return $error_result;
		}

		// 统一处理所有响应结果并缓存（避免缓存穿透）
		$result = $this->process_and_cache_config_response($data, $cache_key);
		
		// 始终返回标准数组结构，符合WooCommerce习惯
		return $result;
	}

	/**
	 * 统一处理配置响应并缓存（避免缓存穿透）
	 */
	private function process_and_cache_config_response($data, $cache_key) {
		// 默认结构
		$result = array(
			'has_valid_config' => false,
			'protectionConfig' => null,
			'variants' => [],
			'protectionConnectionRules' => []
		);
		
		$error_message = null;
		
		// 优先检查data字段（即使有errors，data也可能有值）
		if (isset($data['data'])) {
			$protection_data = $data['data']['protectionProductConnection'] ?? null;
			$protection_config = $protection_data['protectionConfig'] ?? null;
			
			// 无论是否有错误，都先获取规则
			$result['protectionConnectionRules'] = $data['data']['protectionConnectionRules'] ?? [];
			
			if ($protection_data && $protection_config) {
				// 有效配置
				$result['has_valid_config'] = true;
				$result['protectionConfig'] = $protection_config;
				$result['variants'] = $protection_data['variants'] ?? [];
				$error_message = null; // 清除错误信息
			} else {
				$error_message = 'No valid protectionProductConnection in response';
			}
		}
		
		// 记录GraphQL错误（用于调试，但不影响处理）
		if (isset($data['errors'])) {
			$error_details = json_encode($data['errors']);
			error_log('AfterShip Protection: GraphQL returned errors - ' . $error_details);
			
			// 如果没有有效配置，记录错误原因
			if (!$result['has_valid_config']) {
				$error_message = 'GraphQL errors: ' . $error_details;
			}
		}
		
		// 缓存所有结果（成功和失败都缓存，避免缓存穿透）
		set_transient($cache_key, $result, 5 * MINUTE_IN_SECONDS);
		
		return $result;
	}

	/**
	 * V2版本：在checkout页面注入Security令牌
	 * 支持V2 API的完整Security验证机制
	 */
	public function inject_security_tokens() {
		// 检查是否为checkout页面（多种方式检测）
		$is_checkout_page = is_checkout() || 
		                   (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/checkout') !== false) ||
		                   (function_exists('wc_get_page_id') && is_page(wc_get_page_id('checkout'))) ||
		                   (isset($GLOBALS['post']) && $GLOBALS['post'] && has_shortcode($GLOBALS['post']->post_content, 'woocommerce_checkout'));
		
		if (!$is_checkout_page) {
			return;
		}
		
		// 检查WooCommerce可用性
		if (!function_exists('WC') || !WC()) {
			return;
		}
		
		// 防止重复注入
		if (wp_script_is('aftership-protection-nonces', 'done')) {
			return;
		}
		
		// 生成V2 API所有端点的Security tokens
		$security_tokens = array(
			'set_insurance_fee' => wp_create_nonce('aftership_set_insurance_fee'),
			'remove_insurance_fee' => wp_create_nonce('aftership_remove_insurance_fee'),
			'get_cart_details' => wp_create_nonce('aftership_get_cart_details'),
		);
		
		// 注入到前端页面供V2 API使用
		echo '<script type="text/javascript">';
		echo 'window.aftershipProtectionData = ' . json_encode(array(
			'security' => $security_tokens,
			'version' => 'v2',
			'timestamp' => time()
		)) . ';';
		echo '</script>';
	}

		/**
	 * 获取当前域名对应的app key
	 */
	private function get_current_app_key() {
		// 使用WordPress配置的域名，不依赖HTTP头部
		$host = parse_url(get_site_url(), PHP_URL_HOST);
	
		if (empty($host)) {
			// 备用方案：使用SERVER_NAME（相对更安全）
			$host = $_SERVER['SERVER_NAME'] ?? '';
		}
		
		if (empty($host)) {
			return "";
		}
		
		return sanitize_text_field($host);
	}
}