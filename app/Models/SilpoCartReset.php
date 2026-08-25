<?php

namespace App\Models;

use App\SilpoCartResetStatus;
use Database\Factories\SilpoCartResetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'event_id',
    'plan_state_version',
    'request_key',
    'status',
    'cart_id',
    'before_cart_fingerprint',
    'before_product_fingerprint',
    'empty_product_fingerprint',
    'items_count',
    'total',
    'snapshot',
    'error',
    'cleared_at',
    'consumed_at',
])]
#[Hidden(['snapshot'])]
class SilpoCartReset extends Model
{
    /** @use HasFactory<SilpoCartResetFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'plan_state_version' => 'integer',
            'status' => SilpoCartResetStatus::class,
            'items_count' => 'integer',
            'total' => 'decimal:2',
            'snapshot' => 'encrypted:array',
            'cleared_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
