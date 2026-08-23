<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['silpo_identity_hash', 'name', 'email', 'password'])]
#[Hidden(['silpo_identity_hash', 'password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function silpoConnection(): HasOne
    {
        return $this->hasOne(SilpoConnection::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function imageExtractions(): HasMany
    {
        return $this->hasMany(ImageExtraction::class);
    }

    public function cartRuns(): HasManyThrough
    {
        return $this->hasManyThrough(EventCartRun::class, Event::class);
    }
}
