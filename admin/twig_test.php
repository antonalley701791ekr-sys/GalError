<?php
require_once 'includes/config.php';
require_once 'includes/user_auth.php';
require_once 'includes/view.php';

view('front/test.twig', [
    'title' => 'Twig 接入成功',
    'message' => 'Hello from Twig'
]);