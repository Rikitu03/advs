# Vendor Registration Update — Declared Profile & Cross-Check Reference Data Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Capture the franchisee's declared owner/representative and business information at registration as a new gated step, store it as structured reference data (the data the system and officers later cross-check uploaded documents against), and display it read-only on the vendor's own profile.

**Architecture:** A new gated registration step (mirroring the existing signature-enrollment step) sits **between Account and Signature**: `Account → Business & Owner Details → Signature → Verify email`. A Volt form collects ~22 declared fields, a `CreateVendorProfile` action persists them across the `vendors` table (business profile) and a new `vendor_representatives` table (owner identity) inside one transaction, and a new `EnsureVendorProfileComplete` middleware gates vendors to that step until it is done. A single `users.vendor_profile_completed_at` timestamp is the gate signal (consistent with `signature_enrolled_at`).

**Tech Stack:** Laravel 12, Livewire 4 + Volt 1, Flux UI 2 (free), Tailwind v4, Fortify (auth), MySQL 8, PHPUnit 11. Format validation reuses the existing `App\Support\TinValidator` and `RegistrationNumberValidator`.

## Global Constraints

- **PHP 8.2** — curly braces on all control structures; constructor property promotion; explicit return types and param type hints; PHPDoc array shapes over inline comments.
- **Laravel 12 streamlined structure** — middleware registered in `bootstrap/app.php` (not a Kernel); model casts go in a `casts()` method, not a `$casts` property. When **modifying** a migration column, restate **all** prior attributes or they are dropped.
- **Create files with Artisan** — `php artisan make:migration|model|middleware|test|factory|class|volt … --no-interaction`. Do not hand-author files that have a generator.
- **UI is Flux-first** — `<flux:input>`, `<flux:select>`, `<flux:textarea>`, `<flux:button>`, etc. No inline `style=""`. Tailwind v4 utility classes only; no `tailwind.config.js`, no `@tailwindcss/forms`.
- **Tests are PHPUnit classes** (a Pest runner is configured but write PHPUnit). `RefreshDatabase`. **Run `php artisan config:clear` once before any test run** — a cached `bootstrap/cache/config.php` overrides phpunit's sqlite `:memory:` env and makes tests hit the down MySQL `advs` DB (project memory: *tests-need-config-clear*).
- **Format before every commit:** `vendor/bin/pint --dirty --format agent`.
- **Scope is vendor-only.** This plan captures + stores + displays declared data. The automated *declared-vs-extracted* cross-check engine (OCR field extraction → mismatch flags → risk score) is **explicitly out of scope** — it depends on OCR field extraction not yet in production. Officer-facing display of declared data is also deferred (see "Out of Scope" below).
- **No new dependencies.** Reuse existing packages and the existing `App\Support` validators.

## Out of Scope (do not build here)

- Automated comparison of declared values against OCR-extracted document values, and any mismatch flags feeding the risk score.
- Surfacing declared data on the **officer** vendor profile (`admin/vendors/{vendor}`). That page is currently backed entirely by `DemoStore::findVendor()` (session demo data); real registered vendors 404 there. Wiring real vendors into the officer UI is a separate demo-store refactor.
- Cooperative-specific (CDA) registration number capture — the spec's conditional logic only covers DTI (sole proprietorship) vs SEC (partnership/corporation). Cooperatives capture neither conditional number in this plan.

## Spec → Storage Coverage Map

| Declared field (spec) | Column | Table | Task |
|---|---|---|---|
| Business name / Trade name | `company_name` (required) + `trade_name` (optional DBA) | vendors | 2 |
| Type of business entity | `business_entity_type` enum | vendors | 2 |
| TIN | `tin` | vendors | 2 |
| DTI Registration No. (if sole prop) | `dti_registration_number` | vendors | 2 |
| SEC Registration No. (if partnership/corp) | `sec_registration_number` | vendors | 2 |
| Business Permit No. | `business_permit_number` | vendors | 2 |
| Business address (street, barangay, city, province, zip) | `business_street`, `business_barangay`, `business_city`, `business_province`, `business_postal_code` | vendors | 2 |
| Nature/line of business | `nature_of_business` | vendors | 2 |
| Owner full name (first, middle, last, suffix) | `first_name`, `middle_name`, `last_name`, `suffix` | vendor_representatives | 3 |
| Date of birth | `date_of_birth` | vendor_representatives | 3 |
| Gender | `gender` enum | vendor_representatives | 3 |
| Contact number | `contact_number` | vendor_representatives | 3 |
| Government ID type | `government_id_type` (document-type code) | vendor_representatives | 3 |
| Government ID number | `government_id_number` | vendor_representatives | 3 |
| Home address (complete) | `home_address` | vendor_representatives | 3 |
| Email address | `users.email` (already captured at Account step) | users | — |
| Document validity / expiry fields | *Not declared — extracted by the ML pipeline (out of scope)* | — | — |

## File Structure

**Create:**
- `database/migrations/XXXX_add_vendor_profile_completed_at_to_users_table.php` — gate-signal timestamp.
- `database/migrations/XXXX_add_declared_business_fields_to_vendors_table.php` — business reference columns.
- `database/migrations/XXXX_create_vendor_representatives_table.php` — owner/representative identity.
- `app/Models/VendorRepresentative.php` — owner model + gender/gov-ID constants.
- `database/factories/VendorRepresentativeFactory.php`.
- `app/Actions/Vendor/CreateVendorProfile.php` — single use-case orchestrator (transaction).
- `app/Http/Middleware/EnsureVendorProfileComplete.php` — gate to the new step.
- `resources/views/livewire/auth/business-details.blade.php` — the new Volt step (form).
- `tests/Feature/Vendor/CreateVendorProfileTest.php`.
- `tests/Feature/Auth/VendorProfileStepTest.php` — gate + Volt form behavior.

**Modify:**
- `app/Models/User.php` — `vendor()` relation, `hasCompletedVendorProfile()`, cast.
- `app/Models/Vendor.php` — fillable, entity-type constants/helpers, `representative()` relation, `businessAddress` accessor.
- `database/factories/UserFactory.php` — default `vendor_profile_completed_at` + `withoutVendorProfile()` state.
- `database/factories/VendorFactory.php` — new business fields in `definition()`.
- `app/Http/Middleware/EnsureSignatureEnrolled.php` — guard so it only fires after the profile is complete.
- `bootstrap/app.php` — register the new gate before the signature gate.
- `app/Http/Responses/RegisterResponse.php` — redirect to the new step.
- `routes/web.php` — register `business.create` route.
- `resources/views/auth/register.blade.php` — 4-step indicator.
- `resources/views/livewire/vendor/profile.blade.php` — read-only declared-info card.
- `database/seeders/DatabaseSeeder.php` — seeded vendor gets a complete profile.
- `tests/Feature/Auth/RegistrationTest.php` — updated post-register redirect assertion.

---

### Task 1: Gate-signal timestamp + User model + factory

**Files:**
- Create: `database/migrations/XXXX_add_vendor_profile_completed_at_to_users_table.php`
- Modify: `app/Models/User.php`
- Modify: `database/factories/UserFactory.php`
- Test: `tests/Feature/Auth/VendorProfileStepTest.php` (created here, extended later)

**Interfaces:**
- Produces: `User::hasCompletedVendorProfile(): bool`; `User::vendor(): HasOne` (→ `Vendor`); `users.vendor_profile_completed_at` (nullable datetime, cast); `UserFactory::withoutVendorProfile(): static`; default factory users have `vendor_profile_completed_at = now()`.

- [ ] **Step 1: Create the migration**

Run: `php artisan make:migration add_vendor_profile_completed_at_to_users_table --no-interaction`

