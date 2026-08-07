<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $from = $request->date('from') ?: now()->startOfMonth();
        $to   = ($request->date('to') ?: now())->endOfDay();

        $expenses = Expense::with('category')
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->latest('expense_date')->paginate(30)->withQueryString();

        return view('tenant.expenses', [
            'expenses'   => $expenses,
            'categories' => ExpenseCategory::orderBy('name')->get(),
            'total'      => (float) Expense::whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])->sum('amount'),
            'from'       => $from, 'to' => $to,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title'        => 'required|string|max:255',
            'amount'       => 'required|numeric|min:0',
            'expense_date' => 'required|date',
            'category_name'=> 'nullable|string|max:100',
            'note'         => 'nullable|string|max:500',
        ]);

        $categoryId = null;
        if (! empty($data['category_name'])) {
            $categoryId = ExpenseCategory::firstOrCreate(['name' => $data['category_name']])->id;
        }

        Expense::create([
            'title'               => $data['title'],
            'amount'              => $data['amount'],
            'expense_date'        => $data['expense_date'],
            'expense_category_id' => $categoryId,
            'note'                => $data['note'] ?? null,
            'user_id'             => auth('tenant')->id(),
        ]);

        return back()->with('success', 'খরচ যোগ হয়েছে।');
    }

    public function destroy(Expense $expense)
    {
        $expense->delete();
        return back()->with('success', 'খরচ মুছে ফেলা হয়েছে।');
    }
}
