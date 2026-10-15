<?php

namespace App\Services\Chat;

use App\Http\Controllers\Api\V1\Public\CertificateVerifyController;
use App\Http\Resources\CourseSerializer;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Http\Request;

/**
 * Deterministic help assistant. It does NOT call an AI model: every answer comes from the vetted topic list
 * (resources/chatbot/knowledge.php) or from read-only, parameterised queries on published catalogue data and, for a signed-in
 * user, that user's own enrolments. Free text is never concatenated into SQL, never executed, never stored and never echoed
 * back except as letters/digits that have passed the tokeniser.
 */
class ChatAssistant
{
    public const MAX_LENGTH = 300;

    private const STOP = ['a', 'an', 'the', 'is', 'are', 'am', 'do', 'does', 'did', 'i', 'me', 'my', 'we', 'you', 'your', 'to', 'of', 'in', 'on', 'for', 'and', 'or', 'it', 'its', 'can', 'could', 'would', 'should', 'will', 'what', 'which', 'who', 'how', 'when', 'where', 'why', 'there', 'any', 'have', 'has', 'get', 'with', 'about', 'please', 'want', 'need', 'like', 'some', 'tell', 'show', 'find', 'give', 'let', 'know', 'be', 'at', 'as', 'that', 'this', 'than', 'then', 'if', 'so', 'just', 'hi', 'hello', 'hey', 'course', 'courses', 'class', 'classes', 'study', 'learn', 'looking', 'search', 'offer', 'offers', 'available', 'teach', 'taught', 'teaching', 'subject', 'topic', 'topics', 'anything', 'free', 'paid', 'cheap', 'cost', 'price'];

    /** @var array<int, array<string, mixed>> */
    private array $topics;

    public function __construct()
    {
        $this->topics = require resource_path('chatbot/knowledge.php');
    }

    /** Strips control/format characters, normalises Unicode and whitespace and enforces the length cap. */
    public static function clean(string $raw): string
    {
        if (class_exists(\Normalizer::class)) {
            $raw = \Normalizer::normalize($raw, \Normalizer::FORM_KC) ?: $raw;
        }
        $raw = preg_replace('/[\p{C}\p{Zl}\p{Zp}]+/u', ' ', $raw) ?? '';
        $raw = preg_replace('/\s+/u', ' ', $raw) ?? '';

        return mb_substr(trim($raw), 0, self::MAX_LENGTH);
    }

    /** @return array{reply: string, intent: string, cards: array, links: array, suggestions: array} */
    public function respond(string $message, Request $request): array
    {
        $text = self::clean($message);
        $norm = $this->normalise($text);

        if ($norm === '') {
            return $this->welcome();
        }
        if (preg_match('/(?<![a-z0-9])([a-z0-9]{32})(?![a-z0-9])/i', $text, $m)) {
            return $this->certificate($m[1], $request);
        }
        if ($this->has($norm, ['hello', 'hi', 'hey', 'good morning', 'good afternoon', 'good evening', 'peace', 'greetings']) && $this->wordCount($norm) <= 4) {
            return $this->welcome();
        }
        if ($this->has($norm, ['thanks', 'thank you', 'thank', 'appreciate', 'god bless', 'bless you'])) {
            return $this->reply('thanks', 'You are welcome. God bless you as you study. Is there anything else I can help with?', [], [], $this->defaultSuggestions());
        }
        if ($this->has($norm, ['bye', 'goodbye', 'see you'])) {
            return $this->reply('bye', 'Goodbye, and God bless you. I am here whenever you need help.');
        }
        if ($this->has($norm, ['human', 'real person', 'agent', 'speak to someone', 'talk to someone', 'talk to a person', 'speak to a person', 'representative', 'live chat'])) {
            return $this->human($request);
        }
        if ($this->has($norm, ['my courses', 'my enrolments', 'my enrollments', 'my progress', 'what am i enrolled', 'courses i am taking', 'continue learning', 'my classes'])) {
            return $this->myCourses($request);
        }
        $wantsFree = $this->has($norm, ['free course', 'free courses', 'free', 'no cost', 'without paying']);
        $cat = $this->has($norm, ['categories', 'subjects', 'what can i study', 'what do you offer', 'what do you teach', 'all courses', 'catalogue', 'catalog', 'list courses', 'available courses']) ? $this->catalogue() : null;
        if ($cat) {
            return $cat;
        }

        $topic = $this->bestTopic($norm);
        $tokens = $this->searchTokens($norm);
        $explicit = (bool) preg_match('/\b(courses?|classes|programmes?|programs?)\s+(on|about|in|for|covering|related to)\b|\bdo you (have|offer|teach)\b|\bi want to (study|learn)\b|\blearn about\b/', $norm);
        if ($explicit && $tokens && ($found = $this->searchCourses($tokens, $wantsFree, false))) {
            return $found;
        }
        if ($topic && ! ($wantsFree && $topic['id'] === 'pricing')) {
            return $this->topicReply($topic);
        }

        if ($tokens || $wantsFree) {
            $found = $this->searchCourses($tokens, $wantsFree);
            if ($found) {
                return $found;
            }
        }

        return $this->reply('fallback', "I am not sure I understood that. I can help with enrolling, payments and refunds, certificates, exams, assignments, live classes and finding a course. You can also ask for a person.", [], [['Contact the academy', '/contact'], ['Frequently asked questions', '/faq']], $this->defaultSuggestions(), 'Try asking about a subject, such as "courses on Romans".');
    }

