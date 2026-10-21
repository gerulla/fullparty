<?php

namespace App\Support\Characters;

enum CharacterRefreshStage: string
{
    case Profile = 'profile';
    case Classes = 'classes';
    case PhantomJobs = 'phantom_jobs';
    case FFLogs = 'fflogs';
    case Achievements = 'achievements';
    case Saving = 'saving';
}
