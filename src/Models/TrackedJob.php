<?php

namespace HardImpact\Orbit\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TrackedJob extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'status',
        'output',
        'started_at',
        'finished_at',
    ];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
