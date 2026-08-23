<?php

namespace App;

enum CartRunPhase: string
{
    case Preparing = 'preparing';
    case Searching = 'searching';
    case Inspecting = 'inspecting';
    case Deciding = 'deciding';
    case Auditing = 'auditing';
    case ReadyToCommit = 'ready_to_commit';
    case Committing = 'committing';
    case Finished = 'finished';
}
