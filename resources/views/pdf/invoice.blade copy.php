@php

    $rawPriceBreakdown = $order->price_breakdown ?? [];

    if (is_array($rawPriceBreakdown)) {
        $priceBreakdown = $rawPriceBreakdown;
    } elseif (is_object($rawPriceBreakdown)) {
        $priceBreakdown = (array) $rawPriceBreakdown;
    } else {
        $priceBreakdown = json_decode((string) $rawPriceBreakdown, true) ?: [];
    }

    $couponDiscount = round((float) ($order->discount ?? ($priceBreakdown['coupon_discount'] ?? 0)), 2);

    $subtotal = round((float) ($order->subtotal ?? ($priceBreakdown['subtotal'] ?? 0)), 2);

    $deliveryCharge = round((float) ($order->delivery_charge ?? ($priceBreakdown['delivery_charge'] ?? 0)), 2);

    // Tax rates saved on the order.

    $stateRate = (float) ($priceBreakdown['product_state_rate'] ?? 0);

    $localRate = (float) ($priceBreakdown['product_local_rate'] ?? 0);

    $combinedRate = (float) ($priceBreakdown['product_combined_rate'] ?? $stateRate + $localRate);

    // Allocate the order-level coupon proportionally to product lines so the

    // tax breakdown reflects the discounted product price.

    $orderItems = collect($order->items ?? []);

    $itemsGrossTotal = round(
        (float) $orderItems->sum(function ($line) {
            return (float) ($line->total ?? 0);
        }),

        2,
    );

    $discountToAllocate = min(max(0, $couponDiscount), $itemsGrossTotal);

    $discountedProductTotal = round(max(0, $subtotal - $discountToAllocate), 2);

    // Protect against older orders whose subtotal and item totals differ.

    $discountedProductTotal = min($discountedProductTotal, $itemsGrossTotal);

    $itemInvoiceTax = [];

    $allocatedDiscountedGross = 0.0;

    $itemCount = $orderItems->count();

    foreach ($orderItems->values() as $itemIndex => $line) {
        $itemGross = round((float) ($line->total ?? 0), 2);

        if ($itemIndex === $itemCount - 1) {
            // Put any remaining cents on the final line to avoid rounding drift.

            $discountedGross = round(max(0, $discountedProductTotal - $allocatedDiscountedGross), 2);
        } else {
            $discountedGross =
                $itemsGrossTotal > 0 ? round($itemGross * ($discountedProductTotal / $itemsGrossTotal), 2) : 0.0;

            $remainingGross = round(max(0, $discountedProductTotal - $allocatedDiscountedGross), 2);

            $discountedGross = min($discountedGross, $remainingGross);
        }

        $allocatedDiscountedGross = round($allocatedDiscountedGross + $discountedGross, 2);

        // Product prices include tax: extract the tax base instead of adding tax again.

        $taxableAmount = $combinedRate > 0 ? round($discountedGross / (1 + $combinedRate / 100), 2) : $discountedGross;

        $stateTaxAmount = round(($taxableAmount * $stateRate) / 100, 2);

        $localTaxAmount = round(($taxableAmount * $localRate) / 100, 2);

        $lineTaxAmount = round($stateTaxAmount + $localTaxAmount, 2);

        // Reconcile cent-level rounding differences against local tax.

        $roundingDifference = round($discountedGross - ($taxableAmount + $lineTaxAmount), 2);

        if (abs($roundingDifference) >= 0.01) {
            $localTaxAmount = round($localTaxAmount + $roundingDifference, 2);

            $lineTaxAmount = round($stateTaxAmount + $localTaxAmount, 2);
        }

        $itemInvoiceTax[$line->id] = [
            'original_total' => $itemGross,

            'discounted_total' => $discountedGross,

            'allocated_discount' => round(max(0, $itemGross - $discountedGross), 2),

            'taxable_amount' => $taxableAmount,

            'state_tax_amount' => $stateTaxAmount,

            'local_tax_amount' => $localTaxAmount,

            'total_tax_amount' => $lineTaxAmount,
        ];
    }

    $invoiceTaxableTotal = round(array_sum(array_column($itemInvoiceTax, 'taxable_amount')), 2);

    $invoiceStateTaxTotal = round(array_sum(array_column($itemInvoiceTax, 'state_tax_amount')), 2);

    $invoiceLocalTaxTotal = round(array_sum(array_column($itemInvoiceTax, 'local_tax_amount')), 2);

    $invoiceTotalTax = round($invoiceStateTaxTotal + $invoiceLocalTaxTotal, 2);
