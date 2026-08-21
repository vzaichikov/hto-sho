<?php

namespace App\Models;

use Database\Factories\SilpoConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'client_id',
    'client_secret',
    'access_token',
    'refresh_token',
    'token_type',
    'scope',
    'expires_at',
    'profile_snapshot',
    'profile_synced_at',
    'last_verified_at',
    'revoked_at',
])]
#[Hidden(['client_id', 'client_secret', 'access_token', 'refresh_token', 'profile_snapshot'])]
class SilpoConnection extends Model
{
    /** @use HasFactory<SilpoConnectionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'client_id' => 'encrypted',
            'client_secret' => 'encrypted',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'profile_snapshot' => 'encrypted:array',
            'expires_at' => 'datetime',
            'profile_synced_at' => 'datetime',
            'last_verified_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