    public function welcome(): array
    {
        return $this->reply('welcome', 'Peace be with you. I am the GOSPEL LMS assistant. I can answer questions about enrolling, payments, certificates, exams and live classes, and help you find a course. I cannot see your password or card details, so please never type them here.', [], [], $this->defaultSuggestions());
    }

    public function defaultSuggestions(): array
    {
        return ['What courses can I study?', 'How do I enrol?', 'How do I pay?', 'How do I get a certificate?', 'Talk to a person'];
    }

    private function topicReply(array $t): array
    {
        $a = Settings::academy();
        $days = Settings::get('policy.refund_window_days');
        $answer = strtr($t['answer'], [
            '{refund_text}' => $days ? "Refund requests are considered within {$days} days of payment, under the conditions in the refund policy." : 'The conditions for refunds are set out in the refund policy, so please read it before you pay.',
            '{support_email}' => ! empty($a['support_email']) ? ' or by email to '.$a['support_email'] : '',
        ]);
        $links = array_values(array_filter($t['links'] ?? [], fn ($l) => $this->safePath($l[1])));

        return $this->reply($t['id'], $answer, [], $links, $this->related($t['id']));
    }

    private function related(string $id): array
    {
        $map = ['enrol' => ['How do I pay?', 'What courses can I study?'], 'payment' => ['I paid but cannot access my course', 'What is the refund policy?'], 'certificate' => ['How can I verify a certificate?'], 'exams' => ['How is my video progress counted?'], 'contact' => ['Talk to a person']];

        return $map[$id] ?? ['What courses can I study?', 'Talk to a person'];
    }

    private function human(Request $request): array
    {
        $user = $request->user('sanctum');
        $links = $user ? [['Open a support ticket', '/student/support'], ['Contact form', '/contact']] : [['Contact the academy', '/contact'], ['Frequently asked questions', '/faq']];

        return $this->reply('human', 'I am an automated assistant, so for anything about your account, a payment or a decision you disagree with, please contact the academy team. '.($user ? 'Because you are signed in, a support ticket lets you follow the replies in your account.' : 'Use the contact form and a member of the team will reply by email.'), [], $links);
    }

    private function myCourses(Request $request): array
    {
        $user = $request->user('sanctum');
        if (! $user instanceof User) {
            return $this->reply('my_courses_guest', 'Please sign in first and I can list your courses.', [], [['Sign in', '/login'], ['Create an account', '/register']]);
        }
        $rows = CourseEnrollment::query()->where('user_id', $user->id)->whereIn('status', ['active', 'completed'])->with('course:id,title')->latest('enrolled_at')->limit(5)->get();
        if ($rows->isEmpty()) {
            return $this->reply('my_courses_none', 'You are not enrolled in any course yet. Browse the catalogue to find one.', [], [['Browse courses', '/courses']], ['What courses can I study?']);
        }
        $cards = $rows->filter(fn ($r) => $r->course)->map(fn ($r) => ['title' => $r->course->title, 'subtitle' => $r->status === 'completed' ? 'Completed' : 'In progress', 'url' => '/student/courses/'.$r->course->id])->values()->all();

        return $this->reply('my_courses', 'Here are your most recent courses.', $cards, [['All my courses', '/student/courses']]);
    }

    private function certificate(string $code, Request $request): array
    {
        $res = app(CertificateVerifyController::class)->show($request, $code);   // same minimal disclosure and verification log as the public page
        $d = $res->getData(true)['data'] ?? [];
        $status = $d['status'] ?? 'not_found';
        if ($status === 'valid') {
            return $this->reply('cert_valid', "This certificate is valid. It was issued to {$d['holder_name']} for \"{$d['course_title']}\" on {$d['issued_on']} (number {$d['certificate_number']}).", [], [['Open the verification page', '/verify/'.strtolower($code)]]);
        }
        if ($status === 'revoked') {
            return $this->reply('cert_revoked', 'That certificate exists but is no longer valid because it has been revoked. Contact the academy if you need details.', [], [['Contact the academy', '/contact']]);
        }

        return $this->reply('cert_missing', 'I could not find a certificate with that code. Check that you copied all 32 characters exactly, or use the verification page.', [], [['Verify a certificate', '/verify']]);
    }