@endphp

<!DOCTYPE html>

<html>



<head>

    <meta charset="utf-8">

    <style>
        * {

            box-sizing: border-box;

        }



        body {

            font-family: DejaVu Sans, sans-serif;

            font-size: 12px;

            color: #222;

            margin: 0;

            padding: 30px;

        }



        table {

            border-collapse: collapse;

            width: 100%;

        }



        /* HEADER */

        .header-table td {

            vertical-align: top;

            padding: 0;

        }



        .header-table .logo-cell img {

            height: 40px;

        }



        .title {

            text-align: right;

        }



        .title h2 {

            margin: 0;

            font-size: 16px;

        }



        .title p {

            margin: 2px 0 0;

            font-size: 11px;

            color: #666;

        }



        /* SOLD BY / ADDRESS */

        .top-table td {

            vertical-align: top;

            padding: 0;

            font-size: 11px;

            line-height: 1.6;

        }



        .top-table .right-col {

            text-align: right;

        }



        .top-table strong {

            display: block;

            margin-bottom: 3px;

        }



        /* META */

        .meta-table td {

            font-size: 11px;

            padding: 4px 0;

            vertical-align: top;

        }



        .meta-table .right-col {

            text-align: right;

        }



        /* ITEMS */

        table.items {

            margin-top: 15px;

            font-size: 9px;

            table-layout: fixed;

        }



        table.items th,

        table.items td {

            border: 1px solid #999;

            padding: 5px 4px;

            text-align: center;

            overflow-wrap: break-word;

        }



        table.items th {

            background: #f2f2f2;

        }



        table.items td.desc {

            text-align: left;

        }



        table.items td.desc small {

            color: #666;

            display: block;

        }



        .total-row td {

            font-weight: bold;

            text-align: right !important;

            background: #f2f2f2;

        }



        /* INFO BOX */

        table.info-box {

            border: 1px solid #999;

            border-top: none;

        }



        table.info-box td {

            padding: 12px;

            font-size: 11px;

            vertical-align: top;

        }



        table.info-box .sign-col {

            width: 40%;

            text-align: right;

            vertical-align: top;

            font-weight: bold;

            white-space: nowrap;

        }



        table.info-box .sign-col img.signature {

            height: 40px;

            width: auto;

            margin: 8px 0;

        }



        .footer-note {

            border: 1px solid #999;

            border-top: none;

            padding: 6px 8px;

            font-size: 11px;

        }



        /* Fixed footer */

        .footer {

            position: fixed;

            bottom: -20px;

            left: 0;

            right: 0;

            text-align: center;

            font-size: 9px;

            color: #666;

            line-height: 1.6;

            border-top: 1px solid #ddd;

            padding-top: 8px;

        }



        /* Body content ko footer ke peeche jaane se rokne ke liye */

        body {

            padding-bottom: 90px;

        }
    </style>

</head>



