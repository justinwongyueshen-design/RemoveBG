<?php
declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['rbg_csrf'])) {
        $_SESSION['rbg_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['rbg_csrf'];
}

function csrf_verify(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    if (!is_string($sent) || $sent === '' || !hash_equals(csrf_token(), $sent)) {
        throw new AppException('csrf_invalid', 'Your session has expired. Please reload the page and try again.', 403);
    }
}
