export type ReportTargetType = 'resource' | 'upload' | 'holster' | 'group' | 'run' | 'profile' | 'application' | 'membership_application' | 'member_note'
export type ReportTarget = { type: ReportTargetType; id: number; label?: string }
export type ReportModalOptions = { guestSubmitUrl?: string }
export type ReportNoticeAudience = 'reporter' | 'owner'
export type ReportFeedback = { id: number; audience: ReportNoticeAudience; template: string; message: string | null; item_title: string; created_at: string }
export type ReportStatus = 'new' | 'in_review' | 'awaiting_feedback' | 'resolved'
export type ModerationAction = 'claim' | 'hide' | 'restore' | 'ban' | 'unban' | 'dismiss' | 'reopen'
export type CaseSummary = {
    id: number; title: string; target_type: ReportTargetType; target_id: number; status: ReportStatus;
    version: number; assigned_to: number | null; assignee: { id: number; name: string } | null;
    reports_count: number; created_at: string;
}
export type CaseDetail = {
    case: CaseSummary & {
        reports: { id: number; reason: string; details: string | null; snapshot: Record<string, unknown>; reporter: ReportProfile | null; guest_reference: string | null; preview: ReportContentPreview; created_at: string; evidence_url: string | null }[];
        actions: { id: number; action: ModerationAction; reason: string | null; admin: string | null; created_at: string }[];
        feedback: (ReportFeedback & { recipient_count: number; acknowledged_count: number })[];
    };
    target_exists: boolean; can_hide: boolean; is_hidden: boolean;
    current_content: { title?: string; text?: string; content?: Record<string, unknown> } | null;
    subject_user: ReportProfile | null;
    context: ReportReviewContext;
}
export type ReportCasePage = { data: CaseSummary[]; meta: { current_page: number; last_page: number; total: number; per_page: number } }

export type ReportProfile = {
    id: number; name: string; avatar_url: string | null; is_admin: boolean; banned_at: string | null;
    public_profile: boolean; created_at: string | null; description: string | null;
    characters: { id: number; name: string; world: string; datacenter: string; avatar_url: string | null; is_primary: boolean }[];
}
export type ReportContentPreview = {
    title: string; description: string | null; html: string | null; text: string | null;
    fields: { label: string; value: string }[]; tags: string[];
}
export type ReportGroupContext = {
    id: number; name: string; slug: string; description: string | null; profile_picture_url: string | null;
    banner_image_url: string | null; datacenter: string | null; is_visible: boolean; url: string;
    owner: ReportProfile | null; member_count: number;
}
export type ReportReviewContext = {
    preview: ReportContentPreview | null; url: string | null; created_at: string | null; updated_at: string | null;
    profile: ReportProfile | null; owner: ReportProfile | null; member: ReportProfile | null;
    group: ReportGroupContext | null; collection: string | null; cover_url: string | null;
    run: { id: number; title: string; description: string | null; starts_at: string | null; url: string | null } | null;
    images: { id: number; uuid: string; original_name: string; alt_text: string | null; caption: string | null; width: number; height: number; url: string }[];
    items: { name: Record<string, string>; icon_url: string | null; quantity: number }[];
}
