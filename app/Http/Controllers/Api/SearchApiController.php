<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class SearchApiController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'q'           => 'nullable|string|max:100',
            'category_id' => 'nullable|integer|exists:categories,id',
            'per_page'    => 'nullable|integer|min:1|max:100',
        ]);

        $keyword = trim((string) $request->input('q', ''));
        $perPage = $validated['per_page'] ?? 20;

        $query = Product::query()
            ->select('id', 'name', 'slug', 'category_id')
            ->with(['category' => function ($q) {
                $q->select('id', 'name', 'slug')->where('status', 1);
            }])
            ->where('status', 1)
            ->whereHas('category', function ($q) {
                $q->where('status', 1);
            });

        if ($keyword !== '') {

            $escaped = $this->escapeLike($keyword);

            $query->where(function ($q) use ($escaped) {
                $q->where('name', 'LIKE', "%{$escaped}%")
                    ->orWhere('slug', 'LIKE', "%{$escaped}%")
                    ->orWhereHas('category', function ($cat) use ($escaped) {
                        $cat->where('name', 'LIKE', "%{$escaped}%")
                            ->orWhere('slug', 'LIKE', "%{$escaped}%");
                    });
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        $query->orderBy('name');

        $products = $query->paginate($perPage)->withQueryString();

        return response()->json([

            'success' => true,

            'message' => $products->count()
                ? 'Products fetched successfully.'
                : 'No products found.',

            'keyword' => $keyword,

            'pagination' => [
                'current_page'   => $products->currentPage(),
                'last_page'      => $products->lastPage(),
                'per_page'       => $products->perPage(),
                'total'          => $products->total(),
                'has_more_pages' => $products->hasMorePages(),
            ],

            'data' => $products->getCollection()->map(function ($product) {

                $category = $product->category;

                return [
                    'name' => $product->name,
                    'slug' => $product->slug,

                    'category' => [
                        'name' => $category->name ?? null,
                        'slug' => $category->slug ?? null,
                    ],
                ];
            }),

        ], 200);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}