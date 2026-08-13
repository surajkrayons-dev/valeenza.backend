<?php

namespace App\Services;

use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class OrderPdfService
{
    public function getOrderData($id)
    {
        $order = Order::with([
            'items.product.images',
            'coupon',
            'addressData',
            'payment'
        ])->findOrFail($id);

        return $order;
    }

    public function generateAndSave($id)
    {
        $order = $this->getOrderData($id);

        $pdf = Pdf::loadView('pdf.invoice', compact('order'));

        $fileName = 'invoice_' . $order->id . '.pdf';
        $filePath = 'invoices/' . $fileName;

        Storage::disk('public')->put($filePath, $pdf->output());

        $order->update(['pdf' => $filePath]);

        return $filePath;
    }
}