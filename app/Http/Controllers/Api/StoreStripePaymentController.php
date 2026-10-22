<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\Webhook;

use App\Models\EmployeeCommission;
use App\Models\Payment;
use App\Models\AlternativeAddress;
use App\Models\Product;
use App\Models\StoreSetting;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreWallet;
use App\Models\StoreWalletTransaction;
use App\Models\OrderItemCancellation;
use App\Models\Coupon;
use App\Models\ProductTax;

class StoreStripePaymentController extends Controller
{
    /**
     * ============================================================
     * CREATE STRIPE PAYMENT INTENT
     * ============================================================
     *
     * Product price already contains product tax.
     *
     * Example:
     * Product price = $50
     * State tax = 0%
     * Local tax = 1.82%
     *
     * State tax  = $0.00
     * Local tax  = $0.91
     * Product total remains $50.00
     *
     * Delivery charge is added directly.
     * NO tax is charged on delivery.
     */
    public function createOrder(Request $request)
    {
        try {
            $user = $request->user();

            $request->validate([
                'coupon_code' => 'nullable|string',
                'address_id' => 'nullable|exists:alternative_addresses,id',
                'wallet_amount' => 'nullable|numeric|min:0',
            ]);

            $walletInput = (float) ($request->wallet_amount ?? 0);

            /*
             * ========================================================
             * CART
             * ========================================================
             */
            $cart = Cart::where('user_id', $user->id)->firstOrFail();

            $items = CartItem::where('cart_id', $cart->id)->get();

            if ($items->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cart empty',
                ], 422);
            }

            $validatedCart = $this->validateCartItems($items);

            $subtotal = round(
                (float) $validatedCart['subtotal'],
                2
            );

            /*
             * ========================================================
             * COUPON
             * ========================================================
             */
            $discount = 0;
            $couponId = null;
            $coupon = null;

