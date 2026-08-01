<?php

namespace App\Models;

class OnboardingProgress extends YavaModel
{
    protected $table = 'onboarding_progress';
    protected function casts(): array { return ['completed_steps' => 'array', 'draft' => 'array', 'completed_at' => 'datetime']; }
}
