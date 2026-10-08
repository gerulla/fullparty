<?php

namespace App\Services\Moderation;

use App\Models\ContentReport;
use App\Models\Group;
use App\Models\GroupResourceImage;
use App\Models\ModerationCase;
use App\Models\User;
use App\Services\Notifications\AdminReportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReportSubmissionService
{
    public function __construct(
        private readonly ReportTargetRegistry $targets,
        private readonly PublicReportAccess $publicAccess,
        private readonly AdminReportService $adminReports,
    ) {}

    public function submit(User $user, array $data): void
    {
        $this->persist($user, $data);
    }

    public function submitGuest(Group $group, string $fingerprint, array $data): void
    {
        abort_unless(in_array($data['target_type'], PublicReportAccess::TYPES, true), 404);
        $this->persist(null, $data, $group, $fingerprint);
    }

    private function persist(?User $user, array $data, ?Group $publicGroup = null, ?string $guestFingerprint = null): void
    {
        $evidence = null;
        $written = [];
        try {
            DB::transaction(function () use ($user, $data, $publicGroup, $guestFingerprint, &$evidence, &$written) {
                $evidence = null;
                $adapter = $this->targets->adapter($data['target_type']);
                // Serialize case creation on the target, including across different reporters.
                $target = $adapter->modelClass()::whereKey($data['target_id'])->lockForUpdate()->firstOrFail();
                abort_unless($publicGroup
                    ? $this->publicAccess->canReport($target, $publicGroup)
                    : ($user && $adapter->canReport($target, $user)), 404);
                $key = $data['target_type'].':'.$target->id;
                $case = ModerationCase::where('open_key', $key)->lockForUpdate()->first();
                if ($case && $case->reports()->when($user,
                    fn ($query) => $query->where('reporter_id', $user->id),
                    fn ($query) => $query->where('guest_fingerprint', $guestFingerprint))->exists()) {
                    return;
                }
                $snapshot = $adapter->snapshot($target);
                $snapshot['captured_at'] = now()->toIso8601String();
                $snapshot['author_user_id'] = $adapter->subjectUserId($target);
                if (! $case) {
                    $case = ModerationCase::create([
                        'target_type' => $data['target_type'], 'target_id' => $target->id,
                        'open_key' => $key, 'title' => Str::limit((string) ($snapshot['title'] ?? $key), 255, ''),
                        'subject_user_id' => $adapter->subjectUserId($target), 'status' => 'new',
                    ]);
                } else {
                    // New evidence must invalidate an admin's stale decision screen.
                    $case->increment('version');
                    if ($case->status === 'awaiting_feedback') {
                        $case->update(['status' => 'in_review']);
                    }
                }
                if ($target instanceof GroupResourceImage) {
                    $source = Storage::disk(config('group_resources.disk'))->readStream($target->path);
                    abort_unless(is_resource($source), 503);
                    $evidence = 'moderation-evidence/'.Str::uuid();
                    $written[] = $evidence;
                    try {
                        abort_unless(Storage::disk('local')->put($evidence, $source), 503);
                    } finally {
                        fclose($source);
                    }
                }
                $report = ContentReport::create([
                    'moderation_case_id' => $case->id, 'reporter_id' => $user?->id, 'guest_fingerprint' => $guestFingerprint,
                    'reason' => $data['reason'], 'details' => $data['reason'] === 'other' ? trim($data['details']) : null,
                    'snapshot' => $snapshot, 'evidence_path' => $evidence,
                ]);
                // Notify only for a new, committed report; retries and rolled-back evidence stay quiet.
                DB::afterCommit(fn () => $this->notifyAdmins($report, $case));
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($written);
            throw $exception;
        }
        // A transaction retry may have written a file for an abandoned attempt.
        Storage::disk('local')->delete(array_values(array_diff($written, [$evidence])));
    }

    private function notifyAdmins(ContentReport $report, ModerationCase $case): void
    {
        $locale = (string) config('app.locale', 'en');
        $this->adminReports->report(
            key: 'moderation.report.'.$report->id,
            titleKey: 'admin_reports.content_report_title',
            messageKey: 'admin_reports.content_report_message',
            params: [
                'report_id' => $report->id,
                'case_id' => $case->id,
                'target_type' => __('reports.types.'.$case->target_type, [], $locale),
                'target_id' => $case->target_id,
                'reason' => __('reports.reasons.'.$report->reason, [], $locale),
                'reporter' => $report->reporter_id
                    ? __('admin_reports.account_reference', ['id' => $report->reporter_id], $locale)
                    : __('admin_reports.guest_reporter', [], $locale),
                // Guest reports originate on the resource host; review links belong to the main site.
                'url' => rtrim(config('app.url'), '/').route('admin.reports.show', ['case' => $case->id, 'locale' => $locale], false),
            ],
            severity: 'warning',
        );
    }
}
