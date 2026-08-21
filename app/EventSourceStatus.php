<?php

namespace App;

enum EventSourceStatus: string
{
    case Pending = 'pending';
    case Processed = 'processed';
    case Failed = 'failed';
}
