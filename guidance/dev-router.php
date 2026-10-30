<?php

// Development only. Do not expose the PHP development server to the internet.
$p = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
if (str_contains($p, '..') || preg_match('~/(?:\.[^/]*|config|database|includes|uploads|node_modules)(?:/|$)|\.(?:sql|md|log|bak|ini|zip|txt|json|lcctgd)$~i', $p) || preg_match('~^/vendor/|^/guidance/(?:tests|docs|migrations|runtime)/|^/guidance/(?:migrate|domain|backup|jobs|setup-local|dev-router|restoration|restoration-view|restoration-context|restoration-migrate|restoration-ops)\.php$~', $p)) {
    http_response_code(404);
    echo 'Not found';
    return true;
}return false;