- [ ] **Step 2: Fill in the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks the moment a vendor finished the business/owner-details step (the
     * second registration step, before signature enrollment). Presence of this
     * timestamp is the EnsureVendorProfileComplete gate signal — kept on `users`
     * (like signature_enrolled_at) so the gate reads it off the already-loaded
     * user with no extra query.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('vendor_profile_completed_at')->nullable()->after('signature_enrolled_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('vendor_profile_completed_at');
        });
    }
};
```

- [ ] **Step 3: Add the relation, helper, and cast to `User`**

In `app/Models/User.php`, add the import near the other `Illuminate\Database\Eloquent` imports:

```php
use Illuminate\Database\Eloquent\Relations\HasOne;
```

Add `'vendor_profile_completed_at' => 'datetime',` to the array returned by `casts()` (alongside `signature_enrolled_at`):

```php
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'signature_enrolled_at' => 'datetime',
            'vendor_profile_completed_at' => 'datetime',
        ];
```

Add these methods (place `vendor()` near the other relations; `hasCompletedVendorProfile()` next to `hasEnrolledSignature()`):

```php
    /**
     * The vendor company profile owned by this user (vendors are the only role
     * with one).
     *
     * @return HasOne<Vendor, $this>
     */
    public function vendor(): HasOne
    {
        return $this->hasOne(Vendor::class);
    }

    /**
     * Whether the vendor has completed the business/owner-details step of
     * registration (see the EnsureVendorProfileComplete middleware).
     */
    public function hasCompletedVendorProfile(): bool
    {
        return $this->vendor_profile_completed_at !== null;
    }
```

- [ ] **Step 4: Update `UserFactory`**

In `database/factories/UserFactory.php`, add to the array returned by `definition()` (right after the `signature_enrolled_at` line):

```php
            // Factory users default to having finished the business/owner-details
            // step too; use withoutVendorProfile() to test that gate.
            'vendor_profile_completed_at' => now(),
```

Add this state method after `unenrolled()`:

```php
    /**
     * Indicate that the vendor has not completed the business/owner-details step.
     */
    public function withoutVendorProfile(): static
    {
        return $this->state(fn (array $attributes) => [
            'vendor_profile_completed_at' => null,
        ]);
    }
```

- [ ] **Step 5: Write the failing test**

Run: `php artisan make:test --phpunit Auth/VendorProfileStepTest --no-interaction`

Replace the file body with:

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorProfileStepTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_factory_vendor_has_a_completed_profile(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->hasCompletedVendorProfile());
        $this->assertNotNull($user->vendor_profile_completed_at);
    }

    public function test_without_vendor_profile_state_marks_the_profile_incomplete(): void
    {
        $user = User::factory()->withoutVendorProfile()->create();

        $this->assertFalse($user->hasCompletedVendorProfile());
        $this->assertNull($user->vendor_profile_completed_at);
    }
}
```

- [ ] **Step 6: Run the test to verify it fails, then passes**

Run: `php artisan config:clear && php artisan test --compact --filter=VendorProfileStepTest`
Expected first run: FAIL (`vendor_profile_completed_at` column / cast missing). After Steps 2–4: PASS (2 tests).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/User.php database/factories/UserFactory.php database/migrations tests/Feature/Auth/VendorProfileStepTest.php
git commit -m "feat(vendor-reg): add vendor_profile_completed_at gate signal to users"
```

---

### Task 2: Declared business fields on `vendors` + Vendor model + factory

**Files:**
- Create: `database/migrations/XXXX_add_declared_business_fields_to_vendors_table.php`
- Modify: `app/Models/Vendor.php`
- Modify: `database/factories/VendorFactory.php`
- Test: `tests/Unit/VendorBusinessProfileTest.php`

**Interfaces:**
- Produces: `vendors` columns `trade_name, business_entity_type, tin, dti_registration_number, sec_registration_number, business_permit_number, nature_of_business, business_street, business_barangay, business_city, business_province, business_postal_code`; `Vendor::BUSINESS_ENTITY_TYPES` (`array<string,string>`); `Vendor::entityRequiresDti(?string): bool`; `Vendor::entityRequiresSec(?string): bool`; `$vendor->business_address` accessor (string). The `representative()` relation is added in Task 3.

- [ ] **Step 1: Create the migration**

Run: `php artisan make:migration add_declared_business_fields_to_vendors_table --no-interaction`

- [ ] **Step 2: Fill in the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Declared business reference data captured at registration (step 2). These
     * are the franchisee's self-declared values that uploaded documents are later
     * cross-checked against (TIN vs BIR 2303, business name vs DTI/SEC/Mayor's
     * Permit, etc.). The legacy `registration_number` and `address` columns are
     * left in place but are not written by the new flow.
     */
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('trade_name', 150)->nullable()->after('company_name');
            $table->enum('business_entity_type', [
                'sole_proprietorship', 'partnership', 'corporation', 'cooperative',
            ])->nullable()->after('trade_name');
            $table->string('tin', 20)->nullable()->after('business_entity_type');
            $table->string('dti_registration_number', 50)->nullable()->after('tin');
            $table->string('sec_registration_number', 50)->nullable()->after('dti_registration_number');
            $table->string('business_permit_number', 50)->nullable()->after('sec_registration_number');
            $table->string('nature_of_business', 150)->nullable()->after('business_permit_number');
            $table->string('business_street', 255)->nullable()->after('nature_of_business');
            $table->string('business_barangay', 120)->nullable()->after('business_street');
            $table->string('business_city', 120)->nullable()->after('business_barangay');
            $table->string('business_province', 120)->nullable()->after('business_city');
            $table->string('business_postal_code', 10)->nullable()->after('business_province');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn([
                'trade_name', 'business_entity_type', 'tin',
                'dti_registration_number', 'sec_registration_number',
                'business_permit_number', 'nature_of_business',
                'business_street', 'business_barangay', 'business_city',
                'business_province', 'business_postal_code',
            ]);
        });
    }
};
```

- [ ] **Step 3: Update the `Vendor` model**

In `app/Models/Vendor.php`, add the import:

```php
use Illuminate\Database\Eloquent\Casts\Attribute;
```

Add the entity-type constants after the existing `STATUS_*` constants:

```php
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
```

Replace the `$fillable` array with:

```php
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
```

Add these methods (place the static helpers after `casts()`, and the accessor below them):

```php
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
```

- [ ] **Step 4: Update `VendorFactory`**

In `database/factories/VendorFactory.php`, replace the array returned by `definition()` with:

```php
        return [
            'user_id' => User::factory(),
            'company_name' => $this->faker->company(),
            'trade_name' => null,
            'business_entity_type' => 'sole_proprietorship',
            'tin' => $this->faker->numerify('###-###-###-000'),
            'dti_registration_number' => $this->faker->numerify('DTI-#######'),
            'sec_registration_number' => null,
            'business_permit_number' => $this->faker->numerify('BP-#######'),
            'nature_of_business' => 'Food retail and distribution',
            'business_street' => $this->faker->streetAddress(),
            'business_barangay' => 'Barangay '.$this->faker->numberBetween(1, 200),
            'business_city' => $this->faker->city(),
            'business_province' => 'Metro Manila',
            'business_postal_code' => $this->faker->numerify('1###'),
            'registration_number' => $this->faker->numerify('###-###-###-000'),
            'phone_number' => $this->faker->unique()->numerify('+63 9## ### ####'),
            'address' => $this->faker->address(),
            'risk_score' => 0,
            'status' => Vendor::STATUS_PENDING,
        ];
```

- [ ] **Step 5: Write the failing test**

Run: `php artisan make:test --phpunit --unit VendorBusinessProfileTest --no-interaction`

Replace the file body with:

