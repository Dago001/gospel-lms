<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Receipt {{ $receipt->number }}</title>
<style>
 body{font-family:DejaVu Sans,Arial,sans-serif;font-size:12px;color:#202820;margin:0}
 h1{font-size:20px;margin:0 0 4px;color:#164B1C} .muted{color:#626B63}
 .bar{border-top:4px solid #207027;padding-top:18px;margin-bottom:18px}
 table{width:100%;border-collapse:collapse;margin-top:14px} th{text-align:left;border-bottom:1px solid #DDE3DA;padding:6px 4px;font-size:11px;color:#626B63}
 td{padding:7px 4px;border-bottom:1px solid #EEF1EC} .r{text-align:right} .tot td{font-weight:bold;border-bottom:0}
</style></head><body>
<div class="bar">
 <h1>{{ $academy['name'] }}</h1>
 <div class="muted">{{ $academy['legal_name'] }} @if($academy['address'])· {{ $academy['address'] }}@endif @if($academy['support_email'])· {{ $academy['support_email'] }}@endif</div>
</div>
<h1>Payment receipt</h1>
<table style="margin-top:6px"><tr>
 <td><strong>Receipt no.</strong><br>{{ $receipt->number }}</td>
 <td><strong>Date</strong><br>{{ \Illuminate\Support\Carbon::parse($d['paid_at'])->timezone('Africa/Lagos')->format('j M Y, H:i') }} (WAT)</td>
 <td><strong>Order</strong><br>{{ $d['order_number'] }}</td>
 <td><strong>Reference</strong><br>{{ $d['payment_reference'] }}</td>
</tr></table>
<p><strong>Billed to</strong><br>{{ $d['customer']['name'] }}<br>{{ $d['customer']['email'] }}</p>
<table><thead><tr><th>Course</th><th class="r">Price</th><th class="r">Discount</th><th class="r">Amount</th></tr></thead><tbody>
@foreach($d['items'] as $i)
 <tr><td>{{ $i['title'] }}</td><td class="r">{{ \App\Support\Money::format($i['unit_price_minor'], $d['currency']) }}</td><td class="r">{{ \App\Support\Money::format($i['discount_minor'], $d['currency']) }}</td><td class="r">{{ \App\Support\Money::format($i['total_minor'], $d['currency']) }}</td></tr>
@endforeach
 <tr class="tot"><td colspan="3" class="r">Total paid</td><td class="r">{{ \App\Support\Money::format($d['total_minor'], $d['currency']) }}</td></tr>
</tbody></table>
<p class="muted" style="margin-top:24px">Payment processed by Paystack. Card details are never stored by {{ $academy['name'] }}.</p>
</body></html>
