<?php
function errorsBuildQuery(array $input): array {
    $status = $input['status'] ?? '';
    $systemCategory = $input['system_category'] ?? '';
    $page = max(1, (int)($input['page'] ?? 1));
    $perPage = (int)($input['perPage'] ?? 20);

    $systemCategoryOptions = [
        'windows' => 'Windows',
        'android_emulator' => '安卓模拟器',
        'console_handheld' => '主机掌机',
        'mobile_native' => '手机原生',
        'win_handheld' => 'Win掌机',
        'cloud_streaming' => '云/串流',
        'other' => '其他',
    ];

    $statusText = [
        'pending' => '待审核',
        'approved' => '已通过',
        'rejected' => '已拒绝',
    ];

    $where = [];
    $params = [];
    if ($status) { $where[] = 'e.status = ?'; $params[] = $status; }
    if ($systemCategory && array_key_exists($systemCategory, $systemCategoryOptions)) { $where[] = 'e.system_category = ?'; $params[] = $systemCategory; }
    elseif ($systemCategory === 'unlabeled') { $where[] = "(e.system_category IS NULL OR e.system_category = '')"; }

    return compact('status', 'systemCategory', 'page', 'perPage', 'systemCategoryOptions', 'statusText', 'where', 'params');
}
