<?php

namespace App\Enums;

enum AccessRole: string
{
    case Viewer = 'viewer';
    case Editor = 'editor';
}
