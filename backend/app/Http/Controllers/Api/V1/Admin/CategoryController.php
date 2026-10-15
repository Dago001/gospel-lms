<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => Category::withCount('courses')->orderBy('sort_order')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:120', 'unique:categories,name'], 'description' => ['nullable', 'string', 'max:1000'], 'sort_order' => ['nullable', 'integer', 'between:0,65000']]);
        $c = Category::create($d + ['slug' => Str::slug($d['name']), 'sort_order' => $d['sort_order'] ?? 0]);
        $this->audit->log('category.created', $c, new: $d);

        return response()->json(['data' => $c], 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $d = $request->validate(['name' => ['sometimes', 'string', 'max:120', 'unique:categories,name,'.$category->id], 'description' => ['nullable', 'string', 'max:1000'], 'sort_order' => ['nullable', 'integer', 'between:0,65000']]);
        $category->update($d);
        $this->audit->log('category.updated', $category, new: $d);

        return response()->json(['data' => $category]);
    }

    public function destroy(Category $category): JsonResponse
    {
        if ($category->courses()->exists()) {
            throw new DomainException('Move or archive this category\'s courses first.', 'category_in_use', 409);
        }
        $category->delete();
        $this->audit->log('category.deleted', $category);

        return response()->json(['message' => 'Category deleted.']);
    }
}