            if ($request->filled('coupon_code')) {
                $coupon = Coupon::where('code', $request->coupon_code)
                    ->where('status', 1)
                    ->whereDate('expiry_date', '>=', now())
                    ->first();

                if (!$coupon) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Invalid coupon',
                    ], 422);
                }

                if (
                    $coupon->min_amount &&
                    $subtotal < (float) $coupon->min_amount
                ) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Coupon minimum amount not met',
                    ], 422);
                }

                // Coupon validation is done here. The actual discount is
                // calculated after the tax-exclusive product base is known.
                $couponId = $coupon->id;
            }

            /*
             * ========================================================
             * DELIVERY CHARGE
             * ========================================================
             *
             * IMPORTANT:
             * Delivery has NO tax.
             */
            $deliveryCharge = round(
                $this->getDeliveryCharge(),
                2
            );

            /*
             * ========================================================
             * ADDRESS
             * ========================================================
             */
            $address = $this->getUserAddress(
                $request->address_id,
                $user->id
            );

            if ($request->address_id && !$address) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid delivery address',
                ], 422);
            }

            /*
             * ========================================================
             * PRODUCT TAX
             * ========================================================
             */
            $productTaxSetting = $this->getProductTaxForAddress($address);

            // First derive the original tax-exclusive base from tax-inclusive prices.
            $baseTaxCalculation = $this->calculateProductTax(
                $items,
                $productTaxSetting
            );

            // Coupon discounts are applied to the taxable/net amount, not to
            // the tax-inclusive unit price.
            $discount = $this->calculateCouponDiscount(
                $coupon,
                $baseTaxCalculation['taxable_amount']
            );

            $taxCalculation = $this->calculateDiscountedProductTax(
                $items,
                $productTaxSetting,
                $discount,
                $baseTaxCalculation
            );

            $afterDiscount = $taxCalculation['product_total'];
            $productStateRate = $taxCalculation['state_rate'];
            $productLocalRate = $taxCalculation['local_rate'];
            $productCombinedRate = $taxCalculation['combined_rate'];
            $productTax = $taxCalculation['tax'];
            $productTaxableAmount = $taxCalculation['taxable_amount'];
            $productStateTaxAmount = $taxCalculation['state_tax_amount'];
            $productLocalTaxAmount = $taxCalculation['local_tax_amount'];

            /*
             * ========================================================
             * SHIPPING TAX
             * ========================================================
             *
             * Delivery tax is explicitly disabled.
             */
            $shippingTaxRate = 0;
            $shippingTax = 0;

            /*
             * ========================================================
             * FINAL PRODUCT + DELIVERY AMOUNT
             * ========================================================
             *
             * VERY IMPORTANT:
             *
             * productTax is NOT added here.
             *
             * Why?
             *
             * Product price is already the customer-facing tax-inclusive
             * price.
             *
             * Tax is only a breakdown of that product price.
             *
             * Example:
             *
             * Product = $50
             * Tax = $0.91
             *
             * Customer pays $50, NOT $50.91.
             *
             * Delivery = $10
             *
             * Final = $60
             */
            $amountBeforeWallet = round(
                $afterDiscount + $deliveryCharge,
                2
            );

            /*
             * ========================================================
             * WALLET
             * ========================================================
             */
            $wallet = StoreWallet::where('user_id', $user->id)
                ->first();

            if (!$wallet) {
                $wallet = StoreWallet::create([
                    'user_id' => $user->id,
                    'balance' => 0,
                    'total_added' => 0,
                    'total_spent' => 0,
                    'total_refunded' => 0,
                ]);
            }

            if ($walletInput > (float) $wallet->balance) {
                return response()->json([
                    'status' => false,
                    'message' => 'Insufficient wallet balance',
                ], 422);
            }

            $walletUsed = min(
                $walletInput,
                $amountBeforeWallet
            );

            $walletUsed = round($walletUsed, 2);

            $finalAmount = round(
                max(
                    0,
                    $amountBeforeWallet - $walletUsed
                ),
                2
            );

            /*
             * ========================================================
             * WALLET ONLY
             * ========================================================
             */
            if ($finalAmount <= 0) {
                return response()->json([
                    'status' => true,
                    'payment_mode' => 'wallet_only',
                    'order_id' => null,

                    'breakdown' => [
                        'subtotal' => $subtotal,
                        'discount' => $discount,
                        'coupon_discount_basis' => 'taxable_amount',
                        'after_discount' => $afterDiscount,

                        'product_tax_state' =>
                            $productTaxSetting->state_name ?? null,

                        'product_tax_state_code' =>
                            $productTaxSetting->state_code ?? null,

                        'product_state_rate' =>
                            $productStateRate,

                        'product_local_rate' =>
                            $productLocalRate,

                        'product_combined_rate' =>
                            $productCombinedRate,

                        'product_taxable_amount' =>
                            $productTaxableAmount,

                        'product_tax_amount' =>
                            $productTax,

                        'product_state_tax_amount' =>
                            $productStateTaxAmount,

                        'product_local_tax_amount' =>
                            $productLocalTaxAmount,

                        'delivery_charge' =>
                            $deliveryCharge,

                        'shipping_tax_rate' =>
                            0,

                        'shipping_tax_amount' =>
                            0,

                        'wallet_used' =>
                            $walletUsed,

                        'final_amount' =>
                            0,
                    ],
                ]);
            }

            /*
             * ========================================================
             * STRIPE
             * ========================================================
             */
            Stripe::setApiKey(
                config('services.stripe.secret')
            );

            /*
             * Create a fresh PaymentIntent unless the frontend
             * explicitly sends an idempotency key.
             */
            $idempotencyKey =
                $request->header('Idempotency-Key')
                ?: 'store_pi_' . Str::uuid();

            $paymentIntent = PaymentIntent::create(
                [
                    /*
                     * Stripe receives ONLY the actual payable amount.
                     *
                     * Product tax is already included in product price.
                     * Delivery tax is disabled.
                     */
                    'amount' => (int) round(
                        $finalAmount * 100
                    ),

                    'currency' => 'usd',

                    'metadata' => [
                        'user_id' => (string) $user->id,

                        'platform' =>
                            (string) config('store.platform'),

                        'order_type' =>
                            (string) config('store.order_type'),

                        'address_id' =>
                            $request->address_id
                                ? (string) $request->address_id
                                : null,

                        'coupon_code' =>
                            $request->coupon_code,

                        'wallet_used' =>
                            (string) $walletUsed,

                        'subtotal' =>
                            (string) $subtotal,

                        'discount' =>
                            (string) $discount,

                        'delivery_charge' =>
                            (string) $deliveryCharge,

                        'product_tax_state' =>
                            $productTaxSetting->state_name ?? null,

                        'product_tax_state_code' =>
                            $productTaxSetting->state_code ?? null,

                        'product_state_rate' =>
                            (string) $productStateRate,

                        'product_local_rate' =>
                            (string) $productLocalRate,

                        'product_combined_rate' =>
                            (string) $productCombinedRate,

                        'product_tax_amount' =>
                            (string) $productTax,

                        'shipping_tax_rate' =>
                            '0',

                        'shipping_tax_amount' =>
                            '0',

                        'final_amount' =>
                            (string) $finalAmount,

                        'environment' =>
                            app()->environment(),
                    ],

                    'automatic_payment_methods' => [
                        'enabled' => true,
                    ],
                ],
                [
                    'idempotency_key' => $idempotencyKey,
                ]
            );

            return response()->json([
                'status' => true,

                'order_id' =>
                    $paymentIntent->id,

                'client_secret' =>
                    $paymentIntent->client_secret,

                'payment_intent_id' =>
                    $paymentIntent->id,

                'amount' =>
                    $finalAmount,

                'currency' =>
                    'usd',

                'breakdown' => [
                    'subtotal' =>
                        $subtotal,

                    'discount' =>
                        $discount,

                    'coupon_discount_basis' =>
                        'taxable_amount',

                    'original_taxable_amount' =>
                        round((float) $baseTaxCalculation['taxable_amount'], 2),

                    'after_discount' =>
                        $afterDiscount,

                    'product_tax_state' =>
                        $productTaxSetting->state_name ?? null,

                    'product_tax_state_code' =>
                        $productTaxSetting->state_code ?? null,

                    'product_state_rate' =>
                        $productStateRate,

                    'product_local_rate' =>
                        $productLocalRate,

                    'product_combined_rate' =>
                        $productCombinedRate,

                    'product_taxable_amount' =>
                        $productTaxableAmount,

                    'product_tax_amount' =>
                        $productTax,

                    'product_state_tax_amount' =>
                        $productStateTaxAmount,

                    'product_local_tax_amount' =>
                        $productLocalTaxAmount,

                    'delivery_charge' =>
                        $deliveryCharge,

                    'shipping_tax_rate' =>
                        0,

                    'shipping_tax_amount' =>
                        0,

                    'wallet_used' =>
                        $walletUsed,

                    'final_amount' =>
                        $finalAmount,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('STORE CREATE ORDER ERROR', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'status' => false,
                'message' =>
                    'Unable to start payment. Please try again.',
            ], 422);
        }
    }


    /**
     * ============================================================
     * VERIFY STRIPE PAYMENT + CREATE ORDER
     * ============================================================
     */
    public function verify(Request $request)
    {
        $request->validate([
            'payment_intent_id' => 'nullable|string',
            'address_id' =>
                'nullable|exists:alternative_addresses,id',
            'coupon_code' => 'nullable|string',
            'wallet_amount' =>
                'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            $user = $request->user();

            $walletInput = (float) (
                $request->wallet_amount ?? 0
            );

            /*
             * ========================================================
             * CART LOCK
             * ========================================================
             */
            $cart = Cart::where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $items = CartItem::where('cart_id', $cart->id)
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                throw new \Exception('Cart empty');
            }

            /*
             * ========================================================
             * PRODUCTS
             * ========================================================
             */
            $productIds = $items
                ->pluck('product_id')
                ->unique();

            $products = Product::whereIn(
                'id',
                $productIds
            )
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /*
             * ========================================================
             * SUBTOTAL
             * ========================================================
             */
            $subtotal = 0;

            foreach ($items as $item) {
                $product =
                    $products[$item->product_id] ?? null;

                if (!$product) {
                    throw new \Exception(
                        'Product not found'
                    );
                }

                if ($item->quantity <= 0) {
                    throw new \Exception(
                        'Invalid product quantity'
                    );
                }

                if (
                    $product->stock_qty <
                    $item->quantity
                ) {
                    throw new \Exception(
                        $product->name .
                        ' only ' .
                        $product->stock_qty .
                        ' left in stock'
                    );
                }

                if (
                    $item->price_at_time === null ||
                    $item->price_at_time <= 0
                ) {
                    throw new \Exception(
                        $product->name .
                        ' price not configured'
                    );
                }

                $subtotal += round(
                    (float) $item->total_price,
                    2
                );
            }

            $subtotal = round($subtotal, 2);

            /*
             * ========================================================
             * COUPON
             * ========================================================
             */
            $discount = 0;
            $couponId = null;
            $coupon = null;

            if ($request->filled('coupon_code')) {
                $coupon = Coupon::where(
                    'code',
                    $request->coupon_code
                )
                    ->where('status', 1)
                    ->whereDate(
                        'expiry_date',
                        '>=',
                        now()
                    )
                    ->lockForUpdate()
                    ->first();

                if (!$coupon) {
                    throw new \Exception(
                        'Invalid coupon'
                    );
                }

                if (
                    $coupon->min_amount &&
                    $subtotal <
                    (float) $coupon->min_amount
                ) {
                    throw new \Exception(
                        'Coupon minimum amount not met'
                    );
                }

                // Discount amount is calculated after retrieving the
                // destination tax rates and deriving the taxable base.
                $couponId = $coupon->id;
            }

            /*
             * ========================================================
             * DELIVERY
             * ========================================================
             */
            $deliveryCharge = round(
                $this->getDeliveryCharge(),
                2
            );

            /*
             * ========================================================
             * ADDRESS
             * ========================================================
             */
            $address = $this->getUserAddress(
                $request->address_id,
                $user->id
            );

            if (
                $request->address_id &&
                !$address
            ) {
                throw new \Exception(
                    'Invalid delivery address'
                );
            }

            /*
             * ========================================================
             * PRODUCT TAX
             * ========================================================
             */
            $productTaxSetting =
                $this->getProductTaxForAddress(
                    $address
                );

            $baseTaxCalculation = $this->calculateProductTax(
                $items,
                $productTaxSetting
            );

            $discount = $this->calculateCouponDiscount(
                $coupon,
                $baseTaxCalculation['taxable_amount']
            );

            $taxCalculation = $this->calculateDiscountedProductTax(
                $items,
                $productTaxSetting,
                $discount,
                $baseTaxCalculation
            );

            $afterDiscount = $taxCalculation['product_total'];
            $productStateRate = $taxCalculation['state_rate'];
            $productLocalRate = $taxCalculation['local_rate'];
            $productCombinedRate = $taxCalculation['combined_rate'];
            $productTax = $taxCalculation['tax'];
            $productTaxableAmount = $taxCalculation['taxable_amount'];
            $productStateTaxAmount = $taxCalculation['state_tax_amount'];
            $productLocalTaxAmount = $taxCalculation['local_tax_amount'];
            $itemTaxDetails = $taxCalculation['items'];

            /*
             * ========================================================
             * DELIVERY TAX = ZERO
             * ========================================================
             */
            $shippingTaxRate = 0;
            $shippingTax = 0;
            $shippingTaxable = $deliveryCharge;

            /*
             * ========================================================
             * FINAL PAYABLE AMOUNT
             * ========================================================
             *
             * DO NOT ADD productTax.
             *
             * Product price already contains the tax.
             */
            $amountBeforeWallet = round(
                $afterDiscount +
                $deliveryCharge,
                2
            );

            /*
             * ========================================================
             * WALLET
             * ========================================================
             */
            $wallet = StoreWallet::where(
                'user_id',
                $user->id
            )
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                $wallet = StoreWallet::create([
                    'user_id' => $user->id,
                    'balance' => 0,
                    'total_added' => 0,
                    'total_spent' => 0,
                    'total_refunded' => 0,
                ]);
            }

            if (
                $walletInput >
                (float) $wallet->balance
            ) {
                throw new \Exception(
                    'Invalid wallet usage'
                );
            }

            $walletUsed = min(
                $walletInput,
                $amountBeforeWallet
            );

            $walletUsed = round(
                $walletUsed,
                2
            );

            $finalAmount = round(
                max(
                    0,
                    $amountBeforeWallet -
                    $walletUsed
                ),
                2
            );

            /*
             * ========================================================
             * PAYMENT
             * ========================================================
             */
            $payment = null;
            $paymentData = null;
            $paymentMode = 'wallet_only';

            if ($finalAmount > 0) {
                if (!$request->payment_intent_id) {
                    throw new \Exception(
                        'Payment required'
                    );
                }

                /*
                 * Prevent duplicate processing.
                 */
                $existing = Payment::where(
                    'transaction_id',
                    $request->payment_intent_id
                )->first();

                if ($existing) {
                    DB::commit();

                    return response()->json([
                        'status' => true,
                        'message' =>
                            'Payment already processed',
                    ]);
                }

                Stripe::setApiKey(
                    config('services.stripe.secret')
                );

                try {
                    $intent = PaymentIntent::retrieve(
                        $request->payment_intent_id
                    );
                } catch (\Throwable $stripeException) {
                    Log::error(
                        'STRIPE PAYMENT INTENT RETRIEVE ERROR',
                        [
                            'payment_intent_id' =>
                                $request->payment_intent_id,

                            'error' =>
                                $stripeException->getMessage(),
                        ]
                    );

                    throw new \Exception(
                        'Payment could not be verified. Please try again.'
                    );
                }

                /*
                 * Stripe payment must be succeeded.
                 */
                if ($intent->status !== 'succeeded') {
                    Log::warning(
                        'STRIPE PAYMENT NOT SUCCEEDED',
                        [
                            'payment_intent_id' =>
                                $intent->id,

                            'status' =>
                                $intent->status,
                        ]
                    );

                    throw new \Exception(
                        'Payment was not completed. Please try again.'
                    );
                }

                /*
                 * ====================================================
                 * STRIPE AMOUNT VERIFICATION
                 * ====================================================
                 *
                 * This is now EXACTLY the same amount used in
                 * createOrder().
                 */
                $expectedAmountInCents =
                    (int) round(
                        $finalAmount * 100
                    );

                $actualAmountInCents =
                    (int) $intent->amount;

                if (
                    $actualAmountInCents !==
                    $expectedAmountInCents
                ) {
                    Log::error(
                        'STRIPE AMOUNT MISMATCH',
                        [
                            'payment_intent_id' =>
                                $intent->id,

                            'expected' =>
                                $expectedAmountInCents,

                            'actual' =>
                                $actualAmountInCents,

                            'expected_amount' =>
                                $finalAmount,

                            'actual_amount' =>
                                $actualAmountInCents / 100,
                        ]
                    );

                    throw new \Exception(
                        'Payment amount could not be verified. Please try again.'
                    );
                }

                /*
                 * Currency check.
                 */
                if (
                    strtolower(
                        $intent->currency
                    ) !== 'usd'
                ) {
                    Log::error(
                        'STRIPE CURRENCY MISMATCH',
                        [
                            'payment_intent_id' =>
                                $intent->id,

                            'currency' =>
                                $intent->currency,
                        ]
                    );

                    throw new \Exception(
                        'Payment currency could not be verified. Please try again.'
                    );
                }

                /*
                 * Metadata verification.
                 */
                $metaUserId =
                    $intent->metadata['user_id']
                    ?? null;

                $metaPlatform =
                    $intent->metadata['platform']
                    ?? null;

                $metaOrderType =
                    $intent->metadata['order_type']
                    ?? null;

                if (
                    (string) $metaUserId !==
                    (string) $user->id ||

                    $metaPlatform !==
                    config('store.platform') ||

                    $metaOrderType !==
                    config('store.order_type')
                ) {
                    Log::error(
                        'STRIPE METADATA MISMATCH',
                        [
                            'payment_intent_id' =>
                                $intent->id,

                            'expected_user_id' =>
                                $user->id,

                            'meta_user_id' =>
                                $metaUserId,

                            'meta_platform' =>
                                $metaPlatform,

                            'meta_order_type' =>
                                $metaOrderType,
                        ]
                    );

                    throw new \Exception(
                        'Payment could not be verified. Please try again.'
                    );
                }

                /*
                 * Environment verification.
                 */
                $metaEnvironment =
                    $intent->metadata['environment']
                    ?? null;

                if (
                    $metaEnvironment !==
                    app()->environment()
                ) {
                    Log::error(
                        'STRIPE ENVIRONMENT MISMATCH',
                        [
                            'payment_intent_id' =>
                                $intent->id,

                            'expected_environment' =>
                                app()->environment(),

                            'meta_environment' =>
                                $metaEnvironment,
                        ]
                    );

                    throw new \Exception(
                        'Payment could not be verified. Please try again.'
                    );
                }

                $paymentData =
                    $intent->toArray();

                $paymentMode = 'online';

                /*
                 * Payment method.
                 */
                if ($intent->payment_method) {
                    try {
                        $paymentMethod =
                            PaymentMethod::retrieve(
                                $intent->payment_method
                            );

                        $paymentMode =
                            $paymentMethod->type;

                        if (
                            $paymentMethod->type === 'card' &&
                            !empty(
                                $paymentMethod
                                    ->card
                                    ->wallet
                                    ->type
                                    ?? null
                            )
                        ) {
                            $paymentMode =
                                $paymentMethod
                                    ->card
                                    ->wallet
                                    ->type;
                        }
                    } catch (\Throwable $stripeException) {
                        Log::warning(
                            'STRIPE PAYMENT METHOD RETRIEVE ERROR',
                            [
                                'payment_intent_id' =>
                                    $intent->id,

                                'payment_method' =>
                                    $intent->payment_method,

                                'error' =>
                                    $stripeException->getMessage(),
                            ]
                        );
                    }
                }

                /*
                 * ====================================================
                 * PAYMENT RECORD
                 * ====================================================
                 */
                $payment = Payment::create([
                    'user_id' =>
                        $user->id,

                    'platform' =>
                        'valeenza',

                    /*
                     * Keep the existing behavior here.
                     * If your payments.order_id column is a foreign key
                     * to orders.id, this field should be NULL until
                     * the order is created.
                     */
                    'order_id' =>
                        null,

                    'payment_gateway' =>
                        'stripe',

                    'transaction_id' =>
                        $request->payment_intent_id,

                    'amount' =>
                        $finalAmount,

                    'currency' =>
                        'USD',

                    'payment_status' =>
                        'success',

                    'payment_mode' =>
                        $paymentMode,

                    'customer_email' =>
                        $user->email,

                    'customer_phone' =>
                        trim(
                            ($user->country_code ?? '') .
                            ($user->mobile ?? '')
                        ),

                    'payment_request_data' => [
                        'subtotal' =>
                            $subtotal,

                        'discount' =>
                            $discount,

                        'wallet_requested' =>
                            $request->wallet_amount,

                        'wallet_used' =>
                            $walletUsed,

                        'final_amount' =>
                            $finalAmount,

                        'delivery_charge' =>
                            $deliveryCharge,

                        'product_tax_state' =>
                            $productTaxSetting->state_name
                                ?? null,

                        'product_tax_state_code' =>
                            $productTaxSetting->state_code
                                ?? null,

                        'product_state_rate' =>
                            $productStateRate,

                        'product_local_rate' =>
                            $productLocalRate,

                        'product_combined_rate' =>
                            $productCombinedRate,

                        'product_tax_amount' =>
                            $productTax,

                        'shipping_tax_rate' =>
                            0,

                        'shipping_tax_amount' =>
                            0,

                        'coupon_code' =>
                            $request->coupon_code,
                    ],

                    'payment_response_data' =>
                        $paymentData,
                ]);
            }

            /*
             * ========================================================
             * HSN
             * ========================================================
             */
            $hsnCodes = [];

            foreach ($items as $item) {
                $product =
                    $products[$item->product_id]
                    ?? null;

                if (
                    $product &&
                    $product->hsn_code
                ) {
                    $hsnCodes[] =
                        $product->hsn_code;
                }
            }

            $hsnCodes = array_unique(
                $hsnCodes
            );

            $hsnCode = implode(
                ',',
                $hsnCodes
            );

            /*
             * ========================================================
             * TAX TYPE
             * ========================================================
             *
             * This is US sales tax, not Indian GST.
             */
            $taxType = 'us_sales_tax';

            $productCgstAmount = 0;
            $productSgstAmount = 0;
            $productIgstAmount = 0;

            $shippingCgstAmount = 0;
            $shippingSgstAmount = 0;
            $shippingIgstAmount = 0;

            /*
             * ========================================================
             * STOCK
             * ========================================================
             */
            foreach ($items as $item) {
                $product =
                    $products[$item->product_id]
                    ?? null;

                if (!$product) {
                    throw new \Exception(
                        'Product not found'
                    );
                }

                $newStock =
                    $product->stock_qty -
                    $item->quantity;

                $status = 'in_stock';

                if ($newStock <= 0) {
                    $newStock = 0;
                    $status = 'out_of_stock';
                } elseif ($newStock <= 5) {
                    $status = 'few_left';
                }

                $product->update([
                    'stock_qty' =>
                        $newStock,

                    'stock_status' =>
                        $status,
                ]);
            }

            /*
             * ========================================================
             * INVOICE NUMBER
             * ========================================================
             */
            $start =
                (int) env(
                    'INVOICE_START_NUMBER',
                    1
                );

            $usedNumbers =
                Order::whereNotNull(
                    'invoice_sequence'
                )
                    ->orderBy(
                        'invoice_sequence'
                    )
                    ->pluck(
                        'invoice_sequence'
                    )
                    ->toArray();

            $nextInvoiceSequence =
                $start;

            foreach ($usedNumbers as $number) {
                if (
                    $number ==
                    $nextInvoiceSequence
                ) {
                    $nextInvoiceSequence++;
                } elseif (
                    $number >
                    $nextInvoiceSequence
                ) {
                    break;
                }
            }

            /*
             * ========================================================
             * ORDER
             * ========================================================
             *
             * IMPORTANT:
             * total_amount does NOT add productTax again.
             */
            $orderTotal = round(
                $afterDiscount +
                $deliveryCharge,
                2
            );

            $order = Order::create([
                'user_id' =>
                    $user->id,

                'address_id' =>
                    $request->address_id,

                'name' =>
                    $address->name
                    ?? $user->name
                    ?? null,

                'email' =>
                    $address->email
                    ?? $user->email
                    ?? null,

                'mobile' =>
                    $address->mobile
                    ?? null,

                'alternative_mobile' =>
                    $address->alternative_mobile
                    ?? null,

                'city' =>
                    $address->city
                    ?? null,

                'state' =>
                    $address->state
                    ?? null,

                'state_code' =>
                    $address->state_code
                    ?? null,

                'country' =>
                    $address->country
                    ?? 'USA',

                'address' =>
                    $address->address
                    ?? null,

                'pincode' =>
                    $address->pincode
                    ?? null,

                'coupon_id' =>
                    $couponId,

                'payment_id' =>
                    $payment
                        ? $payment->id
                        : null,

                'order_number' =>
                    'ORD-' .
                    strtoupper(
                        uniqid()
                    ),

                'invoice_sequence' =>
                    $nextInvoiceSequence,

                'invoice_number' =>
                    'VLNZ-' .
                    str_pad(
                        $nextInvoiceSequence,
                        4,
                        '0',
                        STR_PAD_LEFT
                    ),

                'hsn_code' =>
                    $hsnCode,

                'subtotal' =>
                    $subtotal,

                'discount' =>
                    $discount,

                'delivery_charge' =>
                    $deliveryCharge,

                'wallet_used' =>
                    $walletUsed,

                'paid_amount' =>
                    $finalAmount,

                /*
                 * Product tax is already included in product price.
                 */
                'total_amount' =>
                    $orderTotal,

                /*
                 * Keep tax fields for reporting/breakdown.
                 */
                'taxable_amount' =>
                    $productTaxableAmount,

                // Legacy India-GST columns are kept for schema compatibility.
                // US sales tax is stored in price_breakdown below.
                'gst_rate' =>
                    0,

                'cgst_amount' =>
                    0,

                'sgst_amount' =>
                    0,

                'igst_amount' =>
                    0,

                'tax_type' =>
                    $taxType,

                'price_breakdown' => [
                    'subtotal' =>
                        $subtotal,

                    'coupon_discount' =>
                        $discount,

                    'coupon_discount_basis' =>
                        'taxable_amount',

                    'original_taxable_amount' =>
                        round((float) $baseTaxCalculation['taxable_amount'], 2),

                    'after_discount' =>
                        $afterDiscount,

                    /*
                     * PRODUCT TAX INFORMATION
                     */
                    'product_unit_prices_tax_included' => true,

                    'product_tax_state' =>
                        $productTaxSetting->state_name
                            ?? null,

                    'product_tax_state_code' =>
                        $productTaxSetting->state_code
                            ?? null,

                    'product_state_rate' =>
                        $productStateRate,

                    'product_local_rate' =>
                        $productLocalRate,

                    'product_combined_rate' =>
                        $productCombinedRate,

                    'product_taxable_amount' =>
                        $productTaxableAmount,

                    'product_tax_amount' =>
                        $productTax,

                    'product_state_tax_amount' =>
                        $productStateTaxAmount,

                    'product_local_tax_amount' =>
                        $productLocalTaxAmount,

                    /*
                     * DELIVERY
                     */
                    'delivery_charge' =>
                        $deliveryCharge,

                    /*
                     * DELIVERY TAX IS ZERO
                     */
                    'shipping_tax_rate' =>
                        0,

                    'shipping_tax_amount' =>
                        0,

                    'shipping_taxable_amount' =>
                        $shippingTaxable,

                    /*
                     * OLD/GENERIC TAX FIELDS
                     */
                    // Legacy India-GST fields kept at zero.
                    'gst_rate' =>
                        0,

                    'tax_type' =>
                        $taxType,

                    'cgst_amount' =>
                        0,

                    'sgst_amount' =>
                        0,

                    'igst_amount' =>
                        0,

                    /*
                     * PAYMENT
                     */
                    'wallet_used' =>
                        $walletUsed,

                    'paid_online' =>
                        $finalAmount,

                    /*
                     * THIS IS THE ACTUAL CUSTOMER PAYABLE
                     */
                    'final_amount' =>
                        $orderTotal,
                ],

                'status' =>
                    'paid',

                'paid_at' =>
                    now(),
            ]);

            /*
             * ========================================================
             * UPDATE PAYMENT WITH ACTUAL ORDER ID
             * ========================================================
             */
            if ($payment) {
                $payment->update([
                    'order_id' =>
                        $order->id,
                ]);
            }

            /*
             * ========================================================
             * EMPLOYEE COMMISSION
             * ========================================================
             */
            if (
                $couponId &&
                $coupon &&
                $coupon->employee_id &&
                $coupon->employee_id != 1
            ) {
                $percentage =
                    $coupon->employee
                        ->commission_percentage
                        ?? 0;

                $commissionAmount =
                    (
                        $order->total_amount *
                        $percentage
                    ) / 100;

                EmployeeCommission::create([
                    'employee_id' =>
                        $coupon->employee_id,

                    'order_id' =>
                        $order->id,

                    'coupon_id' =>
                        $coupon->id,

                    'order_amount' =>
                        $order->total_amount,

                    'commission_percentage' =>
                        $percentage,

                    'commission_amount' =>
                        round(
                            $commissionAmount,
                            2
                        ),

                    'status' =>
                        'delivery_pending',
                ]);
            }

            /*
             * ========================================================
             * WALLET DEDUCTION
             * ========================================================
             */
            $walletTransaction = null;

            if ($walletUsed > 0) {
                $wallet->refresh();

                if (
                    (float) $wallet->balance <
                    $walletUsed
                ) {
                    throw new \Exception(
                        'Wallet changed, retry'
                    );
                }

                $before =
                    (float) $wallet->balance;

                $after = round(
                    $before -
                    $walletUsed,
                    2
                );

                $wallet->update([
                    'balance' =>
                        $after,

                    'total_spent' =>
                        (float) $wallet->total_spent +
                        $walletUsed,
                ]);

                $walletTransaction =
                    StoreWalletTransaction::create([
                        'user_id' =>
                            $user->id,

                        'order_id' =>
                            $order->id,

                        'type' =>
                            'debit',

                        'amount' =>
                            $walletUsed,

                        'source' =>
                            'order_payment',

                        'balance_before' =>
                            $before,

                        'balance_after' =>
                            $after,

                        'note' =>
                            'Wallet used in order #' .
                            $order->id,
                    ]);
            }

            /*
             * ========================================================
             * PACKAGE DIMENSIONS
             * ========================================================
             */
            $totalWeight = 0;
            $maxLength = 0;
            $maxBreadth = 0;
            $totalHeight = 0;

            /*
             * ========================================================
             * ORDER ITEMS
             * ========================================================
             */
            foreach ($items as $item) {
                $product =
                    $products[$item->product_id]
                    ?? null;

                if (!$product) {
                    throw new \Exception(
                        'Product not found'
                    );
                }

                $totalWeight +=
                    (
                        (float) ($product->weight ?? 0) *
                        $item->quantity
                    );

                $maxLength = max(
                    $maxLength,
                    (float) ($product->length ?? 0)
                );

                $maxBreadth = max(
                    $maxBreadth,
                    (float) ($product->breadth ?? 0)
                );

                $totalHeight +=
                    (
                        (float) ($product->height ?? 0) *
                        $item->quantity
                    );

                $itemTax =
                    (float) (
                        $itemTaxDetails[$item->id]['tax']
                        ?? 0
                    );

                /*
                 * Product total is the actual customer-facing
                 * tax-inclusive product amount.
                 */
                $itemTaxableAmount =
                    round(
                        (float) $item->total_price,
                        2
                    );

                $itemTaxRate =
                    $productCombinedRate;

                $itemCgst = 0;
                $itemSgst = 0;
                $itemIgst = 0;

                OrderItem::create([
                    'order_id' =>
                        $order->id,

                    'product_id' =>
                        $item->product_id,

                    'product_name' =>
                        $product->name ?? '',

                    'product_slug' =>
                        $product->slug ?? '',

                    'product_image' =>
                        $product->image ?? '',

                    'ratti' =>
                        $item->ratti,

                    'quantity' =>
                        $item->quantity,

                    'price' =>
                        round((float) $item->price_at_time, 2),

                    'total' =>
                        round((float) $item->total_price, 2),

                    'weight' =>
                        $product->weight,

                    'length' =>
                        $product->length,

                    'breadth' =>
                        $product->breadth,

                    'height' =>
                        $product->height,

                    // Legacy India-GST columns are kept at zero.
                    // Detailed US tax is stored at order level in price_breakdown.
                    'gst_rate' =>
                        0,

                    'gst_amount' =>
                        0,

                    'taxable_amount' =>
                        $itemTaxDetails[$item->id]['taxable_amount'] ?? $itemTaxableAmount,

                    'cgst_amount' =>
                        0,

                    'sgst_amount' =>
                        0,

                    'igst_amount' =>
                        0,

                    'tax_type' =>
                        $taxType,

                    'hsn_code' =>
                        $product->hsn_code,
                ]);
            }

            /*
             * ========================================================
             * UPDATE PACKAGE DETAILS
             * ========================================================
             */
            $order->update([
                'total_weight' =>
                    $totalWeight,

                'box_length' =>
                    $maxLength,

                'box_breadth' =>
                    $maxBreadth,

                'box_height' =>
                    $totalHeight,
            ]);

            /*
             * ========================================================
             * CLEAR CART
             * ========================================================
             */
            CartItem::where(
                'cart_id',
                $cart->id
            )->delete();

            DB::commit();

            /*
             * ========================================================
             * RESPONSE
             * ========================================================
             */
            $order->refresh();

            $order->load([
                'items',
                'payment',
                'user',
            ]);

            return response()->json([
                'status' => true,

                'message' =>
                    'Order placed successfully',

                'order' => [
                    'order_id' =>
                        $order->id,

                    'invoice_number' =>
                        $order->invoice_number,

                    'order_number' =>
                        $order->order_number,

                    'status' =>
                        $order->status,

                    'pricing' => [
                        'subtotal' =>
                            $subtotal,

                        'discount' =>
                            $discount,

                        'after_discount' =>
                            $afterDiscount,

                        'product_tax_state' =>
                            $productTaxSetting->state_name
                                ?? null,

                        'product_tax_state_code' =>
                            $productTaxSetting->state_code
                                ?? null,

                        'product_state_rate' =>
                            $productStateRate,

                        'product_local_rate' =>
                            $productLocalRate,

                        'product_combined_rate' =>
                            $productCombinedRate,

                        'product_taxable_amount' =>
                            $productTaxableAmount,

                        'product_tax_amount' =>
                            $productTax,

                        'delivery_charge' =>
                            $deliveryCharge,

                        'shipping_tax_rate' =>
                            0,

                        'shipping_tax_amount' =>
                            0,

                        'wallet_used' =>
                            $walletUsed,

                        'paid_online' =>
                            $finalAmount,

                        /*
                         * Actual customer payable.
                         */
                        'final_amount' =>
                            $orderTotal,
                    ],

                    'payment' =>
                        $payment
                            ? [
                                'transaction_id' =>
                                    $payment->transaction_id,

                                'payment_gateway' =>
                                    $payment->payment_gateway,

                                'payment_mode' =>
                                    $payment->payment_mode,

                                'amount' =>
                                    $payment->amount,

                                'currency' =>
                                    $payment->currency,

                                'status' =>
                                    $payment->payment_status,
                            ]
                            : [
                                'transaction_id' =>
                                    $walletTransaction
                                        ? 'WALLET-TXN-' .
                                            $walletTransaction->id
                                        : null,

                                'payment_gateway' =>
                                    'wallet',

                                'payment_mode' =>
                                    'wallet_only',

                                'amount' =>
                                    $walletUsed,

                                'currency' =>
                                    'USD',

                                'status' =>
                                    'success',
                            ],

                    'items' =>
                        $order->items->map(
                            function ($item) {
                                return [
                                    'product_id' =>
                                        $item->product_id,

                                    'name' =>
                                        $item->product_name,

                                    'image' =>
                                        $item->product_image,

                                    'quantity' =>
                                        $item->quantity,

                                    'price' =>
                                        $item->price,

                                    'total' =>
                                        $item->total,

                                    'hsn_code' =>
                                        $item->hsn_code,

                                    'gst_rate' =>
                                        $item->gst_rate,

                                    'gst_amount' =>
                                        $item->gst_amount,

                                    'taxable_amount' =>
                                        $item->taxable_amount,

                                    'cgst_amount' =>
                                        $item->cgst_amount,

                                    'sgst_amount' =>
                                        $item->sgst_amount,

                                    'igst_amount' =>
                                        $item->igst_amount,

                                    'tax_type' =>
                                        $item->tax_type,
                                ];
                            }
                        ),
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error(
                'STORE PAYMENT ERROR',
                [
                    'message' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine(),
                ]
            );

            return response()->json([
                'status' => false,
                'message' =>
                    'Payment could not be completed. Please try again.',
            ], 422);
        }
    }


    /**
     * ============================================================
     * STRIPE WEBHOOK
     * ============================================================
     */
    public function webhook(Request $request)
    {
        Stripe::setApiKey(
            config('services.stripe.secret')
        );

        $payload =
            $request->getContent();

        $sigHeader =
            $request->header(
                'Stripe-Signature'
            );

        $endpointSecret =
            config(
                'services.stripe.webhook_secret'
            );

        try {
            $event =
                Webhook::constructEvent(
                    $payload,
                    $sigHeader,
                    $endpointSecret
                );
        } catch (\Throwable $e) {
            Log::error(
                'STRIPE WEBHOOK SIGNATURE ERROR',
                [
                    'error' =>
                        $e->getMessage(),
                ]
            );

            return response()->json([
                'status' => false,
            ], 400);
        }

        try {
            switch ($event->type) {
                case 'payment_intent.succeeded':

                    $intent =
                        $event->data->object;

                    Payment::where(
                        'transaction_id',
                        $intent->id
                    )->update([
                        'payment_status' =>
                            'success',
                    ]);

                    break;


                case 'payment_intent.payment_failed':

                    $intent =
                        $event->data->object;

                    Payment::where(
                        'transaction_id',
                        $intent->id
                    )->update([
                        'payment_status' =>
                            'failed',
                    ]);

                    Log::error(
                        'STRIPE PAYMENT FAILED',
                        [
                            'payment_intent' =>
                                $intent->id,
                        ]
                    );

                    break;


                case 'charge.refunded':

                    $charge =
                        $event->data->object;

                    Payment::where(
                        'transaction_id',
                        $charge->payment_intent
                    )->update([
                        'payment_status' =>
                            'refunded',
                    ]);

                    Log::warning(
                        'STRIPE CHARGE REFUNDED',
                        [
                            'payment_intent' =>
                                $charge->payment_intent,
                        ]
                    );

                    break;


                case 'charge.dispute.created':

                    $dispute =
                        $event->data->object;

                    Payment::where(
                        'transaction_id',
                        $dispute->payment_intent
                    )->update([
                        'payment_status' =>
                            'disputed',
                    ]);

                    Log::error(
                        'STRIPE DISPUTE CREATED',
                        [
                            'payment_intent' =>
                                $dispute->payment_intent,

                            'reason' =>
                                $dispute->reason
                                ?? null,
                        ]
                    );

                    break;


                default:
                    break;
            }
        } catch (\Throwable $e) {
            Log::error(
                'STRIPE WEBHOOK PROCESSING ERROR',
                [
                    'event_type' =>
                        $event->type ?? null,

                    'error' =>
                        $e->getMessage(),
                ]
            );
        }

        return response()->json([
            'status' => true,
        ]);
    }


    /**
     * ============================================================
     * CALCULATE CHECKOUT SUMMARY
     * ============================================================
     */
    public function calculateSummary(Request $request)
    {
        $request->validate([
            'address_id'   => 'nullable|integer|exists:alternative_addresses,id',
            'coupon_code'  => 'nullable|string|max:100',
            'wallet_amount' => 'nullable|numeric|min:0',
        ]);

        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        try {
            // 1. Get customer's cart
            $cart = Cart::where('user_id', $user->id)->first();

            if (!$cart) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cart not found',
                ], 422);
            }

            $items = CartItem::where('cart_id', $cart->id)->get();

            if ($items->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cart empty',
                ], 422);
            }

            // 2. Validate products, quantities, prices and stock
            $validatedCart = $this->validateCartItems($items);

            $subtotal = round(
                (float) $validatedCart['subtotal'],
                2
            );

            // 3. Calculate coupon discount
            $discount = 0;
            $coupon = null;

            if ($request->filled('coupon_code')) {
                $coupon = Coupon::where(
                    'code',
                    $request->coupon_code
                )
                    ->where('status', 1)
                    ->whereDate('expiry_date', '>=', now())
                    ->first();

                if (!$coupon) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Invalid coupon',
                    ], 422);
                }

                if (
                    $coupon->min_amount &&
                    $subtotal < (float) $coupon->min_amount
                ) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Coupon minimum amount not met',
                    ], 422);
                }

                // The discount amount is calculated below from the
                // tax-exclusive product subtotal.
            }

            // 4. Delivery charge
            $deliveryCharge = round(
                $this->getDeliveryCharge(),
                2
            );

            // 5. Get customer's selected address
            $address = $this->getUserAddress(
                $request->address_id,
                $user->id
            );

            if ($request->address_id && !$address) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid delivery address',
                ], 422);
            }

            // 6. Get tax rates for the selected state
            $productTaxSetting = $this->getProductTaxForAddress(
                $address
            );

            // Calculate the original tax-exclusive base first, then apply
            // the coupon to that base and calculate tax on the discounted base.
            $baseTaxCalculation = $this->calculateProductTax(
                $items,
                $productTaxSetting
            );

            $discount = $this->calculateCouponDiscount(
                $coupon,
                $baseTaxCalculation['taxable_amount']
            );

            $taxCalculation = $this->calculateDiscountedProductTax(
                $items,
                $productTaxSetting,
                $discount,
                $baseTaxCalculation
            );

            $afterDiscount = $taxCalculation['product_total'];
            $productStateRate = $taxCalculation['state_rate'];
            $productLocalRate = $taxCalculation['local_rate'];
            $productCombinedRate = $taxCalculation['combined_rate'];
            $productTax = $taxCalculation['tax'];
            $productTaxableAmount = $taxCalculation['taxable_amount'];
            $productStateTaxAmount = $taxCalculation['state_tax_amount'];
            $productLocalTaxAmount = $taxCalculation['local_tax_amount'];

            // 7. Product tax is included in the product price.
            // Delivery tax is disabled by the existing business rule.
            $shippingTaxRate = 0;
            $shippingTaxAmount = 0;

            $orderTotal = round(
                $afterDiscount + $deliveryCharge,
                2
            );

            // 8. Calculate wallet usage without deducting money.
            $walletInput = round(
                (float) ($request->wallet_amount ?? 0),
                2
            );

            $wallet = StoreWallet::where(
                'user_id',
                $user->id
            )->first();

            $walletBalance = round(
                (float) ($wallet->balance ?? 0),
                2
            );

            if ($walletInput > $walletBalance) {
                return response()->json([
                    'status' => false,
                    'message' => 'Insufficient wallet balance',
                ], 422);
            }

            $walletUsed = round(
                min($walletInput, $orderTotal),
                2
            );

            $payableAmount = round(
                max(0, $orderTotal - $walletUsed),
                2
            );

            // 9. Return a compact summary with names aligned to invoice fields.
            // This is only a preview: it does not create an order or deduct wallet funds.
            return response()->json([
                'status' => true,
                'message' => 'Checkout summary calculated successfully',
                'data' => [
                    'currency' => 'USD',

                    'products' => [
                        // Original product total includes embedded State/Local tax.
                        'total_before_discount_including_tax' => round($subtotal, 2),

                        // Tax-exclusive net amount before applying the coupon.
                        'net_amount_before_discount' => round(
                            (float) $baseTaxCalculation['taxable_amount'],
                            2
                        ),

                        'coupon' => [
                            'code' => $coupon?->code,
                            'discount_amount' => round($discount, 2),
                            'applied_to' => 'net_amount_before_discount',
                        ],

                        // Tax-exclusive amount after the coupon is deducted.
                        'net_amount_after_discount' => round(
                            (float) $productTaxableAmount,
                            2
                        ),

                        // Discounted product total, tax included.
                        'total_after_discount_including_tax' => round($afterDiscount, 2),
                    ],

                    'tax' => [
                        'included_in_product_price' => true,
                        'jurisdiction' => [
                            'state' => $productTaxSetting->state_name ?? null,
                            'state_code' => $productTaxSetting->state_code ?? null,
                        ],
                        'state' => [
                            'rate_percent' => round((float) $productStateRate, 2),
                            'amount' => round((float) $productStateTaxAmount, 2),
                        ],
                        'local' => [
                            'rate_percent' => round((float) $productLocalRate, 2),
                            'amount' => round((float) $productLocalTaxAmount, 2),
                        ],
                        'total_amount' => round((float) $productTax, 2),
                    ],

                    'delivery' => [
                        'charge' => round($deliveryCharge, 2),
                        'tax_amount' => round((float) $shippingTaxAmount, 2),
                    ],

                    'wallet' => [
                        'available_balance' => round($walletBalance, 2),
                        'amount_applied' => round($walletUsed, 2),
                    ],

                    'totals' => [
                        'amount_before_wallet' => round($orderTotal, 2),
                        'payable_amount' => round($payableAmount, 2),
                    ],
                ],
            ]);

        } catch (\Throwable $e) {
            Log::error('STORE SUMMARY ERROR', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Unable to calculate order summary. Please try again.',
            ], 422);
        }
    }


    /**
     * ============================================================
     * CANCEL ORDER
     * ============================================================
     */
    public function cancelOrder(
        Request $request,
        $id
    ) {
        $request->validate([
            'cancel_reason' =>
                'required|array|min:1',

            'cancel_reason.*' =>
                'string|max:255',
        ]);

        $cancelReason =
            implode(
                ', ',
                $request->cancel_reason
            );

        DB::beginTransaction();

        try {
            $user =
                auth()->user();

            $order =
                Order::where(
                    'id',
                    $id
                )
                    ->where(
                        'user_id',
                        $user->id
                    )
                    ->with('items')
                    ->lockForUpdate()
                    ->firstOrFail();

            if (
                $order->status ===
                'cancelled'
            ) {
                DB::rollBack();

                return response()->json([
                    'status' => false,
                    'message' =>
                        'Order already cancelled',
                ], 422);
            }

            if (
                in_array(
                    $order->status,
                    [
                        'shipped',
                        'delivered',
                    ]
                )
            ) {
                DB::rollBack();

                return response()->json([
                    'status' => false,
                    'message' =>
                        'Order cannot be cancelled now',
                ], 422);
            }

            /*
             * Refund the discounted product total (including embedded tax),
             * but do not refund delivery. This keeps cancellation consistent
             * with the coupon being applied to the tax-exclusive taxable base.
             */
            $cancellationPricing = $order->price_breakdown ?? [];

            if (is_string($cancellationPricing)) {
                $cancellationPricing = json_decode($cancellationPricing, true) ?: [];
            } elseif (is_object($cancellationPricing)) {
                $cancellationPricing = (array) $cancellationPricing;
            }

            $refundAmount = round(
                (float) (
                    $cancellationPricing['after_discount']
                    ?? max(0, (float) $order->subtotal - (float) $order->discount)
                ),
                2
            );

            /*
             * WALLET
             */
            $wallet =
                StoreWallet::where(
                    'user_id',
                    $user->id
                )
                    ->lockForUpdate()
                    ->first();

            if (!$wallet) {
                $wallet =
                    StoreWallet::create([
                        'user_id' =>
                            $user->id,

                        'balance' =>
                            0,

                        'total_added' =>
                            0,

                        'total_spent' =>
                            0,

                        'total_refunded' =>
                            0,
                    ]);
            }

            $before =
                (float) $wallet->balance;

            $after =
                round(
                    $before +
                    $refundAmount,
                    2
                );

            $wallet->update([
                'balance' =>
                    $after,

                'total_refunded' =>
                    (float)
                        $wallet->total_refunded +
                        $refundAmount,
            ]);

            /*
             * WALLET TRANSACTION
             */
            $walletTransaction =
                StoreWalletTransaction::create([
                    'user_id' =>
                        $user->id,

                    'order_id' =>
                        $order->id,

                    'type' =>
                        'credit',

                    'amount' =>
                        $refundAmount,

                    'source' =>
                        'order_cancel',

                    'balance_before' =>
                        $before,

                    'balance_after' =>
                        $after,

                    'note' =>
                        'Order cancelled refund',
                ]);

            /*
             * RESTORE STOCK
             */
            foreach ($order->items as $item) {
                $product =
                    Product::where(
                        'id',
                        $item->product_id
                    )
                        ->lockForUpdate()
                        ->first();

                if (!$product) {
                    continue;
                }

                $newStock =
                    $product->stock_qty +
                    $item->quantity;

                $status = 'in_stock';

                if ($newStock <= 0) {
                    $newStock = 0;
                    $status = 'out_of_stock';
                } elseif ($newStock <= 5) {
                    $status = 'few_left';
                }

                $product->update([
                    'stock_qty' =>
                        $newStock,

                    'stock_status' =>
                        $status,
                ]);

                /*
                 * ITEM REFUND
                 */
                $itemTotal =
                    (float) $item->total;

                $itemRefund = 0;

                $totalOrderAmount =
                    (float) $order->subtotal;

                if ($totalOrderAmount > 0) {
                    $itemRefund =
                        (
                            $itemTotal /
                            $totalOrderAmount
                        ) *
                        $refundAmount;
                }

                OrderItemCancellation::create([
                    'order_id' =>
                        $order->id,

                    'order_item_id' =>
                        $item->id,

                    'user_id' =>
                        $user->id,

                    'quantity' =>
                        $item->quantity,

                    'refund_amount' =>
                        round(
                            $itemRefund,
                            2
                        ),

                    'cancelled_at' =>
                        now(),

                    'reason' =>
                        $cancelReason,
                ]);
            }

            /*
             * PAYMENT STATUS
             */
            if ($order->payment_id) {
                Payment::where(
                    'id',
                    $order->payment_id
                )->update([
                    'payment_status' =>
                        'refunded',
                ]);
            }

            /*
             * COMMISSION
             */
            EmployeeCommission::where(
                'order_id',
                $order->id
            )->update([
                'status' =>
                    'cancelled',
            ]);

            /*
             * ORDER
             */
            $order->update([
                'status' =>
                    'cancelled',

                'shipping_status' =>
                    'cancelled',

                'cancelled_at' =>
                    now(),

                'cancel_reason' =>
                    $cancelReason,
            ]);

            DB::commit();

            $order->refresh();

            return response()->json([
                'status' => true,

                'message' =>
                    'Order cancelled & refunded',

                'refund' => [
                    'amount' =>
                        $refundAmount,

                    'wallet_before' =>
                        $before,

                    'wallet_after' =>
                        $after,
                ],

                'cancel_reason' =>
                    $order->cancel_reason,

                'pricing' =>
                    $order->price_breakdown,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error(
                'STORE CANCEL ORDER ERROR',
                [
                    'order_id' =>
                        $id,

                    'message' =>
                        $e->getMessage(),
                ]
            );

            return response()->json([
                'status' => false,
                'message' =>
                    'Unable to cancel order. Please try again.',
            ], 422);
        }
    }


    /**
     * ============================================================
     * ORDER DETAILS
     * ============================================================
     */
    public function orderDetails($id)
    {
        $user =
            auth()->user();

        $order =
            Order::with('items')
                ->where(
                    'id',
                    $id
                )
                ->where(
                    'user_id',
                    $user->id
                )
                ->firstOrFail();

        return response()->json([
            'status' => true,

            'data' => [
                'order_id' =>
                    $order->id,

                'status' =>
                    $order->status,

                'pricing' =>
                    $order->price_breakdown,

                'items' =>
                    $order->items,
            ],
        ]);
    }


    /**
     * ============================================================
     * VALIDATE CART ITEMS
     * ============================================================
     */
    private function validateCartItems($items)
    {
        $productIds =
            $items
                ->pluck('product_id')
                ->unique();

        $products =
            Product::whereIn(
                'id',
                $productIds
            )
                ->get()
                ->keyBy('id');

        $subtotal = 0;

        foreach ($items as $item) {
            $product =
                $products[$item->product_id]
                ?? null;

            if (!$product) {
                throw new \Exception(
                    'Product not found'
                );
            }

            if ($item->quantity <= 0) {
                throw new \Exception(
                    'Invalid quantity'
                );
            }

            if (
                $product->stock_qty <
                $item->quantity
            ) {
                throw new \Exception(
                    $product->name .
                    ' only ' .
                    $product->stock_qty .
                    ' left in stock'
                );
            }

            /*
             * Use the price stored at cart time.
             */
            if (
                $item->price_at_time === null ||
                $item->price_at_time <= 0
            ) {
                throw new \Exception(
                    $product->name .
                    ' price not configured'
                );
            }

            $subtotal += round(
                (float) $item->total_price,
                2
            );
        }

        return [
            'subtotal' =>
                round(
                    $subtotal,
                    2
                ),

            'products' =>
                $products,
        ];
    }


    /**
     * ============================================================
     * GET USER ADDRESS
     * ============================================================
     */
    private function getUserAddress(
        $addressId,
        $userId
    ) {
        if (!$addressId) {
            return null;
        }

        return AlternativeAddress::query()
            ->where(
                'id',
                $addressId
            )
            ->where(
                'user_id',
                $userId
            )
            ->first();
    }


    /**
     * ============================================================
     * GET PRODUCT TAX FOR ADDRESS
     * ============================================================
     *
     * First priority:
     *     state_code
     *
     * Fallback:
     *     state name
     *
     * Only active tax records are used.
     */
    private function getProductTaxForAddress(
        $address
    ) {
        if (!$address) {
            return null;
        }

        $taxQuery =
            ProductTax::query()
                ->where(
                    'status',
                    1
                );

        /*
         * STATE CODE MATCH
         *
         * Example:
         * Address = AK
         * ProductTax = AK
         */
        if (
            !empty(
                $address->state_code
            )
        ) {
            $stateCode =
                strtoupper(
                    trim(
                        $address->state_code
                    )
                );

            $tax =
                (clone $taxQuery)
                    ->whereRaw(
                        'UPPER(TRIM(state_code)) = ?',
                        [
                            $stateCode,
                        ]
                    )
                    ->first();

            if ($tax) {
                return $tax;
            }
        }

        /*
         * STATE NAME FALLBACK
         *
         * Example:
         * Address = Alaska
         * ProductTax = Alaska
         */
        if (
            !empty(
                $address->state
            )
        ) {
            $stateName =
                strtolower(
                    trim(
                        $address->state
                    )
                );

            return $taxQuery
                ->whereRaw(
                    'LOWER(TRIM(state_name)) = ?',
                    [
                        $stateName,
                    ]
                )
                ->first();
        }

        return null;
    }


    /**
     * ============================================================
     * CALCULATE PRODUCT TAX
     * ============================================================
     *
     * IMPORTANT BUSINESS RULE:
     *
     * Product price = customer-facing/tax-inclusive amount.
     *
     * State and Local taxes are calculated separately on the SAME
     * original product amount.
     *
     * Example:
     *
     * Product = $50
     * State = 0%
     * Local = 1.82%
     *
     * State tax:
     *     $50 × 0% = $0.00
     *
     * Local tax:
     *     $50 × 1.82% = $0.91
     *
     * Total tax:
     *     $0.91
     *
     * Product customer price:
     *     $50.00
     *
     * We DO NOT add $0.91 again.
     *
     * combined_rate is NOT another tax.
     */
    private function calculateProductTax($items, $productTaxSetting): array
    {
        $stateRate = round((float) ($productTaxSetting->state_rate ?? 0), 2);
        $localRate = round((float) ($productTaxSetting->local_rate ?? 0), 2);
        $combinedRate = round($stateRate + $localRate, 2);

        $taxableAmount = 0.0;
        $tax = 0.0;
        $stateTaxAmount = 0.0;
        $localTaxAmount = 0.0;
        $itemDetails = [];

        foreach ($items as $item) {
            $grossAmount = round((float) ($item->total_price ?? 0), 2);
            $lineTaxableAmount = $combinedRate > 0
                ? round($grossAmount / (1 + ($combinedRate / 100)), 2)
                : $grossAmount;

            $lineStateTax = round($lineTaxableAmount * $stateRate / 100, 2);
            $lineLocalTax = round($lineTaxableAmount * $localRate / 100, 2);
            $lineTax = round($lineStateTax + $lineLocalTax, 2);

            // Reconcile embedded-tax rounding to the original line price.
            $roundingDifference = round(
                $grossAmount - ($lineTaxableAmount + $lineTax),
                2
            );

            if (abs($roundingDifference) >= 0.01) {
                $lineLocalTax = round($lineLocalTax + $roundingDifference, 2);
                $lineTax = round($lineStateTax + $lineLocalTax, 2);
            }

            $taxableAmount = round($taxableAmount + $lineTaxableAmount, 2);
            $stateTaxAmount = round($stateTaxAmount + $lineStateTax, 2);
            $localTaxAmount = round($localTaxAmount + $lineLocalTax, 2);
            $tax = round($tax + $lineTax, 2);

            $itemDetails[$item->id] = [
                'product_total' => $grossAmount,
                'original_total' => $grossAmount,
                'taxable_amount' => $lineTaxableAmount,
                'state_rate' => $stateRate,
                'state_tax' => $lineStateTax,
                'state_tax_amount' => $lineStateTax,
                'local_rate' => $localRate,
                'local_tax' => $lineLocalTax,
                'local_tax_amount' => $lineLocalTax,
                'combined_rate' => $combinedRate,
                'tax' => $lineTax,
                'allocated_discount' => 0.0,
                'discounted_total' => $grossAmount,
            ];
        }

        return [
            'state_rate' => $stateRate,
            'local_rate' => $localRate,
            'combined_rate' => $combinedRate,
            'taxable_amount' => round($taxableAmount, 2),
            'tax' => round($tax, 2),
            'state_tax_amount' => round($stateTaxAmount, 2),
            'local_tax_amount' => round($localTaxAmount, 2),
            'product_total' => round($taxableAmount + $tax, 2),
            'items' => $itemDetails,
        ];
    }

    /**
     * Coupon amounts in this checkout are applied to the tax-exclusive
     * taxable subtotal. Percentage discounts are calculated against that
     * same base; minimum-order eligibility still uses the displayed subtotal.
     */
    private function calculateCouponDiscount(
        ?Coupon $coupon,
        float $taxableSubtotal
    ): float {
        if (!$coupon) {
            return 0.00;
        }

        $taxableSubtotal = round(max(0, $taxableSubtotal), 2);

        if ($coupon->discount_type === 'flat') {
            $discount = (float) $coupon->discount_value;
        } else {
            $discount = $taxableSubtotal * (float) $coupon->discount_value / 100;

            if ((float) ($coupon->max_discount ?? 0) > 0) {
                $discount = min($discount, (float) $coupon->max_discount);
            }
        }

        return round(min(max(0, $discount), $taxableSubtotal), 2);
    }

    /**
     * Allocate a coupon proportionally over the original taxable bases,
     * then recalculate state/local tax on each discounted taxable line.
     */
    private function calculateDiscountedProductTax(
        $items,
        $productTaxSetting,
        float $couponDiscount,
        ?array $baseTaxCalculation = null
    ): array {
        $base = $baseTaxCalculation ?? $this->calculateProductTax($items, $productTaxSetting);

        $stateRate = round((float) $base['state_rate'], 2);
        $localRate = round((float) $base['local_rate'], 2);
        $combinedRate = round((float) $base['combined_rate'], 2);
        $taxableSubtotal = round((float) $base['taxable_amount'], 2);
        $couponDiscount = round(min(max(0, $couponDiscount), $taxableSubtotal), 2);

        $collection = $items->values();
        $count = $collection->count();
        $allocatedDiscount = 0.00;
        $taxableTotal = 0.00;
        $stateTaxTotal = 0.00;
        $localTaxTotal = 0.00;
        $taxTotal = 0.00;
        $productTotal = 0.00;
        $itemDetails = [];

        foreach ($collection as $index => $item) {
            $baseLine = $base['items'][$item->id] ?? [];
            $originalTaxable = round((float) ($baseLine['taxable_amount'] ?? 0), 2);
            $originalGross = round((float) ($baseLine['original_total'] ?? $item->total_price ?? 0), 2);

            if ($index === $count - 1) {
                $lineDiscount = round(max(0, $couponDiscount - $allocatedDiscount), 2);
            } elseif ($taxableSubtotal > 0) {
                $lineDiscount = round($couponDiscount * ($originalTaxable / $taxableSubtotal), 2);
                $lineDiscount = min(
                    $lineDiscount,
                    max(0, round($couponDiscount - $allocatedDiscount, 2)),
                    $originalTaxable
                );
            } else {
                $lineDiscount = 0.00;
            }

            $lineDiscount = min($lineDiscount, $originalTaxable);
            $allocatedDiscount = round($allocatedDiscount + $lineDiscount, 2);
            $lineTaxable = round(max(0, $originalTaxable - $lineDiscount), 2);

            if ($lineDiscount <= 0.00) {
                // Preserve existing inclusive-tax cents for unaffected lines.
                $lineStateTax = round((float) ($baseLine['state_tax_amount'] ?? 0), 2);
                $lineLocalTax = round((float) ($baseLine['local_tax_amount'] ?? 0), 2);
                $lineTax = round($lineStateTax + $lineLocalTax, 2);
                $discountedGross = $originalGross;
            } else {
                $lineStateTax = round($lineTaxable * $stateRate / 100, 2);
                $lineLocalTax = round($lineTaxable * $localRate / 100, 2);
                $lineTax = round($lineStateTax + $lineLocalTax, 2);
                $discountedGross = round($lineTaxable + $lineTax, 2);
            }

            $taxableTotal = round($taxableTotal + $lineTaxable, 2);
            $stateTaxTotal = round($stateTaxTotal + $lineStateTax, 2);
            $localTaxTotal = round($localTaxTotal + $lineLocalTax, 2);
            $taxTotal = round($taxTotal + $lineTax, 2);
            $productTotal = round($productTotal + $discountedGross, 2);

            $itemDetails[$item->id] = [
                'original_total' => $originalGross,
                'product_total' => $discountedGross,
                'discounted_total' => $discountedGross,
                'allocated_discount' => $lineDiscount,
                'taxable_amount' => $lineTaxable,
                'state_rate' => $stateRate,
                'state_tax' => $lineStateTax,
                'state_tax_amount' => $lineStateTax,
                'local_rate' => $localRate,
                'local_tax' => $lineLocalTax,
                'local_tax_amount' => $lineLocalTax,
                'combined_rate' => $combinedRate,
                'tax' => $lineTax,
            ];
        }

        return [
            'state_rate' => $stateRate,
            'local_rate' => $localRate,
            'combined_rate' => $combinedRate,
            'taxable_amount' => round($taxableTotal, 2),
            'tax' => round($taxTotal, 2),
            'state_tax_amount' => round($stateTaxTotal, 2),
            'local_tax_amount' => round($localTaxTotal, 2),
            'product_total' => round($productTotal, 2),
            'items' => $itemDetails,
        ];
    }

    /**
     * ============================================================
     * GET DELIVERY CHARGE
     * ============================================================
     */
    private function getDeliveryCharge()
    {
        return round((float) (
            StoreSetting::orderBy('id', 'desc')->first()?->delivery_charge ?? 0
        ), 2);
    }

}