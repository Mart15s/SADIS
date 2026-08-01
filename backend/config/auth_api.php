<?php

return [
    'emit_legacy_token' => filter_var(env('AUTH_EMIT_LEGACY_TOKEN', false), FILTER_VALIDATE_BOOL),
];