```php
<?php

namespace Tests\Unit;

use App\Models\Vendor;
use Tests\TestCase;

class VendorBusinessProfileTest extends TestCase
{
    public function test_entity_requires_dti_only_for_sole_proprietorship(): void
    {
        $this->assertTrue(Vendor::entityRequiresDti('sole_proprietorship'));
        $this->assertFalse(Vendor::entityRequiresDti('corporation'));
        $this->assertFalse(Vendor::entityRequiresDti(null));
    }

    public function test_entity_requires_sec_for_partnerships_and_corporations(): void
    {
        $this->assertTrue(Vendor::entityRequiresSec('partnership'));
        $this->assertTrue(Vendor::entityRequiresSec('corporation'));
        $this->assertFalse(Vendor::entityRequiresSec('sole_proprietorship'));
        $this->assertFalse(Vendor::entityRequiresSec('cooperative'));
    }

    public function test_business_address_accessor_joins_structured_parts(): void
    {
        $vendor = new Vendor([
            'business_street' => '123 Mabini St',
            'business_barangay' => 'Barangay San Jose',
            'business_city' => 'Pasig',
            'business_province' => 'Metro Manila',
            'business_postal_code' => '1600',
        ]);

        $this->assertSame(
            '123 Mabini St, Barangay San Jose, Pasig, Metro Manila, 1600',
            $vendor->business_address,
        );
    }
}
```

- [ ] **Step 6: Run the test (fails → passes)**

Run: `php artisan config:clear && php artisan test --compact --filter=VendorBusinessProfileTest`
Expected first run: FAIL (methods/columns missing). After Steps 2–4: PASS (3 tests).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/Vendor.php database/factories/VendorFactory.php database/migrations tests/Unit/VendorBusinessProfileTest.php
git commit -m "feat(vendor-reg): add declared business fields to vendors + entity helpers"
```

---

### Task 3: `vendor_representatives` table + model + factory + relation

**Files:**
- Create: `database/migrations/XXXX_create_vendor_representatives_table.php` (via `make:model -mf`)
- Create: `app/Models/VendorRepresentative.php`
- Create: `database/factories/VendorRepresentativeFactory.php`
- Modify: `app/Models/Vendor.php` (add `representative()`)
- Test: `tests/Unit/VendorRepresentativeTest.php`

**Interfaces:**
- Consumes: `Vendor` model (Task 2).
- Produces: `VendorRepresentative` model; `VendorRepresentative::GENDERS` and `::GOVERNMENT_ID_TYPES` (`array<string,string>`); `Vendor::representative(): HasOne` (→ `VendorRepresentative`); `VendorRepresentative::vendor(): BelongsTo`; `VendorRepresentativeFactory`.

- [ ] **Step 1: Generate model + migration + factory**

Run: `php artisan make:model VendorRepresentative -mf --no-interaction`

- [ ] **Step 2: Fill in the migration**

Edit `database/migrations/XXXX_create_vendor_representatives_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The authorized owner/representative of a vendor, one per vendor. These
     * declared identity fields are cross-checked against the submitted
     * government ID and the name printed on the business documents.
     */
    public function up(): void
    {
        Schema::create('vendor_representatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('suffix', 20)->nullable();
            $table->date('date_of_birth');
            $table->enum('gender', ['male', 'female']);
            $table->string('contact_number', 20);
            $table->string('government_id_type', 40);
            $table->string('government_id_number', 60);
            $table->text('home_address');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_representatives');
    }
};
```

- [ ] **Step 3: Write the `VendorRepresentative` model**

Replace `app/Models/VendorRepresentative.php` with:

```php
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
```

- [ ] **Step 4: Write the factory**

Replace `database/factories/VendorRepresentativeFactory.php` with:

```php
<?php

namespace Database\Factories;

use App\Models\Vendor;
use App\Models\VendorRepresentative;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VendorRepresentative>
 */
class VendorRepresentativeFactory extends Factory
{
    protected $model = VendorRepresentative::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::factory(),
            'first_name' => $this->faker->firstName(),
            'middle_name' => $this->faker->lastName(),
            'last_name' => $this->faker->lastName(),
            'suffix' => null,
            'date_of_birth' => $this->faker->dateTimeBetween('-60 years', '-21 years')->format('Y-m-d'),
            'gender' => $this->faker->randomElement(array_keys(VendorRepresentative::GENDERS)),
            'contact_number' => $this->faker->numerify('+63 9## ### ####'),
            'government_id_type' => $this->faker->randomElement(array_keys(VendorRepresentative::GOVERNMENT_ID_TYPES)),
            'government_id_number' => $this->faker->numerify('############'),
            'home_address' => $this->faker->address(),
        ];
    }
}
```

- [ ] **Step 5: Add the relation to `Vendor`**

In `app/Models/Vendor.php`, add the import:

```php
use Illuminate\Database\Eloquent\Relations\HasOne;
```

Add the relation method (next to `submissions()`):

```php
    /**
     * @return HasOne<VendorRepresentative, $this>
     */
    public function representative(): HasOne
    {
        return $this->hasOne(VendorRepresentative::class);
    }
```

- [ ] **Step 6: Write the failing test**

Run: `php artisan make:test --phpunit Vendor/VendorRepresentativeRelationTest --no-interaction`

Replace the file body with:

```php
<?php

namespace Tests\Feature\Vendor;

use App\Models\Vendor;
use App\Models\VendorRepresentative;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorRepresentativeRelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_vendor_has_one_representative(): void
    {
        $vendor = Vendor::factory()->create();
        $rep = VendorRepresentative::factory()->for($vendor)->create([
            'first_name' => 'Maria',
            'middle_name' => 'Santos',
            'last_name' => 'Cruz',
            'suffix' => 'Jr.',
        ]);

        $this->assertTrue($vendor->refresh()->representative->is($rep));
        $this->assertTrue($rep->vendor->is($vendor));
        $this->assertSame('Maria Santos Cruz Jr.', $rep->fullName());
    }
}
```

- [ ] **Step 7: Run the test (fails → passes)**

Run: `php artisan config:clear && php artisan test --compact --filter=VendorRepresentativeRelationTest`
Expected first run: FAIL (table/relation missing). After Steps 2–5: PASS (1 test).

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/VendorRepresentative.php app/Models/Vendor.php database/factories/VendorRepresentativeFactory.php database/migrations tests/Feature/Vendor/VendorRepresentativeRelationTest.php
git commit -m "feat(vendor-reg): add vendor_representatives table, model, factory, relation"
```

---

### Task 4: `CreateVendorProfile` action

**Files:**
- Create: `app/Actions/Vendor/CreateVendorProfile.php`
- Test: `tests/Feature/Vendor/CreateVendorProfileTest.php`

**Interfaces:**
- Consumes: `User`, `Vendor`, `VendorRepresentative` models (Tasks 1–3).
- Produces: `CreateVendorProfile::execute(User $user, array<string,mixed> $data): Vendor`. Persists one `Vendor` + one `VendorRepresentative` in a transaction and sets `$user->vendor_profile_completed_at`. Idempotent (re-running updates rather than duplicates). Nullifies the non-applicable conditional registration number based on entity type, and coerces blank optionals to null.

- [ ] **Step 1: Generate the action class**

Run: `php artisan make:class Actions/Vendor/CreateVendorProfile --no-interaction`

- [ ] **Step 2: Write the failing test**

Run: `php artisan make:test --phpunit Vendor/CreateVendorProfileTest --no-interaction`

Replace the file body with:

