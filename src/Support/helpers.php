<?php

declare(strict_types=1);

if (!function_exists('scope')) {
    function scope(string $id): string
    {
        return 'fw:id="' . htmlspecialchars($id) . '"';
    }
}

if (!function_exists('fw_id')) {
    function fw_id(string $id): string
    {
        trigger_error(
            'fw_id() is deprecated, use scope() instead',
            E_USER_DEPRECATED,
        );
        return scope($id);
    }
}
