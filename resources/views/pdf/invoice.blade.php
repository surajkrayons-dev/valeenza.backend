@php
    $rawPriceBreakdown = $order->price_breakdown ?? [];

    if (is_array($rawPriceBreakdown)) {
        $priceBreakdown = $rawPriceBreakdown;
    } elseif (is_object($rawPriceBreakdown)) {
        $priceBreakdown = (array) $rawPriceBreakdown;
    } else {
        $priceBreakdown = json_decode((string) $rawPriceBreakdown, true) ?: [];
    }

    $subtotal = round((float) ($order->subtotal ?? ($priceBreakdown['subtotal'] ?? 0)), 2);
    $couponDiscount = round((float) ($order->discount ?? ($priceBreakdown['coupon_discount'] ?? 0)), 2);
    $deliveryCharge = round((float) ($order->delivery_charge ?? ($priceBreakdown['delivery_charge'] ?? 0)), 2);

    // Monetary calculations use two decimal places; tax rates are stored/displayed to 2 decimals.
    $stateRate = round((float) ($priceBreakdown['product_state_rate'] ?? 0), 2);
    $localRate = round((float) ($priceBreakdown['product_local_rate'] ?? 0), 2);
    $combinedRate = round((float) ($priceBreakdown['product_combined_rate'] ?? $stateRate + $localRate), 2);

    $couponDiscountBasis = $priceBreakdown['coupon_discount_basis'] ?? null;
    $couponAppliesToTaxable = $couponDiscountBasis === 'taxable_amount';

    $orderItems = collect($order->items ?? []);
    $itemsGrossTotal = round(
        (float) $orderItems->sum(function ($line) {
            return round((float) ($line->total ?? 0), 2);
        }),
        2,
    );

    /*
     * Rebuild original per-line tax bases using the same formula and cent-level
     * rounding as the checkout controller. New orders apply coupons to the
     * tax-exclusive taxable amount; legacy orders retain the original gross-price
     * discount treatment so their invoice continues to match the amount charged.
     */
    $baseTaxLines = [];
    $originalTaxableTotal = 0.0;

    foreach ($orderItems as $line) {
        $gross = round((float) ($line->total ?? 0), 2);
        $taxable = $combinedRate > 0 ? round($gross / (1 + $combinedRate / 100), 2) : $gross;

        $stateTax = round(($taxable * $stateRate) / 100, 2);
        $localTax = round(($taxable * $localRate) / 100, 2);
        $lineTax = round($stateTax + $localTax, 2);
        $roundingDifference = round($gross - ($taxable + $lineTax), 2);

        if (abs($roundingDifference) >= 0.01) {
            $localTax = round($localTax + $roundingDifference, 2);
            $lineTax = round($stateTax + $localTax, 2);
        }

        $baseTaxLines[$line->id] = [
            'original_total' => $gross,
            'taxable_amount' => $taxable,
            'state_tax_amount' => $stateTax,
            'local_tax_amount' => $localTax,
            'total_tax_amount' => $lineTax,
        ];

        $originalTaxableTotal = round($originalTaxableTotal + $taxable, 2);
    }

    $itemInvoiceTax = [];
    $itemCount = $orderItems->count();
    $discountAllocated = 0.0;
    $allocatedGross = 0.0;

    if ($couponAppliesToTaxable) {
        // Correct/current rule: subtract coupon from the taxable base, then recalculate both taxes.
        $discountToAllocate = round(min(max(0, $couponDiscount), $originalTaxableTotal), 2);

        foreach ($orderItems->values() as $index => $line) {
            $baseLine = $baseTaxLines[$line->id] ?? [];
            $originalGross = round((float) ($baseLine['original_total'] ?? ($line->total ?? 0)), 2);
            $originalTaxable = round((float) ($baseLine['taxable_amount'] ?? $originalGross), 2);

            if ($index === $itemCount - 1) {
                $lineDiscount = round(max(0, $discountToAllocate - $discountAllocated), 2);
            } elseif ($originalTaxableTotal > 0) {
                $lineDiscount = round($discountToAllocate * ($originalTaxable / $originalTaxableTotal), 2);
                $lineDiscount = min(
                    $lineDiscount,
                    max(0, round($discountToAllocate - $discountAllocated, 2)),
                    $originalTaxable,
                );
            } else {
                $lineDiscount = 0.0;
            }

            $lineDiscount = round(min(max(0, $lineDiscount), $originalTaxable), 2);
            $discountAllocated = round($discountAllocated + $lineDiscount, 2);
            $discountedTaxable = round(max(0, $originalTaxable - $lineDiscount), 2);

            if ($lineDiscount <= 0.0) {
                // No coupon allocated to this line; preserve its original inclusive total and rounded tax split.
                $stateTax = round((float) ($baseLine['state_tax_amount'] ?? 0), 2);
                $localTax = round((float) ($baseLine['local_tax_amount'] ?? 0), 2);
                $lineTax = round($stateTax + $localTax, 2);
                $discountedGross = $originalGross;
            } else {
                $stateTax = round(($discountedTaxable * $stateRate) / 100, 2);
                $localTax = round(($discountedTaxable * $localRate) / 100, 2);
                $lineTax = round($stateTax + $localTax, 2);
                $discountedGross = round($discountedTaxable + $lineTax, 2);
            }

            $itemInvoiceTax[$line->id] = [
                'original_total' => $originalGross,
                'original_taxable_amount' => $originalTaxable,
                'allocated_discount' => $lineDiscount,
                'taxable_amount' => $discountedTaxable,
                'state_tax_amount' => $stateTax,
                'local_tax_amount' => $localTax,
                'total_tax_amount' => $lineTax,
                'discounted_total' => $discountedGross,
            ];
        }
    } else {
        // Legacy orders: preserve the original amount charged and discount gross price as the historical order did.
        $legacyDiscountedGrossTotal = round(max(0, $subtotal - $couponDiscount), 2);
        $legacyDiscountedGrossTotal = min($legacyDiscountedGrossTotal, $itemsGrossTotal);

        foreach ($orderItems->values() as $index => $line) {
            $originalGross = round((float) ($line->total ?? 0), 2);

            if ($index === $itemCount - 1) {
                $discountedGross = round(max(0, $legacyDiscountedGrossTotal - $allocatedGross), 2);
            } else {
                $discountedGross =
                    $itemsGrossTotal > 0
                        ? round($originalGross * ($legacyDiscountedGrossTotal / $itemsGrossTotal), 2)
                        : 0.0;
                $discountedGross = min(
                    $discountedGross,
                    max(0, round($legacyDiscountedGrossTotal - $allocatedGross, 2)),
                );
            }

            $allocatedGross = round($allocatedGross + $discountedGross, 2);
            $taxable = $combinedRate > 0 ? round($discountedGross / (1 + $combinedRate / 100), 2) : $discountedGross;
            $stateTax = round(($taxable * $stateRate) / 100, 2);
            $localTax = round(($taxable * $localRate) / 100, 2);
            $lineTax = round($stateTax + $localTax, 2);
            $roundingDifference = round($discountedGross - ($taxable + $lineTax), 2);

            if (abs($roundingDifference) >= 0.01) {
                $localTax = round($localTax + $roundingDifference, 2);
                $lineTax = round($stateTax + $localTax, 2);
            }

            $baseLine = $baseTaxLines[$line->id] ?? [];
            $originalTaxable = round((float) ($baseLine['taxable_amount'] ?? $originalGross), 2);

            $itemInvoiceTax[$line->id] = [
                'original_total' => $originalGross,
                'original_taxable_amount' => $originalTaxable,
                'allocated_discount' => round(max(0, $originalGross - $discountedGross), 2),
                'taxable_amount' => $taxable,
                'state_tax_amount' => $stateTax,
                'local_tax_amount' => $localTax,
                'total_tax_amount' => $lineTax,
                'discounted_total' => $discountedGross,
            ];
        }
    }

    $discountedProductTotal = round(array_sum(array_column($itemInvoiceTax, 'discounted_total')), 2);
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
            font-size: 8px;
            table-layout: fixed;
            width: 100%;
        }







        table.items th,
        table.items td {
            border: 1px solid #999;
            padding: 4px 3px;
            text-align: center;
            overflow-wrap: break-word;
            vertical-align: middle;
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
                <th style="width:4%;">S.No</th>
                <th style="width:17%; text-align:left;">Description</th>
                {{-- <th style="width:8%;">Unit Price<br>(Tax Incl.)</th> --}}
                <th style="width:10%;">Unit Price</th>
                <th style="width:4%;">Qty</th>
                <th style="width:9%;">Coupon Discount</th>
                <th style="width:10%;">Net Amount<br>(Taxable After Discount)</th>
                <th style="width:12%;">State Rate<br>(State Tax)</th>
                <th style="width:12%;">Local Rate<br>(Local Tax)</th>
                <th style="width:14%;">Total Amount</th>
            </tr>
        </thead>
        <tbody>
            {{-- PRODUCT LINES --}}
            @foreach ($orderItems as $index => $item)
                @php
                    $lineTax = $itemInvoiceTax[$item->id] ?? [];
                    $fallbackGross = round((float) ($item->total ?? 0), 2);
                    $fallbackOriginalTaxable =
                        $combinedRate > 0 ? round($fallbackGross / (1 + $combinedRate / 100), 2) : $fallbackGross;

                    $originalTaxableAmount = round(
                        (float) ($lineTax['original_taxable_amount'] ?? $fallbackOriginalTaxable),
                        2,
                    );
                    $allocatedCouponDiscount = round((float) ($lineTax['allocated_discount'] ?? 0), 2);
                    $discountedTaxableAmount = round(
                        (float) ($lineTax['taxable_amount'] ?? ($item->taxable_amount ?? $fallbackOriginalTaxable)),
                        2,
                    );
                    $lineStateTax = round((float) ($lineTax['state_tax_amount'] ?? 0), 2);
                    $lineLocalTax = round((float) ($lineTax['local_tax_amount'] ?? 0), 2);
                    $lineTotalAmount = round((float) ($lineTax['discounted_total'] ?? ($item->total ?? 0)), 2);
                @endphp
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td class="desc">
                        {{ $item->product_name }}
                        @if (!empty($item->hsn_code))
                            <small>HSN: {{ $item->hsn_code }}</small>
                        @endif
                    </td>
                    {{-- <td>${{ number_format(round((float) $item->price, 2), 2) }}</td> --}}
                    <td>${{ number_format($originalTaxableAmount, 2) }}</td>
                    <td>{{ $item->quantity }}</td>
                    <td>
                        @if ($allocatedCouponDiscount > 0)
                            -${{ number_format($allocatedCouponDiscount, 2) }}
                            @if ($index === 0 && $couponDiscount > 0 && optional($order->coupon)->code)
                                <small>{{ $order->coupon->code }}</small>
                            @endif
                        @else
                            -
                            @if ($index === 0 && $couponDiscount > 0 && optional($order->coupon)->code)
                                <small>{{ $order->coupon->code }}</small>
                            @endif
                        @endif
                    </td>
                    <td>${{ number_format($discountedTaxableAmount, 2) }}</td>
                    <td>
                        {{ number_format($stateRate, 2) }}%<br>
                        (${{ number_format($lineStateTax, 2) }})
                    </td>
                    <td>
                        {{ number_format($localRate, 2) }}%<br>
                        (${{ number_format($lineLocalTax, 2) }})
                    </td>
                    <td>${{ number_format($lineTotalAmount, 2) }}</td>
                </tr>
            @endforeach

            {{-- PRODUCT SUBTOTAL AFTER DISCOUNT, INCLUDING RECOMPUTED TAX --}}
            @if ($orderItems->isNotEmpty())
                <tr>
                    <td></td>
                    <td class="desc">Subtotal (After Discount)</td>
                    <td>-</td>
                    <td>-</td>
                    <td>-</td>
                    <td>-</td>
                    <td>-</td>
                    <td>-</td>
                    <td>${{ number_format(round($discountedProductTotal, 2), 2) }}</td>
                </tr>
            @endif

            {{-- DELIVERY CHARGE --}}
            @if ($deliveryCharge > 0)
                <tr>
                    <td></td>
                    <td class="desc">Delivery Charge</td>
                    <td>${{ number_format(round($deliveryCharge, 2), 2) }}</td>
                    <td>-</td>
                    <td>-</td>
                    <td>-</td>
                    <td>-</td>
                    <td>-</td>
                    <td>${{ number_format(round($deliveryCharge, 2), 2) }}</td>
                </tr>
            @endif

            {{-- COD CHARGE --}}
            @php
                $codCharge = round((float) ($priceBreakdown['cod_charge'] ?? 0), 2);
                $codTaxableAmount = round((float) ($priceBreakdown['cod_taxable_amount'] ?? 0), 2);
                $codGstRate = round((float) ($priceBreakdown['cod_gst_rate'] ?? 18), 2);
                $codGstAmount = round((float) ($priceBreakdown['cod_gst_amount'] ?? 0), 2);
            @endphp
            @if ($codCharge > 0)
                <tr>
                    <td></td>
                    <td class="desc">COD Charge</td>
                    <td>${{ number_format($codCharge, 2) }}</td>
                    <td>1</td>
                    <td>${{ number_format($codTaxableAmount, 2) }}</td>
                    <td>-</td>
                    <td>${{ number_format($codTaxableAmount, 2) }}</td>
                    <td colspan="2" style="font-size:8px;">
                        COD Tax ({{ number_format($codGstRate, 2) }}%):
                        ${{ number_format($codGstAmount, 2) }}
                    </td>
                    <td>${{ number_format($codCharge, 2) }}</td>
                </tr>
            @endif

            {{-- GRAND TOTAL --}}
            <tr class="total-row">
                <td colspan="8">TOTAL:</td>
                <td>${{ number_format(round((float) $order->total_amount, 2), 2) }}</td>
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