```php
<?php

namespace Tests\Feature\Vendor;

use App\Actions\Vendor\CreateVendorProfile;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateVendorProfileTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Negofood Trading',
            'trade_name' => '',
            'business_entity_type' => 'sole_proprietorship',
            'tin' => '123-456-789-000',
            'dti_registration_number' => 'DTI-2026001',
            'sec_registration_number' => '',
            'business_permit_number' => 'BP-2026-555',
            'nature_of_business' => 'Food retail',
            'business_street' => '12 Ortigas Ave',
            'business_barangay' => 'Barangay San Antonio',
            'business_city' => 'Pasig',
            'business_province' => 'Metro Manila',
            'business_postal_code' => '1600',
            'first_name' => 'Jose',
            'middle_name' => '',
            'last_name' => 'Rizal',
            'suffix' => '',
            'date_of_birth' => '1990-06-19',
            'gender' => 'male',
            'contact_number' => '+63 917 000 0000',
            'government_id_type' => 'national_id',
            'government_id_number' => '1234-5678-9012',
            'home_address' => '37 Real St, Calamba',
        ], $overrides);
    }

    public function test_it_creates_vendor_and_representative_and_marks_profile_complete(): void
    {
        $user = User::factory()->withoutVendorProfile()->create();

        $vendor = app(CreateVendorProfile::class)->execute($user, $this->payload());

        $this->assertSame('Negofood Trading', $vendor->company_name);
        $this->assertSame('sole_proprietorship', $vendor->business_entity_type);
        $this->assertSame('DTI-2026001', $vendor->dti_registration_number);
        $this->assertNull($vendor->sec_registration_number);
        $this->assertNull($vendor->trade_name);
        $this->assertSame('Jose', $vendor->representative->first_name);
        $this->assertNull($vendor->representative->middle_name);
        $this->assertTrue($user->refresh()->hasCompletedVendorProfile());
        $this->assertDatabaseCount('vendors', 1);
        $this->assertDatabaseCount('vendor_representatives', 1);
    }

    public function test_it_nullifies_dti_number_for_a_corporation(): void
    {
        $user = User::factory()->withoutVendorProfile()->create();

        $vendor = app(CreateVendorProfile::class)->execute($user, $this->payload([
            'business_entity_type' => 'corporation',
            'dti_registration_number' => 'DTI-LEFTOVER',
            'sec_registration_number' => 'SEC-CS202600123',
        ]));

        $this->assertNull($vendor->dti_registration_number);
        $this->assertSame('SEC-CS202600123', $vendor->sec_registration_number);
    }

    public function test_it_is_idempotent_and_does_not_duplicate_rows(): void
    {
        $user = User::factory()->withoutVendorProfile()->create();
        $action = app(CreateVendorProfile::class);

        $action->execute($user, $this->payload());
        $action->execute($user, $this->payload(['company_name' => 'Renamed Trading']));

        $this->assertDatabaseCount('vendors', 1);
        $this->assertDatabaseCount('vendor_representatives', 1);
        $this->assertSame('Renamed Trading', $user->vendor->refresh()->company_name);
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php artisan config:clear && php artisan test --compact --filter=CreateVendorProfileTest`
Expected: FAIL (`execute()` not implemented).

- [ ] **Step 4: Implement the action**

Replace `app/Actions/Vendor/CreateVendorProfile.php` with:

```php
<?php

namespace App\Actions\Vendor;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;

/**
 * Persists the business/owner-details step of vendor registration: one Vendor
 * profile row plus its one VendorRepresentative, in a single transaction, and
 * stamps the user's vendor_profile_completed_at so the onboarding gate releases.
 */
class CreateVendorProfile
{
    /**
     * @param  array<string, mixed>  $data  Validated business + representative fields.
     */
    public function execute(User $user, array $data): Vendor
    {
        return DB::transaction(function () use ($user, $data): Vendor {
            $entityType = $data['business_entity_type'];

            $vendor = Vendor::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'company_name' => $data['company_name'],
                    'trade_name' => $this->nullable($data, 'trade_name'),
                    'business_entity_type' => $entityType,
                    'tin' => $data['tin'],
                    'dti_registration_number' => Vendor::entityRequiresDti($entityType)
                        ? $this->nullable($data, 'dti_registration_number')
                        : null,
                    'sec_registration_number' => Vendor::entityRequiresSec($entityType)
                        ? $this->nullable($data, 'sec_registration_number')
                        : null,
                    'business_permit_number' => $data['business_permit_number'],
                    'nature_of_business' => $data['nature_of_business'],
                    'business_street' => $data['business_street'],
                    'business_barangay' => $data['business_barangay'],
                    'business_city' => $data['business_city'],
                    'business_province' => $data['business_province'],
                    'business_postal_code' => $data['business_postal_code'],
                    'status' => Vendor::STATUS_PENDING,
                ],
            );

            $vendor->representative()->updateOrCreate([], [
                'first_name' => $data['first_name'],
                'middle_name' => $this->nullable($data, 'middle_name'),
                'last_name' => $data['last_name'],
                'suffix' => $this->nullable($data, 'suffix'),
                'date_of_birth' => $data['date_of_birth'],
                'gender' => $data['gender'],
                'contact_number' => $data['contact_number'],
                'government_id_type' => $data['government_id_type'],
                'government_id_number' => $data['government_id_number'],
                'home_address' => $data['home_address'],
            ]);

            $user->forceFill(['vendor_profile_completed_at' => now()])->save();

            return $vendor->load('representative');
        });
    }

    /**
     * Return the value for $key, or null when it is absent/blank.
     *
     * @param  array<string, mixed>  $data
     */
    private function nullable(array $data, string $key): ?string
    {
        return filled($data[$key] ?? null) ? (string) $data[$key] : null;
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan config:clear && php artisan test --compact --filter=CreateVendorProfileTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Vendor/CreateVendorProfile.php tests/Feature/Vendor/CreateVendorProfileTest.php
git commit -m "feat(vendor-reg): add CreateVendorProfile action (transactional, idempotent)"
```

---

### Task 5: Business-details route + Volt form (the new step)

**Files:**
- Modify: `routes/web.php`
- Create: `resources/views/livewire/auth/business-details.blade.php`
- Test: `tests/Feature/Auth/VendorProfileStepTest.php` (extend with Volt tests)

**Interfaces:**
- Consumes: `CreateVendorProfile` (Task 4); `Vendor::BUSINESS_ENTITY_TYPES`, `Vendor::entityRequiresDti/Sec` (Task 2); `VendorRepresentative::GENDERS/GOVERNMENT_ID_TYPES` (Task 3); `TinValidator`, `RegistrationNumberValidator` (existing).
- Produces: route name `business.create` at `register/business`; Volt component `auth.business-details` with a `save(CreateVendorProfile $action): void` method that validates, persists, and redirects to `signature.create`.

- [ ] **Step 1: Register the route**

In `routes/web.php`, inside the existing `Route::middleware(['auth'])->group(...)` block (right next to the `signature/enroll` route), add:

```php
    // Step 2 of vendor registration: declare business + owner details before
    // signature enrollment. Auth-only (the user is not verified yet) and exempt
    // from the EnsureVendorProfileComplete gate by its route name.
    Volt::route('register/business', 'auth.business-details')->name('business.create');
```

- [ ] **Step 2: Write the failing tests**

Append these methods to `tests/Feature/Auth/VendorProfileStepTest.php` (add the imports `use App\Models\Vendor;`, `use Livewire\Volt\Volt;` at the top):