    private function catalogue(): array
    {
        $cats = \App\Models\Category::query()->withCount(['courses' => fn ($q) => $q->where('status', 'published')])->orderBy('sort_order')->orderBy('name')->get()->filter(fn ($c) => $c->courses_count > 0);
        if ($cats->isEmpty()) {
            return $this->reply('catalogue_empty', 'No courses are published yet. New courses will appear in the catalogue as soon as the academy releases them.', [], [['Course catalogue', '/courses']]);
        }
        $cards = $cats->take(6)->map(fn ($c) => ['title' => $c->name, 'subtitle' => $c->courses_count.' course'.($c->courses_count === 1 ? '' : 's'), 'url' => '/courses?category='.rawurlencode($c->slug)])->values()->all();

        return $this->reply('catalogue', 'These are the subjects with published courses right now.', $cards, [['All courses', '/courses']], ['Show free courses']);
    }

    /** Read-only catalogue search. Tokens are letters/digits only and every value is a bound parameter. */
    private function searchCourses(array $tokens, bool $freeOnly, bool $emptyReply = true): ?array
    {
        $q = Course::query()->where('status', 'published')->with(['category', 'instructors.profile']);
        if ($freeOnly) {
            $q->where('price_minor', 0);
        }
        foreach ($tokens as $t) {
            $like = '%'.addcslashes($t, '%_\\').'%';
            $q->where(fn ($w) => $w->where('title', 'ilike', $like)->orWhere('summary', 'ilike', $like)->orWhereHas('category', fn ($c) => $c->where('name', 'ilike', $like)));
        }
        $courses = $q->orderByDesc('published_at')->limit(5)->get();
        if ($courses->isEmpty()) {
            if (! $emptyReply) {
                return null;
            }

            return $freeOnly ? $this->reply('search_free_none', 'There are no free courses at the moment.', [], [['All courses', '/courses']])
                : $this->reply('search_none', 'I could not find a published course matching "'.implode(' ', $tokens).'". Try a different word, or browse the whole catalogue.', [], [['Browse all courses', '/courses?q='.rawurlencode(implode(' ', $tokens))]], ['What courses can I study?']);
        }
        $cards = $courses->map(function ($c) {
            $p = CourseSerializer::price($c);
            $price = ! empty($p['is_free']) ? 'Free' : '₦'.number_format(($p['amount_minor'] ?? 0) / 100, 2);

            return ['title' => $c->title, 'subtitle' => ucfirst((string) $c->level).' · '.$price, 'url' => '/courses/'.$c->slug];
        })->all();

        return $this->reply('search', $courses->count() === 1 ? 'I found one course that matches.' : 'I found '.$courses->count().' courses that match.', $cards, [['See all results', '/courses'.($tokens ? '?q='.rawurlencode(implode(' ', $tokens)) : '')]]);
    }

    private function bestTopic(string $norm): ?array
    {
        $best = null;
        $bestScore = 0;
        foreach ($this->topics as $t) {
            $score = 0;
            foreach ($t['keywords'] as $kw) {
                if ($this->has($norm, [$this->normalise($kw)])) {
                    $score += 1 + substr_count($kw, ' ') * 2;
                }
            }
            if ($score > $bestScore) {
                $best = $t;
                $bestScore = $score;
            }
        }

        return $bestScore >= 1 ? $best : null;
    }

    /** Lower-case, de-accented, letters/digits/space only. The only form of user text that ever reaches matching or queries. */
    private function normalise(string $s): string
    {
        $s = mb_strtolower($s);
        $s = str_replace(["’", "'"], '', $s);
        if (function_exists('transliterator_transliterate')) {
            $s = transliterator_transliterate('NFD; [:Nonspacing Mark:] Remove; NFC', $s) ?: $s;
        }
        $s = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $s) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    }

    /** @param string[] $phrases */
    private function has(string $norm, array $phrases): bool
    {
        foreach ($phrases as $p) {
            $p = $this->normalise($p);
            if ($p !== '' && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($p, '/').'(?![\p{L}\p{N}])/u', $norm)) {
                return true;
            }
        }

        return false;
    }

    private function wordCount(string $norm): int
    {
        return count(array_filter(explode(' ', $norm)));
    }

    /** @return string[] up to 3 meaningful words, each 2-30 letters/digits */
    private function searchTokens(string $norm): array
    {
        $out = [];
        foreach (explode(' ', $norm) as $w) {
            $w = trim($w, '-');
            if (mb_strlen($w) >= 2 && mb_strlen($w) <= 30 && ! in_array($w, self::STOP, true) && preg_match('/^[\p{L}\p{N}-]+$/u', $w)) {
                $out[] = $w;
            }
        }

        return array_slice(array_values(array_unique($out)), 0, 3);
    }

    private function safePath(string $p): bool
    {
        return (bool) preg_match('#^/(?!/)[A-Za-z0-9/_\-?=&%.]*$#', $p);
    }

    private function reply(string $intent, string $text, array $cards = [], array $links = [], array $suggestions = [], ?string $hint = null): array
    {
        return [
            'intent' => $intent,
            'reply' => $hint ? $text.' '.$hint : $text,
            'cards' => array_values(array_filter($cards, fn ($c) => $this->safePath($c['url']))),
            'links' => array_values(array_filter(array_map(fn ($l) => ['label' => $l[0], 'url' => $l[1]], $links), fn ($l) => $this->safePath($l['url']))),
            'suggestions' => array_slice($suggestions, 0, 5),
        ];
    }
}
