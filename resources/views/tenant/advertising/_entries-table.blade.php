{{-- Shared by ledger/payments/charges.blade.php. Expects $entries (paginated AdBillingLedger, tenant-visible columns only). --}}
@php
    $typeLabels = [
        'payment' => 'পেমেন্ট',
        'charge' => 'চার্জ',
        'adjustment_credit' => 'অ্যাডজাস্টমেন্ট (ক্রেডিট)',
        'adjustment_debit' => 'অ্যাডজাস্টমেন্ট (ডেবিট)',
    ];
    $credit = ['payment', 'adjustment_credit'];
@endphp
<x-ui.card padding="none">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-paper/60 text-mute text-xs uppercase">
                <tr>
                    <th class="px-5 py-3 text-left">তারিখ</th>
                    <th class="px-5 py-3 text-left">ধরন</th>
                    <th class="px-5 py-3 text-left">নোট</th>
                    <th class="px-5 py-3 text-right">পরিমাণ</th>
                    <th class="px-5 py-3 text-right">ব্যালেন্স</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink/5">
                @forelse ($entries as $entry)
                    <tr>
                        <td class="px-5 py-3 whitespace-nowrap">{{ $entry->created_at->timezone(config('advertising.timezone'))->format('d M Y, h:i A') }}</td>
                        <td class="px-5 py-3">{{ $typeLabels[$entry->type] ?? $entry->type }}</td>
                        <td class="px-5 py-3 text-mute">{{ $entry->note ?: '—' }}</td>
                        <td class="px-5 py-3 text-right font-semibold {{ in_array($entry->type, $credit) ? 'text-leafdk' : 'text-red-600' }}">
                            {{ in_array($entry->type, $credit) ? '+' : '−' }}৳{{ number_format($entry->amount, 2) }}
                        </td>
                        <td class="px-5 py-3 text-right text-mute">৳{{ number_format($entry->balance_after, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-5 py-10 text-center text-mute">কোনো এন্ট্রি পাওয়া যায়নি।</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-ui.card>
<div class="mt-4">{{ $entries->links() }}</div>