```php
    public function test_business_step_renders_for_a_vendor_without_a_profile(): void
    {
        $user = User::factory()->withoutVendorProfile()->unverified()->create();

        $this->actingAs($user)
            ->get(route('business.create'))
            ->assertOk()
            ->assertSee('Business & owner details');
    }

    public function test_vendor_can_submit_the_business_step(): void
    {
        $user = User::factory()->withoutVendorProfile()->unverified()->create();

        Volt::actingAs($user)
            ->test('auth.business-details')
            ->set('company_name', 'Negofood Trading')
            ->set('business_entity_type', 'sole_proprietorship')
            ->set('tin', '123-456-789-000')
            ->set('dti_registration_number', 'DTI-2026001')
            ->set('business_permit_number', 'BP-2026-555')
            ->set('nature_of_business', 'Food retail')
            ->set('business_street', '12 Ortigas Ave')
            ->set('business_barangay', 'Barangay San Antonio')
            ->set('business_city', 'Pasig')
            ->set('business_province', 'Metro Manila')
            ->set('business_postal_code', '1600')
            ->set('first_name', 'Jose')
            ->set('last_name', 'Rizal')
            ->set('date_of_birth', '1990-06-19')
            ->set('gender', 'male')
            ->set('contact_number', '+63 917 000 0000')
            ->set('government_id_type', 'national_id')
            ->set('government_id_number', '1234-5678-9012')
            ->set('home_address', '37 Real St, Calamba')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('signature.create'));

        $user->refresh();
        $this->assertTrue($user->hasCompletedVendorProfile());
        $this->assertSame('Negofood Trading', $user->vendor->company_name);
    }

    public function test_business_step_requires_core_fields(): void
    {
        $user = User::factory()->withoutVendorProfile()->unverified()->create();

        Volt::actingAs($user)
            ->test('auth.business-details')
            ->call('save')
            ->assertHasErrors(['company_name', 'business_entity_type', 'tin', 'first_name', 'last_name']);
    }

    public function test_sole_proprietorship_requires_a_dti_number(): void
    {
        $user = User::factory()->withoutVendorProfile()->unverified()->create();

        Volt::actingAs($user)
            ->test('auth.business-details')
            ->set('business_entity_type', 'sole_proprietorship')
            ->set('sec_registration_number', '')
            ->set('dti_registration_number', '')
            ->call('save')
            ->assertHasErrors(['dti_registration_number']);
    }

    public function test_corporation_requires_a_sec_number(): void
    {
        $user = User::factory()->withoutVendorProfile()->unverified()->create();

        Volt::actingAs($user)
            ->test('auth.business-details')
            ->set('business_entity_type', 'corporation')
            ->set('dti_registration_number', '')
            ->set('sec_registration_number', '')
            ->call('save')
            ->assertHasErrors(['sec_registration_number']);
    }

    public function test_malformed_tin_is_rejected(): void
    {
        $user = User::factory()->withoutVendorProfile()->unverified()->create();

        Volt::actingAs($user)
            ->test('auth.business-details')
            ->set('tin', '12')
            ->call('save')
            ->assertHasErrors(['tin']);
    }
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan config:clear && php artisan test --compact --filter=VendorProfileStepTest`
Expected: FAIL (component `auth.business-details` does not exist).

- [ ] **Step 4: Create the Volt component**

Run: `php artisan make:volt auth/business-details --class --no-interaction`

Replace `resources/views/livewire/auth/business-details.blade.php` with:

```blade
<?php

use App\Actions\Vendor\CreateVendorProfile;
use App\Models\Vendor;
use App\Models\VendorRepresentative;
use App\Support\RegistrationNumberValidator;
use App\Support\TinValidator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    // Business
    public string $company_name = '';
    public string $trade_name = '';
    public string $business_entity_type = '';
    public string $tin = '';
    public string $dti_registration_number = '';
    public string $sec_registration_number = '';
    public string $business_permit_number = '';
    public string $nature_of_business = '';
    public string $business_street = '';
    public string $business_barangay = '';
    public string $business_city = '';
    public string $business_province = '';
    public string $business_postal_code = '';

    // Owner / representative
    public string $first_name = '';
    public string $middle_name = '';
    public string $last_name = '';
    public string $suffix = '';
    public string $date_of_birth = '';
    public string $gender = '';
    public string $contact_number = '';
    public string $government_id_type = '';
    public string $government_id_number = '';
    public string $home_address = '';

    public function requiresDti(): bool
    {
        return Vendor::entityRequiresDti($this->business_entity_type);
    }

    public function requiresSec(): bool
    {
        return Vendor::entityRequiresSec($this->business_entity_type);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:150'],
            'trade_name' => ['nullable', 'string', 'max:150'],
            'business_entity_type' => ['required', Rule::in(array_keys(Vendor::BUSINESS_ENTITY_TYPES))],
            'tin' => ['required', 'string', 'max:20', $this->tinRule()],
            'dti_registration_number' => ['nullable', 'required_if:business_entity_type,sole_proprietorship', 'string', 'max:50', $this->registrationNumberRule()],
            'sec_registration_number' => ['nullable', 'required_if:business_entity_type,partnership,corporation', 'string', 'max:50', $this->registrationNumberRule()],
            'business_permit_number' => ['required', 'string', 'max:50'],
            'nature_of_business' => ['required', 'string', 'max:150'],
            'business_street' => ['required', 'string', 'max:255'],
            'business_barangay' => ['required', 'string', 'max:120'],
            'business_city' => ['required', 'string', 'max:120'],
            'business_province' => ['required', 'string', 'max:120'],
            'business_postal_code' => ['required', 'string', 'max:10'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'gender' => ['required', Rule::in(array_keys(VendorRepresentative::GENDERS))],
            'contact_number' => ['required', 'string', 'max:20'],
            'government_id_type' => ['required', Rule::in(array_keys(VendorRepresentative::GOVERNMENT_ID_TYPES))],
            'government_id_number' => ['required', 'string', 'max:60'],
            'home_address' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * Closure rule: a declared TIN must be a well-formed Philippine TIN.
     */
    protected function tinRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! TinValidator::isValid((string) $value)) {
                $fail(__('Enter a valid TIN (9–14 digits, e.g. 123-456-789-000).'));
            }
        };
    }

    /**
     * Closure rule: validate a DTI/SEC certificate number's format, but only when
     * a value was supplied (the field is conditional on entity type).
     */
    protected function registrationNumberRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (filled($value) && ! RegistrationNumberValidator::isValid((string) $value)) {
                $fail(__('Enter a valid registration number (at least 5 digits).'));
            }
        };
    }

    public function save(CreateVendorProfile $action): void
    {
        $validated = $this->validate();

        $action->execute(Auth::user(), $validated);

        $this->redirectRoute('signature.create', navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header
        :title="__('Business & owner details')"
        :description="__('Step 2 of 4 — declare your business and representative information. This is what we cross-check your uploaded documents against.')"
    />

    {{-- Step indicator --}}
    <ol class="flex items-center gap-2 text-xs font-medium">
        <li class="flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400">
            <flux:icon icon="check-circle" variant="micro" class="size-4" /> {{ __('Account') }}
        </li>
        <li class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></li>
        <li class="flex items-center gap-1.5 text-cu-purple">
            <span class="flex size-4 items-center justify-center rounded-full bg-cu-purple text-[10px] text-white">2</span>
            {{ __('Details') }}
        </li>
        <li class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></li>
        <li class="flex items-center gap-1.5 text-zinc-400 dark:text-zinc-500">
            <span class="flex size-4 items-center justify-center rounded-full border border-current text-[10px]">3</span>
            {{ __('Signature') }}
        </li>
        <li class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></li>
        <li class="flex items-center gap-1.5 text-zinc-400 dark:text-zinc-500">
            <span class="flex size-4 items-center justify-center rounded-full border border-current text-[10px]">4</span>
            {{ __('Verify') }}
        </li>
    </ol>

    <form wire:submit="save" class="flex flex-col gap-6">
        {{-- Business information --}}
        <section class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Business information') }}</flux:heading>

            <flux:input wire:model="company_name" :label="__('Registered business name')" required />
            <flux:input wire:model="trade_name" :label="__('Trade name / DBA (optional)')" />

            <flux:select wire:model.live="business_entity_type" :label="__('Type of business entity')" :placeholder="__('Select entity type')" required>
                @foreach (\App\Models\Vendor::BUSINESS_ENTITY_TYPES as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="tin" :label="__('TIN (Tax Identification Number)')" placeholder="123-456-789-000" required />

            @if ($this->requiresDti())
                <flux:input wire:model="dti_registration_number" :label="__('DTI Registration Number')" required />
            @endif

            @if ($this->requiresSec())
                <flux:input wire:model="sec_registration_number" :label="__('SEC Registration Number')" required />
            @endif

            <flux:input wire:model="business_permit_number" :label="__('Business Permit Number')" required />
            <flux:input wire:model="nature_of_business" :label="__('Nature / line of business')" required />

            <flux:heading size="sm" class="mt-2">{{ __('Business address') }}</flux:heading>
            <flux:input wire:model="business_street" :label="__('Street')" required />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="business_barangay" :label="__('Barangay')" required />
                <flux:input wire:model="business_city" :label="__('City / Municipality')" required />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="business_province" :label="__('Province')" required />
                <flux:input wire:model="business_postal_code" :label="__('ZIP / Postal code')" required />
            </div>
        </section>

        {{-- Owner / representative --}}
        <section class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Owner / authorized representative') }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="first_name" :label="__('First name')" required />
                <flux:input wire:model="middle_name" :label="__('Middle name (optional)')" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="last_name" :label="__('Last name')" required />
                <flux:input wire:model="suffix" :label="__('Suffix (optional)')" placeholder="Jr., Sr., III" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="date_of_birth" type="date" :label="__('Date of birth')" required />
                <flux:select wire:model="gender" :label="__('Gender')" :placeholder="__('Select')" required>
                    @foreach (\App\Models\VendorRepresentative::GENDERS as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:input wire:model="contact_number" :label="__('Contact number')" required />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="government_id_type" :label="__('Government ID type')" :placeholder="__('Select ID type')" required>
                    @foreach (\App\Models\VendorRepresentative::GOVERNMENT_ID_TYPES as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="government_id_number" :label="__('Government ID number')" required />
            </div>

            <flux:textarea wire:model="home_address" :label="__('Home address (complete)')" rows="2" required />
        </section>

        <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">{{ __('Save & continue') }}</span>
            <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
        </flux:button>
    </form>

    <div class="text-center">
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <flux:link as="button" type="submit" class="cursor-pointer text-sm">{{ __('Log out') }}</flux:link>
        </form>
    </div>
</div>
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan config:clear && php artisan test --compact --filter=VendorProfileStepTest`
Expected: PASS (all VendorProfileStepTest methods, including the 6 new ones).

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php resources/views/livewire/auth/business-details.blade.php tests/Feature/Auth/VendorProfileStepTest.php
git commit -m "feat(vendor-reg): add business/owner-details Volt step with conditional DTI/SEC"
```

---

### Task 6: Onboarding gate (route incomplete vendors to the new step)

**Files:**
- Create: `app/Http/Middleware/EnsureVendorProfileComplete.php`
- Modify: `app/Http/Middleware/EnsureSignatureEnrolled.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/Auth/VendorProfileStepTest.php` (extend)

**Interfaces:**
- Consumes: `User::hasCompletedVendorProfile()` (Task 1); route `business.create` (Task 5).
- Produces: `EnsureVendorProfileComplete` middleware appended to the `web` group **before** `EnsureSignatureEnrolled`. Order guarantees: incomplete-profile vendors → `business.create`; complete-but-unenrolled vendors → `signature.create`; the signature gate no longer fires while the profile is incomplete.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Auth/VendorProfileStepTest.php`:

