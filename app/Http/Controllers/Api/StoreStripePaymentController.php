<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\Webhook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\EmployeeCommission;
use App\Models\DeliveryRate;
use App\Models\Payment;
use App\Models\AlternativeAddress;
use App\Models\Product;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreWallet;
use App\Models\StoreWalletTransaction;
use App\Models\OrderItemCancellation;
use App\Models\Coupon;

class StoreStripePaymentController extends Controller
{
    protected $isTest = true; // true = test | false = live

    public function createOrder(Request $request)
    {
        try {

            $user = $request->user();

            $request->validate([
                'coupon_code' => 'nullable|string',
                'address_id' => 'nullable|exists:alternative_addresses,id',
                'wallet_amount' => 'nullable|numeric|min:0',
            ]);

            $walletInput = $request->wallet_amount ?? 0;

            // ðŸ”¥ CART
            $cart = Cart::where('user_id', $user->id)->firstOrFail();
            $items = CartItem::where('cart_id', $cart->id)->get();

            if ($items->isEmpty()) {
                return response()->json(['status' => false, 'message' => 'Cart empty']);
            }

            $validatedCart = $this->validateCartItems($items);

            $subtotal = $validatedCart['subtotal'];

            $discount = 0;
            $couponId = null;

            if ($request->coupon_code) {

                $coupon = Coupon::where('code', $request->coupon_code)
                    ->where('status', 1)
                    ->whereDate('expiry_date', '>=', now())
                    ->lockForUpdate()
                    ->first();

                if (!$coupon) {

                    return response()->json([
                        'status' => false,
                        'message' => 'Invalid coupon'
                    ], 422);
                }

                if (
                    $coupon->min_amount &&
                    $subtotal < $coupon->min_amount
                ) {

                    return response()->json([
                        'status' => false,
                        'message' => 'Coupon minimum amount not met'
                    ], 422);
                }

                if ($coupon->discount_type == 'flat') {

                    $discount = (float) $coupon->discount_value;

                } else {

                    $discount =
                        ($subtotal * $coupon->discount_value) / 100;

                    if ($coupon->max_discount) {

                        $discount = min(
                            $discount,
                            $coupon->max_discount
                        );
                    }
                }

                $discount = min($discount, $subtotal);

                $couponId = $coupon->id;
            }

            $afterDiscount = max(0, $subtotal - $discount);

            $deliveryCharge = 0;

            if ($request->address_id) {

                $address = AlternativeAddress::find($request->address_id);

                if ($address && $address->state) {

                    $deliveryRate = DeliveryRate::where('state', $address->state)
                        ->where('status', 1)
                        ->first();

                    if ($deliveryRate) {
                        $deliveryCharge = $subtotal >= 5 ? 0 : (float) $deliveryRate->delivery_charge;
                    }
                }
            }

            $wallet = StoreWallet::where('user_id', $user->id)
                ->first();

            if (!$wallet) {

                $wallet = StoreWallet::create([
                    'user_id' => $user->id,
                    'balance' => 0
                ]);
            }

            if ($walletInput > $wallet->balance) {
                return response()->json([
                    'status' => false,
                    'message' => 'Insufficient wallet balance'
                ]);
            }

            if ($walletInput > ($afterDiscount + $deliveryCharge)) {
                $walletInput = ($afterDiscount + $deliveryCharge);
            }

            $walletUsed = $walletInput;

            $finalAmount = max(0, ($afterDiscount + $deliveryCharge) - $walletUsed);

            if ($finalAmount <= 0) {

                return response()->json([
                    'status' => true,
                    'payment_mode' => 'wallet_only',
                    'order_id' => null,
                    'breakdown' => [
                        'subtotal' => $subtotal,
                        'discount' => $discount,
                        'delivery_charge' => $deliveryCharge,
                        'wallet_used' => $walletUsed,
                        'final_amount' => 0
                    ]
                ]);
            }

            Stripe::setApiKey(config('services.stripe.secret'));

            $idempotencyKey = 'store_pi_' . md5(implode('|', [
                $user->id,
                $cart->id,
                $request->address_id,
                $request->coupon_code,
                $walletUsed,
                $finalAmount,
            ]));

            $paymentIntent = PaymentIntent::create([
                'amount' => (int) round($finalAmount * 100),
                'currency' => 'usd',
                'metadata' => [
                    'user_id' => $user->id,
                    'platform' => config('store.platform'),
                    'order_type' => config('store.order_type'),
                    'address_id' => $request->address_id,
                    'coupon_code' => $request->coupon_code,
                    'wallet_used' => $walletUsed,
                    'subtotal' => $subtotal,
                    'discount' => $discount,
                    'delivery_charge' => $deliveryCharge,
                    'final_amount' => $finalAmount,
                    'environment' => app()->environment(),
                ],
                'automatic_payment_methods' => ['enabled' => true],
            ], [
                'idempotency_key' => $idempotencyKey,
            ]);

            return response()->json([
                'status' => true,
                'order_id' => $paymentIntent->id,
                'client_secret' => $paymentIntent->client_secret,
                'payment_intent_id' => $paymentIntent->id,
                'amount' => round($finalAmount, 2),
                'currency' => 'usd',
                'breakdown' => [
                    'subtotal' => $subtotal,
                    'discount' => $discount,
                    'delivery_charge' => $deliveryCharge,
                    'wallet_used' => $walletUsed,
                    'final_amount' => $finalAmount
                ]
            ]);

        } catch (\Exception $e) {

            Log::error('STORE CREATE ORDER ERROR', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => false,
                'message' => $this->isTest ? $e->getMessage() : 'Unable to create order'
            ], 422);
        }
    }

    public function verify(Request $request)
    {
        $request->validate([
            'payment_intent_id' => 'nullable',
            'address_id' => 'nullable|exists:alternative_addresses,id',
            'coupon_code' => 'nullable',
            'wallet_amount' => 'nullable|numeric|min:0'
        ]);

        DB::beginTransaction();

        try {

            $user = $request->user();
            $walletInput = $request->wallet_amount ?? 0;

            $cart = Cart::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $items = CartItem::where('cart_id', $cart->id)->lockForUpdate()->get();

            if ($items->isEmpty()) {
                throw new \Exception('Cart empty');
            }

            $productIds = $items->pluck('product_id')->unique();

            $products = Product::whereIn('id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $subtotal = 0;

            foreach ($items as $item) {

                $product = $products[$item->product_id] ?? null;

                if (!$product) {
                    throw new \Exception('Product not found');
                }

                if ($product->stock_qty < $item->quantity) {

                    throw new \Exception(
                        $product->name . ' only ' . $product->stock_qty . ' left in stock'
                    );
                }

                if (
                    $item->price_at_time === null ||
                    $item->price_at_time <= 0
                ) {
                    throw new \Exception(
                        $product->name . ' price not configured'
                    );
                }

                $subtotal += $item->total_price;
            }

            // ðŸ”¥ COUPON
            $discount = 0;
            $couponId = null;

            if ($request->coupon_code) {

                $coupon = Coupon::where('code', $request->coupon_code)
                    ->where('status', 1)
                    ->whereDate('expiry_date', '>=', now())
                    ->lockForUpdate()
                    ->first();

                if (!$coupon) {
                    throw new \Exception('Invalid coupon');
                }

                if (
                    $coupon->min_amount &&
                    $subtotal < $coupon->min_amount
                ) {

                    throw new \Exception(
                        'Coupon minimum amount not met'
                    );
                }

                if ($coupon->discount_type == 'flat') {

                    $discount = (float) $coupon->discount_value;

                } else {

                    $discount =
                        ($subtotal * $coupon->discount_value) / 100;

                    if ($coupon->max_discount) {

                        $discount = min(
                            $discount,
                            $coupon->max_discount
                        );
                    }
                }

                // SAFETY
                $discount = min($discount, $subtotal);

                $couponId = $coupon->id;
            }

            $afterDiscount = max(0, $subtotal - $discount);

            $deliveryCharge = 0;

            if ($request->address_id) {

                $address = AlternativeAddress::find($request->address_id);

                if ($address && $address->state) {

                    $deliveryRate = DeliveryRate::where('state', $address->state)
                        ->where('status', 1)
                        ->first();

                    if ($deliveryRate) {
                        $deliveryCharge = $subtotal >= 5 ? 0 : (float) $deliveryRate->delivery_charge;
                    }
                }
            }

            $wallet = StoreWallet::where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {

                $wallet = StoreWallet::create([
                    'user_id' => $user->id,
                    'balance' => 0
                ]);
            }

            if ($walletInput > $wallet->balance) {
                throw new \Exception('Invalid wallet usage');
            }

            if ($walletInput > ($afterDiscount + $deliveryCharge)) {
                $walletInput = ($afterDiscount + $deliveryCharge);
            }

            $walletUsed = $walletInput;
            $finalAmount = max(0, ($afterDiscount + $deliveryCharge) - $walletUsed);

            if ($finalAmount > 0 && !$request->payment_intent_id) {
                throw new \Exception('Payment required');
            }

            // PAYMENT
            $payment = null;
            $paymentData = null;
            $paymentMode = 'wallet_only';

            if ($finalAmount > 0) {

                $existing = Payment::where('transaction_id', $request->payment_intent_id)->first();
                if ($existing) {
                    DB::commit();
                    return response()->json(['status' => true, 'message' => 'Already processed']);
                }

                if (!$this->isTest) {

                    Stripe::setApiKey(config('services.stripe.secret'));

                    $intent = PaymentIntent::retrieve($request->payment_intent_id);

                    if ($intent->status !== 'succeeded') {
                        throw new \Exception('Payment not captured');
                    }

                    $expectedAmountInCents = (int) round($finalAmount * 100);

                    if ((int) $intent->amount !== $expectedAmountInCents) {

                        Log::error('STRIPE AMOUNT MISMATCH', [
                            'payment_intent_id' => $intent->id,
                            'expected' => $expectedAmountInCents,
                            'actual' => $intent->amount
                        ]);

                        throw new \Exception('Payment amount mismatch');
                    }

                    if (strtolower($intent->currency) !== 'usd') {

                        Log::error('STRIPE CURRENCY MISMATCH', [
                            'payment_intent_id' => $intent->id,
                            'currency' => $intent->currency
                        ]);

                        throw new \Exception('Payment currency mismatch');
                    }

                    $metaUserId = $intent->metadata['user_id'] ?? null;
                    $metaPlatform = $intent->metadata['platform'] ?? null;
                    $metaOrderType = $intent->metadata['order_type'] ?? null;

                    if (
                        (string) $metaUserId !== (string) $user->id ||
                        $metaPlatform !== config('store.platform') ||
                        $metaOrderType !== config('store.order_type')
                    ) {

                        Log::error('STRIPE METADATA MISMATCH', [
                            'payment_intent_id' => $intent->id,
                            'expected_user_id' => $user->id,
                            'meta_user_id' => $metaUserId,
                            'meta_platform' => $metaPlatform,
                            'meta_order_type' => $metaOrderType
                        ]);

                        throw new \Exception('Payment verification failed');
                    }

                    $metaEnvironment = $intent->metadata['environment'] ?? null;

                    if ($metaEnvironment !== app()->environment()) {

                        Log::error('STRIPE ENVIRONMENT MISMATCH', [
                            'payment_intent_id' => $intent->id,
                            'expected_environment' => app()->environment(),
                            'meta_environment' => $metaEnvironment
                        ]);

                        throw new \Exception('Payment verification failed');
                    }

                    $paymentData = $intent->toArray();

                    $paymentMode = 'online';

                    if ($intent->payment_method) {

                        $paymentMethod = PaymentMethod::retrieve($intent->payment_method);

                        $paymentMode = $paymentMethod->type;

                        if (
                            $paymentMethod->type === 'card' &&
                            !empty($paymentMethod->card->wallet->type ?? null)
                        ) {
                            $paymentMode = $paymentMethod->card->wallet->type;
                        }
                    }

                } else {
                    $paymentData = $request->all();
                    $paymentMode = 'test';
                }

                $payment = Payment::create([
                    'user_id' => $user->id,
                    'platform' => 'valeenza',
                    'order_id' => $request->payment_intent_id,
                    'payment_gateway' => 'stripe',
                    'transaction_id' => $request->payment_intent_id,
                    'amount' => round($finalAmount, 2),
                    'currency' => 'USD',
                    'payment_status' => 'success',
                    'payment_mode' => $paymentMode,

                    'customer_email' => $user->email,
                    'customer_phone' => trim(($user->country_code ?? '') . ($user->mobile ?? '')),

                    'payment_request_data' => [
                        'subtotal' => $subtotal,
                        'discount' => $discount,
                        'wallet_requested' => $request->wallet_amount,
                        'wallet_used' => $walletUsed,
                        'final_amount' => $finalAmount,
                        'delivery_charge' => $deliveryCharge,
                        'coupon_code' => $request->coupon_code
                    ],

                    'payment_response_data' => $paymentData
                ]);
            }

            $address = null;

            if ($request->address_id) {

                $address = DB::table('alternative_addresses')
                    ->where('id', $request->address_id)
                    ->first();
            }

            $productTax = 0;
            $productTaxableAmount = 0;

            $shippingTax = 0;
            $shippingTaxable = 0;

            $productTaxRate = config('tax.product_rate');

            $hsnCodes = [];

            foreach ($items as $item) {

                $product = $products[$item->product_id] ?? null;

                if (!$product) {
                    continue;
                }

                $itemTotal = $item->total_price;

                // PRODUCT HSN CODE
                if ($product->hsn_code) {
                    $hsnCodes[] = $product->hsn_code;
                }

                $itemTax = round(
                    ($itemTotal * $productTaxRate) / 100,
                    2
                );

                $itemTaxableAmount = round(
                    $itemTotal - $itemTax,
                    2
                );

                $productTaxableAmount += $itemTaxableAmount;
                $productTax += $itemTax;
            }

            $hsnCodes = array_unique($hsnCodes);

            $hsnCode = implode(',', $hsnCodes);

            $shippingTaxRate = config('tax.shipping_rate');

            $shippingTaxable = 0;
            $shippingTax = 0;

            if ($deliveryCharge > 0) {

                $shippingTax = round(
                    ($deliveryCharge * $shippingTaxRate) / 100,
                    2
                );

                $shippingTaxable = round(
                    $deliveryCharge - $shippingTax,
                    2
                );

            }

            $taxType = 'us_sales_tax';

            $productCgstAmount = 0;
            $productSgstAmount = 0;
            $productIgstAmount = $productTax;

            $shippingCgstAmount = 0;
            $shippingSgstAmount = 0;
            $shippingIgstAmount = $shippingTax;

            foreach ($items as $item) {

                $product = $products[$item->product_id];

                $newStock = $product->stock_qty - $item->quantity;

                $status = 'in_stock';

                if ($newStock == 0) {
                    $status = 'out_of_stock';
                } elseif ($newStock <= 5) {
                    $status = 'few_left';
                }

                $product->update([
                    'stock_qty' => $newStock,
                    'stock_status' => $status
                ]);
            }

            $start = (int) env('INVOICE_START_NUMBER', 1);

            $usedNumbers = Order::whereNotNull('invoice_sequence')
                ->orderBy('invoice_sequence')
                ->pluck('invoice_sequence')
                ->toArray();

            $nextInvoiceSequence = $start;

            foreach ($usedNumbers as $number) {

                if ($number == $nextInvoiceSequence) {

                    $nextInvoiceSequence++;

                } elseif ($number > $nextInvoiceSequence) {

                    break;
                }
            }

            $order = Order::create([
                'user_id' => $user->id,
                'user_name' => $user->name,
                'coupon_id' => $couponId,
                'payment_id' => $payment ? $payment->id : null,
                'order_number' => 'ORD-' . strtoupper(uniqid()),
                'invoice_sequence' => $nextInvoiceSequence,
                'invoice_number' => 'VLNZ-' . str_pad($nextInvoiceSequence, 4, '0', STR_PAD_LEFT),
                'hsn_code' => $hsnCode,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'wallet_used' => $walletUsed,
                'delivery_charge' => $deliveryCharge,
                'paid_amount' => round($finalAmount, 2),
                'total_amount' => round($afterDiscount + $deliveryCharge, 2),

                'price_breakdown' => [
                    'subtotal' => $subtotal,
                    'coupon_discount' => $discount,
                    'delivery_charge' => $deliveryCharge,

                    'product_tax_rate' => $productTaxRate,
                    'product_tax_amount' => $productTax,
                    'shipping_tax_rate' => $shippingTaxRate,
                    'shipping_tax_amount' => $shippingTax,

                    'shipping_taxable_amount' => $shippingTaxable,
                    'product_taxable_amount' => $productTaxableAmount,

                    'product_cgst_amount' => $productCgstAmount,
                    'product_sgst_amount' => $productSgstAmount,
                    'product_igst_amount' => $productIgstAmount,

                    'shipping_cgst_amount' => $shippingCgstAmount,
                    'shipping_sgst_amount' => $shippingSgstAmount,
                    'shipping_igst_amount' => $shippingIgstAmount,
                    'wallet_used' => $walletUsed,
                    'taxable_amount' => round($productTaxableAmount + $shippingTaxable, 2),
                    'gst_rate' => $productTaxRate,
                    'tax_type' => $taxType,
                    'cgst_amount' => $productCgstAmount,
                    'sgst_amount' => $productSgstAmount,
                    'igst_amount' => $productIgstAmount,
                    'paid_online' => round($finalAmount, 2),
                    'final_amount' => round($afterDiscount + $deliveryCharge, 2)
                ],

                'address_id' => $request->address_id,

                'name' => $address->name ?? null,
                'email' => $address->email ?? $user->email,
                'mobile' => $address->mobile ?? null,
                'alternative_mobile' => $address->alternative_mobile ?? null,
                'city' => $address->city ?? null,
                'state_code' => $address->state_code ?? null,
                'state' => $address->state ?? null,
                'country' => $address->country ?? null,
                'address' => $address->address ?? null,
                'pincode' => $address->pincode ?? null,
                'taxable_amount' => round($productTaxableAmount + $shippingTaxable, 2),
                'gst_rate' => $productTaxRate,
                'cgst_amount' => $productCgstAmount,
                'sgst_amount' => $productSgstAmount,
                'igst_amount' => $productIgstAmount,
                'tax_type' => $taxType,

                'status' => 'paid',
                'paid_at' => now()
            ]);

            if (
                $couponId &&
                $coupon &&
                $coupon->employee_id &&
                $coupon->employee_id != 1
            ) {

                $percentage = $coupon->employee->commission_percentage ?? 0;

                $commissionAmount =
                    ($order->total_amount * $percentage) / 100;

                EmployeeCommission::create([
                    'employee_id' => $coupon->employee_id,
                    'order_id' => $order->id,
                    'coupon_id' => $coupon->id,
                    'order_amount' => $order->total_amount,
                    'commission_percentage' => $percentage,
                    'commission_amount' => round($commissionAmount, 2),
                    'status' => 'delivery_pending',
                ]);
            }

            $walletTransaction = null;

            // WALLET DEDUCT AFTER ORDER CREATE
            if ($walletUsed > 0) {

                $wallet->refresh();

                if ($wallet->balance < $walletUsed) {
                    throw new \Exception('Wallet changed, retry');
                }

                $before = $wallet->balance;
                $after = $before - $walletUsed;

                $wallet->update([
                    'balance' => $after,
                    'total_spent' => $wallet->total_spent + $walletUsed
                ]);

                $walletTransaction = StoreWalletTransaction::create([
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'type' => 'debit',
                    'amount' => $walletUsed,
                    'source' => 'order_payment',
                    'balance_before' => $before,
                    'balance_after' => $after,
                    'note' => 'Wallet used in order #' . $order->id
                ]);
            }

                $totalWeight = 0;
                $maxLength = 0;
                $maxBreadth = 0;
                $totalHeight = 0;

                foreach ($items as $item) {

                    $product = $products[$item->product_id] ?? null;
                    if (!$product) {
                        throw new \Exception('Product not found');
                    }
                    $totalWeight += (($product->weight ?? 0) * $item->quantity);
                    $maxLength = max($maxLength, $product->length ?? 0);
                    $maxBreadth = max($maxBreadth, $product->breadth ?? 0);
                    $totalHeight += (($product->height ?? 0) * $item->quantity);

                    $itemTaxRate = config('tax.product_rate');

                    $itemTax = round(
                        ($item->total_price * $itemTaxRate) / 100,
                        2
                    );

                    $itemTaxableAmount = round(
                        $item->total_price - $itemTax,
                        2
                    );

                    $itemCgst = 0;
                    $itemSgst = 0;
                    $itemIgst = $itemTax;

                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $item->product_id,
                        'product_name' => $item->product->name ?? '',
                        'product_slug' => $item->product->slug ?? '',
                        'product_image' => $item->product->image ?? '',
                        'ratti' => $item->ratti,
                        'quantity' => $item->quantity,
                        'price' => $item->price_at_time,
                        'total' => $item->total_price,
                        'weight' => $product->weight,
                        'length' => $product->length,
                        'breadth' => $product->breadth,
                        'height' => $product->height,
                        'gst_rate' => $itemTaxRate,
                        'gst_amount' => $itemTax,
                        'taxable_amount' => $itemTaxableAmount,
                        'cgst_amount' => $itemCgst,
                        'sgst_amount' => $itemSgst,
                        'igst_amount' => $itemIgst,
                        'tax_type' => $taxType,
                        'hsn_code' => $product->hsn_code,
                    ]);
                }

            $order->update([
                'total_weight' => $totalWeight,
                'box_length' => $maxLength,
                'box_breadth' => $maxBreadth,
                'box_height' => $totalHeight,
            ]);

            CartItem::where('cart_id', $cart->id)->delete();

            DB::commit();

            $order->refresh()->load(['items', 'payment', 'user']);

            $order->refresh();

            return response()->json([
                'status' => true,
                'message' => 'Order placed successfully',

                'order' => [
                    'order_id' => $order->id,
                    'invoice_number' => $order->invoice_number,
                    'order_number' => $order->order_number,
                    'status' => $order->status,

                    'pricing' => [
                        'subtotal' => $subtotal,
                        'discount' => $discount,
                        'taxable_amount' => round($productTaxableAmount + $shippingTaxable, 2),
                        'gst_rate' => $productTaxRate,
                        'tax_type' => $taxType,
                        'cgst_amount' => $productCgstAmount,
                        'sgst_amount' => $productSgstAmount,
                        'igst_amount' => $productIgstAmount,
                        'wallet_used' => $walletUsed,
                        'delivery_charge' => $deliveryCharge,
                        'paid_online' => round($finalAmount, 2),
                        'final_amount' => round($afterDiscount + $deliveryCharge, 2)
                    ],

                    'payment' => $payment ? [
                        'transaction_id' => $payment->transaction_id,
                        'payment_gateway' => $payment->payment_gateway,
                        'payment_mode' => $payment->payment_mode,
                        'amount' => $payment->amount,
                        'currency' => $payment->currency,
                        'status' => $payment->payment_status,
                    ] : [
                        'transaction_id' => $walletTransaction
                            ? 'WALLET-TXN-' . $walletTransaction->id
                            : null,
                        'payment_gateway' => 'wallet',
                        'payment_mode' => 'wallet_only',
                        'amount' => $walletUsed,
                        'currency' => 'USD',
                        'status' => 'success',
                    ],

                    'items' => $order->items->map(function ($item) {
                        return [
                            'product_id' => $item->product_id,
                            'name' => $item->product_name,
                            'image' => $item->product_image,
                            'quantity' => $item->quantity,
                            'price' => $item->price,
                            'total' => $item->total,
                            'hsn_code' => $item->hsn_code,
                            'gst_rate' => $item->gst_rate,
                            'gst_amount' => $item->gst_amount,
                            'taxable_amount' => $item->taxable_amount,
                            'cgst_amount' => $item->cgst_amount,
                            'sgst_amount' => $item->sgst_amount,
                            'igst_amount' => $item->igst_amount,
                            'tax_type' => $item->tax_type,
                        ];
                    }),
                ]
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            Log::error('STORE PAYMENT ERROR', [
                'message' => $e->getMessage()
            ]);

            return response()->json([
                'status' => false,
                'message' => $this->isTest ? $e->getMessage() : 'Payment failed'
            ], 422);
        }
    }

    public function webhook(Request $request)
    {
        Stripe::setApiKey(config('services.stripe.secret'));

        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $endpointSecret = config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $endpointSecret);
        } catch (\Exception $e) {
            Log::error('STRIPE WEBHOOK SIGNATURE ERROR', ['error' => $e->getMessage()]);
            return response()->json(['status' => false], 400);
        }

        try {

            switch ($event->type) {

                case 'payment_intent.succeeded':

                    $intent = $event->data->object;

                    Payment::where('transaction_id', $intent->id)
                        ->update(['payment_status' => 'success']);

                    break;

                case 'payment_intent.payment_failed':

                    $intent = $event->data->object;

                    Payment::where('transaction_id', $intent->id)
                        ->update(['payment_status' => 'failed']);

                    Log::error('STRIPE PAYMENT FAILED', ['payment_intent' => $intent->id]);

                    break;

                case 'charge.refunded':

                    $charge = $event->data->object;

                    Payment::where('transaction_id', $charge->payment_intent)
                        ->update(['payment_status' => 'refunded']);

                    Log::error('STRIPE CHARGE REFUNDED', ['payment_intent' => $charge->payment_intent]);

                    break;

                case 'charge.dispute.created':

                    $dispute = $event->data->object;

                    Payment::where('transaction_id', $dispute->payment_intent)
                        ->update(['payment_status' => 'disputed']);

                    Log::error('STRIPE DISPUTE CREATED', [
                        'payment_intent' => $dispute->payment_intent,
                        'reason' => $dispute->reason ?? null
                    ]);

                    break;

                default:
                    break;
            }

        } catch (\Exception $e) {

            Log::error('STRIPE WEBHOOK PROCESSING ERROR', [
                'event_type' => $event->type ?? null,
                'error' => $e->getMessage()
            ]);
        }

        return response()->json(['status' => true]);
    }

    public function calculateSummary(Request $request)
    {
        try {

            $user = $request->user();

            $request->validate([
                'address_id' => 'nullable|exists:alternative_addresses,id',
            ]);

            // CART
            $cart = Cart::where('user_id', $user->id)
                ->firstOrFail();

            $items = CartItem::where('cart_id', $cart->id)
                ->get();

            if ($items->isEmpty()) {

                return response()->json([
                    'status' => false,
                    'message' => 'Cart empty'
                ]);
            }

            $validatedCart = $this->validateCartItems($items);

            $subtotal = $validatedCart['subtotal'];

            // DELIVERY
            $deliveryCharge = 0;

            if ($request->address_id) {

                $address = AlternativeAddress::find($request->address_id);

                if ($address && $address->state) {

                    $deliveryRate = DeliveryRate::where(
                            'state',
                            $address->state
                        )
                        ->where('status', 1)
                        ->first();

                    if ($deliveryRate) {

                        $deliveryCharge =
                            $subtotal >= 5
                                ? 0
                                : (float) $deliveryRate->delivery_charge;
                    }
                }
            }

            return response()->json([

                'status' => true,

                'breakdown' => [

                    'subtotal' => $subtotal,

                    'delivery_charge' => $deliveryCharge,

                    'final_amount' => round($subtotal + $deliveryCharge, 2)
                ]
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    public function cancelOrder(Request $request, $id)
    {
        $request->validate([
            'cancel_reason' => 'required|array|min:1',
            'cancel_reason.*' => 'string|max:255',
        ]);

        $cancelReason = implode(', ', $request->cancel_reason);

        DB::beginTransaction();

        try {

            $user = auth()->user();

            $order = Order::where('id', $id)
                ->where('user_id', $user->id)
                ->with('items')
                ->firstOrFail();

            if ($order->status == 'cancelled') {
                return response()->json([
                    'status' => false,
                    'message' => 'Order already cancelled'
                ]);
            }

            if (in_array($order->status, ['shipped', 'delivered'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Order cannot be cancelled now'
                ]);
            }

            $refundAmount = $order->subtotal;

            // ðŸ”¥ WALLET
            $wallet = StoreWallet::where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {

                $wallet = StoreWallet::create([
                    'user_id' => $user->id,
                    'balance' => 0,
                    'total_added' => 0,
                    'total_spent' => 0,
                    'total_refunded' => 0
                ]);
            }

            $before = $wallet->balance;
            $after = $before + $refundAmount;

            $wallet->update([
                'balance' => $after,
                'total_refunded' => $wallet->total_refunded + $refundAmount
            ]);

            // ðŸ”¥ WALLET TRANSACTION
            StoreWalletTransaction::create([
                'user_id' => $user->id,
                'order_id' => $order->id,
                'type' => 'credit',
                'amount' => $refundAmount,
                'source' => 'order_cancel',
                'balance_before' => $before,
                'balance_after' => $after,
                'note' => 'Order cancelled refund'
            ]);

            // ðŸ”¥ ITEM-WISE REFUND (PROPORTIONAL)
            $totalOrderAmount = $order->subtotal;

            foreach ($order->items as $item) {

                $product = Product::where('id', $item->product_id)
                    ->lockForUpdate()
                    ->first();

                if ($product) {

                    $newStock = $product->stock_qty + $item->quantity;

                    $status = 'in_stock';
                    if ($newStock == 0) {
                        $status = 'out_of_stock';
                    } elseif ($newStock <= 5) {
                        $status = 'few_left';
                    }

                    $product->update([
                        'stock_qty' => $newStock,
                        'stock_status' => $status
                    ]);
                }

                $itemTotal = $item->total;

                $itemRefund = 0;

                if ($totalOrderAmount > 0) {
                    $itemRefund = ($itemTotal / $totalOrderAmount) * $refundAmount;
                }

                OrderItemCancellation::create([
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'user_id' => $user->id,
                    'quantity' => $item->quantity,
                    'refund_amount' => round($itemRefund, 2),
                    'cancelled_at' => now(),
                    'reason' => $cancelReason
                ]);
            }

            // ðŸ”¥ PAYMENT UPDATE
            if ($order->payment_id) {
                Payment::where('id', $order->payment_id)
                    ->update(['payment_status' => 'refunded']);
            }

            // ðŸ”¥ COMMISSION UPDATE
            EmployeeCommission::where(
                'order_id',
                $order->id
            )->update([
                'status' => 'cancelled'
            ]);

            // ðŸ”¥ ORDER UPDATE
            $order->update([
                'status' => 'cancelled',
                'shipping_status' => 'cancelled',
                'cancelled_at' => now(),
                'cancel_reason' => $cancelReason,
            ]);

            DB::commit();

            $order->refresh();

            return response()->json([
                'status' => true,
                'message' => 'Order cancelled & refunded',

                'refund' => [
                    'amount' => $refundAmount,
                    'wallet_before' => $before,
                    'wallet_after' => $after
                ],

                'cancel_reason' => $order->cancel_reason,

                'pricing' => $order->price_breakdown
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function orderDetails($id)
    {
        $user = auth()->user();

        $order = Order::with('items')
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        return response()->json([
            'status' => true,
            'data' => [
                'order_id' => $order->id,
                'status' => $order->status,
                'pricing' => $order->price_breakdown,
                'items' => $order->items
            ]
        ]);
    }

    private function validateCartItems($items)
    {
        $productIds = $items->pluck('product_id')->unique();

        $products = Product::whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $subtotal = 0;

        foreach ($items as $item) {

            $product = $products[$item->product_id] ?? null;

            if (!$product) {
                throw new \Exception('Product not found');
            }

            if ($item->quantity <= 0) {
                throw new \Exception('Invalid quantity');
            }

            // STOCK CHECK
            if ($product->stock_qty < $item->quantity) {

                throw new \Exception(
                    $product->name . ' only ' . $product->stock_qty . ' left in stock'
                );
            }

            // LIVE PRICE
            if (
                $item->price_at_time === null ||
                $item->price_at_time <= 0
            ) {
                throw new \Exception(
                    $product->name . ' price not configured'
                );
            }

            $subtotal += $item->total_price;
        }

            return [
            'subtotal' => $subtotal,
            'products' => $products
        ];
    }
}