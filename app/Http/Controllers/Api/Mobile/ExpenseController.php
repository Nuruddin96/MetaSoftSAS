<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;

/**
 * Mirrors Tenant\ExpenseController's real capability exactly (index with
 * date-range filter + total, store, destroy) — a fully-built backend, not
 * invented for mobile.
 */
class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $from = $request->date('from') ?: now()->startOfMonth();
        $to = ($request->date('to') ?: now())->endOfDay();

        $expenses = Expense::with('category')
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->latest('expense_date')->paginate(30);

        return response()->json([
            'data' => $expenses->getCollection()->map(fn (Expense $e) => $this->present($e))->all(),
            'meta' => [
                'current_page' => $expenses->currentPage(),
                'last_page' => $expenses->lastPage(),
                'per_page' => $expenses->perPage(),
                'total' => $expenses->total(),
                'total_amount' => (float) Expense::whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])->sum('amount'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'expense_date' => 'required|date',
            'category_name' => 'nullable|string|max:100',
            'note' => 'nullable|string|max:500',
        ]);

        $categoryId = null;
        if (! empty($data['category_name'])) {
            $categoryId = ExpenseCategory::firstOrCreate(['name' => $data['category_name']])->id;
        }

        $expense = Expense::create([
            'title' => $data['title'],
            'amount' => $data['amount'],
            'expense_date' => $data['expense_date'],
            'expense_category_id' => $categoryId,
            'note' => $data['note'] ?? null,
            'user_id' => $request->user()->id,
        ]);

        return response()->json($this->present($expense->load('category')), 201);
    }

    public function destroy(Request $request, int $expense)
    {
        $tenant = app('currentTenant');
        $expense = Expense::where('tenant_id', $tenant->id)->findOrFail($expense);
        $expense->delete();

        return response()->json(['ok' => true]);
    }

    protected function present(Expense $e): array
    {
        return [
            'id' => $e->id,
            'title' => $e->title,
            'amount' => (float) $e->amount,
            'expense_date' => optional($e->expense_date)->toDateString(),
            'category_name' => $e->category?->name,
            'note' => $e->note,
        ];
    }
}