```php
    public function test_vendor_without_a_profile_is_gated_to_the_business_step(): void
    {
        $user = User::factory()->withoutVendorProfile()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('business.create'));
        $this->actingAs($user)->get(route('vendor.dashboard'))->assertRedirect(route('business.create'));
    }

    public function test_signature_gate_does_not_fire_before_the_profile_is_complete(): void
    {
        // No profile and no signature: the business gate wins; the user must not
        // be bounced to the signature step yet.
        $user = User::factory()->withoutVendorProfile()->unenrolled()->create();

        $this->actingAs($user)->get(route('vendor.dashboard'))->assertRedirect(route('business.create'));
    }

    public function test_complete_profile_but_unenrolled_vendor_is_gated_to_signature(): void
    {
        $user = User::factory()->unenrolled()->create(); // profile complete by default

        $this->actingAs($user)->get(route('vendor.dashboard'))->assertRedirect(route('signature.create'));
    }

    public function test_livewire_endpoints_are_never_gated_to_the_business_step(): void
    {
        $user = User::factory()->withoutVendorProfile()->unverified()->create();

        $response = $this->actingAs($user)->post(route('default-livewire.update'));

        $this->assertNotSame(
            route('business.create'),
            $response->headers->get('Location'),
            'Livewire update requests must not be redirected by the profile gate.'
        );
    }

    public function test_officers_and_admins_are_never_gated_to_the_business_step(): void
    {
        $officer = User::factory()->role(User::ROLE_COMPLIANCE_OFFICER)->withoutVendorProfile()->create();

        $this->actingAs($officer)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_guests_cannot_access_the_business_step(): void
    {
        $this->get(route('business.create'))->assertRedirect('/login');
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan config:clear && php artisan test --compact --filter=VendorProfileStepTest`
Expected: the new gating tests FAIL (no gate yet — incomplete vendors currently reach the dashboard or are bounced to signature).

- [ ] **Step 3: Create the gate middleware**

Run: `php artisan make:middleware EnsureVendorProfileComplete --no-interaction`

Replace `app/Http/Middleware/EnsureVendorProfileComplete.php` with:

```php
<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces a registered vendor through the business/owner-details step (the second
 * registration step) before signature enrollment, email verification, or any app
 * page.
 *
 * Appended to the `web` group BEFORE EnsureSignatureEnrolled, so business details
 * are collected before the signature. It no-ops for guests, non-vendors, and
 * vendors who have completed their profile, and exempts the business-step routes,
 * logout, and Livewire's own endpoints to avoid a redirect loop.
 */
class EnsureVendorProfileComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User
            && $user->hasRole(User::ROLE_VENDOR)
            && ! $user->hasCompletedVendorProfile()
            && ! $request->routeIs('business.*', 'logout', 'livewire.*', 'default-livewire.*')
        ) {
            return redirect()->route('business.create');
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Guard the signature gate so it fires only after the profile is complete**

In `app/Http/Middleware/EnsureSignatureEnrolled.php`, add the `hasCompletedVendorProfile()` condition to the `if`:

```php
        if ($user instanceof User
            && $user->hasRole(User::ROLE_VENDOR)
            && $user->hasCompletedVendorProfile()
            && ! $user->hasEnrolledSignature()
            && ! $request->routeIs('signature.*', 'logout', 'livewire.*', 'default-livewire.*')
        ) {
            return redirect()->route('signature.create');
        }
```

Update that method's doc comment to note the new ordering (one line): `// Runs after EnsureVendorProfileComplete; only fires once the business profile is complete.`

- [ ] **Step 5: Register the gate before the signature gate**

In `bootstrap/app.php`, add the import:

```php
use App\Http\Middleware\EnsureVendorProfileComplete;
```

Replace the `$middleware->web(append: [...])` call with:

```php
        // Gate every web route. Order matters: a registered vendor declares
        // business/owner details first, then enrolls a signature, before reaching
        // email verification or the app.
        $middleware->web(append: [
            EnsureVendorProfileComplete::class,
            EnsureSignatureEnrolled::class,
        ]);
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan config:clear && php artisan test --compact --filter=VendorProfileStepTest`
Expected: PASS (all methods). Then run the existing signature suite to confirm no regression:

