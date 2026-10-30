<?php
// Academic metadata is part of the already-authorized student profile, not a second profile card.
if (!defined('GUIDANCE_LAYOUT_SECTIONS')) { http_response_code(404); exit; }
function gu_academic(array $u,int $id): string {
    $m=gone('SELECT m.*,u.full_name verifier FROM gd_plus_students m JOIN users u ON u.id=m.verified_by WHERE m.user_id=?',[$id]);
    $body=$m?'<dl><dt>Program</dt><dd>'.e($m['program']).'</dd><dt>Year / section</dt><dd>'.e($m['year_level']).' / '.e($m['section']).'</dd><dt>Source</dt><dd>'.e($m['source']).'</dd><dt>Verified by</dt><dd>'.e($m['verifier']).' · '.e(fmt_dt($m['updated_at'])).'</dd></dl>':'<p>No verified academic metadata is recorded. Program, year and section are never inferred.</p>';
    if(g_head($u))$body.=gdetails('Record verified academic metadata',gform('x.student',gh('id',$id).gf('program','Program','text',$m['program']??'','required maxlength="120"').gf('year_level','Year level','text',$m['year_level']??'','required maxlength="40"').gf('section','Section','text',$m['section']??'','required maxlength="80"').gf('source','Authoritative source / record reference','text',$m['source']??'','required minlength="5" maxlength="200"').'<p class="gd-note">Guidance-scoped metadata, not a duplicate campus identity. Verify against the current school record before saving.</p>','Save verified metadata'));
    return '<div class="gu-subsection"><h4>Academic record</h4>'.$body.'</div>';
}
