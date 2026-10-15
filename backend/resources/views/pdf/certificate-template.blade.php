<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Certificate {{ $number }}</title>
<style>
 @page { margin: 0 }
 html, body { margin: 0; padding: 0 }
 .bg { position: absolute; left: 0; top: 0; width: {{ $w }}pt; height: {{ $h }}pt }
 .t { position: absolute; text-align: center; line-height: 1.2; white-space: nowrap }
</style></head><body>
<img class="bg" src="{{ $bg }}" alt="">
@foreach($fields as $key => $f)
 <div class="t" style="left:{{ $f['left'] }}pt;top:{{ $f['top'] }}pt;width:{{ $f['width'] }}pt;font-size:{{ $f['size'] }}pt;color:{{ $f['color'] }};font-family:'{{ $f['font'] }}'">{!! nl2br(e($f['text'])) !!}</div>
@endforeach
@if($qrBox)
 <img style="position:absolute;left:{{ $qrBox['left'] }}pt;top:{{ $qrBox['top'] }}pt;width:{{ $qrBox['size'] }}pt;height:{{ $qrBox['size'] }}pt" src="{{ $qr }}" alt="QR code to verify this certificate">
@endif
</body></html>
