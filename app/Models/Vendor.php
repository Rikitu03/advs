<?php

namespace App\Models;

use Database\Factories\VendorFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A third-party vendor undergoing accreditation (see
 * {@see database/migrations/2026_06_07_000001_create_vendors_table.php}).
 *
 * @property int $id
 * @property int $user_id
 * @property string $company_name
 * @property string|null $registration_number
 * @property float $risk_score
 * @property string $status
 */
class Vendor extends Model
{
    /** @use HasFactory<VendorFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /**
     * Business entity types and their display labels. Sole proprietors register
     * with the DTI; partnerships/corporations register with the SEC.
     *
     * @var array<string, string>
     */
    public const BUSINESS_ENTITY_TYPES = [
        'sole_proprietorship' => 'Sole Proprietorship',
        'partnership' => 'Partnership',
        'corporation' => 'Corporation',
        'cooperative' => 'Cooperative',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'company_name',
        'trade_name',
        'business_entity_type',
        'tin',
        'dti_registration_number',
        'sec_registration_number',
        'business_permit_number',
        'nature_of_business',
        'business_street',
        'business_barangay',
        'business_city',
        'business_province',
        'business_postal_code',
        'registration_number',
        'phone_number',
        'address',
        'risk_score',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'risk_score' => 'float',
        ];
    }

    /**
     * Whether the given entity type registers its business name with the DTI.
     */
    public static function entityRequiresDti(?string $entityType): bool
    {
        return $entityType === 'sole_proprietorship';
    }

    /**
     * Whether the given entity type registers with the SEC.
     */
    public static function entityRequiresSec(?string $entityType): bool
    {
        return in_array($entityType, ['partnership', 'corporation'], true);
    }

    /**
     * The composed, human-readable business address from its structured parts.
     */
    protected function businessAddress(): Attribute
    {
        return Attribute::make(
            get: fn (): string => collect([
                $this->business_street,
                $this->business_barangay,
                $this->business_city,
                $this->business_province,
                $this->business_postal_code,
            ])->filter()->implode(', '),
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasOne<VendorRepresentative, $this>
     */
    public function representative(): HasOne
    {
        return $this->hasOne(VendorRepresentative::class);
    }

    /**
     * @return HasMany<Submission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * Scope: accredited vendors only (status = approved).
     */
    public function scopeAccredited(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /**
     * Flux badge color for an accreditation status.
     */
    public static function statusColor(string $status): string
    {
        return match ($status) {
            self::STATUS_APPROVED => 'emerald',
            self::STATUS_REJECTED => 'red',
            self::STATUS_UNDER_REVIEW => 'amber',
            default => 'zinc',
        };
    }
}
