<?php

namespace App;

enum SilpoCartResetStatus: string
{
    case Pending = 'pending';
    case Cleared = 'cleared';
    case Consumed = 'consumed';
    case Failed = 'failed';
    case Superseded = 'superseded';
}