Run: `php artisan test --compact --filter=SignatureEnrollmentTest`
Expected: PASS (unchanged — default factory users have a complete profile, so signature gating still resolves to `signature.create`).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Middleware/EnsureVendorProfileComplete.php app/Http/Middleware/EnsureSignatureEnrolled.php bootstrap/app.php tests/Feature/Auth/VendorProfileStepTest.php
git commit -m "feat(vendor-reg): gate vendors to the business-details step before signature"
```

---

### Task 7: Wire the new step into the registration entrypoint

**Files:**
- Modify: `app/Http/Responses/RegisterResponse.php`
- Modify: `resources/views/auth/register.blade.php`
- Modify: `tests/Feature/Auth/RegistrationTest.php`

**Interfaces:**
- Consumes: route `business.create` (Task 5).
- Produces: post-registration redirect now lands on `business.create`; the register screen shows a 4-step indicator.

- [ ] **Step 1: Update the failing assertion in `RegistrationTest`**

In `tests/Feature/Auth/RegistrationTest.php`, inside `test_new_users_can_register_as_vendors`, change the redirect assertion and add a profile-state assertion:

```php
        // Step 2 of registration: declare business + owner details before signature.
        $response->assertRedirect(route('business.create'));

        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'role' => User::ROLE_VENDOR,
            'signature_enrolled_at' => null,
            'vendor_profile_completed_at' => null,
        ]);
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan config:clear && php artisan test --compact --filter=RegistrationTest`
Expected: `test_new_users_can_register_as_vendors` FAILS (still redirects to `signature.create`).

- [ ] **Step 3: Update `RegisterResponse`**

In `app/Http/Responses/RegisterResponse.php`, change the redirect target and the docblock:

```php
/**
 * After registration, send vendors to the business/owner-details step (the
 * second registration step) instead of straight to the dashboard. The
 * EnsureVendorProfileComplete middleware keeps them there until it is completed,
 * then EnsureSignatureEnrolled forwards them to signature enrollment.
 */
class RegisterResponse implements RegisterResponseContract
{
    public function toResponse($request): RedirectResponse|JsonResponse
    {
        return $request->wantsJson()
            ? new JsonResponse('', 201)
            : redirect()->route('business.create');
    }
}
```

- [ ] **Step 4: Update the register screen step indicator to 4 steps**

In `resources/views/auth/register.blade.php`, replace the existing `<ol …>` step-indicator block (the 3-step list) with:

```blade
        {{-- Step indicator: account → details → signature → verify email --}}
        <ol class="flex items-center gap-2 text-xs font-medium">
            <li class="flex items-center gap-1.5 text-cu-purple">
                <span class="flex size-4 items-center justify-center rounded-full bg-cu-purple text-[10px] text-white">1</span>
                {{ __('Account') }}
            </li>
            <li class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></li>
            <li class="flex items-center gap-1.5 text-zinc-400 dark:text-zinc-500">
                <span class="flex size-4 items-center justify-center rounded-full border border-current text-[10px]">2</span>
                {{ __('Details') }}
            </li>
            <li class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></li>
            <li class="flex items-center gap-1.5 text-zinc-400 dark:text-zinc-500">
                <span class="flex size-4 items-center justify-center rounded-full border border-current text-[10px]">3</span>
                {{ __('Signature') }}
            </li>
            <li class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></li>
            <li class="flex items-center gap-1.5 text-zinc-400 dark:text-zinc-500">
                <span class="flex size-4 items-center justify-center rounded-full border border-current text-[10px]">4</span>
                {{ __('Verify email') }}
            </li>
        </ol>
```

Also update the heads-up panel copy: change its heading from `{{ __('Next: enroll your signature') }}` to `{{ __('Next: your business & owner details') }}` and its body text to `{{ __('After creating your account you will declare your business and representative information, then enroll your reference signature.') }}`.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan config:clear && php artisan test --compact --filter=RegistrationTest`
Expected: PASS (3 tests). The register screen still renders (`test_registration_screen_can_be_rendered`).

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Responses/RegisterResponse.php resources/views/auth/register.blade.php tests/Feature/Auth/RegistrationTest.php
git commit -m "feat(vendor-reg): redirect registration to the business-details step (4-step flow)"
```

---

### Task 8: Read-only declared-info card on the vendor's own profile

**Files:**
- Modify: `resources/views/livewire/vendor/profile.blade.php`
- Test: `tests/Feature/Vendor/VendorProfilePageTest.php`

**Interfaces:**
- Consumes: `User::vendor()` + `Vendor::representative()` + `Vendor::$business_address` (Tasks 1–3); `Vendor::BUSINESS_ENTITY_TYPES`, `VendorRepresentative::GENDERS/GOVERNMENT_ID_TYPES` (display labels).
- Produces: the vendor profile page renders a read-only "Declared information" panel of the captured data when a vendor profile exists.

- [ ] **Step 1: Write the failing test**

Run: `php artisan make:test --phpunit Vendor/VendorProfilePageTest --no-interaction`

Replace the file body with:

```php
<?php

namespace Tests\Feature\Vendor;

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorRepresentative;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorProfilePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_shows_declared_business_and_owner_data(): void
    {
        $user = User::factory()->create();
        $vendor = Vendor::factory()->for($user)->create([
            'company_name' => 'Negofood Trading',
            'business_entity_type' => 'sole_proprietorship',
            'tin' => '123-456-789-000',
            'business_city' => 'Pasig',
        ]);
        VendorRepresentative::factory()->for($vendor)->create([
            'first_name' => 'Jose',
            'last_name' => 'Rizal',
            'government_id_type' => 'national_id',
        ]);

        $this->actingAs($user)
            ->get(route('vendor.profile'))
            ->assertOk()
            ->assertSee('Negofood Trading')
            ->assertSee('123-456-789-000')
            ->assertSee('Sole Proprietorship')
            ->assertSee('Jose Rizal')
            ->assertSee('Pasig');
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan config:clear && php artisan test --compact --filter=VendorProfilePageTest`
Expected: FAIL (the page does not render declared data yet).

- [ ] **Step 3: Add the declared-info card to the profile page**

Replace `resources/views/livewire/vendor/profile.blade.php` with:

```blade
<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public function with(): array
    {
        return [
            'vendor' => Auth::user()->vendor?->load('representative'),
        ];
    }
}; ?>

