<?php
// Presentation only: keep the original campus sidebar; existing endpoints enforce access.
if (!isset($u, $view) || !function_exists('gcard') || !is_filer($u)) {
    http_response_code(404);
    exit;
}
$studentPages = [
    ['overview', 'Guidance services'],
    ['requests', 'My Guidance requests'],
    ['appointments', 'My appointments'],
    ['events', 'My activities'],
    ['history', 'My certificates & credits'],
    ['programs', 'Programs'],
    ['calendar', 'Calendar'],
    ['knowledge', 'Resources'],
];
if (gr_ready()) {
    $studentPages[] = ['notifications', 'Notifications'];
    $studentPages[] = ['help', 'Help & services'];
}
$studentPages[] = ['settings', 'Guidance preferences'];
$currentStudentPage = ['event'=>'events', 'intake'=>'requests', 'review'=>'requests'][$view] ?? $view;
$serviceLinks = '<nav class="gd-buttons" aria-label="Student Guidance pages">';
foreach ($studentPages as [$page, $label]) {
    if ($page === 'overview' && $view === 'overview') continue;
    $selected = $currentStudentPage === $page;
    $serviceLinks .= '<a class="btn'.($selected ? ' btn-primary' : '').'" href="'.e(url('guidance/index.php'.($page === 'overview' ? '' : '?view='.$page))).'"'.($selected ? ' aria-current="page"' : '').'>'.e($label).'</a>';
}
$serviceLinks .= '</nav>';
if ($view === 'overview') {
    $requestUrl = gr_ready() ? 'guidance/index.php?view=intake' : 'student/new_ticket.php?office=guide';
    $requestLabel = gr_ready() ? 'New Guidance request' : 'New ticket · Guidance preselected';
    $intro = '<p>Access Guidance support, appointments, activities and your certificates.</p>';
    $intro .= '<p>For another office, use <strong>New Ticket</strong> in the sidebar. <strong>My Tickets</strong> keeps your requests to all offices together.</p>';
    $intro .= '<p><a class="btn btn-primary" href="'.e(url($requestUrl)).'">'.e($requestLabel).'</a></p>';
    if (!gr_ready()) $intro .= '<p class="hint">The original campus ticket form opens with Guidance preselected. That shared form still allows you to choose another office.</p>';
    echo gcard('Choose a service', $intro.$serviceLinks);
} else {
    echo '<details class="gd-card" data-student-guidance-pages><summary>Guidance services · choose a page</summary><div class="gu-card-body">'.$serviceLinks.'</div></details>';
}
