<?php

namespace App;

enum CartSyncStatus: string
{
    case NotSynced = 'not_synced';
    case Syncing = 'syncing';
    case Synced = 'synced';
    case Partial = 'partial';
    case Stale = 'stale';
    case Failed = 'failed';
}