<x-page>
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 text-cu-text">
        @if ($vendor)
            <div class="rounded-2xl border border-cu-border bg-cu-surface p-5">
                <flux:heading size="lg">{{ __('Declared information') }}</flux:heading>
                <p class="text-xs text-cu-muted">{{ __('What your uploaded documents are cross-checked against. Contact a compliance officer to correct any of these.') }}</p>

                <h3 class="mt-5 text-sm font-semibold text-cu-text">{{ __('Business') }}</h3>
                <dl class="mt-3 grid gap-x-6 gap-y-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-cu-muted">{{ __('Registered business name') }}</dt>
                        <dd class="mt-0.5 text-sm">{{ $vendor->company_name }}</dd>
                    </div>
                    @if ($vendor->trade_name)
                        <div>
                            <dt class="text-xs text-cu-muted">{{ __('Trade name') }}</dt>
                            <dd class="mt-0.5 text-sm">{{ $vendor->trade_name }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-xs text-cu-muted">{{ __('Entity type') }}</dt>
                        <dd class="mt-0.5 text-sm">{{ \App\Models\Vendor::BUSINESS_ENTITY_TYPES[$vendor->business_entity_type] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-cu-muted">{{ __('TIN') }}</dt>
                        <dd class="mt-0.5 text-sm">{{ $vendor->tin ?? '—' }}</dd>
                    </div>
                    @if ($vendor->dti_registration_number)
                        <div>
                            <dt class="text-xs text-cu-muted">{{ __('DTI Registration No.') }}</dt>
                            <dd class="mt-0.5 text-sm">{{ $vendor->dti_registration_number }}</dd>
                        </div>
                    @endif
                    @if ($vendor->sec_registration_number)
                        <div>
                            <dt class="text-xs text-cu-muted">{{ __('SEC Registration No.') }}</dt>
                            <dd class="mt-0.5 text-sm">{{ $vendor->sec_registration_number }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-xs text-cu-muted">{{ __('Business Permit No.') }}</dt>
                        <dd class="mt-0.5 text-sm">{{ $vendor->business_permit_number ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-cu-muted">{{ __('Nature of business') }}</dt>
                        <dd class="mt-0.5 text-sm">{{ $vendor->nature_of_business ?? '—' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs text-cu-muted">{{ __('Business address') }}</dt>
                        <dd class="mt-0.5 text-sm">{{ $vendor->business_address ?: '—' }}</dd>
                    </div>
                </dl>

                @if ($vendor->representative)
                    <h3 class="mt-6 text-sm font-semibold text-cu-text">{{ __('Owner / representative') }}</h3>
                    <dl class="mt-3 grid gap-x-6 gap-y-4 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs text-cu-muted">{{ __('Full name') }}</dt>
                            <dd class="mt-0.5 text-sm">{{ $vendor->representative->fullName() }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-cu-muted">{{ __('Date of birth') }}</dt>
                            <dd class="mt-0.5 text-sm">{{ $vendor->representative->date_of_birth?->format('F j, Y') }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-cu-muted">{{ __('Gender') }}</dt>
                            <dd class="mt-0.5 text-sm">{{ \App\Models\VendorRepresentative::GENDERS[$vendor->representative->gender] ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-cu-muted">{{ __('Contact number') }}</dt>
                            <dd class="mt-0.5 text-sm">{{ $vendor->representative->contact_number }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-cu-muted">{{ __('Government ID') }}</dt>
                            <dd class="mt-0.5 text-sm">
                                {{ \App\Models\VendorRepresentative::GOVERNMENT_ID_TYPES[$vendor->representative->government_id_type] ?? '—' }}
                                · {{ $vendor->representative->government_id_number }}
                            </dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-xs text-cu-muted">{{ __('Home address') }}</dt>
                            <dd class="mt-0.5 text-sm">{{ $vendor->representative->home_address }}</dd>
                        </div>
                    </dl>
                @endif
            </div>
        @endif

        <livewire:settings.profile />
    </div>
</x-page>
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan config:clear && php artisan test --compact --filter=VendorProfilePageTest`
Expected: PASS (1 test).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/livewire/vendor/profile.blade.php tests/Feature/Vendor/VendorProfilePageTest.php
git commit -m "feat(vendor-reg): show declared business/owner data on the vendor profile"
```

---

### Task 9: Seed a complete profile for the demo vendor + full-suite verification

**Files:**
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: full suite

**Interfaces:**
- Consumes: `Vendor`/`VendorRepresentative` factories + models (Tasks 2–3). Without this, the seeded `vendor@advs.test` account would be bounced to `business.create` on login because it has no profile.

- [ ] **Step 1: Update the seeder**

In `database/seeders/DatabaseSeeder.php`, add the imports:

```php
use App\Models\Vendor;
use App\Models\VendorRepresentative;
```

Replace the seeded-vendor block (the `if ($role === User::ROLE_VENDOR && ! $user->hasEnrolledSignature()) { … }` block) with:

```php
            // Seeded vendors skip the onboarding gates so the demo account lands
            // on the dashboard: a complete declared profile + an enrolled
            // signature. (signature_path is a placeholder; no real reference
            // image exists for seeded data.)
            if ($role === User::ROLE_VENDOR) {
                $user->forceFill([
                    'signature_path' => "signatures/{$user->id}/seeded-reference.jpg",
                    'signature_enrolled_at' => now(),
                    'vendor_profile_completed_at' => now(),
                ])->save();

                $vendor = Vendor::firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'company_name' => 'Negofood Demo Trading',
                        'business_entity_type' => 'sole_proprietorship',
                        'tin' => '123-456-789-000',
                        'dti_registration_number' => 'DTI-2026000',
                        'business_permit_number' => 'BP-2026-000',
                        'nature_of_business' => 'Food retail and distribution',
                        'business_street' => '1 Caruncho Ave',
                        'business_barangay' => 'Barangay San Nicolas',
                        'business_city' => 'Pasig',
                        'business_province' => 'Metro Manila',
                        'business_postal_code' => '1600',
                        'status' => Vendor::STATUS_PENDING,
                    ],
                );

                VendorRepresentative::firstOrCreate(
                    ['vendor_id' => $vendor->id],
                    [
                        'first_name' => 'Demo',
                        'last_name' => 'Vendor',
                        'date_of_birth' => '1990-01-01',
                        'gender' => 'male',
                        'contact_number' => '+63 917 000 0000',
                        'government_id_type' => 'national_id',
                        'government_id_number' => '1234-5678-9012',
                        'home_address' => '1 Caruncho Ave, Pasig, Metro Manila',
                    ],
                );
            }
```

- [ ] **Step 2: Verify the seed runs end to end**

Run: `php artisan migrate:fresh --seed`
Expected: completes with no errors; `vendors` and `vendor_representatives` each contain one seeded row for `vendor@advs.test`.

> Note: this runs against the real MySQL `advs` DB, so it requires the DB to be up. If it is down, skip this manual step and rely on the test in Step 3.

- [ ] **Step 3: Add a seeder regression test**

Run: `php artisan make:test --phpunit DatabaseSeederTest --no-interaction`

Replace the file body with:

```php
<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_vendor_has_a_complete_profile_and_is_not_gated(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = \App\Models\User::where('email', 'vendor@advs.test')->firstOrFail();

        $this->assertTrue($user->hasCompletedVendorProfile());
        $this->assertTrue($user->hasEnrolledSignature());
        $this->assertNotNull($user->vendor);
        $this->assertNotNull($user->vendor->representative);

        $this->actingAs($user)->get(route('vendor.dashboard'))->assertOk();
    }
}
```

- [ ] **Step 4: Run the seeder test, then the full suite**

Run: `php artisan config:clear && php artisan test --compact --filter=DatabaseSeederTest`
Expected: PASS (1 test).

Run: `php artisan config:clear && php artisan test --compact`
Expected: the entire suite is green (auth, dashboard, signature, vendor, and the new vendor-registration tests).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/seeders/DatabaseSeeder.php tests/Feature/DatabaseSeederTest.php
git commit -m "feat(vendor-reg): seed a complete declared profile for the demo vendor"
```

---

## Self-Review Notes

- **Spec coverage:** Every declared field in the spec maps to a column in the Coverage Map and a task. Document-validity/expiry fields are intentionally excluded (extracted by the ML pipeline, not declared). Conditional DTI/SEC handled in Task 5 (form `@if`) + Task 4 (action nullifies the inapplicable one) + validation `required_if`.
- **Type consistency:** `hasCompletedVendorProfile()`, `vendor_profile_completed_at`, `entityRequiresDti/Sec`, `business_address`, `BUSINESS_ENTITY_TYPES`, `GENDERS`, `GOVERNMENT_ID_TYPES`, `CreateVendorProfile::execute(User, array): Vendor`, and route name `business.create` are used identically everywhere they appear.
- **Gate ordering proof:** business gate first (redirects to `business.create` for any non-exempt route while the profile is incomplete) → a vendor cannot reach `signature.create` until the profile exists; the signature gate's added `hasCompletedVendorProfile()` guard stops it from yanking a vendor off the half-finished business step. Existing signature tests stay green because the default factory user has a completed profile.
- **No placeholders:** every code step contains the full artifact or an exact, unique replacement target.

## Open follow-ups (not in this plan)

1. **Officer-facing display** of declared data on `admin/vendors/{vendor}` — blocked on replacing `DemoStore` with real `Vendor` reads.
2. **Automated declared-vs-extracted cross-check** — depends on OCR field extraction reaching production (project memory: *ocr-preprocessing-otsu-vs-fixed150*), then a comparison service + mismatch flags feeding the Stage 5 risk score and the officer report.
