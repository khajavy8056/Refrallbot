<?php
declare(strict_types=1);

if (!function_exists('current_admin_id')) {
    function current_admin_id(): ?int
    {
        \RB\Support\Session::start();
        $id = $_SESSION['admin_id'] ?? null;
        return $id ? (int) $id : null;
    }
}
