<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Certificate {{ $c->number }}</title>
<style>
 @page { margin: 0 }
 body{font-family:DejaVu Serif,Georgia,serif;color:#202820;margin:0}
 .frame{margin:28px;border:3px solid #207027;padding:6px;height:520px}
 .inner{border:1px solid #B8954B;height:508px;text-align:center;padding:26px 50px;position:relative}
 .academy{font-family:DejaVu Sans,Arial,sans-serif;font-size:15px;letter-spacing:1px;color:#164B1C;font-weight:bold;text-transform:uppercase}
 h1{font-size:30px;margin:14px 0 4px;font-weight:normal;color:#164B1C}
 .name{font-size:32px;margin:18px 0 6px;border-bottom:1px solid #DDE3DA;display:inline-block;padding:0 24px 4px}
 .small{font-family:DejaVu Sans,Arial,sans-serif;font-size:11px;color:#626B63}
 .course{font-size:22px;margin:8px 0 12px;color:#207027}
 .foot{position:absolute;left:50px;right:50px;bottom:24px}
 .foot td{vertical-align:bottom;font-family:DejaVu Sans,Arial,sans-serif;font-size:10px;color:#626B63}
 .sig{border-top:1px solid #202820;padding-top:4px;width:200px;color:#202820}
</style></head><body>
<div class="frame"><div class="inner">
 <div class="academy">{{ $academy['name'] }}</div>
 <h1>Certificate of Completion</h1>
 <div class="small">This is to certify that</div>
 <div class="name">{{ $c->holder_name }}</div>
 <div class="small">has successfully completed the course</div>
 <div class="course">{{ $c->course_title }}</div>
 <div class="small">
  Issued {{ $c->issued_at->timezone('Africa/Lagos')->format('j F Y') }}
  @if($c->duration_minutes) · Course duration {{ intdiv($c->duration_minutes, 60) }}h {{ $c->duration_minutes % 60 }}m @endif
  @if($c->show_grade && $c->grade_percent !== null) · Final grade {{ number_format($c->grade_percent, 1) }}% @endif
 </div>
 <table class="foot" width="100%"><tr>
  <td align="left" width="35%">
    @if($c->signatory_name)<div class="sig">{{ $c->signatory_name }}<br>{{ $c->signatory_title }}</div>@else<div class="sig">Authorised signatory</div>@endif
  </td>
  <td align="center">Certificate no. {{ $c->number }}<br>Verify at {{ $url }}</td>
  <td align="right" width="20%"><img src="{{ $qr }}" width="92" height="92" alt="QR code to verify this certificate"></td>
 </tr></table>
</div></div></body></html>
