<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    use HasFactory;

    public const INSTALL_INSTALLING = 'installing';

    public const INSTALL_ACTIVE = 'active';

    public const INSTALL_UNINSTALLED = 'uninstalled';

    public const INSTALL_SUSPENDED = 'suspended';

    public const MODE_DEDICATED_SPAS = 'dedicated_spas';

    public const MODE_CONSOLIDATED_SPAS = 'consolidated_spas';

    public const MODE_SUBTOTALS_ONLY = 'subtotals_only';

    protected $fillable = [
        'member_id',
        'domain',
        'portal_name',
        'client_endpoint',
        'server_endpoint',
        'access_token_enc',
        'refresh_token_enc',
        'token_expires_at',
        'application_token_enc',
        'app_status',
        'install_status',
        'license_family',
        'license_type',
        'language_id',
        'installer_b24_user_id',
        'installer_name',
        'fee_sync_mode',
        'schema_status',
        'installed_at',
        'uninstalled_at',
        'last_api_call_at',
        'last_sync_at',
        'settings_json',
        'schema_snapshot_json',
        'schema_snapshot_at',
    ];

    protected $hidden = [
        'access_token_enc',
        'refresh_token_enc',
        'application_token_enc',
    ];

    protected function casts(): array
    {
        return [
            'access_token_enc' => 'encrypted',
            'refresh_token_enc' => 'encrypted',
            'application_token_enc' => 'encrypted',
            'token_expires_at' => 'datetime',
            'installer_b24_user_id' => 'integer',
            'installed_at' => 'datetime',
            'uninstalled_at' => 'datetime',
            'last_api_call_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'settings_json' => 'array',
            'schema_snapshot_json' => 'array',
            'schema_snapshot_at' => 'datetime',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function currentSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    public function catalogConfig(): HasOne
    {
        return $this->hasOne(TenantCatalogConfig::class);
    }

    public function spaBuckets(): HasMany
    {
        return $this->hasMany(TenantSpaBucket::class);
    }

    public function entityConfigs(): HasMany
    {
        return $this->hasMany(TenantEntityConfig::class);
    }

    public function fieldMappings(): HasMany
    {
        return $this->hasMany(TenantFieldMapping::class);
    }

    public function enumMappings(): HasMany
    {
        return $this->hasMany(TenantEnumMapping::class);
    }

    public function syncedEntityStates(): HasMany
    {
        return $this->hasMany(SyncedEntityState::class);
    }

    public function syncedSpaLinks(): HasMany
    {
        return $this->hasMany(SyncedSpaLink::class);
    }

    public function monthlyUsage(): HasMany
    {
        return $this->hasMany(TenantUsageMonthly::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class);
    }

    public function isActive(): bool
    {
        return $this->install_status === self::INSTALL_ACTIVE;
    }

    public function isEnterprise(): bool
    {
        return strtolower((string) $this->license_family) === 'ent';
    }

    public function verifyApplicationToken(?string $candidate): bool
    {
        if (! is_string($candidate) || $candidate === '') {
            return false;
        }
        $stored = $this->application_token_enc;
        if (! is_string($stored) || $stored === '') {
            return false;
        }

        return hash_equals($stored, $candidate);
    }
}