<body>

    {{-- HEADER --}}

    <table class="header-table">

        <tr>

            <td class="logo-cell" width="50%">

                <img src="{{ public_path('assets/images/logo-dark.png') }}" alt="Logo">

            </td>

            <td class="title" width="50%">

                <h2>Tax Invoice/Bill of Supply</h2>

                <p>(Original for Recipient)</p>

            </td>

        </tr>

    </table>

    <br>

    {{-- SOLD BY / BILLING / SHIPPING --}}

    @php

        // Check if shipping address is same as billing address

        $sameAddress =
            !$order->addressData ||
            ($order->addressData->name === $order->name &&
                $order->addressData->address === $order->address &&
                $order->addressData->city === $order->city &&
                $order->addressData->pincode === $order->pincode);
    @endphp

    <table class="top-table">

        <tr>

            <td width="50%">

                <strong>Sold By :</strong>

                Valeenza Services LLc,<br>

                12100 Grecian laurel Dr,<br>

                Bakersfield, CA-93311, United States of America<br>

                Email: care@valeenza.co<br><br>

                <strong style="display:inline;">EIN No:</strong>

                42-3422104

            </td>

            <td width="50%" class="right-col">

                <strong>

                    {{ $sameAddress ? 'Billing & Shipping Address :' : 'Billing Address :' }}

                </strong>

                {{ $order->name }},<br>

                {{ $order->email }}, {{ $order->address }},<br>

                {{ $order->city }}, {{ $order->state }}, {{ $order->country }} - {{ $order->pincode }},<br>

                Mob - {{ $order->mobile }}

                {{ $order->alternative_mobile ? ', ' . $order->alternative_mobile : '' }}

                <br><br>

                {{-- State/UT Code --}}

                {{--

                <strong style="margin-top:8px;">State/UT Code:</strong>

                {{ $order->state_code }}<br>

                --}}

                @if (!$sameAddress)
                    <br>

                    <strong>Shipping Address :</strong>

                    {{ $order->addressData->name }},<br>

                    {{ $order->addressData->email }},

                    {{ $order->addressData->address }},<br>

                    {{ $order->addressData->city }},

                    {{ $order->addressData->state }},

                    {{ $order->addressData->country }} -

                    {{ $order->addressData->pincode }},<br>

                    Mob - {{ $order->addressData->mobile }}<br>

                    {{-- State/UT Code --}}

                    {{--

                    <strong>State/UT Code:</strong>

                    {{ $order->addressData->state_code }}<br>

                    --}}
                @endif

                <strong>

                    Place of delivery & supply:

                </strong>

                {{ $order->addressData->state ?? $order->state }}

            </td>

        </tr>

    </table>

    <br>

    {{-- ORDER META --}}

    <table class="meta-table">

        <tr>

            <td width="50%">

                <strong>Order Number:</strong>

                {{ $order->order_number }}<br>

                <strong>Order Date:</strong>

                {{ $order->created_at->format('d/m/Y') }}

            </td>

            <td width="50%" class="right-col">

                <strong>Invoice Number :</strong>

                {{ $order->invoice_number }}<br>

                <strong>Invoice Date :</strong>

                {{ now()->format('d/m/Y') }}

            </td>

        </tr>

    </table>

    {{-- ITEMS TABLE --}}

    <table class="items">

        <thead>

            <tr>

                <th style="width:5%;">S.No</th>

                <th style="width:18%; text-align:left;">Description</th>

                <th style="width:8%;">Unit Price</th>

                <th style="width:5%;">Qty</th>

                <th style="width:9%;">Coupon Discount</th>

                <th style="width:9%;">After Discount</th>

                <th style="width:10%;">Net Amount</th>

                <th style="width:12%;">State Rate<br>(State Tax)</th>

                <th style="width:12%;">Local Rate<br>(Local Tax)</th>

                <th style="width:12%;">Total Amount</th>

            </tr>

        </thead>

        <tbody>

            {{-- PRODUCTS --}}

            @foreach ($orderItems as $index => $item)
                @php

                    $lineTax = $itemInvoiceTax[$item->id] ?? [
                        'allocated_discount' => 0,

                        'taxable_amount' => (float) ($item->taxable_amount ?? ($item->total ?? 0)),

                        'state_tax_amount' => 0,

                        'local_tax_amount' => 0,

                        'discounted_total' => (float) ($item->total ?? 0),
                    ];

                @endphp

                <tr>

                    <td>{{ $index + 1 }}</td>

                    <td class="desc">

                        {{ $item->product_name }}

                        <small>HSN: {{ $item->hsn_code }}</small>

                    </td>

                    <td>${{ number_format((float) $item->price, 2) }}</td>

                    <td>{{ $item->quantity }}</td>

                    <td>

                        @if ($lineTax['allocated_discount'] > 0)
                            -${{ number_format($lineTax['allocated_discount'], 2) }}

                            @if ($index === 0 && $couponDiscount > 0 && optional($order->coupon)->code)
                                <small>{{ $order->coupon->code }}</small>
                            @endif
                        @else
                            -
                        @endif

                    </td>

                    <td>${{ number_format($lineTax['discounted_total'], 2) }}</td>

                    <td>${{ number_format($lineTax['taxable_amount'], 2) }}</td>

                    <td>{{ number_format($stateRate, 2) }}%<br>(${{ number_format($lineTax['state_tax_amount'], 2) }})
                    </td>

                    <td>{{ number_format($localRate, 2) }}%<br>(${{ number_format($lineTax['local_tax_amount'], 2) }})
                    </td>

                    <td>${{ number_format($lineTax['discounted_total'], 2) }}</td>

                </tr>
            @endforeach

            {{-- SUBTOTAL AFTER COUPON DISCOUNT (discount is shown per product row) --}}

            @if ($subtotal > 0)
                <tr>

                    <td></td>

                    <td class="desc">Subtotal (After Discount)</td>

                    <td>-</td>

                    <td>-</td>

                    <td>-</td>

                    <td>-</td>

                    <td>-</td>

                    <td>-</td>

                    <td>-</td>

                    <td>${{ number_format($discountedProductTotal, 2) }}</td>

                </tr>
            @endif

            {{-- DELIVERY CHARGE --}}

            @if ($deliveryCharge > 0)
                <tr>

                    <td></td>

                    <td class="desc">Delivery Charge</td>

                    <td>${{ number_format($deliveryCharge, 2) }}</td>

                    <td>-</td>

                    <td>-</td>

                    <td>-</td>

                    <td>-</td>

                    <td>-</td>

                    <td>-</td>

                    <td>${{ number_format($deliveryCharge, 2) }}</td>

                </tr>
            @endif

            {{-- COD CHARGE --}}

            @php

                $codCharge = (float) ($priceBreakdown['cod_charge'] ?? 0);

                $codTaxableAmount = (float) ($priceBreakdown['cod_taxable_amount'] ?? 0);

                $codGstRate = (float) ($priceBreakdown['cod_gst_rate'] ?? 18);

                $codGstAmount = (float) ($priceBreakdown['cod_gst_amount'] ?? 0);

            @endphp

            @if ($codCharge > 0)
                <tr>

                    <td></td>

                    <td class="desc">COD Charge</td>

                    <td>${{ number_format($codCharge, 2) }}</td>

                    <td>1</td>

                    <td>-</td>

                    <td>${{ number_format($codCharge, 2) }}</td>

                    <td>${{ number_format($codTaxableAmount, 2) }}</td>

                    <td colspan="2" style="font-size:8px;">
                        COD Tax ({{ number_format($codGstRate, 2) }}%):
                        ${{ number_format($codGstAmount, 2) }}
                    </td>

                    <td>${{ number_format($codCharge, 2) }}</td>

                </tr>
            @endif

            {{-- TOTAL --}}

            <tr class="total-row">

                <td colspan="9">TOTAL:

                </td>

                <td>

                    ${{ number_format($order->total_amount, 2) }}

                </td>

            </tr>

        </tbody>

    </table>

    {{-- AMOUNT IN WORDS + SIGNATORY --}}

    <table class="info-box">

        <tr>

            <td width="60%">

                <strong>Amount in Words:</strong><br>

                {{-- &#x20;

                {{ ucwords(\App\Helpers\NumberHelper::convertToWords($order->total_amount)) }} Dollars Only

                --}}

                {{ \App\Helpers\NumberHelper::convertToWords($order->total_amount) }}

            </td>

            <td class="sign-col">

                For Valeenza services LLc:

                <br>

                @if (file_exists(public_path('assets/images/signature.png')))
                    <img class="signature" src="{{ public_path('assets/images/signature.png') }}" alt="Signature">
                @else
                    <br><br><br>
                @endif

                <br>

                Authorized Signatory

            </td>

        </tr>

    </table>

    {{-- FOOTER NOTES --}}

    {{-- <div class="footer-note">

        Whether tax is payable under reverse charge - NO

    </div> --}}

    <div class="footer-note">

        Mode of Payment:

        {{ strtoupper($order->payment->payment_mode ?? 'COD') }}

    </div>

    {{-- FOOTER --}}

    <div class="footer">

        Please note that this invoice is not a demand for payment.<br>

        Regd Office: Valeenza Services LLc<br>

        12100 Grecian laurel Dr, Bakersfield, CA-93311,<br>

        United States of America<br>

        Email: care@valeenza.co |

        Tel: +1 6263624253

    </div>

</body>



</html>
