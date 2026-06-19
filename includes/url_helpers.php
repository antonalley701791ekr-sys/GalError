<?php
/**
 * URL 辅助函数
 * 集中管理所有前台 URL 生成，方便 URL 美化规则的统一修改
 */

/**
 * 生成游戏详情页 URL
 */
function urlGame($id) {
    return '/game/' . (int)$id;
}

/**
 * 生成文章详情页 URL
 */
function urlArticle($id) {
    return '/article/' . (int)$id;
}

/**
 * 生成报错详情页 URL
 */
function urlError($id) {
    return '/error/' . (int)$id;
}

/**
 * 生成话题详情页 URL
 */
function urlDiscussion($id) {
    return '/discussion/' . (int)$id;
}

/**
 * 生成文档详情页 URL
 */
function urlDocument($id) {
    return '/document/' . (int)$id;
}

/**
 * 生成用户主页 URL
 */
function urlUser($userId, $tab = '', $extra = []) {
    $url = '/user/' . (int)$userId;
    $params = [];
    if ($tab) $params['tab'] = $tab;
    foreach ($extra as $k => $v) {
        $params[$k] = $v;
    }
    if ($params) $url .= '?' . http_build_query($params);
    return $url;
}

/**
 * 生成私信聊天 URL
 */
function urlChat($userId) {
    return '/chat/' . (int)$userId;
}

/**
 * 生成列表页 URL（去掉 .php 后缀）
 */
function urlPage($page, $params = []) {
    $url = '/' . $page;
    if ($params) $url .= '?' . http_build_query($params);
    return $url;
}

/**
 * 生成搜索 URL
 */
function urlSearch($params = []) {
    $url = '/search';
    if ($params) $url .= '?' . http_build_query($params);
    return $url;
}

/**
 * 生成提交报错 URL（可带 game_id）
 */
function urlSubmit($gameId = null) {
    $url = '/submit';
    if ($gameId) $url .= '?game_id=' . (int)$gameId;
    return $url;
}

/**
 * 生成提交/编辑文章 URL
 */
function urlSubmitArticle($editId = null) {
    $url = '/submit_article';
    if ($editId) $url .= '?edit=' . (int)$editId;
    return $url;
}

/**
 * 生成提交/编辑话题 URL
 */
function urlSubmitDiscussion($editId = null) {
    $url = '/submit_discussion';
    if ($editId) $url .= '?edit=' . (int)$editId;
    return $url;
}

/**
 * 生成提交游戏 URL
 */
function urlSubmitGame() {
    return '/submit_game';
}
