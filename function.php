<?php

add_action('wp_footer', function () {
    echo "<script>window.wpLoggedIn=" . (is_user_logged_in() ? "true" : "false") . ";</script>";
    if (is_user_logged_in()) {
        $u = wp_get_current_user();
        echo "<script>window.wpUserId=" . (int)$u->ID . ";</script>";
    }
});