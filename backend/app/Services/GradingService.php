<?php

namespace App\Services;

use App\Events\GradePublished;
use App\Exceptions\DomainException;
use App\Models\AssignmentSubmission;
use App\Models\GradeChangeLog;
use App\Models\SubmissionGrade;
use App\Models\User;
use App\Notifications\GradePublishedNotice;
use Illuminate\Support\Facades\DB;

class GradingService
{
    public function __construct(private AuditLogger $audit, private HtmlSanitizer $html) {}

    /**
     * Create or amend a grade.
     * - Unpublished drafts can be edited freely.
     * - A published grade is never silently overwritten: an amendment needs a reason and writes an immutable change-log row.
     *
     * @param  array{score?:float|int|string|null, criterion_scores?:array<string,float|int|string>|null, feedback?:?string, publish?:bool, reason?:?string}  $in
     */
    public function grade(AssignmentSubmission $sub, User $grader, array $in): SubmissionGrade
    {
        $a = $sub->assignment()->with('rubric.criteria', 'course')->first();
        [$raw, $criteria] = $this->resolveScore($a, $in);
        $penalty = $sub->is_late && $a->late_policy === 'penalty' ? $a->late_penalty_percent : 0.0;
        $final = round($raw * (100 - $penalty) / 100, 2);
        $feedback = isset($in['feedback']) ? $this->html->clean($in['feedback']) : null;
        $publish = (bool) ($in['publish'] ?? false);

        $amended = false;
        $grade = DB::transaction(function () use ($sub, $grader, $in, $raw, $penalty, $final, $feedback, $publish, $criteria, &$amended) {
            $locked = AssignmentSubmission::whereKey($sub->id)->lockForUpdate()->firstOrFail();
            $g = SubmissionGrade::where('submission_id', $locked->id)->lockForUpdate()->first();

            if (! $g) {
                $g = SubmissionGrade::create(['submission_id' => $locked->id, 'graded_by' => $grader->id, 'raw_score' => $raw, 'penalty_percent' => $penalty, 'final_score' => $final,
                    'feedback' => $feedback, 'criterion_scores' => $criteria, 'status' => $publish ? 'published' : 'draft', 'published_at' => $publish ? now() : null]);
                $this->audit->log('grade.created', $g, new: ['final_score' => $final, 'published' => $publish], actorId: $grader->id);
            } elseif ($g->status === 'draft') {
                $g->fill(['graded_by' => $grader->id, 'raw_score' => $raw, 'penalty_percent' => $penalty, 'final_score' => $final, 'feedback' => $feedback ?? $g->feedback,
                    'criterion_scores' => $criteria, 'revision' => $g->revision + 1]);
                if ($publish) {
                    $g->fill(['status' => 'published', 'published_at' => now()]);
                }
                $g->save();
            } else {
                $reason = trim((string) ($in['reason'] ?? ''));
                if (mb_strlen($reason) < 5) {
                    throw new DomainException('A reason is required to change a published grade.', 'amendment_reason_required', 422);
                }
                if ($g->final_score == $final && ($feedback === null || $feedback === $g->feedback)) {
                    return $g;                                       // nothing changed; do not create a meaningless amendment
                }
                GradeChangeLog::create(['submission_grade_id' => $g->id, 'previous_score' => $g->final_score, 'new_score' => $final, 'previous_feedback' => $g->feedback,
                    'new_feedback' => $feedback ?? $g->feedback, 'reason' => $reason, 'changed_by' => $grader->id]);
                $this->audit->log('grade.amended', $g, ['final_score' => $g->final_score], ['final_score' => $final], $reason, $grader->id);
                $g->fill(['graded_by' => $grader->id, 'raw_score' => $raw, 'penalty_percent' => $penalty, 'final_score' => $final, 'feedback' => $feedback ?? $g->feedback,
                    'criterion_scores' => $criteria, 'revision' => $g->revision + 1])->save();
                $amended = true;
            }
            if ($g->status === 'published') {
                $locked->update(['status' => 'graded']);
            }

            return $g;
        });

        if ($grade->status === 'published') {
            $user = $sub->user()->first();
            DB::afterCommit(function () use ($user, $a, $sub, $amended) {
                $user->notify(new GradePublishedNotice($a->title, $amended));
                event(new GradePublished($user->id, $a->course_id, 'assignment', $sub->id));
            });
        }

        return $grade;
    }

    /** @return array{0:float,1:?array} raw score and per-criterion points */
    private function resolveScore($a, array $in): array
    {
        if ($a->rubric && $a->rubric->criteria->isNotEmpty()) {
            $given = $in['criterion_scores'] ?? null;
            if (! is_array($given)) {
                throw new DomainException('Score every rubric criterion.', 'criteria_required');
            }
            $sum = 0.0;
            $out = [];
            foreach ($a->rubric->criteria as $c) {
                if (! array_key_exists($c->id, $given) || ! is_numeric($given[$c->id])) {
                    throw new DomainException("Score missing for criterion \"{$c->title}\".", 'criteria_required');
                }
                $pts = round((float) $given[$c->id], 2);
                if ($pts < 0 || $pts > $c->max_points) {
                    throw new DomainException("Marks for \"{$c->title}\" must be between 0 and {$c->max_points}.", 'score_out_of_range');
                }
                $out[$c->id] = $pts;
                $sum += $pts;
            }
            if (array_diff_key($given, $out)) {
                throw new DomainException('Unknown rubric criterion supplied.', 'criteria_unknown');
            }

            return [round($sum, 2), $out];
        }
        $score = $in['score'] ?? null;
        if ($score === null || ! is_numeric($score)) {
            throw new DomainException('A score is required.', 'score_required');
        }
        $score = round((float) $score, 2);
        if ($score < 0 || $score > $a->max_score) {
            throw new DomainException("The score must be between 0 and {$a->max_score}.", 'score_out_of_range');
        }

        return [$score, null];
    }
}
