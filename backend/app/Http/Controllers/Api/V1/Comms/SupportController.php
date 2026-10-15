<?php

namespace App\Http\Controllers\Api\V1\Comms;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Notifications\SupportReplyNotice;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class SupportController extends Controller
{
    private const CATEGORIES = 'account,payment,course,technical,other';

    private function number(): string
    {
        do {
            $n = 'SUP-'.random_int(100000, 999999);
        } while (SupportTicket::where('number', $n)->exists());

        return $n;
    }

    private function row(SupportTicket $t, bool $staff = false): array
    {
        return ['id' => $t->id, 'number' => $t->number, 'subject' => $t->subject, 'category' => $t->category, 'status' => $t->status, 'priority' => $t->priority, 'last_activity_at' => $t->last_activity_at->toIso8601String(), 'created_at' => $t->created_at->toIso8601String()]
            + ($staff ? ['requester' => $t->user ? ['id' => $t->user->id, 'name' => $t->user->name, 'email' => $t->user->email] : ['name' => $t->guest_name, 'email' => $t->guest_email], 'assigned_to' => $t->assigned_to] : []);
    }

    private function messages(SupportTicket $t, bool $staff): array
    {
        return $t->messages()->when(! $staff, fn ($q) => $q->where('is_internal', false))->with('user:id,name')->orderBy('created_at')->get()
            ->map(fn ($m) => ['id' => $m->id, 'author_type' => $m->author_type, 'author' => $m->author_type === 'staff' && ! $staff ? 'Support team' : ($m->user?->name ?? $t->guest_name), 'body' => $m->body, 'internal' => $m->is_internal, 'created_at' => $m->created_at->toIso8601String()])->all();
    }

    private function open(array $d, ?\App\Models\User $user): SupportTicket
    {
        return DB::transaction(function () use ($d, $user) {
            $t = new SupportTicket(['subject' => $d['subject'], 'category' => $d['category'] ?? 'other', 'last_activity_at' => now(), 'status' => 'open', 'priority' => 'normal']);
            $t->number = $this->number();
            $t->user_id = $user?->id;
            $t->course_id = $d['course_id'] ?? null;
            if (! $user) {
                $t->guest_name = $d['name'];
                $t->guest_email = strtolower($d['email']);
            }
            $t->last_activity_at = now();
            $t->save();
            $m = new SupportMessage(['body' => strip_tags($d['message']), 'author_type' => $user ? 'user' : 'guest', 'is_internal' => false]);
            $m->ticket_id = $t->id;
            $m->user_id = $user?->id;
            $m->save();

            return $t;
        });
    }

