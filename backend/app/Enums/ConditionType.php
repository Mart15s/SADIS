<?php

namespace App\Enums;

enum ConditionType: string
{
    case Diseased = 'diseased';
    case Dried = 'dried';
    case Flowering = 'flowering';
    case Germinating = 'germinating';
    case Growing = 'growing';
    case Mature = 'mature';
    case Planted = 'planted';
    case Regenerating = 'regenerating';
}
