<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\AdminController;
use App\Models\ProductTax;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductTaxController extends AdminController
{
    public function getIndex(Request $request)
    {
        $states = ProductTax::query()
            ->select('state_code', 'state_name')
            ->orderBy('state_name', 'asc')
            ->get();

        return view('admin.product_taxes.index', compact('states'));
    }

    public function getList(Request $request)
    {
        $list = ProductTax::query()
            ->select([
                'id',
                'state_code',
                'state_name',
                'state_rate',
                'local_rate',
                'combined_rate',
                'status',
                'created_at'
            ])

            // STATUS FILTER
            ->when(
                $request->filled('status'),
                function ($q) use ($request) {

                    if ($request->status === 'active') {
                        $q->where('status', 1);
                    }

                    if ($request->status === 'inactive') {
                        $q->where('status', 0);
                    }
                }
            )

            // STATE FILTER
            ->when(
                $request->filled('state_code'),
                function ($q) use ($request) {
                    $q->where('state_code', $request->state_code);
                }
            )

            ->orderBy('state_name', 'asc');

        return \DataTables::of($list)

            ->addColumn('status_badge', function ($row) {

                if ($row->status) {
                    return '<span class="badge bg-success">Active</span>';
                }

                return '<span class="badge bg-danger">Inactive</span>';
            })

            ->addColumn('state_rate_display', function ($row) {
                return number_format((float) $row->state_rate, 2) . '%';
            })

            ->addColumn('local_rate_display', function ($row) {
                return number_format((float) $row->local_rate, 2) . '%';
            })

            ->addColumn('combined_rate_display', function ($row) {
                return number_format((float) $row->combined_rate, 2) . '%';
            })

            ->addColumn('action', function ($row) {

                $edit = route(
                    'admin.product_taxes.update.index',
                    ['id' => $row->id]
                );

                $delete = route(
                    'admin.product_taxes.delete',
                    ['id' => $row->id]
                );

                $status = route(
                    'admin.product_taxes.change.status',
                    ['id' => $row->id]
                );

                return '
                    <a href="' . $edit . '"
                    class="btn btn-sm btn-primary">
                        <i class="bx bx-pencil"></i> Edit
                    </a>

                    <button
                        type="button"
                        data-url="' . $status . '"
                        class="btn btn-sm btn-warning change-status-btn">
                        Status
                    </button>

                    <button
                        type="button"
                        data-url="' . $delete . '"
                        class="btn btn-sm btn-danger delete-btn">
                        <i class="bx bx-trash"></i> Delete
                    </button>
                ';
            })

            ->rawColumns([
                'status_badge',
                'action'
            ])

            ->make(true);
    }

    public function getCreate()
    {
        return view('admin.product_taxes.create');
    }

    public function postCreate(Request $request)
    {
        $request->validate([
            'state_code' => 'required|string|size:2|unique:product_taxes,state_code',
            'state_name' => 'required|string|max:100',
            'state_rate' => 'required|numeric|min:0|max:100',
            'local_rate' => 'required|numeric|min:0|max:100',
            'status' => 'nullable|in:0,1'
        ]);

        DB::beginTransaction();

        try {

            $stateRate = (float) $request->state_rate;
            $localRate = (float) $request->local_rate;

            ProductTax::create([
                'state_code' => strtoupper(trim($request->state_code)),
                'state_name' => trim($request->state_name),
                'state_rate' => $stateRate,
                'local_rate' => $localRate,
                'combined_rate' => round($stateRate + $localRate, 2),
                'status' => (int) ($request->status ?? 1)
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Product tax created successfully'
            ]);

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error('PRODUCT TAX CREATE ERROR', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Something went wrong'
            ], 500);
        }
    }

    public function getUpdate(Request $request)
    {
        $product_tax = ProductTax::findOrFail($request->id);

        return view('admin.product_taxes.update', compact('product_tax'));
    }

    public function postUpdate(Request $request, $id)
    {
        $product_tax = ProductTax::findOrFail($id);

        $request->validate([
            'state_code' => [
                'required',
                'string',
                'size:2',
                'unique:product_taxes,state_code,' . $product_tax->id
            ],
            'state_name' => 'required|string|max:100',
            'state_rate' => 'required|numeric|min:0|max:100',
            'local_rate' => 'required|numeric|min:0|max:100',
            'status' => 'nullable|in:0,1'
        ]);

        DB::beginTransaction();

        try {

            $stateRate = (float) $request->state_rate;
            $localRate = (float) $request->local_rate;

            $product_tax->update([
                'state_code' => strtoupper(trim($request->state_code)),
                'state_name' => trim($request->state_name),
                'state_rate' => $stateRate,
                'local_rate' => $localRate,
                'combined_rate' => round($stateRate + $localRate, 2),
                'status' => (int) ($request->status ?? 0)
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Product tax updated successfully'
            ]);

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error('PRODUCT TAX UPDATE ERROR', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Something went wrong'
            ], 500);
        }
    }

    public function getDelete(Request $request)
    {
        $product_tax = ProductTax::findOrFail($request->id);

        $product_tax->delete();

        return response()->json([
            'message' => 'Product tax deleted successfully'
        ]);
    }

    public function getChangeStatus(Request $request)
    {
        $product_tax = ProductTax::findOrFail($request->id);

        $product_tax->status = !$product_tax->status;
        $product_tax->save();

        return response()->json([
            'message' => 'Status updated successfully'
        ]);
    }
}