    // ---- public contact form (no account)
    public function contact(Request $request): JsonResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email:rfc', 'max:190'], 'subject' => ['required', 'string', 'max:200'], 'message' => ['required', 'string', 'min:10', 'max:5000'],
            'category' => ['nullable', 'in:'.self::CATEGORIES], 'website' => ['nullable', 'max:0']]);   // 'website' is a honeypot humans never fill in
        $t = $this->open($d, null);
        Notification::route('mail', $t->guest_email)->notify(new SupportReplyNotice($t->number, 'We received your message', 'Thank you for contacting us. A member of the team will reply by email. Keep this reference: '.$t->number));

        return response()->json(['data' => ['number' => $t->number]], 201);
    }

    // ---- signed-in users
    public function mine(Request $request): JsonResponse
    {
        return response()->json(['data' => SupportTicket::where('user_id', $request->user()->id)->orderByDesc('last_activity_at')->paginate(20)->getCollection()->map(fn ($t) => $this->row($t))]);
    }

    public function create(Request $request): JsonResponse
    {
        $d = $request->validate(['subject' => ['required', 'string', 'max:200'], 'message' => ['required', 'string', 'min:5', 'max:5000'], 'category' => ['nullable', 'in:'.self::CATEGORIES], 'course_id' => ['nullable', 'uuid', 'exists:courses,id']]);

        return response()->json(['data' => $this->row($this->open($d, $request->user()))], 201);
    }

    private function ownTicket(Request $request, SupportTicket $t): SupportTicket
    {
        abort_unless($t->user_id === $request->user()->id, 404);

        return $t;
    }

    public function show(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->ownTicket($request, $ticket);

        return response()->json(['data' => $this->row($ticket) + ['messages' => $this->messages($ticket, false)]]);
    }

    public function reply(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->ownTicket($request, $ticket);
        if ($ticket->status === 'closed') {
            throw new DomainException('This request is closed. Open a new one.', 'ticket_closed', 409);
        }
        $d = $request->validate(['message' => ['required', 'string', 'max:5000']]);
        $m = new SupportMessage(['body' => strip_tags($d['message']), 'author_type' => 'user']);
        $m->ticket_id = $ticket->id;
        $m->user_id = $request->user()->id;
        $m->save();
        $ticket->update(['status' => 'open', 'last_activity_at' => now(), 'closed_at' => null]);

        return response()->json(['data' => ['id' => $m->id]], 201);
    }

    public function close(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->ownTicket($request, $ticket)->update(['status' => 'closed', 'closed_at' => now()]);

        return response()->json(['data' => ['status' => 'closed']]);
    }

    // ---- support staff
    public function staffIndex(Request $request): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', 'in:open,pending,resolved,closed'], 'q' => ['nullable', 'string', 'max:80'], 'assigned' => ['nullable', 'in:me,none']]);
        $p = SupportTicket::with('user:id,name,email')->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('number', 'ilike', '%'.addcslashes($v, '%_\\').'%')->orWhere('subject', 'ilike', '%'.addcslashes($v, '%_\\').'%')))
            ->when(($f['assigned'] ?? null) === 'me', fn ($q) => $q->where('assigned_to', $request->user()->id))->when(($f['assigned'] ?? null) === 'none', fn ($q) => $q->whereNull('assigned_to'))
            ->orderByRaw("case status when 'open' then 0 when 'pending' then 1 else 2 end")->orderBy('last_activity_at')->paginate(25);

        return response()->json(['data' => $p->getCollection()->map(fn ($t) => $this->row($t, true))->values(), 'meta' => ['current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()]]);
    }

    public function staffShow(SupportTicket $ticket): JsonResponse
    {
        $ticket->load('user:id,name,email');

        return response()->json(['data' => $this->row($ticket, true) + ['messages' => $this->messages($ticket, true)]]);
    }

    public function staffReply(Request $request, SupportTicket $ticket): JsonResponse
    {
        $d = $request->validate(['message' => ['required', 'string', 'max:5000'], 'internal' => ['sometimes', 'boolean']]);
        $internal = (bool) ($d['internal'] ?? false);
        $m = new SupportMessage(['body' => strip_tags($d['message']), 'author_type' => 'staff', 'is_internal' => $internal]);
        $m->ticket_id = $ticket->id;
        $m->user_id = $request->user()->id;
        $m->save();
        $ticket->update(['last_activity_at' => now(), 'status' => $internal ? $ticket->status : 'pending', 'assigned_to' => $ticket->assigned_to ?? $request->user()->id]);
        if (! $internal) {
            $n = new SupportReplyNotice($ticket->number, $ticket->subject, $d['message']);
            $ticket->user ? $ticket->user->notify($n) : Notification::route('mail', $ticket->guest_email)->notify($n);
        }

        return response()->json(['data' => ['id' => $m->id]], 201);
    }

    public function staffUpdate(Request $request, SupportTicket $ticket, AuditLogger $audit): JsonResponse
    {
        $d = $request->validate(['status' => ['sometimes', 'in:open,pending,resolved,closed'], 'priority' => ['sometimes', 'in:low,normal,high'], 'assigned_to' => ['sometimes', 'nullable', 'uuid', 'exists:users,id']]);
        if (! empty($d['assigned_to']) && ! \App\Models\User::find($d['assigned_to'])?->hasPermission('support.manage')) {
            throw new DomainException('That person cannot handle support requests.', 'invalid_assignee');
        }
        $old = $ticket->only(array_keys($d));
        $ticket->fill($d);
        if (($d['status'] ?? null) === 'closed') {
            $ticket->closed_at = now();
        }
        $ticket->save();
        $audit->log('support.ticket_updated', $ticket, $old, $d);

        return response()->json(['data' => $this->row($ticket->load('user:id,name,email'), true)]);
    }
}
