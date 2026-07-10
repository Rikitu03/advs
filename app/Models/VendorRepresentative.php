<?php

namespace App\Models;

use Database\Factories\VendorRepresentativeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The authorized owner/representative declared at vendor registration.
 *
 * @property int $id
 * @property int $vendor_id
 * @property string $first_name
 * @property string|null $middle_name
 * @property string $last_name
 * @property string|null $suffix
 * @property string $gender
 * @property string $government_id_type
 */
class VendorRepresentative extends Model
{
    /** @use HasFactory<VendorRepresentativeFactory> */
    use HasFactory;

    /**
     * Genders and their display labels (match the values on government IDs used
     * for cross-checking).
     *
     * @var array<string, string>
     */
    public const GENDERS = [
        'male' => 'Male',
        'female' => 'Female',
    ];

    /**
     * Accepted government ID types. Keys are the `document_types.code` values
     * (see DocumentTypeSeeder) so a declared ID type maps onto the uploaded ID
     * document; values are display labels for the dropdown.
     *
     * @var array<string, string>
     */
    public const GOVERNMENT_ID_TYPES = [
        'national_id' => 'PhilSys National ID (PhilID)',
        'drivers_license' => "Driver's License",
        'passport' => 'Passport',
        'umid' => 'UMID',
        'sss_id' => 'SSS ID',
        'philhealth_id' => 'PhilHealth ID',
        'postal_id' => 'Postal ID',
        'prc_id' => 'PRC ID',
        'voters_id' => "Voter's ID",
        'tin_id' => 'TIN ID',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'vendor_id',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'date_of_birth',
        'gender',
        'contact_number',
        'government_id_type',
        'government_id_number',
        'home_address',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * The representative's full declared name, including any suffix.
     */
    public function fullName(): string
    {
        return collect([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
            $this->suffix,
        ])->filter()->implode(' ');
    }
}
