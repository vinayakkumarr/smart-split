<?php

$urls = [
    'http://127.0.0.1:8000/',
    'http://127.0.0.1:8000/assets/css/style.css',
    'http://127.0.0.1:8000/assets/css/variables.css',
    'http://127.0.0.1:8000/assets/css/layout.css',
    'http://127.0.0.1:8000/assets/css/components.css',
    'http://127.0.0.1:8000/assets/js/app.js',
    'http://127.0.0.1:8000/assets/js/router.js',
    'http://127.0.0.1:8000/assets/js/state.js',
    'http://127.0.0.1:8000/assets/js/api.js',
    'http://127.0.0.1:8000/assets/js/utils/math.js',
    'http://127.0.0.1:8000/assets/js/utils/formatters.js',
    'http://127.0.0.1:8000/assets/js/components/LandingView.js',
    'http://127.0.0.1:8000/assets/js/components/GroupHeader.js',
    'http://127.0.0.1:8000/assets/js/components/MemberList.js',
    'http://127.0.0.1:8000/assets/js/components/ExpenseModal.js',
    'http://127.0.0.1:8000/assets/js/components/BalanceSummary.js',
    'http://127.0.0.1:8000/assets/js/components/SettlementPlan.js',
    'http://127.0.0.1:8000/assets/js/components/ExpenseList.js',
    'http://127.0.0.1:8000/assets/js/components/Toast.js',
    'http://127.0.0.1:8000/assets/js/components/Modal.js',
    'http://127.0.0.1:8000/api/health',
];

foreach ($urls as $url) {
    $headers = get_headers($url, true);
    $status = $headers[0] ?? 'Unknown';
    $type = $headers['Content-Type'] ?? $headers['content-type'] ?? 'Unknown';
    if (is_array($type)) $type = implode(', ', $type);
    echo "$url -> Status: $status | Type: $type\n";
}
