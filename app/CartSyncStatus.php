<?php

namespace App;

enum CartSyncStatus: string
{
    case NotSynced = 'not_synced';
    case Syncing = 'syncing';
    case Synced = 'synced';
    case Stale = 'stale';
    case Failed = 'failed';
}
