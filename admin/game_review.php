<?php
// 游戏审核已合并到游戏管理页面
$qs = $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: games.php' . $qs, true, 301);
exit;
