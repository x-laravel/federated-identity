<?php

namespace XLaravel\FederatedIdentity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use XLaravel\FederatedIdentity\Support\FederatedIdentityConfig;

/**
 * @property int $id
 * @property int|string $user_id
 * @property string $provider
 * @property string $provider_id
 * @property string|null $email
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Identity extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return FederatedIdentityConfig::table();
    }

    public function getConnectionName(): ?string
    {
        return FederatedIdentityConfig::connection() ?? parent::getConnectionName();
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(FederatedIdentityConfig::userModel(), 'user_id');
    }
}
